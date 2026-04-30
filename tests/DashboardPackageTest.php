<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\TestCase;
use Razy\Package\PackageManifest;

/**
 * Integration tests for the Dashboard standalone package.
 *
 * Tests cover:
 *   1. PackageManifest loading from the dashboard directory
 *   2. Controller structure and PackageTrait usage
 *   3. Router API endpoint responses
 *   4. Dashboard HTML asset availability
 */
class DashboardPackageTest extends TestCase
{
    private string $dashboardDir;

    private string $controllerDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardDir = \dirname(__DIR__) . '/packages/dashboard';
        $this->controllerDir = $this->dashboardDir . '/controller';
    }

    // ==================== MANIFEST ====================

    public function testManifestFileExists(): void
    {
        $this->assertFileExists($this->dashboardDir . '/razy.pkg.json');
    }

    public function testManifestIsValidJson(): void
    {
        $raw = \file_get_contents($this->dashboardDir . '/razy.pkg.json');
        $data = \json_decode($raw, true);
        $this->assertIsArray($data, 'razy.pkg.json should decode to an array');
        $this->assertArrayHasKey('package_name', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('mode', $data);
    }

    public function testManifestPackageName(): void
    {
        $manifest = $this->loadManifest();
        $this->assertSame('dashboard', $manifest->getPackageName());
    }

    public function testManifestVersion(): void
    {
        $manifest = $this->loadManifest();
        $this->assertSame('1.0.0', $manifest->getVersion());
    }

    public function testManifestServMode(): void
    {
        $manifest = $this->loadManifest();
        $this->assertSame('serve', $manifest->getMode());
    }

    public function testManifestIsStrict(): void
    {
        $manifest = $this->loadManifest();
        $this->assertTrue($manifest->isStrict());
    }

    public function testManifestHealthcheck(): void
    {
        $manifest = $this->loadManifest();
        $hc = $manifest->getHealthcheck();
        $this->assertIsArray($hc);
        $this->assertArrayHasKey('url', $hc);
        $this->assertStringContainsString('/api/health', $hc['url']);
        $this->assertArrayHasKey('interval', $hc);
        $this->assertArrayHasKey('timeout', $hc);
    }

    public function testManifestFromDirectory(): void
    {
        $manifest = PackageManifest::fromDirectory($this->dashboardDir);
        $this->assertInstanceOf(PackageManifest::class, $manifest);
        $this->assertTrue($manifest->isDirectory());
        $this->assertSame($this->dashboardDir, $manifest->getSourcePath());
    }

    // ==================== CONTROLLER STRUCTURE ====================

    public function testControllerFileExists(): void
    {
        $this->assertFileExists($this->controllerDir . '/app.php');
    }

    public function testControllerReturnsAnonymousClass(): void
    {
        // We cannot include the controller directly because it extends
        // Controller (which requires an Agent in the constructor). Instead,
        // we verify the file contains the expected declaration.
        $source = \file_get_contents($this->controllerDir . '/app.php');
        $this->assertStringContainsString('extends Controller', $source);
        $this->assertStringContainsString('use PackageTrait', $source);
        $this->assertStringContainsString('return new class', $source);
    }

    public function testControllerDefinesLifecycleHooks(): void
    {
        $source = \file_get_contents($this->controllerDir . '/app.php');
        $this->assertStringContainsString('__onPackageStart', $source);
        $this->assertStringContainsString('__onPackageServe', $source);
        $this->assertStringContainsString('__onPackageStop', $source);
        $this->assertStringContainsString('__onPackageHealthcheck', $source);
    }

    public function testControllerNamespace(): void
    {
        $source = \file_get_contents($this->controllerDir . '/app.php');
        // Standalone bootstrap synthesises module_code 'standalone/app', so
        // the namespace should be Razy\Module\standalone_app.
        $this->assertStringContainsString('namespace Razy\Module\standalone_app', $source);
    }

    // ==================== ROUTER ====================

    public function testRouterFileExists(): void
    {
        $this->assertFileExists($this->controllerDir . '/router.php');
    }

    public function testRouterDefinesApiEndpoints(): void
    {
        $source = \file_get_contents($this->controllerDir . '/router.php');
        $this->assertStringContainsString('/api/health', $source);
        $this->assertStringContainsString('/api/packages', $source);
        $this->assertStringContainsString('/api/system', $source);
        $this->assertStringContainsString('/api/package/', $source);
        $this->assertStringContainsString('/api/version', $source);
        $this->assertStringContainsString('/api/sites', $source);
        $this->assertStringContainsString('/api/cache/status', $source);
        $this->assertStringContainsString('/api/cache/stats', $source);
        $this->assertStringContainsString('/api/cache/clear', $source);
        $this->assertStringContainsString('/api/cache/gc', $source);
        $this->assertStringContainsString('/api/inspect/', $source);
        $this->assertStringContainsString('/api/routes/', $source);
        $this->assertStringContainsString('/api/validate/', $source);
        $this->assertStringContainsString('/api/cli', $source);
    }

    /**
     * Test the router's health endpoint function directly.
     */
    public function testRouterHealthFunction(): void
    {
        // Include router functions in an isolated way — the router returns
        // true at the top level, so we need to extract functions manually.
        // We test functions by sourcing the file in a subprocess.
        $result = $this->callRouterEndpoint('/api/health');
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('time', $result);
    }

    /**
     * Test the packages endpoint scans the packages directory.
     */
    public function testRouterPackagesEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/packages');
        $this->assertArrayHasKey('packages', $result);
        $this->assertIsArray($result['packages']);
        $this->assertArrayHasKey('count', $result);

        // Dashboard itself should appear (it's a directory package)
        $names = \array_column($result['packages'], 'package_name');
        $this->assertContains('dashboard', $names, 'Dashboard should appear in its own package list');
    }

    /**
     * Test the system endpoint returns required fields.
     */
    public function testRouterSystemEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/system');
        $this->assertArrayHasKey('php_version', $result);
        $this->assertArrayHasKey('os', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('razy_phar', $result);
        $this->assertArrayHasKey('memory_limit', $result);
        $this->assertSame(PHP_VERSION, $result['php_version']);
    }

    /**
     * Test the package detail endpoint for the dashboard package.
     */
    public function testRouterPackageDetailEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/package/dashboard');
        $this->assertArrayHasKey('package', $result);
        $this->assertSame('dashboard', $result['package']['package_name']);
        $this->assertSame('directory', $result['package']['source_type']);
    }

    /**
     * Test unknown package returns error.
     */
    public function testRouterPackageDetailNotFound(): void
    {
        $result = $this->callRouterEndpoint('/api/package/nonexistent-xyz');
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test the version endpoint returns version string.
     */
    public function testRouterVersionEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/version');
        $this->assertArrayHasKey('version', $result);
        $this->assertIsString($result['version']);
    }

    /**
     * Test the sites endpoint structure.
     */
    public function testRouterSitesEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/sites');
        // Should have at least the distributors key (even if empty or error)
        $this->assertTrue(
            \array_key_exists('distributors', $result) || \array_key_exists('error', $result),
            'Sites endpoint must return distributors or error key'
        );
    }

    /**
     * Test the cache/status endpoint structure.
     */
    public function testRouterCacheStatusEndpoint(): void
    {
        $result = $this->callRouterEndpoint('/api/cache/status');
        $this->assertArrayHasKey('command', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertIsArray($result['output']);
    }

    /**
     * Test the router contains CLI proxy with allowlist.
     */
    public function testRouterCliProxyAllowlist(): void
    {
        $source = \file_get_contents($this->controllerDir . '/router.php');
        // Verify allowlist is defined in router
        $this->assertStringContainsString('version', $source);
        $this->assertStringContainsString('help', $source);
        $this->assertStringContainsString('cache', $source);
        $this->assertStringContainsString('inspect', $source);
        $this->assertStringContainsString('routes', $source);
        $this->assertStringContainsString('validate', $source);
        $this->assertStringContainsString('pkg', $source);
        $this->assertStringContainsString('search', $source);
    }

    // ==================== ASSETS ====================

    public function testAssetsDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->controllerDir . '/assets');
    }

    public function testIndexHtmlExists(): void
    {
        $this->assertFileExists($this->controllerDir . '/assets/index.html');
    }

    public function testIndexHtmlIsValidHtml(): void
    {
        $html = \file_get_contents($this->controllerDir . '/assets/index.html');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringContainsString('Razy', $html);
        $this->assertStringContainsString('Dashboard', $html);
    }

    public function testIndexHtmlHasApiCalls(): void
    {
        $html = \file_get_contents($this->controllerDir . '/assets/index.html');
        $this->assertStringContainsString('/api/health', $html);
        $this->assertStringContainsString('/api/packages', $html);
        $this->assertStringContainsString('/api/system', $html);
        $this->assertStringContainsString('/api/sites', $html);
        $this->assertStringContainsString('/api/cache/status', $html);
        $this->assertStringContainsString('/api/cli', $html);
    }

    public function testIndexHtmlHasTabs(): void
    {
        $html = \file_get_contents($this->controllerDir . '/assets/index.html');
        $this->assertStringContainsString('data-tab="overview"', $html);
        $this->assertStringContainsString('data-tab="packages"', $html);
        $this->assertStringContainsString('data-tab="sites"', $html);
        $this->assertStringContainsString('data-tab="cache"', $html);
        $this->assertStringContainsString('data-tab="inspector"', $html);
        $this->assertStringContainsString('data-tab="terminal"', $html);
    }

    // ==================== PACKAGE TRAIT INTEGRATION ====================

    /**
     * Verify that PackageTrait's resetPackageRegistry is callable
     * and works correctly (ensuring test isolation).
     */
    public function testResetPackageRegistry(): void
    {
        // PackageTrait is a trait — we can't call methods on it directly.
        // But we can verify the class exists and is a trait.
        $this->assertTrue(\trait_exists(\Razy\PackageTrait::class));

        // Call resetPackageRegistry via an anonymous class
        $obj = new class() {
            use \Razy\PackageTrait;

            public function doReset(): void
            {
                self::resetPackageRegistry();
            }
        };

        // Should not throw
        $obj->doReset();
        $this->assertTrue(true);
    }

    // ==================== HELPERS ====================

    private function loadManifest(): PackageManifest
    {
        return PackageManifest::fromDirectory($this->dashboardDir);
    }

    /**
     * Call a router API endpoint by running router.php in a subprocess
     * with the proper environment variables and simulated REQUEST_URI.
     *
     * @param string $uri The URI to request (e.g. /api/health)
     *
     * @return array<string, mixed> Decoded JSON response
     */
    private function callRouterEndpoint(string $uri): array
    {
        $routerPath = $this->controllerDir . '/router.php';
        $projectRoot = \dirname(__DIR__);
        $pkgDir = $projectRoot . '/packages';
        $assetsDir = $this->controllerDir . '/assets';

        // PHP wrapper that sets up the environment and includes the router
        $wrapper = <<<'PHP'
<?php
// Simulate web server environment
$_SERVER['REQUEST_URI'] = $argv[1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_TIME'] = time();

// Set env vars
putenv('RAZY_DASHBOARD_PROJECT_ROOT=' . $argv[2]);
putenv('RAZY_DASHBOARD_PKG_DIR=' . $argv[3]);
putenv('RAZY_DASHBOARD_ASSETS=' . $argv[4]);

// Capture output
ob_start();
$result = require $argv[5];
$output = ob_get_clean();

// The router echoes JSON for API endpoints
echo $output;
PHP;

        $wrapperFile = \sys_get_temp_dir() . '/razy_dashboard_test_' . \md5($uri) . '.php';
        \file_put_contents($wrapperFile, $wrapper);

        $phpBin = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = \sprintf(
            '%s %s %s %s %s %s %s',
            \escapeshellarg($phpBin),
            \escapeshellarg($wrapperFile),
            \escapeshellarg($uri),
            \escapeshellarg($projectRoot),
            \escapeshellarg($pkgDir),
            \escapeshellarg($assetsDir),
            \escapeshellarg($routerPath),
        );

        $output = \shell_exec($cmd);

        @\unlink($wrapperFile);

        $this->assertNotNull($output, "Router returned no output for {$uri}");

        $data = \json_decode($output, true);
        $this->assertIsArray($data, "Router output is not valid JSON for {$uri}: " . ($output ?? '(null)'));

        return $data;
    }
}
