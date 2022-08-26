<?php

namespace Razy;

use Exception;
use Throwable;

/**
 * Write all sites config into sites.inc.php file.
 *
 * @param string $configFilePath
 * @param array $config
 *
 * @return bool
 * @throws Throwable
 */
function writeSiteConfig(string $configFilePath, array $config): bool
{
    // Write the config file
    $source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/sites.inc.php.tpl');
    $root = $source->getRootBlock();

    foreach ($config['domains'] as $domainName => $sites) {
        $domainBlock = $root->newBlock('domain')->assign('domain', $domainName);
        foreach ($sites as $path => $code) {
            $domainBlock->newBlock('site')->assign([
                'path' => $path,
                'dist_code' => $code,
            ]);
        }
    }

    foreach ($config['alias'] as $alias => $domain) {
        if (is_string($domain)) {
            $domain = trim($domain);
            if ($domain) {
                $root->newBlock('alias')->assign([
                    'alias' => $alias,
                    'domain' => $domain,
                ]);
            }
        }
    }

    try {
        $file = fopen($configFilePath, 'w');
        if (!$file) {
            throw new Exception("Can't create lock file!");
        }
        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            fwrite($file, $source->output());
            fflush($file);
            flock($file, LOCK_UN);
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Initial and fix the sites' config by given config path.
 *
 * @param string $configFilePath
 *
 * @return array[]|mixed
 */
function loadSiteConfig(string $configFilePath): array
{
    // Load default config setting
    $config = [
        'domains' => [],
        'alias' => [],
    ];

    // Import the config file setting
    try {
        if (is_file($configFilePath)) {
            $config = require $configFilePath;
        }
    } catch (Exception $e) {
        echo $e;

        exit;
    }

    // Domains config fixing & initialize
    if (!is_array($config['domains'] ?? null)) {
        $config['domains'] = [];
    }

    if (!is_array($config['alias'] ?? null)) {
        $config['alias'] = [];
    }

    return $config;
}

/**
 * @param string $path
 *
 * @return bool
 * @throws Throwable
 *
 */
function updateRewriteRules(string $path): bool
{
    try {
        $source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/htaccess.tpl');
        $rootBlock = $source->getRootBlock();
        foreach (Application::GetDistributors() as $distCode => $info) {
            $rootBlock->newBlock('rewrite')->assign([
                'domain' => preg_quote($info['domain']),
                'dist_code' => $distCode,
                'route_path' => trim($info['url_path'], '/'),
            ]);
            if (count($info['alias'])) {
                foreach ($info['alias'] as $alias) {
                    $rootBlock->newBlock('rewrite')->assign([
                        'domain' => preg_quote($alias),
                        'dist_code' => $distCode,
                        'route_path' => trim($info['url_path'], '/'),
                    ]);
                }
            }
        }
        file_put_contents($path, $source->output());
    } catch (Exception $e) {
        return false;
    }

    return true;
}

/**
 * @param string $command
 * @param array $argv
 * @param array $parameters
 * @return bool
 */
function executeTerminal(string $command, array $argv = [], array $parameters = []): bool
{
    $closureFilePath = append(PHAR_PATH, 'system/terminal/', $command . '.inc.php');
    if (is_file($closureFilePath)) {
        try {
            $closure = include $closureFilePath;
            (new Terminal($command))->run($closure, $argv, $parameters);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    return false;
}
