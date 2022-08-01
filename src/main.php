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

if (PHP_SAPI !== 'cli') {
    Error::SetDebug(DEBUG);
    // Create an Application with HOSTNAME
    $app = new Application(HOSTNAME . ((PORT !== 80) ? ':' . PORT : ''));
    if (!$app->query(URL_QUERY)) {
        Error::Show404();
    }
} else {
    require CORE_FOLDER . 'terminal.func.php';

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
                // Remove -f and its value in the arguments list
                unset($argv[$index], $argv[$index + 1]);
            } elseif ('-' == $arg[0]) {
                $name  = substr($arg, 1);
                $value = null;

                switch ($name) {
                    case 'p':
                    case 'debug':
                        $value = $argv[$index + 1] ?? '';

                        break;

                    default:
                        $value = true;

                        break;
                }
                $parameters[$name] = $value;
            }
        }

        // Convert the relative path into absolute file path
        define('RAZY_PATH', realpath($systemPath));

        // Load the command closure and execute
        if (!executeTerminal($command, $argv, $parameters)) {
            echo Terminal::COLOR_RED . '[Error] Command ' . $command . ' is not available.' . Terminal::COLOR_DEFAULT . PHP_EOL;

            exit;
        }
    }
}
__HALT_COMPILER();
