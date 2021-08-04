<?php

/*
 * This file is part of Razy v0.4.
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

	// A list to load the modules in shared folder or in distribution folder
	'enable_module'   => [
		<!-- START BLOCK: modules -->
        '{$module_code}' => '{$version}',
		<!-- END BLOCK: modules -->
	],

	'exclude_module' => [
	],

	// Auto load all modules in distribution folder
	'greedy'         => {$greedy},
];
