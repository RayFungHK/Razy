<?php
/**
 * Tests for CaddyfileCompiler — Caddy/FrankenPHP configuration generator.
 *
 * Covers:
 * - buildAliasMap() — canonical domain to alias list mapping
 * - buildSiteAddress() — Caddy site address string building
 * - compile() — full template rendering with graceful error handling
 * - Template structure validation
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Routing\CaddyfileCompiler;
use ReflectionMethod;

#[CoversClass(CaddyfileCompiler::class)]
class CaddyfileCompilerTest extends TestCase
{
    private CaddyfileCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new CaddyfileCompiler();

        // Ensure PHAR_PATH is defined for template loading
        if (!defined('PHAR_PATH')) {
            define('PHAR_PATH', SYSTEM_ROOT . '/src');
        }
    }

    // ═══════════════════════════════════════════════════════
    //  buildAliasMap() tests
    // ═══════════════════════════════════════════════════════

    private function invokeBuildAliasMap(array $aliases, array $multisite): array
    {
        $method = new ReflectionMethod(CaddyfileCompiler::class, 'buildAliasMap');
        return $method->invoke($this->compiler, $aliases, $multisite);
    }

    public function testBuildAliasMapEmpty(): void
    {
        $result = $this->invokeBuildAliasMap([], []);
        $this->assertSame([], $result);
    }

    public function testBuildAliasMapSingleAlias(): void
    {
        $multisite = ['example.com' => ['/' => 'myapp']];
        $aliases = ['www.example.com' => 'example.com'];

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertArrayHasKey('example.com', $result);
        $this->assertSame(['www.example.com'], $result['example.com']);
    }

    public function testBuildAliasMapMultipleAliases(): void
    {
        $multisite = ['example.com' => ['/' => 'myapp']];
        $aliases = [
            'www.example.com' => 'example.com',
            'app.example.com' => 'example.com',
        ];

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertArrayHasKey('example.com', $result);
        $this->assertCount(2, $result['example.com']);
        $this->assertContains('www.example.com', $result['example.com']);
        $this->assertContains('app.example.com', $result['example.com']);
    }

    public function testBuildAliasMapIgnoresUnmatchedCanonical(): void
    {
        $multisite = ['example.com' => ['/' => 'myapp']];
        $aliases = ['www.other.com' => 'other.com']; // other.com not in multisite

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertArrayNotHasKey('other.com', $result);
        $this->assertSame([], $result);
    }

    public function testBuildAliasMapWildcardCanonical(): void
    {
        $multisite = ['*' => ['/' => 'fallback']];
        $aliases = ['catch.all' => '*'];

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertArrayHasKey('*', $result);
        $this->assertSame(['catch.all'], $result['*']);
    }

    public function testBuildAliasMapMultipleDomains(): void
    {
        $multisite = [
            'alpha.com' => ['/' => 'app_a'],
            'beta.com'  => ['/' => 'app_b'],
        ];
        $aliases = [
            'www.alpha.com' => 'alpha.com',
            'www.beta.com'  => 'beta.com',
        ];

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertArrayHasKey('alpha.com', $result);
        $this->assertArrayHasKey('beta.com', $result);
        $this->assertSame(['www.alpha.com'], $result['alpha.com']);
        $this->assertSame(['www.beta.com'], $result['beta.com']);
    }

    // ═══════════════════════════════════════════════════════
    //  buildSiteAddress() tests
    // ═══════════════════════════════════════════════════════

    private function invokeBuildSiteAddress(string $domain, array $aliases): string
    {
        $method = new ReflectionMethod(CaddyfileCompiler::class, 'buildSiteAddress');
        return $method->invoke($this->compiler, $domain, $aliases);
    }

    public function testBuildSiteAddressSingleDomain(): void
    {
        $result = $this->invokeBuildSiteAddress('example.com', []);
        $this->assertSame('example.com', $result);
    }

    public function testBuildSiteAddressDomainWithAliases(): void
    {
        $result = $this->invokeBuildSiteAddress('example.com', ['www.example.com', 'app.example.com']);
        $this->assertSame('example.com, www.example.com, app.example.com', $result);
    }

    public function testBuildSiteAddressWildcardBecomes80(): void
    {
        $result = $this->invokeBuildSiteAddress('*', []);
        $this->assertSame(':80', $result);
    }

    public function testBuildSiteAddressWildcardWithAliases(): void
    {
        $result = $this->invokeBuildSiteAddress('*', ['fallback.local']);
        $this->assertSame(':80, fallback.local', $result);
    }

    #[DataProvider('siteAddressProvider')]
    public function testBuildSiteAddressVariations(string $domain, array $aliases, string $expected): void
    {
        $result = $this->invokeBuildSiteAddress($domain, $aliases);
        $this->assertSame($expected, $result);
    }

    public static function siteAddressProvider(): array
    {
        return [
            'bare domain'           => ['example.com', [], 'example.com'],
            'single alias'          => ['example.com', ['www.example.com'], 'example.com, www.example.com'],
            'multiple aliases'      => ['test.io', ['a.test.io', 'b.test.io'], 'test.io, a.test.io, b.test.io'],
            'wildcard no alias'     => ['*', [], ':80'],
            'wildcard with alias'   => ['*', ['default.local'], ':80, default.local'],
            'subdomain'             => ['api.example.com', [], 'api.example.com'],
            'port in domain'        => ['localhost:8080', [], 'localhost:8080'],
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  compile() — output structure tests
    // ═══════════════════════════════════════════════════════

    public function testCompileCreatesOutputFile(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            // With non-existent distributor, the error is caught and skipped
            $result = $this->compiler->compile(
                ['example.com' => ['/' => 'nonexistent_dist']],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileOutputContainsGlobalOptions(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('frankenphp', $content);
            $this->assertStringContainsString('order php_server before file_server', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileOutputContainsDomainSiteBlock(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['mydomain.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,
                '/var/www',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('mydomain.com', $content);
            $this->assertStringContainsString('root * /var/www', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileWorkerModeIncludesWorkerDirective(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,  // worker mode ON
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('worker /app/public/index.php', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileStandardModeNoWorkerDirective(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                false, // worker mode OFF
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringNotContains('worker /app/public/index.php', $content);
            // Standard mode just has php_server without worker
            $this->assertStringContainsString('php_server', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileMultipleDomains(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                [
                    'alpha.com' => ['/' => 'app_a'],
                    'beta.com'  => ['/' => 'app_b'],
                ],
                [],
                $outputPath,
                true,
                '/srv/web',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('alpha.com', $content);
            $this->assertStringContainsString('beta.com', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileWithAliases(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                ['www.example.com' => 'example.com'],
                $outputPath,
                true,
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            // Site address should include both
            $this->assertStringContainsString('example.com, www.example.com', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileWildcardDomain(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['*' => ['/' => 'fallback_dist']],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString(':80', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileSharedModulesBlock(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $content = file_get_contents($outputPath);
            // Shared block should always be present
            $this->assertStringContainsString('@shared', $content);
            $this->assertStringContainsString('shared', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileCustomDocumentRoot(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,
                '/custom/root/path',
            );

            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('root * /custom/root/path', $content);
            $this->assertStringContainsString('worker /custom/root/path/index.php', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileDocumentRootTrailingSlashStripped(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $this->compiler->compile(
                ['example.com' => ['/' => 'test_dist']],
                [],
                $outputPath,
                true,
                '/app/public/',  // trailing slash
            );

            $content = file_get_contents($outputPath);
            // Should NOT have double-slash in root directive
            $this->assertStringNotContains('root * /app/public//', $content);
            $this->assertStringContainsString('root * /app/public', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileEmptyMultisiteStillCreatesFile(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $result = $this->compiler->compile(
                [],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);

            // Should have global options but no site blocks
            $content = file_get_contents($outputPath);
            $this->assertStringContainsString('frankenphp', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  compile() — non-existent distributors handled gracefully
    // ═══════════════════════════════════════════════════════

    public function testCompileHandlesNonExistentDistributor(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            // Should not throw — errors are caught internally
            $result = $this->compiler->compile(
                ['example.com' => ['/' => 'totally_fake_distributor_xyz']],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $this->assertTrue($result);
            $content = file_get_contents($outputPath);
            // Site block should still be generated, just no webasset/data handlers
            $this->assertStringContainsString('example.com', $content);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testCompileReturnsTrue(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $result = $this->compiler->compile(
                ['example.com' => ['/' => 'app']],
                [],
                $outputPath,
            );
            $this->assertTrue($result);
        } finally {
            @unlink($outputPath);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  Edge cases
    // ═══════════════════════════════════════════════════════

    public function testBuildAliasMapWithEmptyAliases(): void
    {
        $multisite = ['example.com' => ['/' => 'myapp']];
        $result = $this->invokeBuildAliasMap([], $multisite);
        $this->assertSame([], $result);
    }

    public function testBuildSiteAddressEmptyAliasArray(): void
    {
        $result = $this->invokeBuildSiteAddress('test.local', []);
        $this->assertSame('test.local', $result);
    }

    public function testBuildAliasMapDuplicateAliasesToSameCanonical(): void
    {
        $multisite = ['example.com' => ['/' => 'app']];
        $aliases = [
            'alias1.com' => 'example.com',
            'alias2.com' => 'example.com',
            'alias3.com' => 'example.com',
        ];

        $result = $this->invokeBuildAliasMap($aliases, $multisite);

        $this->assertCount(3, $result['example.com']);
    }

    public function testCompileMultiplePathsPerDomain(): void
    {
        $outputPath = sys_get_temp_dir() . '/razy_test_caddyfile_' . uniqid();

        try {
            $result = $this->compiler->compile(
                ['example.com' => [
                    '/'      => 'main_app',
                    '/admin' => 'admin_app',
                    '/api'   => 'api_app',
                ]],
                [],
                $outputPath,
                true,
                '/app/public',
            );

            $this->assertTrue($result);
            $this->assertFileExists($outputPath);
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * Helper to use assertStringNotContainsString with exact name.
     */
    private static function assertStringNotContains(string $needle, string $haystack): void
    {
        static::assertStringNotContainsString($needle, $haystack);
    }
}
