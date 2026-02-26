<?php
/**
 * CLI Command: search
 *
 * Searches for modules across all configured repositories defined in
 * repository.inc.php. Displays matching modules with their descriptions,
 * authors, available versions, and install instructions.
 *
 * Usage:
 *   php Razy.phar search <query> [options]
 *
 * Arguments:
 *   query  Search keyword or module code pattern
 *
 * Options:
 *   -v, --verbose    Show detailed information (all versions, repository URLs)
 *   --refresh        Force refresh repository index cache
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Exception;
return function (string $query = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Module Search', true);
    $this->writeLineLogging('Search modules from configured repositories', true);
    $this->writeLineLogging('', true);

    // Parse options
    $verbose = false;
    $refresh = false;

    foreach ($options as $option) {
        if ($option === '-v' || $option === '--verbose') {
            $verbose = true;
        } elseif ($option === '--refresh') {
            $refresh = true;
        }
    }

    // Show usage if no query
    if (!$query) {
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar search <query>', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  -v, --verbose    Show detailed information', true);
        $this->writeLineLogging('  --refresh        Force refresh repository index cache', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  php Razy.phar search database', true);
        $this->writeLineLogging('  php Razy.phar search vendor/module', true);
        exit(1);
    }

    // Load repository configuration from the project root
    $repositoryConfig = SYSTEM_ROOT . '/repository.inc.php';
    if (!is_file($repositoryConfig)) {
        $this->writeLineLogging('{@c:yellow}[WARNING] No repository.inc.php found.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Create repository.inc.php in your project root:', true);
        $this->writeLineLogging('  {@c:cyan}<?php{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    \'https://github.com/username/repo/\' => \'main\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}];{@reset}', true);
        exit(1);
    }

    $repositories = include $repositoryConfig;
    if (!is_array($repositories) || empty($repositories)) {
        $this->writeLineLogging('{@c:yellow}[WARNING] No repositories configured.{@reset}', true);
        exit(1);
    }

    $this->writeLineLogging('Searching for: {@c:cyan}' . $query . '{@reset}', true);
    $this->writeLineLogging('', true);

    // Initialize RepositoryManager and perform the search query
    $repoManager = new RepositoryManager($repositories);

    // Execute search across all configured repositories
    $results = $repoManager->search($query, $refresh);

    if (empty($results)) {
        $this->writeLineLogging('{@c:yellow}No modules found matching "' . $query . '".{@reset}', true);
        exit(0);
    }

    $this->writeLineLogging('Found {@c:green}' . count($results) . '{@reset} module(s):', true);
    $this->writeLineLogging('', true);

    foreach ($results as $moduleCode => $info) {
        $this->writeLineLogging('{@c:green}' . $moduleCode . '{@reset}', true);

        if (!empty($info['description'])) {
            $this->writeLineLogging('  ' . $info['description'], true);
        }

        if (!empty($info['author'])) {
            $this->writeLineLogging('  Author: {@c:blue}' . $info['author'] . '{@reset}', true);
        }

        $this->writeLineLogging('  Latest: {@c:cyan}' . ($info['latest'] ?? 'N/A') . '{@reset}', true);

        if ($verbose && !empty($info['versions'])) {
            $versions = is_array($info['versions']) ? $info['versions'] : [$info['versions']];
            $this->writeLineLogging('  Versions: ' . implode(', ', array_slice($versions, 0, 5)), true);
            if (count($versions) > 5) {
                $this->writeLineLogging('            ... and ' . (count($versions) - 5) . ' more', true);
            }
        }

        if ($verbose && !empty($info['repository'])) {
            $this->writeLineLogging('  Repository: ' . $info['repository'], true);
        }

        $this->writeLineLogging('', true);
    }

    // Show install instructions
    $firstModule = array_key_first($results);
    $this->writeLineLogging('To install a module:', true);
    $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $firstModule . '{@reset}', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('With specific version:', true);
    $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $firstModule . ' --version=1.0.0{@reset}', true);

    exit(0);
};
