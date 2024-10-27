<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Update distributor module and package', true);

    // Check the parameters is valid
    $distCode = trim($distCode);
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

    $app->compose($distCode, function (string $type, string $packageName, ...$args) {
        if (PackageManager::TYPE_READY == $type) {
            $this->writeLineLogging('Validating package: {@c:green}' . $packageName . '{@reset} (' . $args[0] . ')', true);
        } elseif (PackageManager::TYPE_DOWNLOAD_PROGRESS == $type) {
            $size       = (int) $args[1];
            $downloaded = (int) $args[2];
            echo $this->format('{@clear} - Downloading: {@c:green}' . $packageName . ' @' . $args[0] . '{@reset} (' . ((!$downloaded) ? '0' : floor(($downloaded / $size) * 100)) . '%)', true);
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
