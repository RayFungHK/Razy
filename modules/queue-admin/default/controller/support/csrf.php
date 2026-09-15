<?php

/**
 * razymod/queue-admin — CSRF double-submit helper for the HTML shell.
 *
 * WHY double-submit and not Razy\Csrf\CsrfTokenManager (verified, stated
 * honestly): CsrfTokenManager stores its token in a SessionInterface, and the
 * framework Session subsystem emits NO cookie anywhere (grep-verified: zero
 * setcookie/headers_send across Session.php, SessionConfig.php, drivers) —
 * cookie wiring is app-operator territory (SessionMiddleware is installed
 * globally by the site, never by a module). A self-contained module shell
 * must not assume that wiring, so the token travels as a host-only,
 * SameSite=Lax cookie AND a request header, compared with hash_equals.
 * This defends the CSRF class for THIS surface without claiming more.
 *
 * Returns ['issue' => callable(): string, 'verify' => callable(): bool].
 */

return [
    'issue' => static function (): string {
        // lint-allow: RZ-003 — cookie read is the double-submit contract itself, cast/validated below.
        $existing = $_COOKIE['raze_qa_csrf'] ?? '';
        // lint-allow: RZ-003 — shape check on the raw cookie before reuse.
        if (\is_string($existing) && \preg_match('/^[0-9a-f]{64}$/', $existing) === 1) {
            return $existing;
        }

        $token = \bin2hex(\random_bytes(32));

        if (!\headers_sent()) {
            \setcookie('raze_qa_csrf', $token, [
                'path' => '/',
                'httponly' => false, // the page reads it to echo into the header — inherent to double-submit
                'samesite' => 'Lax',
            ]);
        }

        return $token;
    },

    'verify' => static function (): bool {
        // lint-allow: RZ-003 — double-submit comparison inputs, validated via hash_equals.
        $cookie = $_COOKIE['raze_qa_csrf'] ?? '';
        // lint-allow: RZ-003 — header input; never echoed, only constant-time compared.
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!\is_string($cookie) || !\is_string($header) || $cookie === '' || $header === '') {
            return false;
        }

        return \hash_equals($cookie, $header);
    },
];
