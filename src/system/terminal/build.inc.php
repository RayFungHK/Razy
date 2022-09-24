<?php

namespace Razy;

return function () {
    // Check the PHP version is support Razy
    $this->writeLine('Checking environment...');
    $color = (version_compare(PHP_VERSION, '7.4.0') >= 0) ? 'green' : 'red';
    $this->writeLine('PHP Version: {@c:' . $color . '}' . PHP_VERSION . '{@reset} (PHP 7.4+ is required)');

    // Read the path from user input
    $this->writeLine('Please input your path to install Razy (v' . RAZY_VERSION . '). [default: ./]');
    $path           = Terminal::read();
    $path           = (!$path) ? SYSTEM_ROOT : append(SYSTEM_ROOT, $path);
    $installSuccess = true;

    if (is_dir($path)) {
        // Start copy and write Razy core file in specified directory
        $path = realpath($path);
        $this->writeLine('{@c:green}Started to setup Razy (v' . RAZY_VERSION . ') environment in ' . $path . ' ...', true);

        // Creating required directory
        $setupDir = ['config', 'plugins', 'sites', 'shared'];
        foreach ($setupDir as $dir) {
            $dirPath = append($path, $dir);
            $message = 'Creating directory ' . $dir . ': ';
            if (is_file($dirPath)) {
                $message .= '{@c:red}Failed';
            } elseif (is_dir($dirPath)) {
                $message .= '{@c:green}Checked';
            } else {
                if (mkdir($dirPath)) {
                    chmod($dirPath, 0777);
                    $message .= '{@c:green}Success';
                } else {
                    $message .= '{@:red}Failed';
                    $installSuccess = false;
                }
            }
            $this->writeLine($message, true);
        }

        // Writing sites.inc.php
        $source  = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/sites.inc.php.tpl');
        $message = 'Writing File sites.inc.php... ';
        $source->getRoot()->newBlock('domain')->assign([
            'domain' => 'localhost',
        ])->newBlock('site')->assign([
            'path'      => '/',
            'dist_code' => 'main',
        ]);
        file_put_contents(append($path, 'sites.inc.php'), $source->output());
        $message .= '{@c:green}Done';
        $this->writeLine($message, true);

        // Clone template file
        $setupFile = [
            'index.php'          => 'index.php',
            'repository.inc.php' => 'repository.inc.php',
            'htaccess.tpl'       => '.htaccess',
        ];
        foreach ($setupFile as $file => $destFileName) {
            $filePath = 'phar://./' . PHAR_FILE . '/asset/setup/' . $file;
            $destPath = append($path, $destFileName);
            $message  = 'Creating File ' . $destFileName . ': ';
            if (is_dir($destPath)) {
                $message .= '{@c:red}Failed';
            } elseif (is_file($destPath)) {
                $message .= '{@c:green}Checked';
            } else {
                if (copy($filePath, $destPath)) {
                    $message .= '{@c:green}Success.';
                } else {
                    $message .= '{@c:red}Failed.';
                    $installSuccess = false;
                }
            }
            $this->writeLine($message, true);
        }

        if ($installSuccess) {
            $source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/config.inc.php.tpl');
            $source->getRoot()->assign([
                'install_path' => $path,
            ]);
            file_put_contents(append($path, 'config.inc.php'), $source->output());

            $this->writeLine('{@c:green}Installation success.', true);
            $this->writeLine('The config.inc.php has wrote!');
            $this->writeLine('Run the following command to check the usage of Razy:');
            $this->writeLine('+----------------------------------------+');
            $this->writeLine('php ' . PHAR_FILE . ' help', true, '|{@c:blue} %-39s{@reset}|');
            $this->writeLine('+----------------------------------------+');
        } else {
            $this->writeLine('{@c:red}Installation failed', true);
        }
    } else {
        $this->writeLine('{@c:red}[Error] The directory (' . $path . ') does not exist.', true);
        $this->run();

        return false;
    }

    return true;
};
