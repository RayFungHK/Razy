<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Entity class for the Razy Template Engine. An Entity is a
 * runtime instance of a Block, holding assigned parameter values and child
 * entity collections. Entities handle template rendering by processing
 * parameter tags, function tags, and modifier chains.
 *
 *
 * @license MIT
 */

namespace Razy\Template;

use Razy\Exception\TemplateException;
use Razy\ModuleInfo;
use Razy\Template;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Plugin\TFunctionCustom;
use ReflectionProperty;
use Throwable;

/**
 * Runtime instance of a template Block, used for rendering.
 *
 * An Entity holds parameter values and child entity collections for a specific
 * Block. During rendering, it processes parameter tags (`{$var}`), function tags
 * (`{@func ...}`), modifier chains (`{$var->modifier}`), and inline comments
 * (`{#...}`). Entities support hierarchical value resolution, linked wrapper
 * entities, and path-based entity lookup.
 *
 * @class Entity
 */
class Entity
{
    use ParameterBagTrait;

    /**
     * Function tags `{@name}`, closers `{/name}`, and comments `{#...}`.
     * A leading `.` is skipped, matching the historical tag scanner.
     */
    private const FUNCTION_TAG_PATTERN = '/\.(*SKIP)(*FAIL)|{(?:@(\w+)((?:(?:\.|(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)|[^{}])*)|\/(\w+)|#[^}]*?)}/s';

    /** @var array<string, array<string, mixed>> Cached parsed parameter values keyed by name and path */
    private array $caches = [];

    /** @var array<string, array<string, Entity>> Child entities indexed by block name and entity ID */
    private array $entities = [];

    /** @var array<string, mixed> Entity-level template parameters */
    private array $parameters = [];

    /** @var Entity|null Linked wrapper entity for delegating block creation */
    private ?Entity $linkedEntity = null;

    /** @var array<string, string> RZ-28: plugin output held out of the {$…} pass, by marker */
    private array $held = [];

    /** Random per outermost parseText(); null when no parse is running */
    private ?string $holdToken = null;

    /** parseText() nesting (a plugin may parse its own body) */
    private int $textDepth = 0;

    /**
     * Entity constructor.
     *
     * @param Block $block The Block object
     * @param string $id The entity id
     * @param self|null $parent The parent Entity object
     *
     * @throws Throwable
     */
    public function __construct(private readonly Block $block, private string $id = '', private readonly ?self $parent = null)
    {
        $this->id = \trim($this->id);
        // Generate a random hex ID if none was provided
        if (!$this->id) {
            $this->id = \sprintf('%04x%04x', \mt_rand(0, 0xffff), \mt_rand(0, 0xffff));
        }
    }

    /**
     * Whether the tag's modifier chain already makes its output safe, so
     * auto-escape must not touch it: ->raw or ->escape anywhere in the chain,
     * or ->json as the LAST modifier (its JSON_HEX_TAG/AMP/APOS/QUOT output
     * holds no `<`, `>`, `&`, `'` or `"`, so it is safe in <script> and in
     * attributes; entity-encoding it would break `const c = {$cfg->json};`).
     * ->json only counts when last because a later modifier may reintroduce
     * markup. Quoted path keys and quoted modifier arguments are blanked first,
     * so text such as `{$u.'bio->raw'}` or `->truncate:5:'->raw'` cannot switch
     * escaping off.
     *
     * @param string $clip One pipe-delimited alternative of a tag
     *
     * @return bool
     */
    private static function hasSafeModifier(string $clip): bool
    {
        $unquoted = \trim((string) \preg_replace('/(?<q>[\'"])(?:\\\.|(?!\k<q>).)*\k<q>/s', "''", $clip));

        return 1 === \preg_match('/->(?:raw|escape)(?![\w])/', $unquoted)
            || 1 === \preg_match('/->json(?::(?:\w+|\'\'|-?\d+(?:\.\d+)?))*$/', $unquoted);
    }

    /**
     * Get the module which was loaded the template file.
     *
     * @return ModuleInfo|null
     */
    public function getModule(): ?ModuleInfo
    {
        return $this->block->getModule();
    }

