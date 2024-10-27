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

use Exception;

return function (string $fqdn = '', string $code = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Update the site or create a new sites', true);

    // Check the parameters is valid
    $fqdn = trim($fqdn);
    if (!$fqdn) {
        $this->writeLineLogging('{@c:r}[ERROR] The FQDN is required.', true);

        exit;
    }

    $code = trim($code);
    if (!$code) {
        $this->writeLineLogging('{@c:r}[ERROR] The distributor code is required.', true);

        exit;
    }

    // Load default config setting
    $app = new Application();
    $config = $app->loadSiteConfig();

    // Extract the domain and the path from the FQDN string
    $fqdn = trim(preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
    if (!str_contains($fqdn, '/')) {
        $domain = $fqdn;
        $path   = '/';
    } else {
        [$domain, $path] = explode('/', $fqdn, 2);
        $path            = '/' . $path;
    }

    if (!is_array($config['domains'][$domain] ?? null)) {
        $config['domains'][$domain] = [];
    }

    // Put or override the site setting
    $config['domains'][$domain][$path] = $code;

    // If the `i` parameter is given, create the folder including the dist.php
    if ($parameters['i']) {
        $distFolder = fix_path(append(SYSTEM_ROOT, 'sites', $code));
        // If the distributor path is not under Razy location
        if (!str_starts_with($distFolder, SYSTEM_ROOT)) {
            $this->writeLineLogging('{@c:r}[ERROR] The distributor folder ' . $distFolder . ' is not valid.', true);

            exit;
        }
        if (!is_dir($distFolder)) {
            if (mkdir($distFolder, 0777, true)) {
                $source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/dist.php.tpl');
                $root   = $source->getRoot();
                $root->assign([
                    'dist_code' => $code,
                    'autoload'  => 'true',
                    'greedy'    => 'true',
                ]);

                try {
                    file_put_contents(append($distFolder, 'dist.php'), $source->output());
                } catch (Exception) {
                    $this->writeLineLogging('{@c:r}[ERROR] Failed to create the distributor config file.', true);
                }
            } else {
                $this->writeLineLogging('{@c:r}[ERROR] Failed to initialize distributor folder.', true);

                exit;
            }
        }
    }

    $message = 'Writing File sites.inc.php... ';
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
