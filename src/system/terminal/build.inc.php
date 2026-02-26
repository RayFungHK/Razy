<?php

/**
 * CLI Command: build.
 *
 * Sets up the Razy framework environment in a specified directory.
 * Creates the necessary folder structure, configuration files, and
 * copies boilerplate templates (index.php, .htaccess, sites.inc.php, etc.)
 * to bootstrap a new Razy installation.
 *
 * Usage:
 *   php Razy.phar build [install_path]
 *
 * Arguments:
 *   install_path  Target directory for installation (default: current directory)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;

return function (string $installPath = '') use (&$parameters) {
    // Check the PHP version is support Razy
    $this->writeLineLogging('Checking environment...');
    $color = (\version_compare(PHP_VERSION, '8.2.0') >= 0) ? 'green' : 'red';
    $this->writeLineLogging('PHP Version: {@c:' . $color . '}' . PHP_VERSION . '{@reset} (PHP 7.4+ is required)');

    // Get installation path from positional argument or ask user for input
    $installPath = \trim($installPath);
    if ($installPath !== '') {
        if ($installPath === '.') {
            $path = SYSTEM_ROOT;
        } else {
            $path = PathUtil::append(SYSTEM_ROOT, $installPath);
        }
    } else {
        // Ask user for input (original behavior)
        $this->writeLineLogging('Please input your path to install Razy (v' . RAZY_VERSION . '). [default: ./]');
        $userInput = Terminal::read();
        $path = (!$userInput) ? SYSTEM_ROOT : PathUtil::append(SYSTEM_ROOT, $userInput);
    }

    $installSuccess = true;

    if (\is_dir($path)) {
        // Resolve to the absolute path and begin environment setup
        $path = \realpath($path);
        $this->writeLineLogging('{@c:green}Started to setup Razy (v' . RAZY_VERSION . ') environment in ' . $path . ' ...', true);

        // Create the required subdirectories for configuration, plugins, sites, and shared modules
        $setupDir = ['config', 'plugins', 'sites', 'shared'];
        foreach ($setupDir as $dir) {
            $dirPath = PathUtil::append($path, $dir);
            $message = 'Creating directory ' . $dir . ': ';
            if (\is_file($dirPath)) {
                $message .= '{@c:red}Failed';
            } elseif (\is_dir($dirPath)) {
                $message .= '{@c:green}Checked';
            } else {
                if (\mkdir($dirPath)) {
                    \chmod($dirPath, 0777);
                    $message .= '{@c:green}Success';
                } else {
                    $message .= '{@:red}Failed';
                    $installSuccess = false;
                }
            }
            $this->writeLineLogging($message, true);
        }

        // Generate the default sites.inc.php from template with localhost domain
        $source = Template::loadFile(PHAR_PATH . '/asset/setup/sites.inc.php.tpl');
        $message = 'Writing File sites.inc.php... ';
        $source->getRoot()->newBlock('domain')->assign([
            'domain' => 'localhost',
        ])->newBlock('site')->assign([
            'path' => '/',
            'dist_code' => 'main',
        ]);
        \file_put_contents(PathUtil::append($path, 'sites.inc.php'), $source->output());
        $message .= '{@c:green}Done';
        $this->writeLineLogging($message, true);

        // Copy boilerplate files (index.php, repository config, .htaccess) from phar assets
        $setupFile = [
            'index.php' => 'index.php',
            'repository.inc.php' => 'repository.inc.php',
            'htaccess.tpl' => '.htaccess',
        ];
        foreach ($setupFile as $file => $destFileName) {
            $filePath = PHAR_PATH . '/asset/setup/' . $file;
            $destPath = PathUtil::append($path, $destFileName);
            $message = 'Creating File ' . $destFileName . ': ';
            if (\is_dir($destPath)) {
                $message .= '{@c:red}Failed';
            } elseif (\is_file($destPath)) {
                $message .= '{@c:green}Checked';
            } else {
                if (\copy($filePath, $destPath)) {
                    $message .= '{@c:green}Success.';
                } else {
                    $message .= '{@c:red}Failed.';
                    $installSuccess = false;
                }
            }
            $this->writeLineLogging($message, true);
        }

        if ($installSuccess) {
            // Generate the main config.inc.php with installation path and timezone
            $source = Template::loadFile(PHAR_PATH . '/asset/setup/config.inc.php.tpl');
            $source->getRoot()->assign([
                'install_path' => $path,
                'timezone' => \date_default_timezone_get(),
            ]);
            \file_put_contents(PathUtil::append($path, 'config.inc.php'), $source->output());

            $this->writeLineLogging('{@c:green}Installation success.', true);
            $this->writeLineLogging('The config.inc.php has wrote!');
            $this->writeLineLogging('Run the following command to check the usage of Razy:');
            $this->writeLineLogging('+----------------------------------------+');
            $this->writeLineLogging('php ' . PHAR_FILE . ' help', true, '|{@c:blue} %-39s{@reset}|');
            $this->writeLineLogging('+----------------------------------------+');
        } else {
            $this->writeLineLogging('{@c:red}Installation failed', true);
        }
    } else {
        $this->writeLineLogging('{@c:red}[Error] The directory (' . $path . ') does not exist.', true);
        $this->run();

        return false;
    }

    return true;
};
