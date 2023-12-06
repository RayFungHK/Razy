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

use Phar;
use Throwable;
use const DIRECTORY_SEPARATOR;

if (!defined('SYSTEM_ROOT')) {
    // Remove the phar:// beginning of the current phar located path
    define('SYSTEM_ROOT', dirname(Phar::running(false)));
}
define('PHAR_FILE', basename(Phar::running(false)));
define('PHAR_PATH', Phar::running());

if (!is_dir(SYSTEM_ROOT)) {
    echo 'Invalid application setup directory (SYSTEM_ROOT).';

    exit;
}

define('CORE_FOLDER', PHAR_PATH . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);

require CORE_FOLDER . 'functions.inc.php';
require CORE_FOLDER . 'core.inc.php';

Application::UpdateSites();

if (WEB_MODE) {
    Error::SetDebug(DEBUG ?? false);
    // Create an Application with HOSTNAME
    try {
        $app = new Application(HOSTNAME . ':' . PORT);
        if (!$app->query(URL_QUERY)) {
            Error::Show404();
        }
    } catch (Throwable $e) {
        try {
            Error::ShowException($e);
        } catch (Throwable $e) {
            echo $e;
            // Display error
        }
    }
} else {
    $argv = $_SERVER['argv'];
    array_shift($argv);

    if (!empty($argv)) {
        $command = array_shift($argv);

        $parameters = [];
        // Find the parameter setting -f, to locate the Razy system framework
        $systemPath = './';
        foreach ($argv as $index => $arg) {
            // Check the argument is -f, and extract the next argument as its value
            if ('-f' == $arg) {
                $systemPath = $argv[$index + 1] ?? '';
                if (!$systemPath || !is_dir($systemPath)) {
                    echo Terminal::COLOR_RED . '[Error] The location is not a valid directory.' . Terminal::COLOR_DEFAULT . PHP_EOL;

                    exit;
                }
                // Remove -f and its value in the argument list
                unset($argv[$index], $argv[$index + 1]);
            } elseif ('-' == $arg[0]) {
                $name  = substr($arg, 1);

                $value = match ($name) {
                    'p', 'debug' => $argv[$index + 1] ?? '',
                    default => true,
                };
                $parameters[$name] = $value;
            }
        }

        // Convert the relative path into absolute file path
        define('RAZY_PATH', realpath($systemPath));

        $closureFilePath = append(PHAR_PATH, 'system/terminal/', $command . '.inc.php');
        if (is_file($closureFilePath)) {
            try {
                $closure = include $closureFilePath;
                (new Terminal($command))->run($closure, $argv, $parameters);
            } catch (Throwable $e) {
                echo PHP_EOL . Terminal::COLOR_RED . $e->getMessage() . Terminal::COLOR_DEFAULT . PHP_EOL;
            }

            return true;
        }

        echo Terminal::COLOR_RED . '[Error] Command ' . $command . ' is not available.' . Terminal::COLOR_DEFAULT . PHP_EOL;
    }
}
__HALT_COMPILER();