    /**
     * Detach this entity from the parent entity list.
     *
     * @return self Chainable
     */
    public function detach(): self
    {
        $this->parent->remove($this->block->getName(), $this->id);

        return $this;
    }

    /**
     * Remove the entity by given block name and entity id.
     *
     * @param string $blockName The block name under current block level
     * @param string $id The entity id
     *
     * @return self Chainable
     */
    public function remove(string $blockName, string $id): self
    {
        if (isset($this->entities[$blockName])) {
            unset($this->entities[$blockName][$id]);
        }

        return $this;
    }

    /**
     * Find the entities by given path.
     *
     * @param string $path The block path
     *
     * @return array An array contains matched entity
     *
     * @throws Error
     */
    public function find(string $path): array
    {
        $path = \trim($path);
        $paths = \preg_split('/(?<quote>[\'"])(\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>(*SKIP)(*FAIL)|\//', $path, PREG_SPLIT_NO_EMPTY);
        if (empty($paths)) {
            return [];
        }

        $entities = $this->entities;
        if (empty($entities)) {
            return [];
        }

        // Walk through each path segment to drill down into nested entities
        foreach ($paths as $blockName) {
            $blockName = \trim($blockName);
            if (!\preg_match('/^(\w+)(?:\[(?:(\d+)|(?<quote>[\'"])(\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>)\])?$/', $blockName, $matches)) {
                throw new TemplateException('The path of `' . $blockName . '` is not in valid format.');
            }

            $entityMatched = [];
            // Match entities by block name, optionally filtered by ID or index
            foreach ($entities as $entity) {
                if (!$entity->hasBlock($matches[1])) {
                    continue;
                }

                if ($matches[4] ?? '') {
                    // Lookup by specific entity ID
                    $entityMatched[] = $entity->getEntity($matches[1], $matches[4]);
                } else {
                    $entityList = $this->getEntities($matches[1]);
                    if ('' !== ($matches[2] ?? '')) {
                        // Lookup by numeric index
                        if ($matches[2] < \count($entityList)) {
                            $entityMatched[] = $entityList[$matches[2]];
                        }
                    } else {
                        $entityMatched = \array_merge($entityMatched, $entityList);
                    }
                }
            }

            if (empty($entityMatched)) {
                return [];
            }
            $entities = $entityMatched;
        }

        return $entities;
    }

    /**
     * Check if the sub block is existing by given name.
     *
     * @param string $blockName The block name under current block level
     *
     * @return bool Return true id the block is existing
     */
    public function hasBlock(string $blockName): bool
    {
        return $this->block->hasBlock($blockName);
    }

    /**
     * Get specify entity by the given block name and identity.
     *
     * @param string $blockName The block name to obtain its entity
     * @param string $identity The identity of the entity
     *
     * @return Entity|null A sub-block Entity
     */
    public function getEntity(string $blockName, string $identity): ?self
    {
        return $this->entities[$blockName][$identity] ?? null;
    }

    /**
     * Get the entity list by the given block name.
     *
     * @param string $blockName The block name to obtain its entity
     *
     * @return array An array contains entities
     */
    public function getEntities(string $blockName): array
    {
        return $this->entities[$blockName] ?? [];
    }

    /**
     * Return the count of entity by block name.
     *
     * @param string $blockName The block name
     *
     * @return int The count of entity
     */
    public function getBlockCount(string $blockName): int
    {
        if (isset($this->entities[$blockName])) {
            return \count($this->entities[$blockName]);
        }

        return 0;
    }

    /**
     * RZ-27: the Template manager rendering this entity (anchors, layout); null
     * for an entity that is not attached to a parsed block.
     */
    public function getManager(): ?Template
    {
        // An entity made without its constructor (a bare test double) has no block
        if (!(new ReflectionProperty(self::class, 'block'))->isInitialized($this)) {
            return null;
        }

        return $this->block->getSource()->getManager();
    }

    /**
     * Get the current entity block name.
     *
     * @return string The block name
     */
    public function getBlockName(): string
    {
        return $this->block->getName();
    }

