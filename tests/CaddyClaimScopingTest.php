<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\ConfigurationException;
use Razy\Routing\CaddyfileCompiler;

/**
 * Coexistence Phase 2: Caddy claim-scoping (option (a)) + FM-5 health handle.
 *
 * Doctrine (three emission modes, architecture/ROUTE-COEXISTENCE.md §4):
 *   1. sub-path-only mounts      → scoped @php_claimed matcher (+ exclusions
 *      INSIDE the matcher) + dedicated /_razy/health handle;
 *   2. root mount + exclusions   → Phase 1 @not_excluded gate (unchanged);
 *   3. root mount, no exclusions → bare php_server, BYTE-IDENTICAL legacy
 *      (re-pinned against the same shipped goldens here, from the Phase 2
 *      side — the health handle deliberately does NOT appear: FM-5 only bites
 *      scoped hosts, and root mounts already reach the probe).
 */
#[CoversClass(CaddyfileCompiler::class)]
class CaddyClaimScopingTest extends TestCase
{
    private const SUBPATH = ['example.com' => ['/app' => 'coex_fake_dist']];

    private const TWO_SUBPATHS = ['example.com' => ['/app' => 'coex_fake_dist', '/shop' => 'coex_fake_dist']];

    private const MIXED_ROOT_AND_SUB = ['example.com' => ['/' => 'coex_fake_dist', '/app' => 'coex_fake_dist']];

