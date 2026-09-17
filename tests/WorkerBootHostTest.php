<?php

// phpcs.ignore

/**
 * Worker-boot host resolution — regression pins (source-level).
 *
 * Origin 2026-09: booting the benchmark gate site (multisite layout, no
 * standalone/) under frankenphp worker mode crashed EVERY worker thread at
 * boot: the boot pass has no request, so bootstrap.inc.php:86 leaves
 * HOSTNAME at its 'UNKNOWN' sentinel, and Application::host('UNKNOWN:8080')
 * died in FQDN validation — Caddy then panicked the pool ("too many
 * consecutive worker failures"). No multisite worker could ever boot.
 *
 * The fix (src/main.php, worker boot branch): the sentinel boots the DEFAULT
 * site via host('') — which resolves the documented '*' entry of
 * sites.inc.php. The standard (non-worker) path keeps passing the live
 * HOSTNAME:PORT, since fpm/CLI-server requests always carry SERVER_NAME.
 *
 * main.php cannot be unit-loaded (it defines constants and dispatches), so
 * these are the same style of source-pin tests the DatabaseReconnectTest
 * established: exact syntax, exact position.
 *
 * @license MIT
 */

class WorkerBootHostTest extends PHPUnit\Framework\TestCase
{
    private static function mainSource(): string
    {
        $src = \file_get_contents(\dirname(__DIR__) . '/src/main.php');
        self::assertIsString($src, 'src/main.php must exist');

        return $src;
    }

    /**
     * The sentinel guard must sit in the WORKER boot branch and hand host()
     * the guarded value — not the raw constant.
     */
    public function testWorkerBootGuardExists(): void
    {
        $src = self::mainSource();

        $this->assertMatchesRegularExpression(
            '/\$bootHost = \(\'\' === HOSTNAME \|\| \'UNKNOWN\' === HOSTNAME\) \? \'\' : HOSTNAME \. \':\' \. PORT;/',
            $src,
            'worker boot must collapse the UNKNOWN sentinel to host(\'\') (default "*" site)',
        );

        $this->assertMatchesRegularExpression(
            '/\$bootHost[^\n]*\n\s*\$app->host\(\$bootHost\);/',
            $src,
            'the worker branch must call host() with the guarded value, not the raw constant',
        );
    }

    /**
     * The standard (non-worker) request path must still resolve the REAL
     * request host — exactly one raw HOSTNAME:PORT call may remain.
     */
    public function testStandardPathStillUsesLiveHost(): void
    {
        $src = self::mainSource();

        $raw = \preg_match_all('/\$app->host\(HOSTNAME \. \':\' \. PORT\);/', $src);

        $this->assertSame(
            1,
            $raw,
            'exactly one raw HOSTNAME:PORT host() call may remain — the standard request path; '
            . 'a second one means the worker branch regressed',
        );
    }
}
