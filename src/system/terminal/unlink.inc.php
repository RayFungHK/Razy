<?php
/**
 * CLI Command: unlink
 *
 * Removes a domain alias from the site configuration.
 * After removing the alias, the .htaccess rewrite rules are regenerated.
 *
 * Usage:
 *   php Razy.phar unlink <alias>
 *
 * Arguments:
 *   alias  The alias FQDN to remove (e.g., www.example.com)
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\NetworkUtil;
return function (string $alias = '') {
    $this->writeLineLogging('{@s:bu}Remove an alias', true);
    $this->writeLineLogging('Removing an alias {@s:u}' . $alias . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !NetworkUtil::isFqdn($alias)) {
        $this->writeLineLogging('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    // Load site configuration and remove the alias entry
    $app = new Application();
    $config = $app->loadSiteConfig();
    unset($config['alias'][$alias]);

    // Persist updated configuration to sites.inc.php
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
