<?php

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Unpack asset', true);

    $this->writeLineLogging('{@c:blue}Updating rewrite rules...', true);
    Application::UpdateRewriteRules();
    $this->writeLineLogging('{@c:green}Completed.', true);
};
