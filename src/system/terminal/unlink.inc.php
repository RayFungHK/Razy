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

return function (string $alias = '') {
    $this->writeLineLogging('{@s:bu}Remove an alias', true);
    $this->writeLineLogging('Removing an alias {@s:u}' . $alias . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !is_fqdn($alias)) {
        $this->writeLineLogging('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    // Load default config setting
    $app = new Application();
    $config = $app->loadSiteConfig();
    unset($config['alias'][$alias]);

    $message = 'Writing file sites.inc.php... ';
    if ($app->writeSiteConfig($config)) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);

    $app->updateSites();
    $message = 'Updating rewrite rules... ';
    if ($app->updateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);
};
