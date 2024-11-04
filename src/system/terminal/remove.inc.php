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

return function (string $fqdn = '') {
    // Check the parameters is valid
    $fqdn = trim($fqdn);
    if (!$fqdn) {
        $this->writeLineLogging('{@c:red}[ERROR] The FQDN is required.', true);

        exit;
    }

    // Load default config setting
    $app = new Application();
    $config = $app->loadSiteConfig();

    // Extract the domain and the path from the FQDN string
    $fqdn = trim(preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
    [$domain, $path] = explode('/', $fqdn, 2);
    $path = '/' . $path;

    // Remove the specified domain and path setting
    unset($config['domains'][$domain][$path]);

    if ($app->writeSiteConfig($config)) {
        $this->writeLineLogging('{@c:green}Done.', true);
    } else {
        $this->writeLineLogging('{@c:red}Failed.', true);
    }

    $app->updateSites();
    $message = 'Updating rewrite rules... ';
    if ($app->updateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);
};
