<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Controller;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ClosureLoader;
use Razy\Module\CommandRegistry;
use Razy\Module\EventDispatcher;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\ThreadManager;
use ReflectionProperty;

/**
 * Module lifecycle and delegation tests.
 *
 * Strategy: Create temp filesystem fixtures for ModuleInfo, mock Distributor,
 * and use Reflection to inject mock controllers for post-construction testing.
 */
#[CoversClass(Module::class)]
class ModuleTest extends TestCase
{
    private string $tempDir;

    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Build a temp directory tree for a minimal module
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_module_test_' . \uniqid();
        $this->modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_module';
        $versionDir = $this->modulePath . DIRECTORY_SEPARATOR . 'default';
        $controllerDir = $versionDir . DIRECTORY_SEPARATOR . 'controller';

        \mkdir($controllerDir, 0o777, true);

        // Create minimal package.php
        \file_put_contents($versionDir . DIRECTORY_SEPARATOR . 'package.php', '<?php return [
            "alias" => "testmod",
        ];');

        // Create minimal controller file
        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'TestModule.php', '<?php
use Razy\Controller;
return new class(null) extends Controller {
    public function __onInit($agent): bool { return true; }
    public function __onLoad($agent): bool { return true; }
    public function __onReady(): void {}
    public function __onRequire(): bool { return true; }
    public function __onDispose(): void {}
};');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public static function unloadableStatusProvider(): array
    {
        return [
            'Pending' => [ModuleStatus::Pending],
            'Initialing' => [ModuleStatus::Initialing],
            'Processing' => [ModuleStatus::Processing],
            'InQueue' => [ModuleStatus::InQueue],
            'Loaded' => [ModuleStatus::Loaded],
            'Unloaded' => [ModuleStatus::Unloaded],
            'Disabled' => [ModuleStatus::Disabled],
        ];
    }

    // ==================== CONSTRUCTION & INITIAL STATE ====================

    public function testConstructorSetsStatusToPending(): void
    {
        $module = $this->createModule();
        $this->assertSame(ModuleStatus::Pending, $module->getStatus());
    }

    public function testConstructorCreatesModuleInfo(): void
    {
        $module = $this->createModule();
        $info = $module->getModuleInfo();
        $this->assertInstanceOf(ModuleInfo::class, $info);
        $this->assertSame('test/TestModule', $info->getCode());
    }

    public function testConstructorCreatesAgent(): void
    {
        $module = $this->createModule();
        $agent = $this->getPrivate($module, 'agent');
        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testConstructorCreatesThreadManager(): void
    {
        $module = $this->createModule();
        $this->assertInstanceOf(ThreadManager::class, $module->getThreadManager());
    }

    public function testConstructorCreatesClosureLoader(): void
    {
        $module = $this->createModule();
        $loader = $this->getPrivate($module, 'closureLoader');
        $this->assertInstanceOf(ClosureLoader::class, $loader);
    }

    public function testConstructorCreatesCommandRegistry(): void
    {
        $module = $this->createModule();
        $commands = $this->getPrivate($module, 'commands');
        $this->assertInstanceOf(CommandRegistry::class, $commands);
    }

    public function testConstructorCreatesEventDispatcher(): void
    {
        $module = $this->createModule();
        $dispatcher = $this->getPrivate($module, 'eventDispatcher');
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
    }

    public function testConstructorRegistersAPI(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        // Mock sub-objects
        $mockRegistry = $this->createMock(ModuleRegistry::class);
        // Module has no API name by default in our fixture, so registerAPI should NOT be called
        $mockRegistry->expects($this->never())->method('registerAPI');
        $dist->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
    }

    public function testModuleInfoAuthorIsSet(): void
    {
        $module = $this->createModule();
        $this->assertSame('Test Author', $module->getModuleInfo()->getAuthor());
    }

    public function testIsRoutableDefaultsToTrue(): void
    {
        $module = $this->createModule();
        $this->assertTrue($module->isRoutable());
    }

    // ==================== LIFECYCLE: standby() ====================

    public function testStandbyChangesStatusFromPendingToProcessing(): void
    {
        $module = $this->createModule();
        $this->assertSame(ModuleStatus::Pending, $module->getStatus());
        $module->standby();
        $this->assertSame(ModuleStatus::Processing, $module->getStatus());
    }

    public function testStandbyDoesNotChangeNonPendingStatus(): void
    {
        $module = $this->createModule();
        $this->setStatus($module, ModuleStatus::InQueue);
        $module->standby();
        $this->assertSame(ModuleStatus::InQueue, $module->getStatus());
    }

    public function testStandbyReturnsSelf(): void
    {
        $module = $this->createModule();
        $this->assertSame($module, $module->standby());
    }

    // ==================== LIFECYCLE: unload() ====================

    public function testUnloadSetsStatusToUnloaded(): void
    {
        $module = $this->createModule();
        $module->unload();
        $this->assertSame(ModuleStatus::Unloaded, $module->getStatus());
    }

    public function testUnloadDoesNotChangeFailedStatus(): void
    {
        $module = $this->createModule();
        $this->setStatus($module, ModuleStatus::Failed);
        $module->unload();
        $this->assertSame(ModuleStatus::Failed, $module->getStatus());
    }

    public function testUnloadReturnsSelf(): void
    {
        $module = $this->createModule();
        $this->assertSame($module, $module->unload());
    }

    #[DataProvider('unloadableStatusProvider')]
    public function testUnloadFromVariousStatuses(ModuleStatus $initial): void
    {
        $module = $this->createModule();
        $this->setStatus($module, $initial);
        $module->unload();
        $this->assertSame(ModuleStatus::Unloaded, $module->getStatus());
    }

    // ==================== LIFECYCLE: initialize() ====================

    public function testInitializeTransitionsToInQueue(): void
    {
        $module = $this->createModule();
        $result = $module->initialize();
        $this->assertTrue($result);
        $this->assertSame(ModuleStatus::InQueue, $module->getStatus());
    }

    public function testInitializeCreatesController(): void
    {
        $module = $this->createModule();
        $module->initialize();
        $controller = $this->getPrivate($module, 'controller');
        $this->assertInstanceOf(Controller::class, $controller);
    }

    // ==================== LIFECYCLE: prepare() ====================

    public function testPrepareTransitionsToLoaded(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);
        $this->setStatus($module, ModuleStatus::InQueue);

        $result = $module->prepare();
        $this->assertTrue($result);
        $this->assertSame(ModuleStatus::Loaded, $module->getStatus());
    }

