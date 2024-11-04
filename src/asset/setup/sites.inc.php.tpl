<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
	'domains' => [
		/*
		 * The key is the domain and the value is the string of distribution path.
		 * You can set the value as an array for advanced distribution setup.
		 *
		 * The distribution folder must contain a dist.php
		 *
		 * Basic usage:
		 * 'domain.name' => (string) The module distribution path
		 *
		 * Advanced usage:
		 * (The module folder will not be loaded if it is a distribution folder)
		 * 'domain.name' => (array) [
		 *   'path' => (string) The module distribution path in sites folder
		 * ]
		 */
		<!-- START BLOCK: domain -->
		'{$domain}' => [
			<!-- START BLOCK: site -->
			'{$path}' => '{$dist_code}',
			<!-- END BLOCK: site -->
		],
		<!-- END BLOCK: domain -->
	],

	// The domain alias, it will be used if the domain is not exists
	'alias' => [
		<!-- START BLOCK: alias -->
		'{$alias}' => '{$domain}',
		<!-- END BLOCK: alias -->
	],
];
