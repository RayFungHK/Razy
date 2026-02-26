<?php

/**
 * Razy Framework — PHAR Build Script
 *
 * Builds Razy.phar from the src/ directory and optionally copies it to
 * one or more target paths.
 *
 * Usage:
 *   php build.php [path1] [path2] ...
 *
 * Examples:
 *   php build.php                                  # Build Razy.phar in project root
 *   php build.php /var/www/mysite                  # Build and copy to /var/www/mysite/Razy.phar
 *   php build.php ./site-a ./site-b ../staging     # Build and copy to multiple locations
 */

try {
    $pharFile = 'Razy.phar';

    // clean up
    if (file_exists($pharFile)) {
        unlink($pharFile);
    }

    if (file_exists($pharFile . '.gz')) {
        unlink($pharFile . '.gz');
    }

    // create phar
    $phar = new Phar($pharFile);

    // start buffering. Mandatory to modify stub to add shebang
    $phar->startBuffering();

    // Create the default stub from main.php entrypoint
    $defaultStub = $phar->createDefaultStub('main.php');

    // Add the rest of the apps files
    $phar->buildFromDirectory(__DIR__ . '/src');

    // Customize the stub to add the shebang
    $stub = "#!/usr/bin/env php \n" . $defaultStub;

    // Add the stub
    $phar->setStub($stub);

    $phar->stopBuffering();

    // plus - compressing it into gzip
    $phar->compressFiles(Phar::GZ);

    # Make the file executable
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod(__DIR__ . '/' . $pharFile, 0770);
    }

    echo "$pharFile successfully created" . PHP_EOL;

    // Copy to target paths passed as CLI arguments
    $targets = array_slice($argv, 1);
    foreach ($targets as $target) {
        $target = rtrim($target, '/\\');
        if (!is_dir($target)) {
            echo "  [SKIP] Directory does not exist: $target" . PHP_EOL;
            continue;
        }
        $dest = $target . DIRECTORY_SEPARATOR . $pharFile;
        if (copy(__DIR__ . '/' . $pharFile, $dest)) {
            echo "  [OK]   Copied to $dest" . PHP_EOL;
        } else {
            echo "  [FAIL] Could not copy to $dest" . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
