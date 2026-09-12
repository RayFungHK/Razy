<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Razy Framework - Registry Sources Configuration (Setup Asset)
 *
 * Returns an associative array mapping repository URLs to the branch their
 * index.json is served from. Consumed by `search`, `install --from-repo`,
 * `pkg install`, and `sync` to discover and install packs.
 *
 * Format: 'repository_url' => 'branch_name'
 *
 * HONEST STATUS (2026-07): the entry below is the planned OFFICIAL Razy
 * registry. Published 2026-07 (seeded from the framework's
 * tools/registry-seed/); its README documents the layout + checksum contract.
 * If it is ever unreachable, Razy reports "unreachable or index.json not
 * found" and installs nothing. You can:
 *   - point a single command at any live registry:
 *       php Razy.phar install <vendor>/<mod> --from-repo --from=https://github.com/<you>/<registry>@main
 *   - or replace the URL below with another registry.
 *
 * Note: since S0, deleting this file is safe — Razy falls back to the same
 * built-in default official registry (Razy\RepositoryManager::DEFAULT_OFFICIAL_URL).
 * A hand-written file like this one stays authoritative when it returns entries.
 *
 * @package Razy
 * @license MIT
 */

// Map of package repository URLs to the branch that should be tracked
return [
    'https://github.com/RayFungHK/Razy-Repository/' => 'main',
];
