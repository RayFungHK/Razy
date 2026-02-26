<?php
/**
 * API Command: hello
 *
 * Called by other modules via: $this->api('{$module_code}')->hello($name)
 *
 * @package Razy
 * @license MIT
 */

return function (string $name = 'World'): string {
    return 'Hello, ' . $name . '!';
};
