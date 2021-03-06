<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Throwable;

class Pilot
{
    /**
     * @var Module
     */
    private Module $module;

    /**
     * Pilot constructor.
     *
     * @param Module $module
     */
    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * Register an API command.
     *
     * @param array|string $command An array of API command or the API command will register
     * @param null|string  $path    The path of the closure file
     *
     * @throws Throwable
     *
     * @return $this Fluent interface
     */
    public function addAPI($command, ?string $path = null): self
    {
        if (Module::STATUS_INITIALING !== $this->module->getStatus()) {
            throw new Error('The module is not in initialize status');
        }

        if (is_array($command)) {
            foreach ($command as $_command => $_path) {
                if (is_string($_path)) {
                    $this->addAPI($_command, $_path);
                }
            }
        } else {
            $command = trim($command);
            if (1 !== preg_match('/^[a-z]\w*$/i', $command)) {
                throw new Error('Invalid command name format');
            }

            $this->module->addAPI($command, $path);
        }

        return $this;
    }

    /**
     * Start listen an event.
     *
     * @param array|string $event An array of event or the name of the event
     * @param null|string  $path  The path of the closure
     *
     * @throws Throwable
     *
     * @return $this Fluent interface
     */
    public function listen($event, ?string $path = null): self
    {
        if (Module::STATUS_INITIALING !== $this->module->getStatus()) {
            throw new Error('The module is not in initialize status');
        }

        if (is_array($event)) {
            foreach ($event as $_event => $_path) {
                $this->listen($_event, $_path);
            }
        } else {
            $event = trim($event);
            if (1 !== preg_match('/^[a-z]\w*(\.[a-z]\w*)*$/i', $event)) {
                throw new Error('Invalid event name format');
            }

            $this->module->listen($event, $path);
        }

        return $this;
    }

    /**
     * Add a route by the string of route, the regular route will be matched in URL query routing in priority.
     * You can use parentheses to enclose the string and pass to the closure function if the route is matched.
     * Also, you can use the following words to capture specified characters:
     * :a Match any characters
     * :d Match any digit characters, 0-9
     * :D Match any non-digit characters
     * :w Match any alphabet characters, a-zA-Z
     * :W Match any non-alphabet characters
     * :[\w\d-@*] Match the characters by the regex expression string
     * Add {min,max} to capture specified length of the string, the max value can be ignored such as {3,} to match 0 to
     * 3 length string or {5} to match fixed 5 length string.
     *
     * @param mixed $route An array of route set or a string of route. The string of route will be matched as a regular
     *                     expression
     * @param null  $path  The path of the closure file
     *
     * @throws Throwable
     *
     * @return $this Fluent interface
     */
    public function addRoute($route, $path = null): self
    {
        if (Module::STATUS_INITIALING !== $this->module->getStatus()) {
            throw new Error('The module is not in initialize status');
        }

        if (is_array($route)) {
            foreach ($route as $_route => $_method) {
                if (is_string($_method)) {
                    $this->addRoute($_route, $_method);
                }
            }
        } else {
            if (!is_string($route)) {
                throw new Error('The route must be a string or an array');
            }

            $route  = trim($route);
            $path   = trim(tidy($path, false, '/'), '/');
            $method = $path;

            if (strlen($method) > 0 && strlen($path) > 0) {
                $this->module->addRoute($route, $path);
            }
        }

        return $this;
    }

    /**
     * Add a route by given nested array, each level equal as the URL path and the folder level.
     * For example, if there is an array contains 3 level with `1`, `2`, `3` and the `3` has a value `test`, the path
     * of domain.com/module/1/2/3 will linked to ./controller/1/2/3/test.php closure file.
     *
     * @param mixed      $route The path of the route based on the module code
     * @param null|mixed $path
     *
     * @return $this Fluent interface
     * @throws Throwable
     *
     * @throws Error
     */
    public function addLazyRoute($route, $path = null): self
    {
        if (Module::STATUS_INITIALING !== $this->module->getStatus()) {
            throw new Error('The module is not in initialize status');
        }

        if (is_array($route)) {
            foreach ($route as $_route => $_method) {
                $this->addLazyRoute($_route, $_method);
            }
        } else {
            if (!is_string($route)) {
                throw new Error('The route must be a string or an array');
            }
            $route = trim($route);

            if (is_string($path)) {
                $path = trim(tidy($path, false, '/'), '/');
                $this->module->addLazyRoute($route, $path);
            } elseif (is_array($path)) {
                $extendRoute = function (array $routeSet, string $relativePath = '') use (&$extendRoute) {
                    foreach ($routeSet as $node => $path) {
                        if (is_array($path)) {
                            $extendRoute($path, append($relativePath, $node));
                        } else {
                            $path = trim($path);
                            if (strlen($path) > 0) {
                                $routePath = ($node == '@self') ? $relativePath : append($relativePath, $node);
                                $this->module->addLazyRoute($routePath, append($relativePath, $path));
                            }
                        }
                    }
                };
                $extendRoute($path, $route);
            }
        }

        return $this;
    }

    /**
     * Bind the method to calling the specified closure.
     *
     * @param array|string $method
     * @param null|string  $path
     *
     * @return $this
     *@throws Error
     *
     */
    public function bind($method, ?string $path = null): Pilot
    {
        if (Module::STATUS_INITIALING !== $this->module->getStatus()) {
            throw new Error('The module is not in initialize status');
        }

        if (is_array($method)) {
            foreach ($method as $_method => $_path) {
                $this->bind($_method, $_path);
            }
        } else {
            $method = trim($method);
            if (1 !== preg_match('/^[a-z]\w*$/i', $method)) {
                throw new Error('Invalid method name format');
            }

            $this->module->bind($method, $path);
        }

        return $this;
    }

    /**
     * Check the route is conflicted and return the conflicted module code.
     *
     * @param string $urlQuery
     *
     * @return string
     */
    public function conflict(string $urlQuery): string
    {
        return $this->module->conflict($urlQuery);
    }

    /**
     * Redirect to the specified url when all modules are ready.
     *
     * @param string $url
     *
     * @return Pilot
     */
    public function redirect(string $url): Pilot
    {
        $this->module->redirect($url);
        return $this;
    }
}
