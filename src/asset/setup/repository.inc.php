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
 * Razy Framework - Default Repository Configuration (Setup Asset)
 *
 * Returns an associative array mapping repository URLs to their target branch.
 * These repositories are used by the Razy package manager to discover and
 * install modules, plugins, and shared libraries.
 *
 * Format: 'repository_url' => 'branch_name'
 *
 * @package Razy
 * @license MIT
 */

// Map of package repository URLs to the branch that should be tracked
return [
    'https://github.com/RayFungHK/Razy-Repository/' => 'master',
];
