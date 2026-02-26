<?php

/**
 * CLI Command: compose.
 *
 * Composes a distributor by resolving and installing its required packages
 * and dependencies. This command reads the distributor's module configuration,
 * validates package versions, downloads missing or outdated packages from
 * configured repositories, and extracts them into the appropriate directories.
 *
 * Usage:
 *   php Razy.phar compose <distributor_code>
 *
 * Arguments:
 *   distributor_code  The code identifying the target distributor
 *
 * @license MIT
 */

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Update distributor module and package', true);

    // Check the parameters is valid
    $distCode = \trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:r}[ERROR] The distributor code is required.', true);

        exit;
    }

    $app = new Application();
    $app->loadSiteConfig();

    if (!$app->hasDistributor($distCode)) {
        $this->writeLineLogging('The distributor `' . $distCode . '` has not found', true);

        return false;
    }

    // Execute the compose process with a callback to report progress and status
    $app->compose($distCode, function (string $type, string $packageName, ...$args) {
        if ('version_conflict' === $type) {
            // $args[0] = module, $args[1] = required, $args[2] = installed
            $this->writeLineLogging('{@c:yellow}[WARNING] Version conflict detected:{@reset}', true);
            $this->writeLineLogging('  Package: {@c:green}' . $packageName . '{@reset}', true);
            $this->writeLineLogging('  Module: {@c:cyan}' . ($args[0] ?: 'unknown') . '{@reset}', true);
            $this->writeLineLogging('  Required: {@c:yellow}' . $args[1] . '{@reset}', true);
            $this->writeLineLogging('  Installed: {@c:red}' . $args[2] . '{@reset}', true);
            $this->writeLineLogging('', true);
        } elseif (PackageManager::TYPE_READY == $type) {
            $this->writeLineLogging('Validating package: {@c:green}' . $packageName . '{@reset} (' . $args[0] . ')', true);
        } elseif (PackageManager::TYPE_DOWNLOAD_PROGRESS == $type) {
            $size = (int) $args[1];
            $downloaded = (int) $args[2];
            echo $this->format('{@clear} - Downloading: {@c:green}' . $packageName . ' @' . $args[0] . '{@reset} (' . ((!$downloaded) ? '0' : \floor(($downloaded / $size) * 100)) . '%)', true);
        } elseif (PackageManager::TYPE_DOWNLOAD_FINISHED == $type) {
            echo PHP_EOL;
        } elseif (PackageManager::TYPE_UPDATED == $type) {
            $this->writeLineLogging(' - {@c:green}Done.{@reset}', true);
        } elseif (PackageManager::TYPE_FAILED == $type) {
            $this->writeLineLogging(' - {@c:red}Cannot update package ' . $args[0] . ' (' . $args[1] . ').{@reset}', true);
        } elseif (PackageManager::TYPE_EXTRACT == $type) {
            $this->writeLineLogging(' - {@c:green}' . $packageName . '{@reset}: Extracting `' . $args[0] . '` from `' . $args[1] . '`', true);
        }
    });

    return true;
};