    /**
     * Get the total number of entities by the given block name.
     *
     * @param string $blockName
     *
     * @return int
     */
    public function getEntityCount(string $blockName): int
    {
        return \count($this->entities[$blockName] ?? []);
    }

    /**
     * Get the template file location.
     *
     * @return string
     */
    public function getFileLocation(): string
    {
        return $this->block->getFileLocation();
    }

    /**
     * Get the entity id.
     *
     * @return string The entity id
     */
    public function getID(): string
    {
        return $this->id;
    }

    /**
     * Get the template block content by given name.
     *
     * @param string $name
     *
     * @return Block|null
     *
     * @throws Throwable
     */
    public function getTemplate(string $name): ?Block
    {
        return $this->block->getTemplate($name);
    }

    /**
     * Check if current contains specified entity by the given block name.
     *
     * @param string $blockName The block name to obtain its entity
     * @param string $identity The identity of the entity
     *
     * @return bool Return true if exists
     */
    public function hasEntity(string $blockName, string $identity = ''): bool
    {
        if ($identity) {
            return isset($this->entities[$blockName][$identity]);
        }

        return \count($this->entities[$blockName] ?? []) > 0;
    }

    /**
     * Create a new block or return the Entity instance by the given id that the Entity is matched.
     *
     * @param string $blockName The block name under current block level
     * @param string $id The entity id
     *
     * @return Entity The Entity object
     *
     * @throws Throwable
     */
    public function newBlock(string $blockName, string $id = ''): self
    {
        $id = \trim($id);
        if (!$id) {
            $id = \sprintf('%04x%04x', \mt_rand(0, 0xffff), \mt_rand(0, 0xffff));
        }

        // Return existing entity if one already exists with this block name and ID
        if (isset($this->entities[$blockName][$id])) {
            return $this->entities[$blockName][$id];
        }

        $block = $this->block->getBlock($blockName);

        if (!isset($this->entities[$blockName])) {
            $this->entities[$blockName] = [];
        }

        // Delegate to linked wrapper entity if one exists
        if ($this->linkedEntity) {
            return $this->linkedEntity->newBlock($blockName, $id);
        }
        $blockEntity = new self($block, $id, $this);
        $this->entities[$blockName][$id] = $blockEntity;
        if ($block->getType() === 'WRAPPER') {
            $this->linkedEntity = $this->entities[$blockName][$id];
            return $this->linkedEntity->newBlock($blockName, $id);
        }

        return $blockEntity;
    }

    /**
     * Process and return the block content, and the parameter tag and function tag will be replaced.
     *
     * @return string The processed block content
     *
     * @throws Throwable
     */
    public function process(): string
    {
        $content = '';
        $structure = $this->block->getStructure();
        // Iterate through structure: text segments are parsed, Block references trigger entity processing
        foreach ($structure as $index => $entity) {
            if ($entity instanceof Block) {
                $content .= $this->processEntity($index);
            } else {
                // Use pre-compiled tokens when available (segments without function tags),
                // avoiding both parseFunctionTag() and parseText() regex overhead
                $compiled = $this->block->getCompiledSegment($index);
                if ($compiled) {
                    $content .= $this->processCompiledSegment($compiled);
                } else {
                    $clip = $entity;
                    $content .= $this->parseText($clip);
                }
            }
        }

        return $content;
    }

    /**
     * Parse the content and return the content with the content processed by function tag and the converted parameter
     * tag.
     *
     * @param string $content A clip of block content
     *
     * @return string The block content which has replaced the parameter tag
     *
     * @throws Throwable
     */
    public function parseText(string $content): string
    {
        // RZ-28: a function plugin's output is final. It is held out of the {$…}
        // pass below behind a per-render marker and spliced back afterwards, so a
        // value a plugin printed (a component's text, a URL, a script body) is never
        // read as a parameter tag. Only the template source is substituted.
        if (0 === $this->textDepth++) {
            $this->held = [];
            $this->holdToken = \bin2hex(\random_bytes(8));
        }

        try {
            $content = $this->parseFunctionTag($content);

            return $this->splice($this->substitute($content));
        } finally {
            if (0 === --$this->textDepth) {
                $this->held = [];
                $this->holdToken = null;
            }
        }
    }

