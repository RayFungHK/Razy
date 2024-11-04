<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Template;

use Closure;
use Razy\Error;
use Razy\FileReader;
use Razy\ModuleInfo;
use Razy\Template\Plugin\TFunctionCustom;
use Razy\Template\Plugin\TModifier;
use Razy\Template\Plugin\TFunction;
use Throwable;

use function Razy\append;

class Block
{
    private array $blocks = [];
    private array $parameters = [];
    private string $path;
    private array $structure = [];
    private ?Block $parent;

    /**
     * Block constructor.
     *
     * @param Source $source The Source object
     * @param string $blockName The block name
     * @param FileReader $reader The FileReader of the template file reader
     * @param bool $readonly
     * @param null|self $parent Current block parent
     *
     * @throws Throwable
     */
    public function __construct(private readonly Source $source, private readonly string $blockName, FileReader $reader, private readonly bool $readonly = false, self $parent = null, private readonly string $type = '')
    {
        $this->parent = $parent;
        if (!$parent) {
            $this->path = '/';
        } else {
            $this->path = $parent->getPath() . '/' . $blockName;
        }

        $concat = '';

        while (($line = $reader->fetch()) !== null) {
            // If the line is a block tag
            if (str_contains($line, '<!-- ')) {
                if (preg_match('/^\s*<!-- (INCLUDE|TEMPLATE|START|END|WRAPPER|RECURSION|USE (\w[\w-]*)) BLOCK: (.+) -->\s*$/', $line, $matches)) {
                    if ('INCLUDE' !== $matches[1] && !preg_match('/^\w[\w\-]+(?=[^-])\w$/', $matches[3])) {
                        $concat .= $line;
                    } else {
                        if ($concat) {
                            $this->structure[] = $concat;
                            $concat = '';
                        }

                        if ('INCLUDE' === $matches[1]) {
                            $path = realpath(append($this->source->getFileDirectory(), $matches[3]));
                            if ($path) {
                                $reader->prepend($path);
                            }
                        } elseif ('WRAPPER' === $matches[1] || 'START' === $matches[1] || 'TEMPLATE' === $matches[1]) {
                            $matches[3] = trim($matches[3]);
                            if (isset($this->structure[$matches[3]])) {
                                throw new Error('The block ' . $this->path . '/' . $matches[3] . ' is already exists.');
                            }

                            $this->blocks[$matches[3]] = new self($this->source, $matches[3], $reader, 'TEMPLATE' === $matches[1], $this, $matches[1]);
                            $this->structure[$matches[3]] = $this->blocks[$matches[3]];
                        } elseif ('RECURSION' === $matches[1]) {
                            if (!($parent = $this->getClosest($matches[3]))) {
                                throw new Error('No parent block ' . $matches[3] . ' is found to declare as a recursion block.');
                            }

                            $this->blocks[$matches[3]] = $parent;
                            $this->structure[$matches[3]] = $this->blocks[$matches[3]];
                        } elseif (str_starts_with($matches[1], 'USE ')) {
                            $found = false;
                            while (($parent = $this->parent) !== null) {
                                if ($parent->hasBlock($matches[2])) {
                                    $found = true;
                                    $this->blocks[$matches[3]] = $parent->getBlock($matches[2]);
                                    $this->structure[$matches[3]] = $this->blocks[$matches[3]];

                                    break;
                                }

                                exit;
                            }

                            if (!$found) {
                                throw new Error('The template block ' . $matches[3] . ' cannot be found from parent block.');
                            }
                        } elseif ('END' === $matches[1]) {
                            if ($blockName === $matches[3]) {
                                break;
                            }

                            throw new Error('The block ' . $matches[3] . ' does not have the START tag. Current block [' . $blockName . ']');
                        }
                    }
                }

                continue;
            }

            $concat .= $line;
        }

        if ($concat) {
            $this->structure[] = $concat;
        }
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
    public function getClosest(string $block): ?Block
    {
        $block = trim($block);
        if (!$block || !$this->parent) {
            return null;
        }
        if ($this->getName() === $block) {
            return $this;
        }

        return $this->parent->getClosest($block);
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
        return array_key_exists($name, $this->blocks);
    }

    /**
     * Return the block by given name.
     *
     * @param string $name The block name
     *
     * @return Block The Block object
     * @throws Throwable
     */
    public function getBlock(string $name): Block
    {
        $name = trim($name);
        if (!$this->hasBlock($name)) {
            throw new Error('Block ' . $name . ' is not exists in ' . $this->getPath() . '.');
        }

        return $this->blocks[$name];
    }

    /**
     * Assign the block level parameter value.
     *
     * @param mixed $parameter The parameter name or an array of parameters
     * @param mixed|null $value The parameter value
     *
     * @return self Chainable
     * @throws Throwable
     */
    public function assign(mixed $parameter, mixed $value = null): Block
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
        } else {
            throw new Error('Invalid parameter name');
        }

        return $this;
    }

    /**
     * Bind the reference variable
     *
     * @param string $parameter
     * @param null $value
     *
     * @return $this
     * @throws Throwable
     */
    public function bind(string $parameter, mixed &$value): Block
    {
        $this->parameters[$parameter] = $value;

        return $this;
    }

    /**
     * Get the template file located system path
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
     * @return Block The parent Entity object
     */
    public function getParent(): Block
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
     * Get the template block by given name
     *
     * @param string $name
     *
     * @return Block|null
     * @throws Throwable
     */
    public function getTemplate(string $name): ?Block
    {
        $parent = $this;
        do {
            if ($parent->hasBlock($name)) {
                $block = $parent->getBlock($name);
                // Only template block is readonly
                if ($block->isReadonly()) {
                    return $block;
                }
            }
        } while (($parent = $this->parent) !== null);

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
     * Return the parameter value.
     *
     * @param string $parameter The parameter name
     * @param bool $recursion
     *
     * @return mixed The parameter value
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
        return array_key_exists($parameter, $this->parameters);
    }

    /**
     * Load the plugin from registered plugin folder
     *
     * @param string $type
     * @param string $name
     *
     * @return TModifier|TFunction|TFunctionCustom|null
     * @throws Error
     * @throws Throwable
     */
    public function loadPlugin(string $type, string $name): null|TModifier|TFunction|TFunctionCustom
    {
        return $this->source->loadPlugin($type, $name);
    }

    /**
     * Return a new Entity object related on this Block
     *
     * @return Entity
     * @throws Throwable
     */
    public function newEntity(): Entity
    {
        return new Entity($this);
    }
}
