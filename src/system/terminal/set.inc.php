<?php

/**
 * CLI Command: set.
 *
 * Creates or updates a site binding in the sites configuration.
 * Maps a domain/path combination to a distributor code. Optionally
 * initializes the distributor directory structure with the -i flag.
 *
 * Usage:
 *   php Razy.phar set <fqdn[/path]> <distributor_code> [-i]
 *
 * Arguments:
 *   fqdn             The fully qualified domain (optionally with path)
 *   distributor_code  The distributor code to bind to
 *
 * Options:
 *   -i  Initialize the distributor folder with dist.php if it doesn't exist
 *
 * Examples:
 *   php Razy.phar set localhost mysite -i
 *   php Razy.phar set example.com/api api_dist
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Util\PathUtil;

return function (string $fqdn = '', string $code = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Update the site or create a new sites', true);

    // Check the parameters is valid
    $fqdn = \trim($fqdn);
    if (!$fqdn) {
        $this->writeLineLogging('{@c:r}[ERROR] The FQDN is required.', true);

        exit;
    }

    $code = \trim($code);
    if (!$code) {
        $this->writeLineLogging('{@c:r}[ERROR] The distributor code is required.', true);

        exit;
    }

    // Load the current site configuration
    $app = new Application();
    $config = $app->loadSiteConfig();

    // Parse the FQDN into domain and path components
    $fqdn = \trim(\preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
    if (!\str_contains($fqdn, '/')) {
        $domain = $fqdn;
        $path = '/';
    } else {
        [$domain, $path] = \explode('/', $fqdn, 2);
        $path = '/' . $path;
    }

    if (!\is_array($config['domains'][$domain] ?? null)) {
        $config['domains'][$domain] = [];
    }

    // Register or override the domain/path binding with the distributor code
    $config['domains'][$domain][$path] = $code;

    // If the -i flag is set, create the distributor folder and dist.php template
    if (isset($parameters['i'])) {
        $distFolder = PathUtil::fixPath(PathUtil::append(SYSTEM_ROOT, 'sites', $code));
        // If the distributor path is not under Razy location
        if (!\str_starts_with($distFolder, SYSTEM_ROOT)) {
            $this->writeLineLogging('{@c:r}[ERROR] The distributor folder ' . $distFolder . ' is not valid.', true);

            exit;
        }
        if (!\is_dir($distFolder)) {
            if (\mkdir($distFolder, 0777, true)) {
                $source = Template::loadFile(PHAR_PATH . '/asset/setup/dist.php.tpl');
                $root = $source->getRoot();
                $root->assign([
                    'dist_code' => $code,
                    'autoload' => 'true',
                    'greedy' => 'true',
                ]);

                try {
                    \file_put_contents(PathUtil::append($distFolder, 'dist.php'), $source->output());
                } catch (Exception) {
                    $this->writeLineLogging('{@c:r}[ERROR] Failed to create the distributor config file.', true);
                }
            } else {
                $this->writeLineLogging('{@c:r}[ERROR] Failed to initialize distributor folder.', true);

                exit;
            }
        }
    }

    // Write update config and regenerate rewrite rules
    $message = 'Writing File sites.inc.php... ';
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
