<?php

/*
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
	'dist'            => '{$dist_code}',
	'repo'            => [
	],
	'autoload_shared' => {$autoload},

	// A list to load modules by tag
	'modules'         => [
		<!-- START BLOCK: modules -->
        '{$module_code}' => '{$version}',
		<!-- END BLOCK: modules -->
	],

	'exclude_module' => [
	],

	// Auto load all modules in distribution folder
	'greedy'         => {$greedy},

	// Fallback routing: when enabled (default), unmatched requests are forwarded
	// to index.php for PHP route matching. When disabled, unmatched requests that
	// don't match an existing file or directory will return 404 at the server level.
	'fallback'       => true,

	// Template auto-escape: every variable tag is HTML-escaped unless it has ->raw
	// (trusted HTML; the lint wants a `lint-allow: RZ-004 <reason>` next to it)
	// or ->escape. Dists created before this flag existed default to false.
	'auto_escape'    => true,

	// Custom module path: Load modules from a different directory within the Razy project
	// The path must be inside SYSTEM_ROOT. Useful for organizing modules in a shared location.
	// Example: 'modules/' or 'dev/my_modules/' (relative to SYSTEM_ROOT)
	// 'module_path' => '',
];
