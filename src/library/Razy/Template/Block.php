<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Block class for the Razy Template Engine. Blocks are structural
 * units parsed from template tag comments, supporting nested sub-blocks,
 * parameter assignment, template inclusion, recursion, and wrapper patterns.
 *
 *
 * @license MIT
 */

namespace Razy\Template;

use Razy\Exception\TemplateException;
use Razy\FileReader;
use Razy\ModuleInfo;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Plugin\TFunctionCustom;
use Razy\Template\Plugin\TModifier;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Represents a structural block within a parsed template file.
 *
 * Blocks are created by parsing template tag comments (e.g., `<!-- START BLOCK: name -->`).
 * They support nesting, parameter assignment, recursion references, wrapper patterns,
 * and cross-block reuse via the `USE` directive. Each Block maintains its own structure
 * of text segments and child blocks, and can spawn Entity instances for rendering.
 *
 * @class Block
 */
class Block
{
    use ParameterBagTrait;

    /** @var int Maximum depth for parent traversal to prevent infinite recursion */
    private const MAX_DEPTH = 100;

    /** @var array<string, Block> Named child blocks indexed by block name */
    private array $blocks = [];

    /** @var array<string, mixed> Block-level template parameters */
    private array $parameters = [];

    /** @var string Fully-qualified path of this block within the template hierarchy */
    private string $path;

    /** @var array<int|string, string|Block> Ordered structure of text segments and child block references */
    private array $structure = [];

    /** @var array<int|string, CompiledTemplate> Pre-compiled segments for function-tag-free text, keyed by structure index */
    private array $compiledSegments = [];

    /** @var Block|null Reference to the parent block, null for root */
    private ?Block $parent;

    /**
     * Block constructor.
     *
     * @param Source $source The Source object
     * @param string $blockName The block name
     * @param FileReader $reader The FileReader of the template file reader
     * @param bool $readonly
     * @param self|null $parent Current block parent
     *
     * @throws Throwable
     */
    public function __construct(private readonly Source $source, private readonly string $blockName, FileReader $reader, private readonly bool $readonly = false, self $parent = null, private readonly string $type = '')
    {
        $this->parent = $parent;
        // Root block path is '/', child blocks build hierarchical path from parent
        if (!$parent) {
            $this->path = '/';
        } else {
            $this->path = (($parent->getPath() === '/') ? '' : $parent->getPath()) . '/' . $blockName;
        }

        // Accumulator for plain text content between block structure tags
        $concat = '';

        while (($line = $reader->fetch()) !== null) {
            // If the line is a block tag
            if (\str_contains($line, '<!-- ')) {
                if (\preg_match('/^\s*<!-- (INCLUDE|TEMPLATE|START|END|WRAPPER|RECURSION|USE (\w[\w-]*)) BLOCK: (.+) -->\s*$/', $line, $matches)) {
                    // Skip non-INCLUDE tags with invalid block names (treat as plain text)
                    if ('INCLUDE' !== $matches[1] && !\preg_match('/^\w[\w\-]+(?=[^-])\w$/', $matches[3])) {
                        $concat .= $line;
                    } else {
                        // Flush accumulated text before processing a structural tag
                        if ($concat) {
                            $this->structure[] = $concat;
                            $concat = '';
                        }

                        // INCLUDE: resolve and prepend an external template file into the reader
                        if ('INCLUDE' === $matches[1]) {
                            $path = \realpath(PathUtil::append($this->source->getFileDirectory(), $matches[3]));
                            if ($path) {
                                $reader->prepend($path);
                            }
                        } elseif ('WRAPPER' === $matches[1] || 'START' === $matches[1] || 'TEMPLATE' === $matches[1]) {
                            // Block opening: create a new child Block (TEMPLATE blocks are readonly)
                            $matches[3] = \trim($matches[3]);
                            if (isset($this->structure[$matches[3]])) {
                                throw new TemplateException('The block ' . $this->path . '/' . $matches[3] . ' is already exists.');
                            }

                            $this->blocks[$matches[3]] = new self($this->source, $matches[3], $reader, 'TEMPLATE' === $matches[1], $this, $matches[1]);
                            $this->structure[$matches[3]] = $this->blocks[$matches[3]];
                        } elseif ('RECURSION' === $matches[1]) {
                            // RECURSION: reference nearest ancestor block with matching name
                            if (!($parent = $this->getClosest($matches[3]))) {
                                throw new TemplateException('No parent block ' . $matches[3] . ' is found to declare as a recursion block.');
                            }

                            $this->blocks[$matches[3]] = $parent;
                            $this->structure[$matches[3]] = $this->blocks[$matches[3]];
                        } elseif (\str_starts_with($matches[1], 'USE ')) {
                            // USE directive: reuse a named block from a parent block's scope
                            $found = false;
                            $parent = $this->parent;
                            $depth = 0;
                            while ($parent !== null) {
                                if (++$depth > self::MAX_DEPTH) {
                                    throw new TemplateException('Maximum block nesting depth (' . self::MAX_DEPTH . ') exceeded while resolving USE directive for block ' . $matches[3] . '.');
                                }
                                if ($parent->hasBlock($matches[2])) {
                                    $found = true;
                                    $this->blocks[$matches[3]] = $parent->getBlock($matches[2]);
                                    $this->structure[$matches[3]] = $this->blocks[$matches[3]];

                                    break;
                                }
                                $parent = $parent->getParent();
                            }

                            if (!$found) {
                                throw new TemplateException('The template block ' . $matches[3] . ' cannot be found from parent block.');
                            }
                        } elseif ('END' === $matches[1]) {
                            // END tag must match current block name to close parsing
                            if ($blockName === $matches[3]) {
                                break;
                            }

                            throw new TemplateException('The block ' . $matches[3] . ' does not have the START tag. Current block [' . $blockName . ']');
                        }
                    }
                }

                continue;
            }

            $concat .= $line;
        }

        // Flush any remaining accumulated text content
        if ($concat) {
            $this->structure[] = $concat;
        }

        // Pre-compile text segments that don't contain function tags.
        // These can use the tokenized form at render time, skipping both
        // the function tag regex (parseFunctionTag) and variable tag regex (parseText).
        foreach ($this->structure as $index => $segment) {
            if (\is_string($segment)
                && !\str_contains($segment, '{@')
                && !\str_contains($segment, '{/')
                && !\str_contains($segment, '{#')
            ) {
                $this->compiledSegments[$index] = CompiledTemplate::compile($segment);
            }
        }
    }

