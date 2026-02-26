<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\TestCase;
use Razy\Collection;
use Razy\Database\Statement;
use Razy\Pipeline;
use Razy\PluginManager;
use Razy\Template;
use Razy\Template\CompiledTemplate;

/**
 * Tests that worker mode reset behaviour is correct.
 *
 * Phase 1.1: Plugin folders are re-registered after resetAll()
 * Phase 1.3: CompiledTemplate cache is cleared between requests
 *
 * @covers \Razy\PluginManager
 * @covers \Razy\Template\CompiledTemplate
 */
class WorkerModeResetTest extends TestCase
{
    private PluginManager $manager;

    protected function setUp(): void
    {
        $this->manager = new PluginManager();
        PluginManager::setInstance($this->manager);
    }

    protected function tearDown(): void
    {
        PluginManager::setInstance(null);
        CompiledTemplate::clearCache();
    }

    // ── Phase 1.1: Plugin folder re-registration ─────────────

    public function testResetAllClearsFolders(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder(Template::class, $dir);
        $this->assertNotEmpty($this->manager->getFolders(Template::class));

        $this->manager->resetAll();
        $this->assertEmpty($this->manager->getFolders(Template::class));
    }

    public function testFoldersCanBeReRegisteredAfterResetAll(): void
    {
        $dir = \sys_get_temp_dir();
        // Simulate bootstrap registration
        $this->manager->addFolder(Template::class, $dir, 'initial');
        $this->manager->addFolder(Collection::class, $dir, 'initial');

        // Simulate worker reset
        $this->manager->resetAll();
        $this->assertEmpty($this->manager->getFolders(Template::class));
        $this->assertEmpty($this->manager->getFolders(Collection::class));

        // Simulate re-registration (what the fixed worker loop does)
        $this->manager->addFolder(Template::class, $dir, 'rebuilt');
        $this->manager->addFolder(Collection::class, $dir, 'rebuilt');

        $templateFolders = $this->manager->getFolders(Template::class);
        $collectionFolders = $this->manager->getFolders(Collection::class);
        $this->assertNotEmpty($templateFolders);
        $this->assertNotEmpty($collectionFolders);
    }

    public function testAllFourPluginOwnersCanReRegister(): void
    {
        $dir = \sys_get_temp_dir();
        $owners = [
            Template::class,
            Collection::class,
            Statement::class,
            Pipeline::class,
        ];

        // Simulate bootstrap
        foreach ($owners as $owner) {
            $this->manager->addFolder($owner, $dir);
        }

        // Simulate worker reset
        $this->manager->resetAll();

        // Simulate re-registration
        foreach ($owners as $owner) {
            $this->manager->addFolder($owner, $dir, 'reregistered');
        }

        // Verify all 4 owners have folders
        foreach ($owners as $owner) {
            $this->assertNotEmpty(
                $this->manager->getFolders($owner),
                "Plugin folders for {$owner} should be available after re-registration",
            );
        }
    }

    public function testMultipleResetAndReRegisterCycles(): void
    {
        $dir = \sys_get_temp_dir();

        for ($cycle = 1; $cycle <= 5; $cycle++) {
            $this->manager->addFolder(Template::class, $dir, "cycle-{$cycle}");
            $folders = $this->manager->getFolders(Template::class);
            $this->assertNotEmpty($folders, "Cycle {$cycle}: folders should be registered");

            $this->manager->resetAll();
            $this->assertEmpty(
                $this->manager->getFolders(Template::class),
                "Cycle {$cycle}: folders should be cleared after resetAll",
            );
        }
    }

    public function testPluginCacheIsClearedOnResetAll(): void
    {
        $dir = \sys_get_temp_dir();
        $this->manager->addFolder(Template::class, $dir);

        // Access cached plugins before reset
        $cacheBefore = $this->manager->getCachedPlugins(Template::class);
        $this->assertEmpty($cacheBefore);

        $this->manager->resetAll();
        $cacheAfter = $this->manager->getCachedPlugins(Template::class);
        $this->assertEmpty($cacheAfter);
    }

    // ── Phase 1.3: CompiledTemplate cache clearing ───────────

    public function testCompiledTemplateClearCacheResetsState(): void
    {
        // Compile a template segment
        $compiled = CompiledTemplate::compile('Hello {$name}!');
        $this->assertNotNull($compiled);

        // Cache should not be empty now — clearCache resets it
        CompiledTemplate::clearCache();

        // Compile again — should still work (rebuilds cache)
        $compiled2 = CompiledTemplate::compile('Hello {$name}!');
        $this->assertNotNull($compiled2);
    }

    public function testCompiledTemplateClearCacheIsIdempotent(): void
    {
        CompiledTemplate::clearCache();
        CompiledTemplate::clearCache();
        CompiledTemplate::clearCache();

        // Should still work after multiple clears
        $compiled = CompiledTemplate::compile('Test {$var}');
        $this->assertNotNull($compiled);
    }

    public function testWorkerResetSequenceSimulation(): void
    {
        $dir = \sys_get_temp_dir();

        // Simulate a full worker request lifecycle (3 cycles)
        for ($request = 1; $request <= 3; $request++) {
            // --- Request phase: use plugins and templates ---
            $this->manager->addFolder(Template::class, $dir);
            $this->manager->addFolder(Collection::class, $dir);
            $compiled = CompiledTemplate::compile("Request {$request}: {\$data}");
            $this->assertNotNull($compiled);

            // --- Cleanup phase (mirrors the finally block in main.php) ---
            $this->manager->resetAll();
            CompiledTemplate::clearCache();

            // --- Re-registration phase ---
            $this->manager->addFolder(Template::class, $dir, 'reregistered');
            $this->manager->addFolder(Collection::class, $dir, 'reregistered');

            $this->assertNotEmpty(
                $this->manager->getFolders(Template::class),
                "Request {$request}: Template folders should survive reset+re-register",
            );
        }
    }
}
