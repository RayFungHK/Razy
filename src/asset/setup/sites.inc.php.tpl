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
		 * The key is the domain ('*' = default site: serves any host nothing
		 * else matched) and the value MUST BE AN ARRAY of URL-path prefix =>
		 * distribution code. String values are parsed nowhere — Application's
		 * updateSites() requires is_array() (verified 2026-09; an earlier
		 * revision of this comment advertised a string shortcut that was
		 * never implemented, and entries written that way were silently
		 * dropped).
		 *
		 * The distribution folder must contain a dist.php
		 *
		 * Usage:
		 * 'domain.name' => [ '/' => 'mysite' ]        // whole host
		 * '*'           => [ '/' => 'fallback' ]      // default site
		 *
		 * Path mapping (longest prefix wins):
		 * 'domain.name' => [
		 *   '/'      => 'main',
		 *   '/docs'  => 'docs',
		 * ]
		 *
		 * Tagging (a '@' tag selects from dist.php's declared tags):
		 * 'domain.name' => [ '/' => 'mysite@v2' ]
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
