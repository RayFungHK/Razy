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
use Throwable;

class Plugin
{
    /**
     * @var bool
     */
    private bool $isLoaded = true;

    /**
     * @var bool
     */
    private bool $enclose = false;

    /**
     * @var array
     */
    private array $parameters = [];

    /**
     * @var Closure
     */
    private Closure $processor;

    /**
     * @var bool
     */
    private bool $bypassParser;

    /**
     * @var string
     */
    private string $name;

    /**
     * Plugin constructor.
     *
     * @param array  $settings The setting of the plugin
     * @param string $type     The type of the plugin, modifier or function
     * @param string $name     The name of the plugin
     */
    public function __construct(array $settings, string $type, string $name)
    {
        $name = trim($name);
        if (!preg_match('/^\w+$/', $name)) {
            $this->isLoaded = false;
        } else {
            $this->name = $name;

            if ('function' === $type) {
                $this->enclose      = (bool) ($settings['enclose_content'] ?? false);
                $this->bypassParser = (bool) ($settings['bypass_parser'] ?? '');

                if (isset($settings['parameters'])) {
                    if (!is_array($settings['parameters'])) {
                        $this->isLoaded = false;
                    } else {
                        $this->parameters = $settings['parameters'];
                    }
                }
            }

            if (!$settings['processor'] instanceof Closure) {
                $this->isLoaded = false;
            } else {
                $this->processor = $settings['processor'];
            }
        }
    }

    /**
     * Return true if the plugin is an enclosure tag.
     *
     * @return bool
     */
    public function isEnclose(): bool
    {
        return $this->enclose;
    }

    /**
     * Return true if the plugin is loaded successfully, else the function tag in template file will not be parsed.
     *
     * @return bool
     */
    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }

    /**
     * Get the name of the plugin.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Modify the value.
     *
     * @param mixed  $value     The value to modify
     * @param string $paramText The well-formatted modifier's parameter string
     *
     * @return mixed
     */
    public function modify($value, string $paramText = '')
    {
        $arguments = [$value];
        preg_match_all('/:(?:(\w+)|(-?\d+(?:\.\d+)?|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $paramText ?? '', $args);
        // The first argument always is the value
        foreach ($args as $arg) {
            $arguments[] = (array_key_exists(4, $arg)) ? $arg[4] : ($arg[2] ?? $arg[1] ?? null);
        }

        return call_user_func_array($this->processor, $arguments);
    }

    /**
     * Start process the function.
     *
     * @param Entity $entity      The Entity instance
     * @param string $paramText   The well-formatted function's parameter string
     * @param string $wrappedText The wrapped content if the plugin is an enclosure tag
     *
     * @throws Throwable
     *
     * @return mixed
     */
    public function process(Entity $entity, string $paramText = '', string $wrappedText = '')
    {
        $parameters = [];
        if ($this->bypassParser) {
            $parameters = ['param_text' => $paramText];
        } else {
            $text = trim($paramText);
            if (0 === strlen($text)) {
                $parameters = $this->parameters;
            } else {
                $paramText = ' ' . $paramText;
                if (preg_match('/((?:\s+\w+=(?:(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)))*|-?\d+(?:\.\d+)?|(?P>rq))|true|false))+)|((?:\s+(?P>value))+)/', $paramText, $matches)) {
                    $parameters = $this->parameters;
                    $paramText  = trim($paramText);
                    $clips      = preg_split('/(?:(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>|\\.)(*SKIP)(*FAIL)|\s+/', $paramText);

                    if (isset($matches[5])) {
                        foreach ($parameters as &$value) {
                            if (count($clips) > 0) {
                                $clip  = array_shift($clips);
                                $value = $entity->parseValue($clip);
                            }

                            if (empty($clips)) {
                                break;
                            }
                        }
                    } else {
                        preg_match_all('/\s+(\w+)=(?:(\$\w+(?:\.(?:\w+|(?P>rq)))*)|true|false|(-?\d+(?:\.\d+)?)|(?<rq>(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $paramText, $matches, PREG_SET_ORDER);
                        foreach ($clips as $param) {
                            [$parameter, $value] = explode('=', $param);
                            if (array_key_exists($parameter, $this->parameters)) {
                                $parameters[$parameter] = $entity->parseValue($value);
                            }
                        }
                    }
                }
            }
        }

        return call_user_func_array($this->processor->bindTo($entity), [$wrappedText, $parameters]);
    }
}
