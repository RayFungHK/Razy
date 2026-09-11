<?php
/**
 * golden/consumer — lazy-route handler for /<module-alias>/panel.
 *
 * Filename follows the verified loader convention: a slash-less closure path is
 * prefixed with the module class name (src/library/Razy/Module/ClosureLoader.php:126)
 * → controller/consumer.panel.php. Bare 'panel.php' here would never load.
 *
 * THE ONE SANCTIONED CROSS-MODULE PATH (RZ-001): api('vendor/module')->command().
 * The provider's closure files are private to the provider — reaching for them
 * with require/include (or any file read) silently breaks on the provider's
 * next version bump, bypasses __onAPICall permissioning, and is invisible to
 * validate/compose/pack. It is a defect, not a shortcut.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    $routed = $this->getRoutedInfo();
    $arguments = is_array($routed['arguments'] ?? null) ? $routed['arguments'] : [];
    $id = (int) ($arguments['id'] ?? array_values($arguments)[0] ?? 0);

    // api() returns NULLABLE (verified src/library/Razy/Controller.php:510
    // — "final public function api(string $moduleCode): ?Emitter"). The
    // provider may be absent from this distributor — check and degrade
    // explicitly instead of fataling on null.
    $provider = $this->api('golden/provider');

    if ($provider === null) {
        $this->xhr()->responseAsBody([
            'ok'    => false,
            'error' => 'golden/provider is not installed in this distributor (declare it in package.php require + run compose)',
        ]);
        return;
    }

    // Typed argument in, structured array out — the provider's contract
    // (demos/golden/provider/default/controller/api/find_user.php).
    $result = $provider->findUser($id);
    $name = (string) ($result['user']['name'] ?? '');

    // ESCAPED-OUTPUT NOTE (RZ-004): this JSON body is raw on purpose — a JSON
    // client consumes values verbatim and HTML-escaping here would corrupt them.
    // The escaping duty lands at the HTML boundary instead. If this data were
    // rendered into a .tpl, escape it in the CONTROLLER first (the engine does
    // NOT auto-escape — verified src/library/Razy/Template/Entity.php:388-399
    // returns the raw value):
    //
    //     $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');   // then hand to the template
    //
    // or build the markup with Razy\DOM (text nodes/attributes escaped by
    // construction, verified src/library/Razy/DOM.php:84-140).
    $this->xhr()->responseAsBody([
        'ok'    => (bool) ($result['found'] ?? false),
        'panel' => [
            'user_id' => $id,
            'name'    => $name,
            'role'    => $result['user']['role'] ?? null,
            'source'  => $result['source'] ?? 'unknown',
        ],
    ]);
};
