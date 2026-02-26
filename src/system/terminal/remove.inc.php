<?php

/**
 * CLI Command: remove.
 *
 * Removes a site (domain + path binding) from the sites configuration.
 * After removal, the .htaccess rewrite rules are regenerated.
 *
 * Usage:
 *   php Razy.phar remove <fqdn/path>
 *
 * Arguments:
 *   fqdn/path  The fully qualified domain name and path to remove
 *              (e.g., "example.com/mysite")
 *
 * @license MIT
 */

namespace Razy;

return function (string $fqdn = '') {
    // Check the parameters is valid
    $fqdn = \trim($fqdn);
    if (!$fqdn) {
        $this->writeLineLogging('{@c:red}[ERROR] The FQDN is required.', true);

        exit;
    }

    // Load site configuration
    $app = new Application();
    $config = $app->loadSiteConfig();

    // Normalize the FQDN string and extract domain + path components
    $fqdn = \trim(\preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
    [$domain, $path] = \explode('/', $fqdn, 2);
    $path = '/' . $path;

    // Remove the domain/path binding from the configuration
    unset($config['domains'][$domain][$path]);

    // Write the updated configuration and regenerate rewrite rules
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
