<?php

/**
 * CLI Command: search.
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
 *   --refresh        Accepted for compatibility (index cache is per-run today)
 *
 * @license MIT
 */

namespace Razy;

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
        $this->writeLineLogging('  --refresh        Accepted for compatibility (index cache is per-run)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  php Razy.phar search database', true);
        $this->writeLineLogging('  php Razy.phar search vendor/module', true);
        exit(1);
    }

    // Load repository configuration; when the project file is absent/empty,
    // fall back to the built-in default registry (same resolution as `install`)
    // so search works out-of-the-box instead of hard-exiting (S1).
    $repositories = RepositoryManager::resolveRepositories();
    if (!\is_array($repositories) || empty($repositories)) {
        $this->writeLineLogging('{@c:yellow}[WARNING] No repositories configured.{@reset}', true);
        exit(1);
    }

    if (!\is_file(SYSTEM_ROOT . '/repository.inc.php')) {
        $this->writeLineLogging('{@c:yellow}[NOTE]{@reset} no repository.inc.php — using the built-in default registry (add one to override).', true);
    }

    $this->writeLineLogging('Searching for: {@c:cyan}' . $query . '{@reset}', true);
    $this->writeLineLogging('', true);

    // Initialize RepositoryManager and perform the search query
    $repoManager = new RepositoryManager($repositories);

    // Execute search across all configured repositories. NOTE: RepositoryManager's
    // index cache is per-instance (fresh every CLI run) — --refresh is accepted
    // for interface stability but has nothing stale to evict today.
    $results = $repoManager->search($query);

    // Publisher trust banner (S5/G4): the trust outcome of every fetched index
    // is printed — an unsigned or REFUSED registry is never silent.
    foreach ($repoManager->getTrustReport() as $trustUrl => $trustState) {
        if ($trustState === RepositoryManager::TRUST_INVALID) {
            $this->writeLineLogging('{@c:red}[SIGNATURE INVALID]{@reset} ' . $trustUrl . ' — index.sig failed verification against the pinned publisher key; index REFUSED.', true);
        } elseif ($trustState === RepositoryManager::TRUST_UNSIGNED) {
            $this->writeLineLogging('{@c:yellow}[UNVERIFIED]{@reset} ' . $trustUrl . ' — no index.sig (or no pinned key): integrity is checksum-only.', true);
        } else {
            $this->writeLineLogging('{@c:green}[SIGNED]{@reset} ' . $trustUrl . ' — index verified against the pinned publisher key.', true);
        }
    }

    $this->writeLineLogging('', true);

    if (empty($results)) {
        $this->writeLineLogging('{@c:yellow}No modules found matching "' . $query . '".{@reset}', true);
        exit(0);
    }

    $this->writeLineLogging('Found {@c:green}' . \count($results) . '{@reset} module(s):', true);
    $this->writeLineLogging('', true);

    // search() returns a flat LIST whose rows carry 'module_code' — the array
    // KEYS are numeric, so the code must be read from the row (was printed as
    // 0/1/2 until the S5 live E2E exposed it).
    foreach ($results as $info) {
        $moduleCode = (string) ($info['module_code'] ?? '');

        $this->writeLineLogging('{@c:green}' . $moduleCode . '{@reset}', true);

        if (!empty($info['description'])) {
            $this->writeLineLogging('  ' . $info['description'], true);
        }

        if (!empty($info['author'])) {
            $this->writeLineLogging('  Author: {@c:blue}' . $info['author'] . '{@reset}', true);
        }

        $this->writeLineLogging('  Latest: {@c:cyan}' . ($info['latest'] ?? 'N/A') . '{@reset}', true);

        if ($verbose && !empty($info['versions'])) {
            $versions = \is_array($info['versions']) ? $info['versions'] : [$info['versions']];
            $this->writeLineLogging('  Versions: ' . \implode(', ', \array_slice($versions, 0, 5)), true);
            if (\count($versions) > 5) {
                $this->writeLineLogging('            ... and ' . (\count($versions) - 5) . ' more', true);
            }
        }

        if ($verbose && !empty($info['repository'])) {
            $this->writeLineLogging('  Repository: ' . $info['repository'], true);
        }

        $this->writeLineLogging('', true);
    }

    // Show install instructions
    $firstModule = (string) ($results[0]['module_code'] ?? '');
    $this->writeLineLogging('To install a module:', true);
    $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $firstModule . '{@reset}', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('With specific version:', true);
    $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $firstModule . ' --version=1.0.0{@reset}', true);

    exit(0);
};