    /**
     * Parse the text or parameter pattern into a value.
     *
     * @return mixed The value of the parameter
     *
     * @throws Throwable
     */
    public function parseValue(string $content): mixed
    {
        $content = \trim($content);
        if (0 == \strlen($content)) {
            return null;
        }

        // If the content is a parameter tag
        if (\preg_match('/^(?:(true|false)|(-?\d+(?:\.\d+)?)|(?<q>[\'"])((?:\.(*SKIP)|(?!\k<q>).)*)\k<q>)$/', $content, $matches)) {
            if ($matches[1]) {
                return $matches[1] === 'true';
            }
            return $matches[4] ?? $matches[2] ?? null;
        }
        if ('$' == $content[0] && \preg_match('/^\$(\w+)((?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)))*)((?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)$/', $content, $matches)) {
            return $this->parseParameter($matches[1], $matches[2] ?? '', $matches[5] ?? '');
        }

        return null;
    }

    /**
     * Return a parameter value from the entity scope.
     *
     * When $recursion is true and the parameter is not assigned at this
     * scope, resolution continues upward: Entity → Block → Source → Template.
     *
     * @param string $parameter The parameter name
     * @param bool $recursion If true, walk up through Block, Source, and Template scopes when not found here
     *
     * @return mixed The parameter value, or null if not found at any resolved scope
     */
    public function getValue(string $parameter, bool $recursion = false): mixed
    {
        if ($recursion) {
            if (!$this->parameterAssigned($parameter)) {
                return $this->block->getValue($parameter, true);
            }
        }

        return $this->parameters[$parameter] ?? null;
    }

    /**
     * Determine the parameter has been assigned.
     *
     * @param string $parameter The parameter name
     *
     * @return bool Return true if the parameter is existing
     */
    public function parameterAssigned(string $parameter): bool
    {
        return \array_key_exists($parameter, $this->parameters);
    }

    /**
     * Run function tags once and leave `{$param}` tags in place.
     *
     * `{@if}` uses this on the chosen branch only. The caller then substitutes
     * parameter tags a single time, so a value that itself contains `{$…}` or
     * `{@…}` is text, not a second template (RZ-30).
     *
     * @param string $content Branch text, not yet substituted
     *
     * @return string The same text with function tags expanded
     *
     * @throws Throwable
     */
    public function expandFunctionTags(string $content): string
    {
        return $this->parseFunctionTag($content);
    }

    /**
     * Split on the `{@else}` that belongs to this body, not one nested in another enclosure.
     *
     * The split reads the template source. A value substituted later cannot
     * insert an `{@else}` and move the branch (RZ-30).
     *
     * @param string $content The enclosed `{@if}` body, before substitution
     *
     * @return array{0: string, 1: string} True branch and false branch (`''` when there is no `{@else}`)
     *
     * @throws Throwable
     */
    public function splitTopElse(string $content): array
    {
        $rest = $content;
        $true = '';
        $stack = [];
        while (\preg_match(self::FUNCTION_TAG_PATTERN, $rest, $matches, PREG_OFFSET_CAPTURE)) {
            $tag = $matches[0][0];
            $pos = (int) $matches[0][1];
            $isClosing = isset($matches[4][0]);
            $isComment = \str_starts_with($tag, '{#');
            $name = $matches[4][0] ?? $matches[1][0] ?? '';

            $true .= \substr($rest, 0, $pos);
            $rest = \substr($rest, $pos + \strlen($tag));

            if ($isComment) {
                $true .= $tag;

                continue;
            }

            // `{@else}` is not a plugin. At depth 0 it is this body's divider.
            if (!$isClosing && 'else' === $name && [] === $stack) {
                return [$true, $rest];
            }

            $true .= $tag;
            if (!$isClosing) {
                $plugin = $this->block->loadPlugin('function', $name);
                if ($plugin && $plugin->isEncloseContent()) {
                    $stack[] = $name;
                }
            } elseif ([] !== $stack && $name === \end($stack)) {
                \array_pop($stack);
            }
        }

        return [$content, ''];
    }

