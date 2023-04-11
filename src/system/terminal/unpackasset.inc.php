<?php

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Unpack asset', true);

    // Check the parameters is valid
    $distCode = trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:r}[ERROR] The distributor code is required.', true);

        exit;
    }

    if (!Application::DistributorExists($distCode)) {
        $this->writeLineLogging('The distributor `' . $distCode . '` has not found', true);

        return;
    }

    $this->writeLineLogging('The distributor `' . $distCode . '` found, started unpacking...', true);
    Application::UnpackAsset($distCode, function ($moduleCode, $unpacked) {
        if (count($unpacked) > 0) {
            $this->writeLineLogging('Module [' . $moduleCode . '] {@c:green}' . count($unpacked) . '{@reset} assets have unpacked.', true);
        }
    });

    $this->writeLineLogging('Updating rewrite rules...', true);
    Application::UpdateRewriteRules();
};
