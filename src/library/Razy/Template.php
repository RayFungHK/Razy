<?php
/**
 * This file is part of Razy v0.5.
 *
 * Template engine for the Razy framework. Provides a powerful template parsing
 * system with support for variables, modifiers, functions, and block-based
 * template composition.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;
use Exception;
use InvalidArgumentException;
use Razy\Exception\TemplateException;
use Razy\Template\Block;
use Razy\Template\Plugin\TFunctionCustom;
use Razy\Template\Plugin\TModifier;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Source;
use Razy\Contract\TemplateInterface;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Template engine manager for the Razy framework.
 *
 * Handles template loading, parsing, and rendering with support for plugin-based
 * modifiers and functions, parameter binding, source management, and queue-based
 * output composition.
 *
 * @class Template
 */
class Template implements TemplateInterface
{
    use PluginTrait;
    use Template\ParameterBagTrait;

    /** @var array<string, mixed> Manager-level template parameters */
    private array $parameters = [];

    /** @var array<string, TModifier|TFunction|TFunctionCustom> Loaded plugin instances indexed by type.name */
    private array $plugins = [];

    /** @var array<string, Source> Queue of Source entities for ordered output */
    private array $queue = [];

    /** @var array<string, Source> Registered Source entities indexed by ID */
    private array $sources = [];

    /** @var array<string, Block> Global template blocks indexed by name */
    private array $templates = [];

    /**
     * Template constructor.
     *
     * @param string $folder The folder of the plugin located
     */
    public function __construct(string $folder = '')
    {
        $this->addPluginFolder($folder);
    }

    /**
     * Parse the content without modifier and function tag
     *
     * @param string $content
     * @param array $parameters
     * @return mixed
     * @throws Throwable
     */
    static public function ParseContent(string $content, array $parameters = []): mixed
    {
        // Match template variable tags like {$var} or {$var|fallback} with optional pipe-separated fallbacks
        return preg_replace_callback('/{((\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*)(?:\|(?:(?2)|(?P>rq)))*)}/', function ($matches) use ($parameters) {
            // Split the matched expression by pipe '|' to get fallback alternatives
            $clips = preg_split('/(?<quote>[\'"])(\\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>(*SKIP)(*FAIL)|\|/', $matches[1]);
            // Try each fallback value until a renderable one is found
            foreach ($clips as $clip) {
                $value = self::ParseValue($clip, $parameters) ?? '';
                if (is_scalar($value) || method_exists($value, '__toString')) {
                    return $value;
                }
            }

            // No valid fallback found; return empty string
            return '';
        }, $content);
    }

    /**
     * Parse the text or parameter pattern into a value.
     *
     * @return mixed The value of the parameter
     * @throws Throwable
     */
    static private function ParseValue(string $content, array $parameters = []): mixed
    {
        $content = trim($content);
        if (0 == strlen($content)) {
            return null;
        }

        // Match literal values: booleans, numbers, or quoted strings
        if (preg_match('/^(?:(true|false)|(-?\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>)$/', $content, $matches)) {
            if ($matches[1]) {
                return $matches[1] === 'true';
            }
            return $matches[4] ?? $matches[2] ?? null;
        }

        // Match parameter variable references like $varName.path.to.value
        if ('$' == $content[0] && preg_match('/^\$(\w+)((?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*)((?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)$/', $content, $matches)) {
            if (isset($parameters[$matches[1]])) {
                return self::GetValueByPath($parameters[$matches[1]], $matches[2] ?? '');
            }
        }

        return null;
    }

