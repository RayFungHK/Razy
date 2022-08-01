<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Template;

use Closure;
use Razy\Error;
use Throwable;

/**
 * Template entity will be processed in output. Except the root entity, you can create any number of entity to list
 * a bunch of data. Every entity contains its parameter, to allow front end developer use for.
 */
class Entity
{
    /**
     * The Block object.
     *
     * @var Block
     */
    private Block $block;

    /**
     * The parent entity.
     *
     * @var null|Entity
     */
    private ?Entity $parent;

    /**
     * The entity id.
     *
     * @var string
     */
    private string $id;

    /**
     * An array contains the entity parameters.
     *
     * @var array
     */
    private array $parameters = [];

    /**
     * An array contains the sub entity under current entity.
     *
     * @var array
     */
    private array $entities = [];

    /**
     * @var array
     */
    private array $caches = [];

    /**
     * Entity constructor.
     *
     * @param Block     $block  The Block object
     * @param string    $id     The entity id
     * @param null|self $parent The parent Entity object
     */
    public function __construct(Block $block, string $id = '', self $parent = null)
    {
        $this->block  = $block;
        $this->parent = $parent;
        $id           = trim($id);
        if (!$id) {
            $id = sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
        $this->id = $id;
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
     * Detach this entity from the parent entity list.
     *
     * @return self Chainable
     */
    public function detach(): Entity
    {
        $this->parent->remove($this->block->getName(), $this->id);

        return $this;
    }

    /**
     * Remove the entity by given block name and entity id.
     *
     * @param string $blockName The block name under current block level
     * @param string $id        The entity id
     *
     * @return self Chainable
     */
    public function remove(string $blockName, string $id): Entity
    {
        if (isset($this->entities[$blockName])) {
            unset($this->entities[$blockName][$id]);
        }

        return $this;
    }

    /**
     * Check if the sub block is exists by given name.
     *
     * @param string $blockName The block name under current block level
     *
     * @return bool Return true id the block is exists
     */
    public function hasBlock(string $blockName): bool
    {
        return $this->block->hasBlock($blockName);
    }

    /**
     * Create a new block or return the Entity instance by the given id that the Entity is matched.
     *
     * @param string $blockName The block name under current block level
     * @param string $id        The entity id
     *
     * @throws Throwable
     *
     * @return Entity The Entity object
     */
    public function newBlock(string $blockName, string $id = ''): Entity
    {
        $id = trim($id);
        if (!$id) {
            $id = sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }

        if (isset($this->entities[$blockName][$id])) {
            return $this->entities[$blockName][$id];
        }

        $block = $this->block->getBlock($blockName);

        if (!isset($this->entities[$blockName])) {
            $this->entities[$blockName] = [];
        }
        $blockEntity                     = new self($block, $id, $this);
        $this->entities[$blockName][$id] = $blockEntity;

        return $blockEntity;
    }

    /**
     * Assign the entity level parameter value.
     *
     * @param mixed $parameter The parameter name or an array of parameters
     * @param mixed $value     The parameter value
     *
     * @throws Throwable
     *
     * @return self Chainable
     */
    public function assign($parameter, $value = null): Entity
    {
        if (is_array($parameter)) {
            foreach ($parameter as $index => $value) {
                $this->assign($index, $value);
            }
        } elseif (is_string($parameter)) {
            if ($value instanceof Closure) {
                // If the value is closure, pass the current value to closure
                $this->parameters[$parameter] = $value($this->parameters[$parameter] ?? null);
            } else {
                $this->parameters[$parameter] = $value;
            }
            unset($this->caches[$parameter]);
        } else {
            throw new Error('Invalid parameter name');
        }

        return $this;
    }

    /**
     * @param string $parameter
     * @param null   $value
     *
     * @return $this
     *@throws Throwable
     *
     */
    public function bind(string $parameter, &$value): Entity
    {
        $this->parameters[$parameter] = &$value;

        return $this;
    }

    /**
     * Process and return the block content, and the parameter tag and function tag will be replaced.
     *
     * @throws Throwable
     *
     * @return string The processed block content
     */
    public function process(): string
    {
        $content   = '';
        $structure = $this->block->getStructure();
        foreach ($structure as $index => $entity) {
            if ($entity instanceof Block) {
                $content .= $this->processEntity($index);
            } else {
                $clip = $entity;
                $content .= $this->parseText($clip);
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
     * @throws Throwable
     *
     * @return string The block content which has replaced the parameter tag
     */
    public function parseText(string $content): string
    {
        $content = $this->parseFunctionTag($content);

        return preg_replace_callback('/{(\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)}/', function ($matches) {
            $value = $this->parseValue($matches[1]) ?? '';

            return (is_iterable($value) || (is_object($value) && !method_exists($value, '__toString'))) ? '' : $value;
        }, $content);
    }

    /**
     * Parse the text or parameter pattern into a value.
     *
     * @param string $content
     *
     * @throws Throwable
     *
     * @return mixed The value of the parameter
     */
    public function parseValue(string $content)
    {
        $content = trim($content);
        if (0 == strlen($content)) {
            return null;
        }

        // If the content is a parameter tag
        if (preg_match('/^(true|false)|(-?\d+(?:\.\d+)?)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>$/', $content, $matches)) {
            if ($matches[1]) {
                return $matches[1] === 'true';
            }
            return $matches[4] ?? $matches[2] ?? null;
        }
        if ('$' == $content[0] && preg_match('/^\$(\w+)((?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*)((?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)$/', $content, $matches)) {
            return $this->parseParameter($matches[1], $matches[2] ?? '', $matches[5] ?? '');
        }

        return null;
    }

    /**
     * Return the parameter value.
     *
     * @param string $parameter The parameter name
     * @param bool   $recursion Enable to get the value recursively
     *
     * @return mixed The parameter value
     */
    public function getValue(string $parameter, bool $recursion = false)
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
     * @return bool Return true if the parameter is exists
     */
    public function parameterAssigned(string $parameter): bool
    {
        return array_key_exists($parameter, $this->parameters);
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
            return count($this->entities[$blockName]);
        }

        return 0;
    }

    /**
     * Find the entities by given path.
     *
     * @param string $path The block path
     *
     * @return array An array contains matched entity
     *@throws Error
     *
     */
    public function find(string $path): array
    {
        $path  = trim($path);
        $paths = preg_split('/(?<quote>[\'"])(\\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>(*SKIP)(*FAIL)|\//', $path, PREG_SPLIT_NO_EMPTY);
        if (empty($paths)) {
            return [];
        }

        $entities = $this->entities;
        if (empty($entities)) {
            return [];
        }

        foreach ($paths as $blockName) {
            $blockName = trim($blockName);
            if (!preg_match('/^(\w+)(?:\[(?:(\d+)|(?<quote>[\'"])(\\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>)\])?$/', $blockName, $matches)) {
                throw new Error('The path of `' . $blockName . '` is not in valid format.');
            }

            $entityMatched = [];
            /** @var Entity $entity */
            foreach ($entities as $entity) {
                if (!($entity instanceof Entity)) {
                    var_dump(gettype($entity));

                    exit;
                }
                if (!$entity->hasBlock($matches[1])) {
                    continue;
                }

                if ($matches[4] ?? '') {
                    // Specified ID
                    $entityMatched[] = $entity->getEntity($matches[1], $matches[4]);
                } else {
                    $entityList = $this->getEntities($matches[1]);
                    if (null !== $matches[2]) {
                        if ($matches[2] < count($entityList)) {
                            $entityMatched[] = $entityList[$matches[2]];
                        }
                    } else {
                        $entityMatched = array_merge($entityMatched, $entityList);
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
     * Get the current entity block name.
     *
     * @return string The block name
     */
    public function getBlockName(): string
    {
        return $this->block->getName();
    }

    /**
     * Check if current contains specified entity by the given block name.
     *
     * @param string $blockName The block name to obtain its entity
     * @param string $identity  The identity of the entity
     *
     * @return bool Return true if exists
     */
    public function hasEntity(string $blockName, string $identity = ''): bool
    {
        if ($identity) {
            return isset($this->entities[$blockName][$identity]);
        }

        return count($this->entities[$blockName] ?? []) > 0;
    }

    /**
     * Get the total number of entities by the given block name.
     *
     * @param string $blockName The block name to obtain its entity
     *
     * @return int
     */
    public function getEntityCount(string $blockName): int
    {
        return count($this->entities[$blockName] ?? []);
    }

    /**
     * Get specify entity by the given block name and identity.
     *
     * @param string $blockName The block name to obtain its entity
     * @param string $identity  The identity of the entity
     *
     * @return Entity A sub-block Entity
     */
    public function getEntity(string $blockName, string $identity): ?Entity
    {
        return $this->entities[$blockName][$identity] ?? null;
    }

    /**
     * @return string Get the template file location
     */
    public function getFileLocation(): string
    {
        return $this->block->getFileLocation();
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
     * @param string      $content   A clip of block content
     * @param null|Plugin $enclosure The enclosure Plugin instance
     *
     * @throws Throwable
     *
     * @return string The block content which has replaced the function tag
     */
    private function parseFunctionTag(string &$content, Plugin $enclosure = null): string
    {
        $stacking = [];
        $result   = '';
        while (preg_match('/\\.(*SKIP)(*FAIL)|{(?:@(\w+)((?:(?:\\.|(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)|[^{}])*)|\/(\w+))}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];

            $isClosingTag = isset($matches[4][0]);
            $functionName = $matches[4][0] ?? $matches[1][0];

            // Append the content before the matched tag into result
            $result .= substr($content, 0, $offset);

            // Crop the content after the matched element
            $content = substr($content, $offset + strlen($matches[0][0]));

            if (null !== $enclosure) {
                if ($isClosingTag) {
                    if (empty($stacking) && $enclosure->getName() === $functionName) {
                        return $result;
                    }
                }

                $result .= $matches[0][0];
                if (!$isClosingTag) {
                    $plugin = $this->block->loadPlugin('function', $functionName);
                    if (null !== $plugin && $plugin->isLoaded() && $plugin->isEnclose()) {
                        $stacking[] = $functionName;
                    }
                } elseif ($functionName === end($stacking)) {
                    array_pop($stacking);
                }
            } else {
                $plugin = $this->block->loadPlugin('function', $functionName);
                if (null !== $plugin && $plugin->isLoaded()) {
                    if ($plugin->isEnclose()) {
                        $wrapped = '';

                        if (false !== strpos($content, '{/' . $matches[1][0] . '}')) {
                            $wrapped = $this->parseFunctionTag($content, $plugin);
                            $wrapped = $plugin->process($this, $matches[2][0] ?? '', $wrapped);
                        }

                        $result .= $wrapped;
                    } else {
                        $result .= $plugin->process($this, $matches[2][0] ?? '');
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
     * @param string $name     The name of the parameter
     * @param string $path     The path of the array to obtain the value
     * @param string $modifier The modifier syntax
     *
     * @throws Throwable
     *
     * @return null|mixed
     */
    private function parseParameter(string $name, string $path = '', string $modifier = '')
    {
        // Load the cached value by the parameter name and its path.
        if (!isset($this->caches[$name][$path])) {
            $value = $this->getValue($name, true);
            if (null !== $value) {
                if (strlen($path) > 0) {
                    preg_match_all('/\.(?:(\w+)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)+)\k<q>)/', $path, $matches, PREG_SET_ORDER);
                    foreach ($matches as $clip) {
                        $key = (strlen($clip[3] ?? '') > 0) ? $clip[3] : ($clip[1] ?? '');
                        if (is_iterable($value)) {
                            $value = $value[$key] ?? null;
                        } elseif (is_object($value)) {
                            if (property_exists($key, $value)) {
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

            if (!isset($this->caches[$name])) {
                $this->caches[$name] = [];
            }

            $this->caches[$name][$path] = $value;
        } else {
            $value = $this->caches[$name][$path];
        }

        // Modifier
        if (strlen($modifier) > 0) {
            preg_match_all('/->(\w+)((?::(?:\w+|(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>|-?\d+(?:\.\d+)?))*)/', $modifier, $matches, PREG_SET_ORDER);
            foreach ($matches as $clip) {
                $plugin = $this->block->loadPlugin('modifier', $clip[1]);
                $value  = $plugin->modify($value, $clip[2]);
            }
        }

        return $value;
    }
}
