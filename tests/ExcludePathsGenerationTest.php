<?php

/**
 * Tests for declared sibling exclusions (Route Coexistence Phase 1).
 *
 * Covers the host-level `exclude_paths` config key end-to-end at generator
 * level (no Apache/Caddy binaries involved):
 * - ExcludePaths::normalize() validation (leading slash, regex chars,
 *   reserved first segments, non-strings) and tidy/dedup behaviour
 * - Apache output: exact passthrough lines, ordered before every claim
 * - Caddy output: php_server matcher gate in worker + standard modes
 * - Regression guard: empty/absent config ⇒ byte-identical legacy output
 *   (compared against tests/fixtures/coexistence/*.golden captured from the
 *   pre-Phase-1 generators)
 *
 * This file is part of Razy v1.0.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\ConfigurationException;
use Razy\Routing\CaddyfileCompiler;
use Razy\Routing\ExcludePaths;
use Razy\Routing\RewriteRuleCompiler;

#[CoversClass(ExcludePaths::class)]
#[CoversClass(RewriteRuleCompiler::class)]
#[CoversClass(CaddyfileCompiler::class)]
class ExcludePathsGenerationTest extends TestCase
{
    /** Fixture multisite shape: fake distributor => only domain-detection claims render */
    private const MULTISITE = ['example.com' => ['/' => 'coex_fake_dist']];

    private string $tmpDir = '';

    protected function setUp(): void
    {
        // Ensure PHAR_PATH is defined for template loading (same contract as CaddyfileCompilerTest)
        if (!\defined('PHAR_PATH')) {
            \define('PHAR_PATH', SYSTEM_ROOT . '/src');
        }

        $this->tmpDir = \sys_get_temp_dir() . '/razy_exclude_paths_' . \uniqid();
        \mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->tmpDir . '/*') as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tmpDir);
    }

    // ═══════════════════════════════════════════════════════
    //  ExcludePaths::normalize() validation
    // ═══════════════════════════════════════════════════════

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEntryProvider(): array
    {
        return [
            'missing leading slash' => ['api-py'],
            'relative dotdot' => ['../api-py'],
            'regex star' => ['/api-py.*'],
            'regex group' => ['/api(py)?'],
            'regex pipe' => ['/a|b'],
            'regex plus' => ['/a+'],
            'regex brackets' => ['/a[0-9]'],
            'backslash' => ['/api\py'],
            'quote' => ["/api'py"],
            'double slash' => ['//api-py'],
            'whole host' => ['/'],
            'empty string' => [''],
            'reserved shared' => ['/shared'],
            'reserved index.php' => ['/index.php'],
            'reserved data subdir' => ['/data/x'],
            'reserved webassets' => ['/webassets'],
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Normalize CRLF to LF for snippet-level assertions only (generated output
     * follows the checked-out template's newline style); the byte-identical
     * golden comparison deliberately stays raw.
     */
    private static function lf(string $content): string
    {
        return \str_replace(["\r\n", "\r"], "\n", $content);
    }

    // ═══════════════════════════════════════════════════════
    //  Apache (.htaccess) emission
    // ═══════════════════════════════════════════════════════

    public function testApacheEmitsExactPassthroughLinesPerPrefix(): void
    {
        $content = self::lf($this->compileApache(['/api-py', '/reporting/']));

        $expected = "# Declared sibling prefix /api-py: never claimed by Razy (sites.inc.php 'exclude_paths' — see manual/08-coexistence.md).\n"
            . "# REQUEST_URI carries the full path; per-directory patterns below see the prefix-stripped path.\n"
            . "RewriteCond %{REQUEST_URI} ^/api-py(/|\$)\n"
            . "RewriteRule ^ - [L]\n";

        $this->assertStringContainsString($expected, $content, 'exact passthrough pair for /api-py');
        $this->assertStringContainsString(
            "RewriteCond %{REQUEST_URI} ^/reporting(/|\$)\nRewriteRule ^ - [L]",
            $content,
            'trailing-slash config entry tidied to /reporting',
        );
    }

    public function testApacheExclusionsPrecedeEveryClaim(): void
    {
        $content = self::lf($this->compileApache(['/api-py']));

        $exclusionPos = \strpos($content, 'RewriteCond %{REQUEST_URI} ^/api-py(/|$)');
        $this->assertIsInt($exclusionPos, 'exclusion rule must exist');
        $this->assertLessThan(
            (int) \strpos($content, '# Rewrite the shared module location'),
            $exclusionPos,
            'exclusion must precede the global shared rule',
        );
        $this->assertLessThan(
            (int) \strpos($content, 'RewriteCond %{HTTP_HOST}'),
            $exclusionPos,
            'exclusion must precede the domain gate',
        );
    }

    public function testApacheEscapesDotInConditionPattern(): void
    {
        $content = self::lf($this->compileApache(['/v2.0_svc']));

        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} ^/v2\.0_svc(/|$)', $content);
        $this->assertStringNotContainsString('^/v2.0_svc(/|$)', $content, 'bare dot must not survive unescaped');
    }

    // ═══════════════════════════════════════════════════════
    //  Caddy (Caddyfile) emission
    // ═══════════════════════════════════════════════════════

    public function testCaddyWorkerModeGatesPhpServerMatcher(): void
    {
        $content = self::lf($this->compileCaddy(['/api-py', '/reporting'], true));

        $this->assertStringContainsString(
            '@not_excluded not path /api-py /api-py/* /reporting /reporting/*',
            $content,
            'named matcher must list bare prefix and prefix/* (Caddy path matchers are exact)',
        );
        $this->assertStringContainsString("php_server @not_excluded {\n\t\tworker /app/public/index.php\n\t}", $content);
    }

    public function testCaddyStandardModeGatesPhpServerMatcher(): void
    {
        $content = self::lf($this->compileCaddy(['/api-py'], false));

        $this->assertStringContainsString('@not_excluded not path /api-py /api-py/*', $content);
        $this->assertStringContainsString("\tphp_server @not_excluded\n", $content);
        $this->assertStringNotContainsString('worker ', $content, 'no worker directive in standard mode');
        $this->assertStringNotContainsString('reverse_proxy', $content, 'decision Q3: never emit reverse_proxy for excluded paths');
    }

    public function testCaddyExclusionMatcherAppliesToEverySiteBlock(): void
    {
        $outputPath = $this->tmpDir . '/Caddyfile.multi';

        (new CaddyfileCompiler())->compile(
            ['alpha.com' => ['/' => 'coex_fake_dist'], 'beta.com' => ['/' => 'coex_fake_dist']],
            [],
            $outputPath,
            true,
            '/app/public',
            ['/api-py'],
        );

        $content = (string) \file_get_contents($outputPath);
        $this->assertSame(2, \substr_count($content, '@not_excluded not path /api-py /api-py/*'));
        $this->assertSame(2, \substr_count($content, 'php_server @not_excluded {'));
    }

    // ═══════════════════════════════════════════════════════
    //  Regression guard — empty/absent config ⇒ byte-identical output
    // ═══════════════════════════════════════════════════════

    public function testEmptyAndAbsentExclusionsAreByteIdenticalToLegacyOutput(): void
    {
        $goldenDir = __DIR__ . '/fixtures/coexistence/';

        // Absent argument (default) and explicit empty list must both match the
        // pre-Phase-1 goldens captured from the shipped generators.
        $cases = [
            'apache' => [
                'golden' => $goldenDir . 'apache-no-exclusions.golden',
                'omitted' => $this->compileApacheRaw([]),
                'empty' => $this->compileApacheRaw([[]]),
            ],
            'caddy-worker' => [
                'golden' => $goldenDir . 'caddy-worker-no-exclusions.golden',
                'omitted' => $this->compileCaddyRaw([]),
                'empty' => $this->compileCaddyRaw([[]]),
            ],
            'caddy-standard' => [
                'golden' => $goldenDir . 'caddy-standard-no-exclusions.golden',
                'omitted' => $this->compileCaddyStandardRaw(),
                'empty' => $this->compileCaddyStandardRaw([[]]),
            ],
        ];

        foreach ($cases as $label => $case) {
            $golden = (string) \file_get_contents($case['golden']);
            $this->assertSame($golden, $case['omitted'], "{$label}: omitted argument must be byte-identical to legacy golden");
            $this->assertSame($golden, $case['empty'], "{$label}: explicit [] must be byte-identical to legacy golden");
        }
    }

    #[DataProvider('invalidEntryProvider')]
    public function testNormalizeRejectsWithClearMessage(string $entry): void
    {
        try {
            ExcludePaths::normalize([$entry]);
            $this->fail('entry ' . \var_export($entry, true) . ' must be rejected');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('exclude_paths', $e->getMessage());
            $this->assertStringContainsString($entry, $e->getMessage(), 'message must name the offending entry');
        }
    }

    public function testNormalizeRejectsNonListAndNonStrings(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be an array');
        /** @phpstan-ignore-next-line intentional misuse test */
        ExcludePaths::normalize('/api-py');
    }

    public function testNormalizeRejectsIntegerEntry(): void
    {
        try {
            /** @phpstan-ignore-next-line intentional misuse test */
            ExcludePaths::normalize([42]);
            $this->fail('integer entry must be rejected');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('string', $e->getMessage());
        }
    }

    public function testNormalizeTidiesTrailingSlashAndDeduplicates(): void
    {
        $this->assertSame(
            ['/api-py', '/reporting'],
            ExcludePaths::normalize(['/api-py/', ' /api-py ', '/api-py', '/reporting/']),
        );
    }

    public function testNormalizeEmptyIsNoOp(): void
    {
        $this->assertSame([], ExcludePaths::normalize([]));
        $this->assertSame([], ExcludePaths::normalize(null));
    }

    public function testApachePatternEscapesOnlyDots(): void
    {
        $this->assertSame('/api-py', ExcludePaths::apachePattern('/api-py'));
        $this->assertSame('/v2\.0_svc', ExcludePaths::apachePattern('/v2.0_svc'));
    }

    public function testCaddyPathsPairsBareAndWildcard(): void
    {
        $this->assertSame('/api-py /api-py/* /reporting /reporting/*', ExcludePaths::caddyPaths(['/api-py', '/reporting']));
        $this->assertSame('', ExcludePaths::caddyPaths([]));
    }

    private function compileApache(array $excludePaths): string
    {
        return $this->compileApacheRaw([$excludePaths]);
    }

    /**
     * @param array<array> $args full compile() args (empty list = omit excludePaths)
     */
    private function compileApacheRaw(array $args): string
    {
        $outputPath = $this->tmpDir . '/.htaccess.' . \uniqid();

        if ($args === []) {
            (new RewriteRuleCompiler())->compile(self::MULTISITE, [], $outputPath);
        } else {
            (new RewriteRuleCompiler())->compile(self::MULTISITE, [], $outputPath, ...$args);
        }

        return (string) \file_get_contents($outputPath);
    }

    private function compileCaddy(array $excludePaths, bool $workerMode): string
    {
        $outputPath = $this->tmpDir . '/Caddyfile.' . \uniqid();
        (new CaddyfileCompiler())->compile(self::MULTISITE, [], $outputPath, $workerMode, '/app/public', $excludePaths);

        return (string) \file_get_contents($outputPath);
    }

    /**
     * @param array<array> $args trailing args beyond documentRoot (empty = omit excludePaths)
     */
    private function compileCaddyRaw(array $args): string
    {
        $outputPath = $this->tmpDir . '/Caddyfile.w' . \uniqid();

        if ($args === []) {
            (new CaddyfileCompiler())->compile(self::MULTISITE, [], $outputPath, true, '/app/public');
        } else {
            (new CaddyfileCompiler())->compile(self::MULTISITE, [], $outputPath, true, '/app/public', ...$args);
        }

        return (string) \file_get_contents($outputPath);
    }

    /**
     * @param array<array> $args trailing args beyond documentRoot (empty = omit excludePaths)
     */
    private function compileCaddyStandardRaw(array $args = []): string
    {
        $outputPath = $this->tmpDir . '/Caddyfile.s' . \uniqid();
        (new CaddyfileCompiler())->compile(self::MULTISITE, [], $outputPath, false, '/app/public', ...$args);

        return (string) \file_get_contents($outputPath);
    }
}