    /**
     * Get the value by parameter path syntax
     *
     * @param mixed $value
     * @param string $path
     * @return mixed
     */
    static public function GetValueByPath(mixed $value, string $path = ''): mixed
    {
        if (null !== $value) {
            if (strlen($path) > 0) {
                // Extract path segments separated by dots, supporting quoted keys
                preg_match_all('/\.(?:(\w+)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)+)\k<q>)/', $path, $matches, PREG_SET_ORDER);

                // Traverse the value by each path segment (array key or object property)
                foreach ($matches as $clip) {
                    $key = (strlen($clip[3] ?? '') > 0) ? $clip[3] : ($clip[1] ?? '');
                    if (is_iterable($value)) {
                        $value = $value[$key] ?? null;
                    } elseif (is_object($value)) {
                        if (property_exists($value, $key)) {
                            $value = $value->{$key};
                        } else {
                            $value = null;
                        }
                    } else {
                        $value = null;
                    }

                    if (null === $value) {
                        break;
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Load the template file without any plugin folder setup and return as Source object.
     *
     * @param string $path
     * @param ModuleInfo|null $module
     * @return Source
     * @throws Throwable
     */
    public static function loadFile(string $path, ?ModuleInfo $module = null): Source
    {
        return (new Template())->load($path, $module);
    }

    /**
     * Read and extract comments from a template file.
     *
     * Parses template comments in the format {# ... } and returns an array of
     * comments with their content and line numbers. Used for extracting metadata
     * like @llm prompt directives from template files.
     *
     * Uses regex pattern matching similar to Entity::parseFunctionTag() to detect
     * comment tags consistently with the template engine's parsing logic.
     *
     * @param string $path Template file path
     * @return array List of comments with 'content' and 'line' keys
     * @throws Throwable
     */
    public static function readComment(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $content = file_get_contents($path);
            $comments = [];
            
            // Use regex pattern similar to Entity::parseFunctionTag() to find all comment tags
            // Pattern: {# ... } (comment starts with { # and ends with })
            if (preg_match_all('/\{#[^}]*?\}/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $commentText = $match[0];
                    $offset = $match[1];
                    
                    // Count newlines before this match to determine line number
                    $lineNumber = substr_count($content, "\n", 0, $offset) + 1;
                    
                    // Extract and clean comment content
                    // Remove opening {# and closing }
                    $cleanContent = preg_replace('/^\{\s*#\s*/', '', $commentText);
                    $cleanContent = preg_replace('/\s*\}$/', '', $cleanContent);
                    $cleanContent = trim($cleanContent);
                    
                    if ($cleanContent) {
                        $comments[] = [
                            'content' => $cleanContent,
                            'line' => $lineNumber,
                        ];
                    }
                }
            }

            return $comments;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Load the template file and return as Source object.
     *
     * @param string $path The file path
     * @param ModuleInfo|null $module
     *
     * @return Source The Source object
     * @throws Throwable
     */
    public function load(string $path, ?ModuleInfo $module = null): Source
    {
        $source = new Source($path, $this, $module);
        $this->sources[$source->getID()] = $source;

        return $source;
    }

    /**
     * Add a source to queue list.
     *
     * @param Source $source
     * @param string $name
     * @return $this
     */
    public function addQueue(Source $source, string $name): Template
    {
        $exists = $this->sources[$source->getID()] ?? null;
        if (!$exists || $exists !== $source) {
            // If the Source entity is not under the Template engine, skip adding queue list.
            return $this;
        }
        $name = trim($name);
        if (!$name) {
            $name = sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
        $this->queue[$name] = $source;

        return $this;
    }

    /**
     * Get the template content by given name
     *
     * @param string $name
     * @return Block|null
     */
    public function getTemplate(string $name): ?Block
    {
        return $this->templates[$name] ?? null;
    }

    /**
     * Return a parameter value from the manager (global) scope.
     *
     * This is the widest scope — the final fallback when Entity, Block,
     * and Source scopes do not contain the requested parameter.
     *
     * @param string $parameter The parameter name
     *
     * @return mixed The parameter value, or null if not assigned
     */
    public function getValue(string $parameter): mixed
    {
        return $this->parameters[$parameter] ?? null;
    }

    /**
     * Insert other Source entity into template engine.
     *
     * @param Source $source
     * @return $this
     */
    public function insert(Source $source): Template
    {
        $this->sources[$source->getID()] = $source;
        $source->link($this);

        return $this;
    }

    /**
     * Get the plugin closure from the plugin pool.
     *
     * @param string $type The type of the plugin
     * @param string $name The plugin name
     *
     * @return TModifier|TFunction|TFunctionCustom|null The plugin entity
     * @throws Error
     * @throws ReflectionException
     */
    public function loadPlugin(string $type, string $name): TModifier|TFunction|TFunctionCustom|null
    {
        $name = strtolower($name);
        $identify = $type . '.' . $name;

        if (!isset($this->plugins[$identify])) {
            if ($plugin = self::GetPlugin($identify)) {
                try {
                    $entity = $plugin['entity']();
                    // Verify the plugin class extends the expected base class for its type
                    $reflection = new ReflectionClass($entity);
                    $parent = $reflection->getParentClass();
                    if (('function' === $type && ($parent->getName() === 'Razy\Template\Plugin\TFunction' || $parent->getName() === 'Razy\Template\Plugin\TFunctionCustom')) || ('modifier' === $type && $parent->getName() === 'Razy\Template\Plugin\TModifier')) {
                        $entity->setName($name);
                        if ($plugin['args']) {
                            $entity->bind($plugin['args']);
                        }
                        return ($this->plugins[$identify] = $entity);
                    }
                }
                catch (Exception) {
                    return null;
                }
            }
        }

        return $this->plugins[$identify] ?? null;
    }

    /**
     * Load the template file as a global template block
     *
     * @param $name
     * @param string|null $path
     * @return $this
     * @throws Throwable
     */
    public function loadTemplate($name, ?string $path = null): Template
    {
        if (is_array($name)) {
            foreach ($name as $filepath => $tplName) {
                $this->loadTemplate($tplName, $filepath);
            }
            return $this;
        } elseif (is_string($name)) {
            $name = trim($name);
            if (!preg_match('/^\w+$/', $name)) {
                throw new TemplateException('Invalid template name format.');
            }

            $this->templates[$name] = (new Source($path, $this))->getRootBlock();
            return $this;
        }

        throw new InvalidArgumentException('Invalid argument type of path, only string or array is accepted.');
    }

    /**
     * Return the entity content in queue list by given section name.
     *
     * @param array $sections An array contains section name
     * @throws Throwable
     */
    public function outputQueued(array $sections): string
    {
        $content = '';
        foreach ($sections as $section) {
            if (isset($this->queue[$section])) {
                $content .= $this->queue[$section]->output();
            }
        }
        $this->queue = [];

        return $content;
    }
}
