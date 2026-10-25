<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the TFunction base class for template function plugins. Template
 * functions are invoked via `{@name ...}` tags and can optionally enclose
 * content between opening and closing tags.
 *
 *
 * @license MIT
 */

namespace Razy\Template\Plugin;

use Razy\Controller;
use Razy\Template\Entity;
use Throwable;

/**
 * Base class for standard template function plugins.
 *
 * Template functions appear as `{@name param=value}` tags in templates.
 * Subclasses override `processor()` to implement custom rendering logic.
 * Functions can declare allowed parameters, support extended (arbitrary)
 * parameters, and optionally enclose content (`{@name}...{/name}`).
 *
 * @class TFunction
 */
class TFunction
{
    /** @var bool Whether this function encloses content between open/close tags */
    protected bool $encloseContent = false;

    /**
     * RZ-28: the plugin returns template text for the caller to substitute
     * (`{@if}` returns its chosen branch). Every other plugin's output is final
     * and is never scanned for `{$…}`.
     *
     * Opt in only when the return value is template *source* (text taken from
     * the template, such as the enclosed body). Never return a user value or
     * anything built from one: it would be expanded and substituted like
     * template code (a value `{$secret}` would print the secret).
     */
    protected bool $templateOutput = false;

    /** @var bool Whether arbitrary parameter names are accepted beyond allowedParameters */
    protected bool $extendedParameter = false;

    /** @var array<string, mixed> Declared parameters with default values */
    protected array $allowedParameters = [];

    /** @var Controller|null The bound controller instance, if any */
    protected ?Controller $controller = null;

    /** @var string The registered name of this function plugin */
    private string $name;

    /**
     * Bind a Controller instance to this plugin for module context access.
     *
     * @param Controller $entity The controller to bind
     *
     * @return static Chainable
     */
    final public function bind(Controller $entity): static
    {
        $this->controller = $entity;

        return $this;
    }

    /**
     * Start parse the function tag.
     *
     * @param Entity $entity The Entity instance
     * @param string $syntax The well-formatted function's parameter string
     * @param string $wrappedText The wrapped content if the plugin is an enclosure tag
     *
     * @return string|null
     *
     * @throws Throwable
     */
    final public function parse(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string
    {
        $parameters = [];
        $arguments = [];

        $text = \trim($syntax);
        if (0 === \strlen($text)) {
            // No parameters provided; use defaults
            $parameters = $this->allowedParameters;
        } else {
            $syntax = ' ' . $syntax;
            if (\preg_match('/^\s((?::\w+)*)((?:\s+\w+=(?:(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)))*|-?\d+(?:\.\d+)?|(?P>rq))|true|false))+)|((?:\s+(?P>value))+)$/', $syntax, $matches)) {
                $parameters = $this->allowedParameters;
                $arguments = \explode(':', \ltrim(\trim($matches[1]), ':'));
                // Group 2 is the named `key=value` list. A positional call
                // matches group 6 instead, and group 2 is empty, so `{@each $items}`
                // never bound `source` (RZ-31).
                $syntax = \trim(($matches[6] ?? '') !== '' ? $matches[6] : ($matches[2] ?? ''));
                $clips = \preg_split('/(?:(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>|\.)(*SKIP)(*FAIL)|\s+/', $syntax);

                if (isset($matches[6])) {
                    // Positional parameters: assign values in order to allowedParameters
                    foreach ($parameters as &$value) {
                        if (\count($clips) > 0) {
                            $clip = \array_shift($clips);
                            $value = $entity->parseValue($clip);
                        }

                        if (empty($clips)) {
                            break;
                        }
                    }
                } else {
                    // Named parameters: parse key=value pairs
                    \preg_match_all('/\s+(\w+)=(?:(\$\w+(?:\.(?:\w+|(?P>rq)))*)|true|false|(-?\d+(?:\.\d+)?)|(?<rq>(?<q>[\'"])((?:\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $syntax, $matches, PREG_SET_ORDER);
                    foreach ($clips as $param) {
                        [$parameter, $value] = \explode('=', $param);
                        if ($this->extendedParameter || \array_key_exists($parameter, $this->allowedParameters)) {
                            $parameters[$parameter] = $entity->parseValue($value);
                        }
                    }
                }
            }
        }

        // Invoke the processor and handle callable return values
        $result = $this->processor($entity, $parameters, $arguments, $wrappedText);

        return \is_callable($result) ? \call_user_func($result) : $result;
    }

    /**
     * Check whether this function encloses content between open/close tags.
     *
     * @return bool True if this is an enclosure-type function
     */
    final public function returnsTemplate(): bool
    {
        return $this->templateOutput;
    }

    final public function isEncloseContent(): bool
    {
        return $this->encloseContent;
    }

    /**
     * Get the declared allowed parameters and their default values.
     *
     * @return array<string, mixed> Parameter names mapped to default values
     */
    final public function getAllowedParameters(): array
    {
        return $this->allowedParameters;
    }

    /**
     * Check whether arbitrary parameter names are accepted.
     *
     * @return bool True if extended parameters are enabled
     */
    final public function isExtendedParameter(): bool
    {
        return $this->extendedParameter;
    }

    /**
     * Get the registered name of this function plugin.
     *
     * @return string The plugin name
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the registered name of this function plugin.
     *
     * @param string $name The plugin name
     *
     * @return TFunction Chainable
     */
    final public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Process the function tag and return rendered output.
     *
     * Override this method in subclasses to implement custom function logic.
     *
     * @param Entity $entity The current template Entity context
     * @param array $parameters Parsed named parameters with their values
     * @param array $arguments Colon-separated arguments from the tag syntax
     * @param string $wrappedText Content enclosed between open/close tags (if encloseContent is true)
     *
     * @return string|null The rendered output string
     */
    protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
    {
        return '';
    }
}
