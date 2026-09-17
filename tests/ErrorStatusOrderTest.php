<?php

// phpcs.ignore

/**
 * Error-response status integrity — regression pins (origin 2026-09).
 *
 * Live-caught by the benchmark gate site: under frankenphp worker mode the
 * dispatch runs with NO output buffer, and
 *   - show404() called ob_clean() unconditionally: the notice it raised IS
 *     output, so the following 404 header failed with "headers already sent"
 *     and curl measured a 404 BODY arriving under status 200;
 *   - showException() echoed the page BEFORE its status header — the same
 *     first-byte-poisons-headers trap (the warning fired visibly all through
 *     the gate-site boot logs).
 * Every crawler, monitor, and CDN in front of a worker deploy was eating
 * wrong status codes on every error page.
 *
 * WEB_MODE is a bootstrap constant (never true under phpunit), so these are
 * source-position pins — the two ordering invariants are exactly what broke.
 *
 * @license MIT
 */

class ErrorStatusOrderTest extends PHPUnit\Framework\TestCase
{
    private static function src(): string
    {
        $s = \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Error/ErrorRenderer.php');
        self::assertIsString($s);

        return $s;
    }

    public function testShow404GuardsObCleanAndHeadersFirst(): void
    {
        $src = self::src();

        // Positional invariants inside show404(): the guard exists, and the
        // 404 status header precedes the body echo. (Position pins, not one
        // giant regex — comment text must never be load-bearing.)
        $this->assertMatchesRegularExpression(
            '/if \(\\\ob_get_level\(\) > 0\) \{\s*\\\ob_clean\(\);/',
            $src,
            'show404 must only clean an EXISTING buffer — worker mode has none, '
            . 'and the unguarded ob_clean() notice poisoned every later header()',
        );

        $headerPos = \strpos($src, "\\header('HTTP/1.1 404 Not Found', true, 404)");
        $echoPos = \strpos($src, "echo '<h1>404 Not Found</h1>'");

        self::assertGreaterThan(0, $headerPos, 'the 404 status header (replace form) must exist');
        self::assertGreaterThan(0, $echoPos);
        $this->assertLessThan(
            $echoPos,
            $headerPos,
            'the 404 status must be set BEFORE any body output — first byte poisons headers'
        );
    }

    public function testShowExceptionStatusHeaderPrecedesEcho(): void
    {
        $src = self::src();

        $headerPos = \strpos($src, "\\header('HTTP/1.1 ' . (\\is_numeric(");
        $echoPos = \strpos($src, 'echo $source->output();');

        self::assertGreaterThan(0, $headerPos, 'status header call must exist');
        self::assertGreaterThan(0, $echoPos, 'page echo must exist');
        $this->assertLessThan(
            $echoPos,
            $headerPos,
            'the exception status header must be set BEFORE the page is echoed — '
            . 'after the first byte header() is a warning-only no-op (worker mode, 2026-09 logs)'
        );
    }

    public function testLegacyHttp10StatusLineIsGone(): void
    {
        $this->assertStringNotContainsString(
            'HTTP/1.0',
            self::src(),
            'HTTP/1.0 status lines are retired: the replace-form header() is the '
            . 'PHP-8.5-safe house style since 2026-09',
        );
    }
}
