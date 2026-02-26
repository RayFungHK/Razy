<?php
/**
 * CLI Command: link
 *
 * Adds or updates a domain alias in the site configuration.
 * An alias maps one FQDN to another, allowing multiple domain
 * names to resolve to the same distributor.
 *
 * Usage:
 *   php Razy.phar link <alias> <domain>
 *
 * Arguments:
 *   alias   The alias FQDN to create (e.g., www.example.com)
 *   domain  The target domain the alias points to (e.g., example.com)
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\NetworkUtil;
return function (string $alias = '', string $domain = '') {
    $this->writeLineLogging('{@s:bu}Adding or update an alias', true);
    $this->writeLineLogging('Creating an alias {@s:u}' . $alias . '{@reset} linking to {@s:u}' . $domain . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !NetworkUtil::isFqdn($alias)) {
        $this->writeLineLogging('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    $domain = trim($domain);
    if (!$domain || !NetworkUtil::isFqdn($domain)) {
        $this->writeLineLogging('{@c:r}[ERROR] The domain is required or the format is invalid.', true);

        exit;
    }

    // Load site configuration and register the alias mapping
    $app = (new Application());
    $config = $app->loadSiteConfig();
    $config['alias'][$alias] = $domain;

    // Persist the updated site configuration to sites.inc.php
    $message = 'Writing file sites.inc.php... ';
    if ($app->writeSiteConfig($config)) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);

    // Refresh the sites list and regenerate .htaccess rewrite rules
    $app->updateSites();
    $message = 'Updating rewrite rules... ';
    if ($app->updateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);
};