    public function testPrepareReturnsFalseWhenOnLoadReturnsFalse(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module, ['__onLoad' => false]);
        $this->injectController($module, $ctrl);
        $this->setStatus($module, ModuleStatus::InQueue);

        $result = $module->prepare();
        $this->assertFalse($result);
    }

    public function testPrepareCallsOnLoad(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $module->prepare();
        $this->assertCount(1, $ctrl->calls);
        $this->assertSame('__onLoad', $ctrl->calls[0][0]);
    }

    // ==================== LIFECYCLE: notify() ====================

    public function testNotifyCallsOnReady(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $module->notify();
        $this->assertCount(1, $ctrl->calls);
        $this->assertSame('__onReady', $ctrl->calls[0][0]);
    }

    // ==================== LIFECYCLE: require() ====================

    public function testRequireCallsOnRequire(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $result = $module->require();
        $this->assertTrue($result);
        $this->assertSame('__onRequire', $ctrl->calls[0][0]);
    }

    public function testRequireReturnsFalseWhenOnRequireReturnsFalse(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module, ['__onRequire' => false]);
        $this->injectController($module, $ctrl);

        $this->assertFalse($module->require());
    }

    // ==================== LIFECYCLE: dispose() ====================

    public function testDisposeCallsOnDispose(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $module->dispose();
        $this->assertSame('__onDispose', $ctrl->calls[0][0]);
    }

    public function testDisposeReturnsSelf(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $this->assertSame($module, $module->dispose());
    }

    // ==================== LIFECYCLE: entry() ====================

    public function testEntryCallsOnEntry(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $routedInfo = ['path' => '/test', 'args' => [1, 2]];
        $module->entry($routedInfo);
        $this->assertSame('__onEntry', $ctrl->calls[0][0]);
        $this->assertSame($routedInfo, $ctrl->calls[0][1][0]);
    }

    public function testEntryReturnsSelf(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $this->assertSame($module, $module->entry([]));
    }

    // ==================== LIFECYCLE: announce() ====================

    public function testAnnounceCallsOnScriptReadyInCLIMode(): void
    {
        // CLI_MODE is true in test bootstrap
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);
        $this->setStatus($module, ModuleStatus::Loaded);

        $info = $this->createMock(ModuleInfo::class);
        $module->announce($info);

        $this->assertSame('__onScriptReady', $ctrl->calls[0][0]);
    }

    public function testAnnounceDoesNothingWhenNotLoaded(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);
        $this->setStatus($module, ModuleStatus::InQueue);

        $info = $this->createMock(ModuleInfo::class);
        $module->announce($info);

        $this->assertEmpty($ctrl->calls);
    }

    // ==================== DELEGATION: getters ====================

    public function testGetStatusReturnsCurrentStatus(): void
    {
        $module = $this->createModule();
        $this->assertSame(ModuleStatus::Pending, $module->getStatus());
        $this->setStatus($module, ModuleStatus::Loaded);
        $this->assertSame(ModuleStatus::Loaded, $module->getStatus());
    }

    public function testGetModuleInfoReturnsInstance(): void
    {
        $module = $this->createModule();
        $this->assertInstanceOf(ModuleInfo::class, $module->getModuleInfo());
    }

    public function testGetContainerDelegatesToDistributor(): void
    {
        $module = $this->createModule();
        $this->assertNull($module->getContainer());
    }

    public function testGetSiteURLDelegatesToDistributor(): void
    {
        $module = $this->createModule();
        $this->assertSame('/site/', $module->getSiteURL());
    }

    public function testGetModuleURLCombinesSiteURLAndAlias(): void
    {
        $module = $this->createModule();
        $url = $module->getModuleURL();
        // Should be /site/ + alias (testmod from package.php)
        $this->assertStringContainsString('testmod', $url);
        $this->assertStringStartsWith('/site/', $url);
    }

    public function testGetRoutedInfoDelegatesToDistributor(): void
    {
        $module = $this->createModule();
        $this->assertSame([], $module->getRoutedInfo());
    }

    public function testGetThreadManagerReturnsInstance(): void
    {
        $module = $this->createModule();
        $tm1 = $module->getThreadManager();
        $tm2 = $module->getThreadManager();
        $this->assertSame($tm1, $tm2);
    }

    // ==================== ROUTING DELEGATION ====================

    public function testAddRouteDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->expects($this->once())
            ->method('setRoute')
            ->with($this->isInstanceOf(Module::class), '/users', 'handler');
        $dist->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $dist->method('getRegistry')->willReturn($mockRegistry);
        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $module->addRoute('/users', 'handler');
    }

    public function testAddRoutePrependsSlash(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->expects($this->once())
            ->method('setRoute')
            ->with($this->anything(), '/users', $this->anything());
        $dist->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $dist->method('getRegistry')->willReturn($mockRegistry);
        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $module->addRoute('users', 'handler');
    }

    public function testAddRouteReturnsSelf(): void
    {
        $module = $this->createModule();
        $this->assertSame($module, $module->addRoute('/test', 'handler'));
    }

    public function testAddShadowRouteDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->expects($this->once())->method('setShadowRoute');
        $dist->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mockRegistry->method('getLoadedModule')->willReturn(null);
        $dist->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $module->addShadowRoute('/shadow', 'other/mod', 'shadowHandler');
    }

    public function testAddLazyRouteDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->expects($this->once())->method('setLazyRoute');
        $dist->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $dist->method('getRegistry')->willReturn($mockRegistry);
        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $module->addLazyRoute('/lazy', 'lazyHandler');
    }

    public function testAddScriptDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->expects($this->once())->method('setScript');
        $dist->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $dist->method('getRegistry')->willReturn($mockRegistry);
        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $module->addScript('build', 'buildHandler');
    }

    // ==================== COMMANDS ====================

    public function testGetAPICommandsReturnsEmptyByDefault(): void
    {
        $module = $this->createModule();
        $this->assertSame([], $module->getAPICommands());
    }

    public function testGetBridgeCommandsReturnsEmptyByDefault(): void
    {
        $module = $this->createModule();
        $this->assertSame([], $module->getBridgeCommands());
    }

    // ==================== EVENTS ====================

    public function testIsEventListeningReturnsFalseByDefault(): void
    {
        $module = $this->createModule();
        $this->assertFalse($module->isEventListening('any/module', 'someEvent'));
    }

    public function testListenDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mockRegistry->method('getLoadedModule')->willReturn(null);
        $mockRegistry->expects($this->once())
            ->method('registerListener')
            ->with('other/module', 'onReady', $this->isInstanceOf(Module::class));
        $dist->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $result = $module->listen('other/module:onReady', 'handler.php');
        $this->assertFalse($result); // target module not loaded
    }

    // ==================== VALIDATE ====================

    public function testValidateReturnsTrueWhenNoRequires(): void
    {
        $module = $this->createModule();
        $this->assertTrue($module->validate());
    }

    // ==================== TOUCH ====================

    public function testTouchCallsControllerOnTouch(): void
    {
        $module = $this->createModule();
        $ctrl = $this->createTestController($module);
        $this->injectController($module, $ctrl);

        $info = $this->createMock(ModuleInfo::class);
        $result = $module->touch($info, '1.0.0', 'hello');

        $this->assertTrue($result);
        $this->assertSame('__onTouch', $ctrl->calls[0][0]);
        $this->assertSame('1.0.0', $ctrl->calls[0][1][1]);
        $this->assertSame('hello', $ctrl->calls[0][1][2]);
    }

    // ==================== HANDSHAKE ====================

    public function testHandshakeDelegatesToDistributor(): void
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test');

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mockRegistry->expects($this->once())
            ->method('handshakeTo')
            ->with('other/module', $this->isInstanceOf(ModuleInfo::class), $this->anything(), 'hi')
            ->willReturn(true);
        $dist->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $dist->method('getPrerequisites')->willReturn($mockPrereqs);

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
        $this->assertTrue($module->handshake('other/module', 'hi'));
    }

    // ==================== CLOSURE BINDING ====================

    public function testBindReturnsSelf(): void
    {
        $module = $this->createModule();
        $this->assertSame($module, $module->bind('myMethod', 'path/to/closure.php'));
    }

    public function testGetBindingReturnsRegisteredPath(): void
    {
        $module = $this->createModule();
        $module->bind('myMethod', 'path/to/closure.php');
        // Path separator is OS-dependent
        $expected = \str_replace('/', DIRECTORY_SEPARATOR, 'path/to/closure.php');
        $this->assertSame($expected, $module->getBinding('myMethod'));
    }

    public function testGetBindingReturnsEmptyForUnbound(): void
    {
        $module = $this->createModule();
        $this->assertSame('', $module->getBinding('unbound'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($path) ? $this->removeDirectory($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    /**
     * Build a minimal Module with a mocked Distributor.
     */
    private function createModule(?Distributor $distributor = null): Module
    {
        $dist = $distributor ?? $this->createMockDistributor();

        return new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
            'description' => 'Test module',
        ]);
    }

    private function createMockDistributor(): Distributor
    {
        $mock = $this->createMock(Distributor::class);
        $mock->method('isStrict')->willReturn(false);
        $mock->method('getCode')->willReturn('test-dist');
        $mock->method('getSiteURL')->willReturn('/site/');
        $mock->method('getContainer')->willReturn(null);

        // Mock sub-objects for delegate method access
        $mockRouter = $this->createMock(RouteDispatcher::class);
        $mockRouter->method('getRoutedInfo')->willReturn([]);
        $mock->method('getRouter')->willReturn($mockRouter);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mock->method('getRegistry')->willReturn($mockRegistry);

        $mockPrereqs = $this->createMock(PrerequisiteResolver::class);
        $mock->method('getPrerequisites')->willReturn($mockPrereqs);

        return $mock;
    }

    /**
     * Inject a mock Controller into an existing Module via Reflection.
     */
    private function injectController(Module $module, ?Controller $controller): void
    {
        $ref = new ReflectionProperty(Module::class, 'controller');
        $ref->setAccessible(true);
        $ref->setValue($module, $controller);
    }

    /**
     * Set module status via Reflection.
     */
    private function setStatus(Module $module, ModuleStatus $status): void
    {
        $ref = new ReflectionProperty(Module::class, 'status');
        $ref->setAccessible(true);
        $ref->setValue($module, $status);
    }

    /**
     * Get private property via Reflection.
     */
    private function getPrivate(Module $module, string $property): mixed
    {
        $ref = new ReflectionProperty(Module::class, $property);
        $ref->setAccessible(true);
        return $ref->getValue($module);
    }

    /**
     * Create a mock Controller that tracks method calls.
     * Controller has a final __construct, so we create a real anonymous subclass.
     */
    private function createTestController(Module $module, array $overrides = []): Controller
    {
        $ctrl = new class($module) extends Controller {
            public array $calls = [];

            public array $returnValues = [];

            public function setReturn(string $method, mixed $value): void
            {
                $this->returnValues[$method] = $value;
            }

            public function __onInit($agent): bool
            {
                $this->calls[] = ['__onInit', [$agent]];
                return $this->returnValues['__onInit'] ?? true;
            }

            public function __onLoad($agent): bool
            {
                $this->calls[] = ['__onLoad', [$agent]];
                return $this->returnValues['__onLoad'] ?? true;
            }

            public function __onReady(): void
            {
                $this->calls[] = ['__onReady', []];
            }

            public function __onRequire(): bool
            {
                $this->calls[] = ['__onRequire', []];
                return $this->returnValues['__onRequire'] ?? true;
            }

            public function __onDispose(): void
            {
                $this->calls[] = ['__onDispose', []];
            }

            public function __onEntry(array $routedInfo): void
            {
                $this->calls[] = ['__onEntry', [$routedInfo]];
            }

            public function __onRouted(ModuleInfo $moduleInfo): void
            {
                $this->calls[] = ['__onRouted', [$moduleInfo]];
            }

            public function __onScriptReady(ModuleInfo $moduleInfo): void
            {
                $this->calls[] = ['__onScriptReady', [$moduleInfo]];
            }

            public function __onTouch(ModuleInfo $module, string $version, string $message = ''): bool
            {
                $this->calls[] = ['__onTouch', [$module, $version, $message]];
                return $this->returnValues['__onTouch'] ?? true;
            }
        };

        foreach ($overrides as $method => $value) {
            $ctrl->setReturn($method, $value);
        }

        return $ctrl;
    }
}
