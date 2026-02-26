<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Razy\PluginManager;

/**
 * Tests for the PluginManager centralized plugin registry.
 *
 * @covers \Razy\PluginManager
 */
class PluginManagerTest extends TestCase
{
    private PluginManager $manager;

    protected function setUp(): void
    {
        $this->manager = new PluginManager();
        // Isolate singleton for each test
        PluginManager::setInstance($this->manager);
    }

    protected function tearDown(): void
    {
        PluginManager::setInstance(null);
    }

    // ── Singleton ────────────────────────────────────────────

    public function testGetInstanceReturnsSameInstance(): void
    {
        $a = PluginManager::getInstance();
        $b = PluginManager::getInstance();
        $this->assertSame($a, $b);
    }

    public function testSetInstanceReplacesGlobalInstance(): void
    {
        $custom = new PluginManager();
        PluginManager::setInstance($custom);
        $this->assertSame($custom, PluginManager::getInstance());
    }

    public function testSetInstanceNullCreatesNewOnNextGet(): void
    {
        $orig = PluginManager::getInstance();
        PluginManager::setInstance(null);
        $new = PluginManager::getInstance();
        $this->assertNotSame($orig, $new);
    }

    // ── addFolder ────────────────────────────────────────────

    public function testAddFolderRegistersValidDirectory(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir, ['key' => 'val']);
        $folders = $this->manager->getFolders('OwnerA');
        $this->assertArrayHasKey(
            \Razy\Util\PathUtil::tidy($dir),
            $folders,
        );
        $this->assertSame(['key' => 'val'], $folders[\Razy\Util\PathUtil::tidy($dir)]);
    }

    public function testAddFolderIgnoresNonExistentDirectory(): void
    {
        $this->manager->addFolder('OwnerA', '/nonexistent/path');
        $this->assertEmpty($this->manager->getFolders('OwnerA'));
    }

    public function testAddFolderIgnoresEmptyString(): void
    {
        $this->manager->addFolder('OwnerA', '');
        $this->assertEmpty($this->manager->getFolders('OwnerA'));
    }

    public function testAddFolderIgnoresWhitespaceOnly(): void
    {
        $this->manager->addFolder('OwnerA', '   ');
        $this->assertEmpty($this->manager->getFolders('OwnerA'));
    }

    public function testAddFolderMultipleFoldersSameOwner(): void
    {
        $dir1 = \sys_get_temp_dir();
        // Use a known subdirectory that exists
        $this->manager->addFolder('OwnerA', $dir1);
        $folders = $this->manager->getFolders('OwnerA');
        $this->assertCount(1, $folders);
    }

    public function testAddFolderDifferentOwners(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir, 'argsA');
        $this->manager->addFolder('OwnerB', $dir, 'argsB');
        $this->assertCount(1, $this->manager->getFolders('OwnerA'));
        $this->assertCount(1, $this->manager->getFolders('OwnerB'));
    }

    public function testAddFolderDefaultArgsIsNull(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir);
        $folders = $this->manager->getFolders('OwnerA');
        $this->assertNull($folders[\Razy\Util\PathUtil::tidy($dir)]);
    }

    // ── getPlugin ────────────────────────────────────────────

    public function testGetPluginReturnsNullWhenNoFolders(): void
    {
        $this->assertNull($this->manager->getPlugin('OwnerA', 'some.plugin'));
    }

    public function testGetPluginLoadsClosureFromFile(): void
    {
        // Create a temp plugin file
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'test.plugin.php';
        \file_put_contents($pluginFile, '<?php return function() { return "hello"; };');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir, 'myArgs');
            $result = $this->manager->getPlugin('OwnerA', 'test.plugin');

            $this->assertNotNull($result);
            $this->assertInstanceOf(Closure::class, $result['entity']);
            $this->assertSame('myArgs', $result['args']);
            $this->assertSame('hello', ($result['entity'])());
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    public function testGetPluginCachesResult(): void
    {
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'cached.plugin.php';
        \file_put_contents($pluginFile, '<?php return function() { return 42; };');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir);
            $first = $this->manager->getPlugin('OwnerA', 'cached.plugin');
            $second = $this->manager->getPlugin('OwnerA', 'cached.plugin');

            $this->assertSame($first, $second);
            // Verify it's in cache
            $this->assertArrayHasKey('cached.plugin', $this->manager->getCachedPlugins('OwnerA'));
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    public function testGetPluginReturnsNullForNonClosureReturn(): void
    {
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'nonclosure.plugin.php';
        \file_put_contents($pluginFile, '<?php return "not a closure";');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir);
            $result = $this->manager->getPlugin('OwnerA', 'nonclosure.plugin');
            $this->assertNull($result);
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    public function testGetPluginReturnsNullForNonexistentPlugin(): void
    {
        $this->manager->addFolder('OwnerA', \sys_get_temp_dir());
        $this->assertNull($this->manager->getPlugin('OwnerA', 'nonexistent.plugin'));
    }

    public function testGetPluginOwnerIsolation(): void
    {
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'isolated.plugin.php';
        \file_put_contents($pluginFile, '<?php return function() { return "ownerA"; };');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir);
            // OwnerB has no folders
            $this->assertNotNull($this->manager->getPlugin('OwnerA', 'isolated.plugin'));
            $this->assertNull($this->manager->getPlugin('OwnerB', 'isolated.plugin'));
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    public function testGetPluginThrowsOnThrowingFile(): void
    {
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'throwing.plugin.php';
        \file_put_contents($pluginFile, '<?php throw new \RuntimeException("broken plugin");');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir);
            $this->expectException(\Razy\Exception\ConfigurationException::class);
            $this->manager->getPlugin('OwnerA', 'throwing.plugin');
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    // ── reset / resetAll ─────────────────────────────────────

    public function testResetClearsSpecificOwner(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir);
        $this->manager->addFolder('OwnerB', $dir);

        $this->manager->reset('OwnerA');

        $this->assertEmpty($this->manager->getFolders('OwnerA'));
        $this->assertNotEmpty($this->manager->getFolders('OwnerB'));
    }

    public function testResetIdempotentOnNonexistentOwner(): void
    {
        // Should not throw
        $this->manager->reset('Nonexistent');
        $this->assertEmpty($this->manager->getFolders('Nonexistent'));
    }

    public function testResetAllClearsEverything(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir);
        $this->manager->addFolder('OwnerB', $dir);
        $this->manager->addFolder('OwnerC', $dir);

        $this->manager->resetAll();

        $this->assertEmpty($this->manager->getRegisteredOwners());
    }

    public function testResetAllIdempotent(): void
    {
        $this->manager->resetAll();
        $this->manager->resetAll();
        $this->assertEmpty($this->manager->getRegisteredOwners());
    }

    public function testResetClearsCachedPlugins(): void
    {
        $pluginDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_test_plugins_' . \uniqid();
        \mkdir($pluginDir, 0o777, true);
        $pluginFile = $pluginDir . DIRECTORY_SEPARATOR . 'resettable.plugin.php';
        \file_put_contents($pluginFile, '<?php return function() { return "data"; };');

        try {
            $this->manager->addFolder('OwnerA', $pluginDir);
            $this->manager->getPlugin('OwnerA', 'resettable.plugin');
            $this->assertNotEmpty($this->manager->getCachedPlugins('OwnerA'));

            $this->manager->reset('OwnerA');
            $this->assertEmpty($this->manager->getCachedPlugins('OwnerA'));
        } finally {
            @\unlink($pluginFile);
            @\rmdir($pluginDir);
        }
    }

    // ── Diagnostic accessors ─────────────────────────────────

    public function testGetFoldersEmptyForUnknownOwner(): void
    {
        $this->assertSame([], $this->manager->getFolders('Unknown'));
    }

    public function testGetCachedPluginsEmptyForUnknownOwner(): void
    {
        $this->assertSame([], $this->manager->getCachedPlugins('Unknown'));
    }

    public function testGetRegisteredOwnersEmptyByDefault(): void
    {
        $this->assertSame([], $this->manager->getRegisteredOwners());
    }

    public function testGetRegisteredOwnersReflectsAdded(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('Alpha', $dir);
        $this->manager->addFolder('Beta', $dir);
        $owners = $this->manager->getRegisteredOwners();
        $this->assertContains('Alpha', $owners);
        $this->assertContains('Beta', $owners);
        $this->assertCount(2, $owners);
    }

    // ── Re-registration after reset ──────────────────────────

    public function testCanReRegisterAfterReset(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir, 'first');
        $this->manager->reset('OwnerA');
        $this->manager->addFolder('OwnerA', $dir, 'second');

        $folders = $this->manager->getFolders('OwnerA');
        $this->assertSame('second', $folders[\Razy\Util\PathUtil::tidy($dir)]);
    }

    public function testCanReRegisterAfterResetAll(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder('OwnerA', $dir);
        $this->manager->resetAll();
        $this->manager->addFolder('OwnerA', $dir, 'rebuilt');

        $folders = $this->manager->getFolders('OwnerA');
        $this->assertNotEmpty($folders);
    }
}
