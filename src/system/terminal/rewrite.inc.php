<?php

namespace Razy;

return function () use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Update Rewrite Rule', true);

    $this->writeLineLogging('{@c:blue}Updating rewrite rules...', true);
    Application::UpdateRewriteRules();
    $this->writeLineLogging('{@c:green}Completed.', true);
};
