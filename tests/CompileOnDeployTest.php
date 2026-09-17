<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Compiler\BootCompiler;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\Route;
use Razy\Util\PathUtil;
use ReflectionProperty;

/**
 * COMPILE-ON-DEPLOY (M2) — the deploy-time boot snapshot: dump captures
 * declaration tables, replay rebuilds them without __onInit (RZ-009 is the
 * contract that makes the exchange legal), and every failure mode degrades
 * to the full legacy boot, never to wrong traffic. The end-to-end proof on
 * a real dist (benchgate, 4 modules) is the replay self-proof inside the
 * compile command itself; these are the unit gates + wiring pins.
 */
#[CoversNothing]
final class CompileOnDeployTest extends TestCase
{
    private string $tempDir;

    private string $modulePath;

    /** @var list<string> extra dirs/files to sweep */
    private array $sweep = [];

    protected function setUp(): void
    {
        parent::setUp();

        // minimal real module tree (ModuleTest pattern)
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_compile_test_' . \uniqid();
        $this->modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_module';
        $versionDir = $this->modulePath . DIRECTORY_SEPARATOR . 'default';
        $controllerDir = $versionDir . DIRECTORY_SEPARATOR . 'controller';
        \mkdir($controllerDir, 0o777, true);

        \file_put_contents($this->modulePath . DIRECTORY_SEPARATOR . 'module.php', '<?php return [
            "module_code" => "test/TestModule",
            "author" => "Test Author",
            "description" => "Test module",
        ];');
        \file_put_contents($versionDir . DIRECTORY_SEPARATOR . 'package.php', '<?php return [
            "alias" => "testmod",
        ];');
        // __onInit counts its runs globally — the replay contract is that it
        // is NOT re-run, and a per-anonymous-class static cannot observe that
        // across two includes of the same file.
        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'TestModule.php', '<?php
use Razy\Controller;
return new class(null) extends Controller {
    public function __onInit($agent): bool { $GLOBALS["rz_oninit_runs"] = ($GLOBALS["rz_oninit_runs"] ?? 0) + 1; return true; }
    public function __onLoad($agent): bool { return true; }
    public function __onReady(): void {}
    public function __onRequire(): bool { return true; }
    public function __onDispose(): void {}
};');
        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'internal.php', '<?php return function () {};');