    /**
     * Invalidate cached value when a parameter is reassigned.
     *
     * @param string $parameter The parameter name that was assigned
     */
    protected function onParameterAssigned(string $parameter): void
    {
        unset($this->caches[$parameter]);
    }

    /**
     * Replace parameter tags with their values (one pass over template text).
     *
     * @throws Throwable
     */
    private function substitute(string $content): string
    {
        // Replace parameter tags {$var} with resolved values, supporting dot-path access and modifier chains
        return (string) \preg_replace_callback('/{((\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)(?:\|(?:(?2)|(?P>rq)))*)}/', function ($matches) {
            $clips = \preg_split('/(?<quote>[\'"])(\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>(*SKIP)(*FAIL)|\|/', $matches[1]);

            return $this->renderTag($clips);
        }, $content);
    }

    /**
     * Template source a `$templateOutput` plugin returned (`{@repeat}`'s body,
     * `{@if}`'s branch): its own function tags are expanded now, under the same
     * hold, and the caller's single pass substitutes its `{$…}`, exactly as if it
     * had been written in place.
     *
     * @throws Throwable
     */
    private function templateSource(string $source): string
    {
        return \str_contains($source, '{') ? $this->parseFunctionTag($source) : $source;
    }

    /**
     * Hold a plugin's final output out of the parameter pass.
     */
    private function hold(string $output): string
    {
        if (null === $this->holdToken || '' === $output) {
            return $output;
        }
        $marker = "\x1Brz-held:" . $this->holdToken . ':' . \count($this->held) . "\x1B";
        $this->held[$marker] = $output;

        return $marker;
    }

    /**
     * Put held plugin output back (one pass; markers inside held output are its own).
     */
    private function splice(string $content): string
    {
        if ([] === $this->held || null === $this->holdToken || !\str_contains($content, "\x1Brz-held:" . $this->holdToken)) {
            return $content;
        }

        return \strtr($content, $this->held);
    }

    /**
     * Process a pre-compiled template segment using tokenized variable references.
     * Skips regex matching entirely — iterates pre-parsed tokens and resolves values directly.
     *
     * @param CompiledTemplate $compiled The pre-compiled segment tokens
     *
     * @return string Rendered content with variables resolved
     */
    private function processCompiledSegment(CompiledTemplate $compiled): string
    {
        $result = '';
        foreach ($compiled->segments as $segment) {
            if (\is_string($segment)) {
                $result .= $segment;
            } else {
                // Resolve pipe-delimited variable alternatives (pre-split during compilation)
                $result .= $this->renderTag($segment['clips']);
            }
        }
        return $result;
    }

    /**
     * Render one `{$tag}`: the first pipe-delimited alternative that yields a
     * renderable value wins. With auto-escape on (RZ-03) the value is
     * HTML-escaped unless that alternative's modifier chain has ->raw or ->escape,
     * or ends in ->json (already context-safe; see hasSafeModifier()).
     *
     * @param array<int, string> $clips The pipe-delimited alternatives
     *
     * @return string
     *
     * @throws Throwable
     */
    private function renderTag(array $clips): string
    {
        foreach ($clips as $clip) {
            $value = $this->parseValue($clip) ?? '';
            if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
                if (\is_bool($value)) {
                    $value = $value ? '1' : '';
                }
                $value = (string) $value;
                if ($this->block->isAutoEscape() && !self::hasSafeModifier($clip)) {
                    return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }

                return $value;
            }
        }

