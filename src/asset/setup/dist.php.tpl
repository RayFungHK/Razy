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
	// (trusted HTML; the lint wants a `<!-- lint-allow: RZ-004 <reason> -->` comment
	// next to it), ->escape, or ends in ->json (<script>-safe JSON). Dists created
	// before this flag existed default to false.
	'auto_escape'    => true,

	// One session for the dist (CSRF tokens included). The cookie is an opaque
	// 64-hex token; the store keys each session by sha256(cookie) hex, so another
	// service sharing the store can find it. Driver: file (default) | database |
	// redis | array. Secure defaults to 'auto' (set when the request is HTTPS).
	// 'session' => [
	// 	'driver'   => 'database',
	// 	'database' => ['connection' => 'main'],   // table defaults to razy_sessions_<dist>
	// 	'name'     => 'app_session',
	// 	'secure'   => 'auto',
	// 	'same_site' => 'Lax',
	// ],

	// Security headers on every response, error pages included (RZ-10). Absent or
	// true = nosniff, Referrer-Policy strict-origin-when-cross-origin and
	// same-origin framing (X-Frame-Options + CSP frame-ancestors); false = none.
	// {nonce} in 'csp' is this request's nonce; templates print it as the csp_nonce variable.
	// HSTS is sent over HTTPS only. 'headers' adds or (with false) removes headers.
	// 'security_headers' => [
	// 	'csp'             => "default-src 'self'; script-src 'self' 'nonce-{nonce}'",
	// 	'csp_report_only' => false,
	// 	'hsts'            => ['max_age' => 31536000, 'include_subdomains' => true],
	// 	'headers'         => ['Permissions-Policy' => 'camera=(), microphone=()'],
	// ],

	// Web assets (RZ-09). In templates the asset function (file='js/app.js') prints the
	// module's file URL with ?v=<content hash>; the importmap function (in <head>, with
	// the CSP nonce) maps every module .js/.mjs URL to its hashed URL so each ES module loads once.
	// 'serve' answers /webassets/{alias}/{version}/{file} in PHP (default: on under
	// `php Razy.phar serve`, off elsewhere); a hashed URL is cached immutable_max_age
	// seconds as immutable, any other max_age (0 = revalidate). 'imports' adds bare specifiers.
	// 'assets' => [
	// 	'serve'             => false,
	// 	'max_age'           => 0,
	// 	'immutable_max_age' => 31536000,
	// 	'base_url'          => 'https://cdn.example.com/',
	// 	'imports'           => ['app/ui' => 'core/js/ui.js'],
	// ],

	// Authorization (RZ-06). When a module registers $agent->identity(...), a
	// signed-out request on a Route::can() route gets 401; an HTML GET is sent
	// to login_url with ?next=<the page>. Signed in but refused = 403.
	// 'authz' => ['login_url' => '/login'],

	// Request resolvers (RZ-12) are registered by modules, not here: in __onLoad,
	// $agent->resolveRequest(fn (RequestContext $request) => …) runs before routing on
	// every request; it may look up the host or tenant (I/O is fine there, unlike
	// __onInit), record it with $request->set('tenant', …) and return null, or end the
	// request with a Response (Response::notFound(), Response::redirect('/…')).

	// Custom module path: Load modules from a different directory within the Razy project
	// The path must be inside SYSTEM_ROOT. Useful for organizing modules in a shared location.
	// Example: 'modules/' or 'dev/my_modules/' (relative to SYSTEM_ROOT)
	// 'module_path' => '',
];
