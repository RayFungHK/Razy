<?php

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Unpack asset', true);

    // Check the parameters is valid
    $distCode = trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:red}[ERROR] The distributor code is required.', true);

        exit;
    }

    if (!Application::DistributorExists($distCode)) {
        $this->writeLineLogging('The distributor `' . $distCode . '` has not found', true);

        return;
    }

    $this->writeLineLogging('{@c:blue}Updating rewrite rules...', true);
    Application::UpdateRewriteRules();
    $this->writeLineLogging('{@c:green}Completed.', true);
};
