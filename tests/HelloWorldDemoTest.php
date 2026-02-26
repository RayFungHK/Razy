<?php

/**
 * Unit tests for the Hello World minimal demo module.
 *
 * Validates file structure, module identity, controller registration,
 * and route handler — ensuring the demo stays minimal and correct.
 *
 * @package Razy\Tests
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
class HelloWorldDemoTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        // demo_modules/demo/hello_world relative to project root
        $this->moduleRoot = \dirname(__DIR__)
            . DIRECTORY_SEPARATOR . 'demo_modules'
            . DIRECTORY_SEPARATOR . 'demo'
            . DIRECTORY_SEPARATOR . 'hello_world';
    }

    // ── File existence ───────────────────────────────────────────

    #[Test]
    public function moduleDirectoryExists(): void
    {
        $this->assertDirectoryExists($this->moduleRoot);
    }

    #[Test]
    public function modulePhpExists(): void
    {
        $this->assertFileExists($this->moduleRoot . '/module.php');
    }

    #[Test]
    public function packagePhpExists(): void
    {
        $this->assertFileExists($this->moduleRoot . '/default/package.php');
    }

    #[Test]
    public function controllerFileExists(): void
    {
        $this->assertFileExists(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
    }

    #[Test]
    public function indexHandlerFileExists(): void
    {
        $this->assertFileExists(
            $this->moduleRoot . '/default/controller/hello_world.index.php',
        );
    }

    // ── module.php identity ──────────────────────────────────────

    #[Test]
    public function modulePhpReturnsArray(): void
    {
        $result = require $this->moduleRoot . '/module.php';
        $this->assertIsArray($result);
    }

    #[Test]
    public function modulePhpHasRequiredKeys(): void
    {
        $result = require $this->moduleRoot . '/module.php';
        foreach (['module_code', 'name', 'author', 'description', 'version'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    #[Test]
    public function moduleCodeMatchesDirectory(): void
    {
        $result = require $this->moduleRoot . '/module.php';
        $this->assertSame('demo/hello_world', $result['module_code']);
    }

    #[Test]
    public function moduleVersionIsSemver(): void
    {
        $result = require $this->moduleRoot . '/module.php';
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $result['version'],
        );
    }

    // ── package.php identity ────────────────────────────────────

    #[Test]
    public function packagePhpReturnsArray(): void
    {
        $result = require $this->moduleRoot . '/default/package.php';
        $this->assertIsArray($result);
    }

    #[Test]
    public function packageCodeMatchesModule(): void
    {
        $result = require $this->moduleRoot . '/default/package.php';
        $this->assertSame('demo/hello_world', $result['module_code']);
    }

    #[Test]
    public function packageVersionMatchesModule(): void
    {
        $mod = require $this->moduleRoot . '/module.php';
        $pkg = require $this->moduleRoot . '/default/package.php';
        $this->assertSame($mod['version'], $pkg['version']);
    }

    // ── Controller content ──────────────────────────────────────

    #[Test]
    public function controllerExtendsControllerClass(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringContainsString('extends Controller', $source);
    }

    #[Test]
    public function controllerHasOnInit(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringContainsString('__onInit', $source);
    }

    #[Test]
    public function controllerRegistersIndexRoute(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringContainsString("addRoute('/', 'index')", $source);
    }

    #[Test]
    public function controllerHasNoEventRegistration(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        // No events — this is a minimal module
        $this->assertStringNotContainsString('listen(', $source);
        $this->assertStringNotContainsString('trigger(', $source);
    }

    #[Test]
    public function controllerHasNoApiRegistration(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringNotContainsString('addAPI(', $source);
        $this->assertStringNotContainsString('addAPICommand(', $source);
    }

    #[Test]
    public function controllerHasNoTemplateUsage(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringNotContainsString('loadTemplate(', $source);
        $this->assertStringNotContainsString('Template::', $source);
    }

    // ── Route handler content ───────────────────────────────────

    #[Test]
    public function indexHandlerReturnsClosure(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.index.php',
        );
        $this->assertStringContainsString('return function', $source);
    }

    #[Test]
    public function indexHandlerOutputsHelloWorld(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.index.php',
        );
        $this->assertStringContainsString('Hello, World!', $source);
    }

    #[Test]
    public function indexHandlerIsMinimal(): void
    {
        // The handler file should be very short (< 30 lines)
        $lines = \file($this->moduleRoot . '/default/controller/hello_world.index.php');
        $this->assertLessThan(30, \count($lines), 'Handler should be minimal');
    }

    // ── Minimality: only 4 files ────────────────────────────────

    #[Test]
    public function moduleHasExactlyFourFiles(): void
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->moduleRoot,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        $this->assertCount(4, $files, 'Minimal demo should have exactly 4 files');
    }

    // ── Comments (educational) ──────────────────────────────────

    #[Test]
    public function modulePhpHasComments(): void
    {
        $source = \file_get_contents($this->moduleRoot . '/module.php');
        $this->assertStringContainsString('/*', $source);
    }

    #[Test]
    public function controllerHasComments(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.php',
        );
        $this->assertStringContainsString('/*', $source);
    }

    #[Test]
    public function handlerHasComments(): void
    {
        $source = \file_get_contents(
            $this->moduleRoot . '/default/controller/hello_world.index.php',
        );
        $this->assertStringContainsString('/*', $source);
    }

    // ── No template directory ───────────────────────────────────

    #[Test]
    public function noViewDirectoryExists(): void
    {
        $this->assertDirectoryDoesNotExist(
            $this->moduleRoot . '/default/view',
        );
    }

    #[Test]
    public function noTemplateDirectoryExists(): void
    {
        $this->assertDirectoryDoesNotExist(
            $this->moduleRoot . '/default/template',
        );
    }
}