    /**
     * Get the pre-compiled template for a specific structure segment.
     *
     * @param int|string $index The structure index
     *
     * @return CompiledTemplate|null The compiled template or null if segment has function tags
     */
    public function getCompiledSegment(int|string $index): ?CompiledTemplate
    {
        return $this->compiledSegments[$index] ?? null;
    }

    /**
     * Get the block structure type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the module which was loaded the template file.
     *
     * @return ModuleInfo|null
     */
    public function getModule(): ?ModuleInfo
    {
        return $this->source->getModule();
    }

    /**
     * Get the block path.
     *
     * @return string The block path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Walk through the parent and return the block by given block name.
     *
     * @param string $block the block name
     *
     * @return Block|null The Block object
     */
    public function getClosest(string $block): ?self
    {
        $block = \trim($block);
        if (!$block || !$this->parent) {
            return null;
        }
        if ($this->getName() === $block) {
            return $this;
        }

        // Iterative traversal with depth guard instead of unbounded recursion
        $parent = $this->parent;
        $depth = 0;
        while ($parent !== null) {
            if (++$depth > self::MAX_DEPTH) {
                throw new TemplateException('Maximum block nesting depth (' . self::MAX_DEPTH . ') exceeded while searching for block ' . $block . '.');
            }
            if ($parent->getName() === $block) {
                return $parent;
            }
            $parent = $parent->parent;
        }

        return null;
    }

    /**
     * Get the block name.
     *
     * @return string The block name
     */
    public function getName(): string
    {
        return $this->blockName;
    }

    /**
     * Determine the block is existing in current block.
     *
     * @param string $name The block name
     *
     * @return bool Return true if the block is existing
     */
    public function hasBlock(string $name): bool
    {
        return \array_key_exists($name, $this->blocks);
    }

    /**
     * Return the block by given name.
     *
     * @param string $name The block name
     *
     * @return Block The Block object
     *
     * @throws Throwable
     */
    public function getBlock(string $name): self
    {
        $name = \trim($name);
        if (!$this->hasBlock($name)) {
            throw new TemplateException('Block ' . $name . ' is not exists in ' . $this->getPath() . '.');
        }

        return $this->blocks[$name];
    }

    /**
     * Get the template file located system path.
     *
     * @return string Get the template file location
     */
    public function getFileLocation(): string
    {
        return $this->source->getFileDirectory();
    }

    /**
     * Get the parent entity.
     *
     * @return Block|null The parent Block object, or null for the root block
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Return the processed block structure.
     *
     * @return array An array contains the block structure
     */
    public function getStructure(): array
    {
        return $this->structure;
    }

    /**
     * Get the template block by given name.
     *
     * @param string $name
     *
     * @return Block|null
     *
     * @throws Throwable
     */
    public function getTemplate(string $name): ?self
    {
        $parent = $this;
        $depth = 0;
        do {
            if (++$depth > self::MAX_DEPTH) {
                throw new TemplateException('Maximum block nesting depth (' . self::MAX_DEPTH . ') exceeded while searching for template block ' . $name . '.');
            }
            if ($parent->hasBlock($name)) {
                $block = $parent->getBlock($name);
                // Only template block is readonly
                if ($block->isReadonly()) {
                    return $block;
                }
            }
        } while (($parent = $parent->getParent()) !== null);

        // Get the global Template Block
        return $this->source->getTemplate($name);
    }

    /**
     * Get the type is readonly or not.
     *
     * @return bool
     */
    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    /**
     * Return a parameter value from the block scope.
     *
     * When $recursion is true and the parameter is not assigned at this
     * scope, resolution continues upward: Block → Source → Template.
     *
     * @param string $parameter The parameter name
     * @param bool $recursion If true, walk up through Source and Template scopes when not found here
     *
     * @return mixed The parameter value, or null if not found at any resolved scope
     */
    public function getValue(string $parameter, bool $recursion = false): mixed
    {
        if ($recursion) {
            if (!$this->parameterAssigned($parameter)) {
                return $this->source->getValue($parameter, true);
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
     * Load the plugin from registered plugin folder.
     *
     * @param string $type
     * @param string $name
     *
     * @return TModifier|TFunction|TFunctionCustom|null
     *
     * @throws Error
     * @throws Throwable
     */
    public function loadPlugin(string $type, string $name): null|TModifier|TFunction|TFunctionCustom
    {
        return $this->source->loadPlugin($type, $name);
    }

    /**
     * Return a new Entity object related on this Block.
     *
     * @return Entity
     *
     * @throws Throwable
     */
    public function newEntity(): Entity
    {
        return new Entity($this);
    }
}
