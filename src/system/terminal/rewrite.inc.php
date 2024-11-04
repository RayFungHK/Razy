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
    $this->writeLineLogging('{@s:bu}Rebuild rewrite rules', true);

    $this->writeLineLogging('{@c:blue}Updating rewrite rules...', true);
    (new Application())->updateRewriteRules();
    $this->writeLineLogging('{@c:green}Completed.', true);
};
