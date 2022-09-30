<?php

namespace Razy;

use Exception;
use Throwable;

/**
 * @param string $command
 * @param array $argv
 * @param array $parameters
 * @return bool
 */
function executeTerminal(string $command, array $argv = [], array $parameters = []): bool
{
    $closureFilePath = append(PHAR_PATH, 'system/terminal/', $command . '.inc.php');
    if (is_file($closureFilePath)) {
        try {
            $closure = include $closureFilePath;
            (new Terminal($command))->run($closure, $argv, $parameters);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    return false;
}
