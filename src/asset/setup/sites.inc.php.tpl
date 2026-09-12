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
		 *
		 * Tagging:
		 * '/path' => 'mysite@v2'   // use the 'v2' tag from dist.php modules
		 *
		 * Per-domain config folder mapping is handled via config_mapping in dist.php.
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

	// Host-level sibling path exclusions for same-host coexistence (see
	// manual/08-coexistence.md). Each entry is a host-absolute path prefix
	// (e.g. '/api-py') of an application sharing this host; segments allow
	// letters, digits, '.', '_' and '-' only — no regex/glob syntax.
	// The generated .htaccess / Caddyfile will never claim these paths.
	// Regenerate after editing:
	//   php Razy.phar rewrite          (Apache .htaccess)
	//   php Razy.phar rewrite --caddy  (Caddy/FrankenPHP Caddyfile)
	'exclude_paths' => [
		<!-- START BLOCK: exclude_path -->
		'{$prefix}',
		<!-- END BLOCK: exclude_path -->
	],
];