        return '';
    }

    /**
     * Process the entities in list by the block name.
     *
     * @param string $blockName The block name
     *
     * @return string All output content from entities
     */
    private function processEntity(string $blockName): string
    {
        $content = '';
        if (isset($this->entities[$blockName])) {
            if ($this->linkedEntity) {
                if (!$this->linkedEntity->hasBlock($blockName) || !$this->linkedEntity->getBlockCount($blockName)) {
                    return '';
                }
            }

            foreach ($this->entities[$blockName] as $entity) {
                $content .= $entity->process();
            }

            return $content;
        }

        return '';
    }

    /**
     * Parse the function tag if the specified plugin is loaded.
     *
     * @param string $content A clip of block content
     * @param TFunction|TFunctionCustom|null $enclosure
     *
     * @return string The block content which has replaced the function tag
     *
     * @throws Error
     * @throws Throwable
     */
    private function parseFunctionTag(string &$content, TFunction|TFunctionCustom|null $enclosure = null): string
    {
        // Stack to track nested enclosure function tags during parsing
        $stacking = [];
        $result = '';
        // Match function tags {@name...}, closing tags {/name}, and comment tags {#...}
        while (\preg_match(self::FUNCTION_TAG_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = (int) $matches[0][1];

            $isClosingTag = isset($matches[4][0]);
            $isCommentTag = \str_starts_with($matches[0][0], '{#');
            $functionName = $matches[4][0] ?? $matches[1][0] ?? '';

            // Append the content before the matched tag into result
            $result .= \substr($content, 0, $offset);

            // Crop the content after the matched element
            $content = \substr($content, $offset + \strlen($matches[0][0]));

            // Handle inline comment blocks {#...} - skip entire block
            if ($isCommentTag) {
                continue;
            }

            if (null !== $enclosure) {
                // Enclosure mode: collect content until matching closing tag is found
                if ($isClosingTag) {
                    if (empty($stacking) && $enclosure->getName() === $functionName) {
                        return $result;
                    }
                }

                $result .= $matches[0][0];
                if (!$isClosingTag) {
                    $plugin = $this->block->loadPlugin('function', $functionName);
                    if ($plugin && $plugin->isEncloseContent()) {
                        $stacking[] = $functionName;
                    }
                } elseif ($functionName === \end($stacking)) {
                    \array_pop($stacking);
                }
            } else {
                // Normal mode: load and invoke plugin, replace tag with output
                $plugin = $this->block->loadPlugin('function', $functionName);

                if ($plugin) {
                    if ($plugin->isEncloseContent()) {
                        $wrapped = '';

                        if (\str_contains($content, '{/' . $matches[1][0] . '}')) {
                            $wrapped = $this->parseFunctionTag($content, $plugin);
                            $wrapped = $plugin->parse($this, $matches[2][0] ?? '', $wrapped);
                        }

                        $result .= $plugin->returnsTemplate() ? $this->templateSource((string) $wrapped) : $this->hold((string) $wrapped);
                    } else {
                        $output = (string) $plugin->parse($this, $matches[2][0] ?? '');
                        $result .= $plugin->returnsTemplate() ? $this->templateSource($output) : $this->hold($output);
                    }
                } else {
                    $result .= $matches[0][0];
                }
            }
        }

        $result .= $content;

        return $result;
    }

    /**
     * Get the value by given parameter name, path and modifier.
     *
     * @param string $name The name of the parameter
     * @param string $path The path of the array to obtain the value
     * @param string $modifier The modifier syntax
     *
     * @return mixed|null
     *
     * @throws Throwable
     */
    private function parseParameter(string $name, string $path = '', string $modifier = ''): mixed
    {
        // Load the cached value by the parameter name and its path.
        if (!isset($this->caches[$name][$path])) {
            $value = $this->getValue($name, true);

            $value = Template::GetValueByPath($value, $path);

            $this->caches[$name][$path] = $value;
        } else {
            $value = $this->caches[$name][$path];
        }

        // Apply modifier chain (e.g., ->uppercase, ->truncate:50)
        if (\strlen($modifier) > 0) {
            \preg_match_all('/->(\w+)((?::(?:\w+|(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>|-?\d+(?:\.\d+)?))*)/', $modifier, $matches, PREG_SET_ORDER);
            foreach ($matches as $clip) {
                $plugin = $this->block->loadPlugin('modifier', $clip[1]);
                if ($plugin) {
                    $value = $plugin->modify($value, $clip[2] ?? '');
                }
            }
        }

        return $value;
    }
}