        $GLOBALS['rz_oninit_runs'] = 0;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['rz_oninit_runs']);
        foreach ($this->sweep as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        $this->removeDir($this->tempDir);
        $this->sweep = [];
        parent::tearDown();
    }

    // ── declaration dump / replay ─────────────────────────────────────────

    public function testDumpCapturesStringDeclarationsAndRefusesClosures(): void
    {
        $module = $this->createModule();
        $module->addAPICommand('doit', 'api/doit');
        $module->addBridgeCommand('fetch', 'api/fetch');
        $module->bind('internalDo', 'internal');
        $module->listen('test/TestModule:ping', 'closures/evt');
        $module->listen('test/TestModule:hook', function () {
        }); // Closures refuse, never vanish

        $decl = $module->dumpDeclarations();

        self::assertSame(['doit' => 'api/doit'], $decl['api']);
        self::assertSame(['fetch' => 'api/fetch'], $decl['bridge']);
        self::assertSame(['internalDo' => 'internal'], $decl['bindings']);
        self::assertSame(['test/TestModule:ping' => 'closures/evt'], $decl['events'], 'the FULL vendor/module:event key must survive the dump');
        self::assertCount(1, $decl['_refusals'], 'the closure listener must surface as a refusal');
        self::assertStringContainsString('closure listener', $decl['_refusals'][0]);
    }

    public function testApplyDeclarationsRebuildsWithoutRunningOnInit(): void
    {
        $source = $this->createModule();
        $source->addAPICommand('doit', 'api/doit');
        $source->bind('internalDo', 'internal');
        $source->listen('test/TestModule:ping', 'closures/evt');
        $decl = $source->dumpDeclarations();

        // a fresh module shell (never initialized, controller null)
        $replay = $this->createModule();
        self::assertNull($this->readPrivate($replay, 'controller'));
        self::assertSame(0, $GLOBALS['rz_oninit_runs'], 'fixture sanity: nothing booted yet');

        $replay->applyDeclarations($decl);

        self::assertInstanceOf(\Razy\Controller::class, $this->readPrivate($replay, 'controller'), 'the controller is rebuilt');
        self::assertSame(0, $GLOBALS['rz_oninit_runs'], 'RZ-009 exchange: declarations replay WITHOUT re-running __onInit');
        self::assertSame(ModuleStatus::InQueue, $replay->getStatus());
        self::assertSame(['doit' => 'api/doit'], $replay->getAPICommands());
        self::assertSame('internal', $this->readPrivate($replay, 'closureLoader')->getBinding('internalDo'));
        self::assertSame(
            ['test/TestModule' => ['ping' => 'closures/evt']],
            $this->readPrivate($replay, 'eventDispatcher')->getRegistrations()['events'],
            'the event replays into the real dispatcher with its event name intact',
        );
    }

    // ── route table rehydration ───────────────────────────────────────────

    public function testLoadCompiledRehydratesRouteEntitiesAndLinksModules(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModule();

        $rows = [
            'GET:/x/' => [
                'module' => 'test/TestModule',
                'module_code' => 'test/TestModule',
                'path' => ['__route' => [
                    'closure_path' => 'ping',
                    'method' => 'GET',
                    'name' => 'x-route',
                    'ready_gate' => null,
                    'csrf_exempt' => null,
                    'middleware' => [],
                    'data' => ['k' => 1],
                ]],
                'route' => '/x/',
                'route_path' => '/x/',
                'type' => 'standard',
                'method' => 'GET',
                'compiled_regex' => '#^x$#',
                'is_redirect' => false,
                'redirect_absolute' => false,
                'redirect_target' => '',
                'tidied_path' => 'x',
                'tidied_route' => '/x/',
                'ready_gate' => null,
            ],
        ];

        $dispatcher->loadCompiled($rows, ['test/TestModule' => $module]);
        $dispatcher->loadCompiledNames(['x-route' => 'GET:/x/']);

        $routes = $dispatcher->getRoutes();
        self::assertArrayHasKey('GET:/x/', $routes);
        $row = $routes['GET:/x/'];
        self::assertSame($module, $row['module'], 'the live Module object is re-linked by code');
        self::assertInstanceOf(Route::class, $row['path']);
        self::assertSame('ping', $row['path']->getClosurePath());
        self::assertSame('GET', $row['path']->getMethod());
        self::assertSame(['k' => 1], $row['path']->getData());
        self::assertSame(['x-route' => 'GET:/x/'], $dispatcher->getNamedRoutes());
    }

    // ── artifact naming / fingerprint / usability ─────────────────────────

    public function testArtifactPathFilesWildcardTagAsUnderscore(): void
    {
        // a literal '*' is not a legal filename on Windows-family systems
        self::assertStringEndsWith('mydist@_.php', BootCompiler::artifactPath('mydist', '*'));
        self::assertStringEndsWith('mydist@v2.php', BootCompiler::artifactPath('mydist', 'v2'));
    }

    public function testFingerprintIsStableThenMovesWithFileEdits(): void
    {
        $dist = 'compiletest_dist';
        $dir = PathUtil::append(SITES_FOLDER, $dist);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o777, true);
        }
        $file = PathUtil::append($dir, 'dist.php');
        \file_put_contents($file, '<?php return [];');
        $this->sweep[] = $file;
        \clearstatcache();

        $before = BootCompiler::fingerprint($dist);
        self::assertSame($before, BootCompiler::fingerprint($dist), 'same files => same fingerprint');

        \file_put_contents($file, '<?php return []; // touched');
        @\touch($file, \time() + 10); // explicit mtime, no sleep
        \clearstatcache();

        self::assertNotSame($before, BootCompiler::fingerprint($dist), 'an edit MUST move the fingerprint (staleness must become visible)');

        @\rmdir($dir);
    }

    public function testWriteThenUsableDataRoundTripAndStalenessFallback(): void
    {
        $distMock = $this->createMock(Distributor::class);
        $distMock->method('getCode')->willReturn('unittest-dist');
        $distMock->method('getTag')->willReturn('*');
        $distMock->method('getIdentity')->willReturn('unittest-dist@*');

        BootCompiler::clear('unittest-dist'); // no leftovers from earlier runs

        $dump = ['modules' => [], 'queue_order' => [], 'routes' => [], 'named' => [], 'module_middleware' => []];
        $artifact = BootCompiler::write($distMock, $dump);
        $this->sweep[] = $artifact;

        $data = BootCompiler::usableData($distMock);
        self::assertIsArray($data, 'a fresh artifact with a matching fingerprint is usable');
        self::assertSame('compiled-boot-1', $data['schema']);

        // a new file under the dist tree MUST deactivate the artifact
        $dir = PathUtil::append(SITES_FOLDER, 'unittest-dist');
        \mkdir($dir, 0o777, true);
        $probe = PathUtil::append($dir, 'late.php');
        \file_put_contents($probe, '<?php return [];');
        $this->sweep[] = $probe;
        \clearstatcache();

        self::assertNull(BootCompiler::usableData($distMock), 'stale artifact => full boot, the one rule that must never bend');

        @\rmdir($dir);
        BootCompiler::clear('unittest-dist');
    }

    // ── wiring pins: the doors are wired, not hinted ──────────────────────

    public function testM1ClassmapIsBakedAndConsulted(): void
    {
        $build = (string) \file_get_contents(SYSTEM_ROOT . '/build.php');
        self::assertStringContainsString("\$phar->addFromString('system/classmap.php'", $build, 'the map is baked at build time');

        $boot = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/bootstrap.inc.php');
        self::assertStringContainsString('system/classmap.php', $boot, 'the autoloader consults the baked map');
        self::assertStringContainsString('fall through to the legacy ladder', $boot, 'a map hit that fails to define the class keeps the probe path — the map can only ever make a hit faster');
    }

    public function testCompiledDoorIsOptInAndSelfProves(): void
    {
        $dist = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString("\$config['compiled_boot'] ?? false", $dist, 'absent flag = today\'s boot, byte for byte');
        self::assertStringContainsString('assembleCompiled($boot, $modules)', $dist, 'the compiled path replaces assembly, not lifecycle');

        $cli = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/compile.inc.php');
        self::assertStringContainsString('replay verified', $cli, 'compiling proves the replay equals the legacy boot before shipping');
        self::assertStringContainsString('misfiring', $cli, 'a diverging replay deletes its own artifact');
    }

    // ── standalone arm ────────────────────────────────────────────────────

    public function testStandaloneArtifactIsFolderKeyedAndRoundTrips(): void
    {
        $folder = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_sa_' . \uniqid();
        \mkdir($folder, 0o777, true);
        \file_put_contents($folder . '/module.php', '<?php return ["module_code" => "standalone/app"];');
        $this->sweep[] = $folder . '/module.php';
        $this->sweep[] = $folder;

        $sa = $this->createMock(\Razy\Standalone::class);
        $sa->method('getFolderPath')->willReturn($folder);

        // the artifact path keys the normalized folder — not a dist code
        $artifact = BootCompiler::standaloneArtifactPath($folder);
        self::assertStringContainsString('standalone@' . \md5(\str_replace('\\', '/', $folder)), $artifact);

        // existence IS the opt-in: none yet -> full boot
        self::assertNull(BootCompiler::usableStandalone($sa));

        $dump = ['modules' => [], 'queue_order' => [], 'routes' => [], 'named' => [], 'module_middleware' => []];
        BootCompiler::writeStandalone($sa, $dump);
        self::assertIsArray(BootCompiler::usableStandalone($sa), 'artifact present + fingerprint fresh => replay');

        $late = $folder . '/late.php';
        \file_put_contents($late, '<?php return [];');
        $this->sweep[] = $late;
        \clearstatcache();
        self::assertNull(BootCompiler::usableStandalone($sa), 'an edit under the app folder degrades to the full boot');

        BootCompiler::clearStandalone($folder);
        self::assertNull(BootCompiler::usableStandalone($sa), 'clear is the revert door');
    }

    public function testStandaloneArmIsWiredNotHinted(): void
    {
        $sa = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Standalone.php');
        self::assertStringContainsString('$modules === [] && ($boot = BootCompiler::usableStandalone($this)) !== null', $sa, 'artifact-existence opt-in AND the co-module escape hatch (loadModule-injected graphs take the legacy path whole)');
        self::assertStringContainsString('assembleCompiled($boot, $modules)', $sa, 'the replay replaces assembly, not lifecycle');

        $cli = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/compile.inc.php');
        self::assertStringContainsString('writeStandalone', $cli);
        self::assertStringContainsString('realpath', $cli, 'the folder key must be the path AS RUNTIME SEES IT');

        $df = (string) \file_get_contents(SYSTEM_ROOT . '/benchmark/docker/Dockerfile.razy-fpm');
        self::assertStringContainsString('ARG COMPILED=1', $df, 'the fpm benchmark image carries the A/B switch');
    }

    public function testFingerprintShortensOnlyWhereBytecodeIsFrozen(): void
    {
        // The suite runs WITHOUT opcache — the guard must therefore keep the
        // full stats here (and in any dev box): a stale artifact still falls
        // back, which the round-trip test's staleness assertion proves live.
        $trust = \getenv('RAZY_COMPILE_TRUST');
        \putenv('RAZY_COMPILE_TRUST');
        try {
            self::assertFalse(BootCompiler::fingerprintSkipped(), 'no opcache => no shortening, stats stay on');

            \putenv('RAZY_COMPILE_TRUST=1');
            self::assertTrue(BootCompiler::fingerprintSkipped(), 'the declared-trust door still works');
        } finally {
            \putenv(false === $trust ? 'RAZY_COMPILE_TRUST' : "RAZY_COMPILE_TRUST={$trust}");
        }

        // The frozen-bytecode branch (opcache on + vt=0) cannot be built in
        // this process — pin the wiring so nobody re-inlines the env check.
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Compiler/BootCompiler.php');
        self::assertStringContainsString('if (!self::fingerprintSkipped()) {', $src, 'both usable() gates run through the decision point');
        self::assertSame(2, \substr_count($src, 'if (!self::fingerprintSkipped()) {'), 'both usable() gates run through the decision point');
        self::assertStringContainsString('opcache_get_status', $src);
        // the STATUS shape is FLAT ('opcache_enabled' top-level); the nested
        // ['opcache']['enabled'] belongs to get_CONFIGURATION — reading it
        // from the status array shipped a dead gate once (2026-09-17):
        self::assertStringContainsString("\$status['opcache_enabled']", $src);
        self::assertStringNotContainsString("\$status['opcache']['enabled']", $src);
        self::assertStringContainsString('opcache.validate_timestamps', $src);
    }

    public function testForeignArtifactIsRefusedWhereTheFingerprintIsShortened(): void
    {
        // benchgate live-fire 2026-09-17: a dev-machine artifact COPY'd into
        // an image carries foreign folder keys; with the fingerprint
        // shortened, staleness can NO LONGER catch it — the structure floor
        // (every named folder exists here) must, or replay crashes the boot.
        self::assertTrue(BootCompiler::originLooksLocal(['modules' => []]), 'an empty module set is vacuously local');
        self::assertTrue(
            BootCompiler::originLooksLocal(['modules' => ['a/b' => ['manifest' => ['folder' => SYSTEM_ROOT]]]]),
            'a folder that exists here is local'
        );
        self::assertFalse(
            BootCompiler::originLooksLocal(['modules' => ['a/b' => ['manifest' => ['folder' => 'C:\Users\someone\site\modules\a\b']]]]),
            'a Windows-shaped folder cannot exist on this Linux test box (and vice versa: the floor is shape-blind, it only asks is_dir)'
        );
        self::assertFalse(
            BootCompiler::originLooksLocal(['modules' => ['a/b' => ['manifest' => []]]]),
            'a declaration without a folder fails the floor too — replay could not place it anyway'
        );

        // the floor runs where the fingerprint does not: TRUST declared (the
        // suite has no opcache, so TRUST is the only reachable skip here)
        $distMock = $this->createMock(Distributor::class);
        $distMock->method('getCode')->willReturn('unittest-foreign');
        $distMock->method('getTag')->willReturn('*');
        $distMock->method('getIdentity')->willReturn('unittest-foreign@*');
        BootCompiler::clear('unittest-foreign');

        $dump = [
            'modules' => ['a/b' => ['manifest' => ['folder' => PathUtil::append(SYSTEM_ROOT, 'no', 'such', 'folder')]]],
            'queue_order' => [],
            'routes' => [],
            'named' => [],
            'module_middleware' => [],
        ];
        $this->sweep[] = BootCompiler::write($distMock, $dump);

        $trust = \getenv('RAZY_COMPILE_TRUST');
        \putenv('RAZY_COMPILE_TRUST=1');
        try {
            self::assertNull(BootCompiler::usableData($distMock), 'TRUST shortens the fingerprint but never the structure floor');
        } finally {
            \putenv(false === $trust ? 'RAZY_COMPILE_TRUST' : "RAZY_COMPILE_TRUST={$trust}");
        }

        BootCompiler::clear('unittest-foreign');
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function createModule(): Module
    {
        $mock = $this->createMock(Distributor::class);
        $mock->method('isStrict')->willReturn(false);
        $mock->method('getCode')->willReturn('test-dist');
        $mock->method('getSiteURL')->willReturn('/site/');
        $mock->method('getContainer')->willReturn(null);

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mock->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mock->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $mock->method('getPrerequisites')->willReturn($mockPrereqs);

        return new Module($mock, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
            'description' => 'Test module',
        ]);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \glob($dir . '/*') as $f) {
            if (\is_dir($f)) {
                $this->removeDir($f);
            } else {
                @\unlink($f);
            }
        }
        @\rmdir($dir);
    }
}
