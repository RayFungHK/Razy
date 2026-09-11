<?php
/**
 * golden/provider — lazy-route handler for /<module-alias>/users.
 *
 * FILENAME CONVENTION (verified in code, overriding older doc examples):
 * closure paths WITHOUT a slash are prefixed with the module class name by
 * src/library/Razy/Module/ClosureLoader.php:126 — so addLazyRoute(['users' =>
 * 'users']) resolves to controller/provider.users.php, exactly like the
 * on-disk route_demo files (route → route_demo.user.php). A bare 'users.php'
 * at controller/users.php would NEVER be found.
 *
 * No input superglobals anywhere (RZ-003): routed data comes from
 * getRoutedInfo() — keys url_query, base_url, route, module, closure_path,
 * arguments, type, method, is_shadow (verified Distributor/RouteDispatcher.php
 * routedInfo literal; rules pack "Verified API surface").
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    $routed = $this->getRoutedInfo();
    $arguments = is_array($routed['arguments'] ?? null) ? $routed['arguments'] : [];
    $id = (int) ($arguments['id'] ?? array_values($arguments)[0] ?? 0);

    // This module consumes its OWN internal binding — the public API command
    // 'findUser' is reserved for peers (RZ-010 made visible, not just enforced).
    $result = $this->lookupUser($id);

    // Event broadcast (RZ-008 — events, never shared state): trigger() takes
    // the BARE event name; the framework qualifies it with THIS module's code
    // (verified src/library/Razy/Controller.php:339-342 +
    // src/library/Razy/EventEmitter.php:72-73). Peers subscribe with the
    // qualified name 'golden/provider:userSeen' — see golden/consumer.
    // Firing happens at request time, NOT in __onInit (RZ-009).
    $emitter = $this->trigger('userSeen');
    $emitter->resolve([
        'user_id' => $id,
        'found'   => (bool) ($result['found'] ?? false),
    ]);

    // Response: xhr() is a factory returning an XHR; responseAsBody(array)
    // verified src/library/Razy/XHR.php:185. A JSON body keeps values raw by
    // design — HTML escaping belongs at the HTML boundary (RZ-004), where the
    // engine does NOT auto-escape (verified src/library/Razy/Template/Entity.php
    // parseText returns the raw value; see demos/anti-patterns.md).
    $this->xhr()->responseAsBody([
        'view'     => 'users',
        'user'     => $result['user'] ?? null,
        'found'    => (bool) ($result['found'] ?? false),
        'watchers' => count($emitter->getAllResponse()),
    ]);
};