    private const TRAILING_SLASH = ['example.com' => ['/app/' => 'coex_fake_dist']];

    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!\defined('PHAR_PATH')) {
            \define('PHAR_PATH', SYSTEM_ROOT . '/src');
        }

        $this->tmpDir = \sys_get_temp_dir() . '/razy_claim_scoping_' . \uniqid();
        \mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->tmpDir . '/*') as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tmpDir);
    }

    private static function lf(string $content): string
    {
        return \str_replace(["\r\n", "\r"], "\n", $content);
    }

    // ── Mode 1: scoped claim ─────────────────────────────────────────

    public function testSubPathMountEmitsScopedMatcherAndGatedClaim(): void
    {
        $content = $this->compile(self::SUBPATH);

        $this->assertStringContainsString('@php_claimed {', $content);
        $this->assertStringContainsString('path /app /app/*', $content);
        $this->assertStringContainsString('php_server @php_claimed {', $content, 'worker mode must gate the claim');
        $this->assertStringNotContainsString('@not_excluded', $content, 'no exclusions configured');
    }

    public function testMultipleMountsMergeIntoOneMatcherAsOrList(): void
    {
        $content = $this->compile(self::TWO_SUBPATHS);

        $this->assertStringContainsString('path /app /app/* /shop /shop/*', $content);
        // One matcher DEFINITION per site (the second textual hit is the
        // `php_server @php_claimed` gate referencing it — count definitions only).
        $this->assertSame(1, \substr_count($content, "\n\t@php_claimed {"));
    }

    public function testScopedHostGetsHealthHandle(): void
    {
        $content = $this->compile(self::SUBPATH);

        $this->assertStringContainsString('handle /_razy/health {', $content, 'FM-5: probe must survive scoped claims');
        $this->assertStringContainsString('Cache-Control "no-store, max-age=0"', $content);
    }

    public function testStandardModeScopedClaimHasNoWorkerBlock(): void
    {
        $content = $this->compile(self::SUBPATH, false);

        $this->assertStringContainsString('php_server @php_claimed', $content);
        $this->assertStringNotContainsString('worker /app/public/index.php', $content);
    }

    // ── Exclusions inside the scoped matcher ─────────────────────────

    public function testExclusionsAbsorbedIntoScopedMatcherNotSeparateGate(): void
    {
        $content = $this->compile(self::SUBPATH, true, ['/api-py']);

        $this->assertStringContainsString('not path /api-py /api-py/*', $content);
        $this->assertStringContainsString('php_server @php_claimed', $content);
        $this->assertStringNotContainsString('@not_excluded', $content, 'the Phase 1 gate must NOT double-emit in scoped mode');
    }

    // ── Mode 2/3: root mount claims the host ─────────────────────────

    public function testRootMountClaimHostDespiteAdditionalSubPath(): void
    {
        $content = $this->compile(self::MIXED_ROOT_AND_SUB);

        $this->assertStringNotContainsString('@php_claimed', $content, 'a root mount claims the whole host — scoping would be a lie');
        $this->assertStringNotContainsString('handle /_razy/health', $content, 'root mounts already reach the probe');
        $this->assertStringContainsString('php_server {', $content);
    }

    public function testRootMountWithExclusionsKeepsPhaseOneGate(): void
    {
        $content = $this->compile(self::MIXED_ROOT_AND_SUB, true, ['/api-py']);

        $this->assertStringContainsString('@not_excluded not path /api-py /api-py/*', $content);
        $this->assertStringContainsString('php_server @not_excluded {', $content);
        $this->assertStringNotContainsString('@php_claimed', $content);
    }

    public function testRootOnlyOutputStillByteIdenticalToLegacyGoldens(): void
    {
        $goldenDir = __DIR__ . '/fixtures/coexistence/';
        $rootOnly = ['example.com' => ['/' => 'coex_fake_dist']];

        // Raw (CRLF-preserving) comparison — same goldens the Phase 1 suite pins.
        $outputPathW = $this->tmpDir . '/gw';
        (new CaddyfileCompiler())->compile($rootOnly, [], $outputPathW, true, '/app/public');
        $outputPathS = $this->tmpDir . '/gs';
        (new CaddyfileCompiler())->compile($rootOnly, [], $outputPathS, false, '/app/public');

        $this->assertSame((string) \file_get_contents($goldenDir . 'caddy-worker-no-exclusions.golden'), (string) \file_get_contents($outputPathW), 'worker: Phase 2 must not touch root-only output');
        $this->assertSame((string) \file_get_contents($goldenDir . 'caddy-standard-no-exclusions.golden'), (string) \file_get_contents($outputPathS), 'standard: Phase 2 must not touch root-only output');
    }

    // ── Path hygiene ─────────────────────────────────────────────────

    public function testTrailingSlashMountDeduplicatesToSinglePrefix(): void
    {
        $content = $this->compile(self::TRAILING_SLASH);

        $this->assertStringContainsString('path /app /app/*', $content);
        $this->assertStringNotContainsString('/app/ /app/*', $content);
    }

    public function testMultiSegmentMountScopesWithItsFullPrefix(): void
    {
        // set.inc.php:60 explode(fqdn,'/',2) makes `/team/app` a legitimate mount;
        // Apache consumes multi-segment mounts (RewriteRuleCompiler.php:202) —
        // Caddy scoping must not regress them into a throw.
        $content = $this->compile(['example.com' => ['/team/app' => 'coex_fake_dist']]);

        $this->assertStringContainsString('path /team/app /team/app/*', $content);
    }

    public function testUnsafeMountPathFailsLoudBeforeAnyEmission(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->compile(['example.com' => ['/bad segment' => 'coex_fake_dist']]);
    }

    public function testDotSegmentMountPathFailsLoud(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->compile(['example.com' => ['/team/../evil' => 'coex_fake_dist']]);
    }

    public function testBraceBearingMountPathFailsLoud(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->compile(['example.com' => ['/a{b' => 'coex_fake_dist']]);
    }

    /**
     * @param array<string, array<string, string>> $multisite
     */
    private function compile(array $multisite, bool $workerMode = true, array $excludePaths = []): string
    {
        $outputPath = $this->tmpDir . '/Caddyfile.' . \uniqid();
        (new CaddyfileCompiler())->compile($multisite, [], $outputPath, $workerMode, '/app/public', $excludePaths);

        return self::lf((string) \file_get_contents($outputPath));
    }
}
