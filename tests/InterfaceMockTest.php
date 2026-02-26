<?php
declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Contract\ContainerInterface;
use Razy\Contract\DatabaseInterface;
use Razy\Contract\EventDispatcherInterface;
use Razy\Contract\ModuleInterface;
use Razy\Contract\TemplateInterface;
use Razy\Database\Query;
use Razy\Database\Statement;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Template\Source;

/**
 * Tests that all core interfaces can be mocked/stubbed properly,
 * verifying their contracts work with PHPUnit's mock builder.
 */
#[CoversClass(ContainerInterface::class)]
class InterfaceMockTest extends TestCase
{
    // ─── ContainerInterface ──────────────────────────────────
    public function testContainerMockGet(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('db')->willReturn('database-service');
        $this->assertSame('database-service', $container->get('db'));
    }

    public function testContainerMockHas(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            ['db', true],
            ['missing', false],
        ]);
        $this->assertTrue($container->has('db'));
        $this->assertFalse($container->has('missing'));
    }

    public function testContainerMockBind(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('bind')
            ->with('logger', $this->isType('string'));
        $container->bind('logger', 'FileLogger');
    }

    public function testContainerMockSingleton(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('singleton')
            ->with('cache', null);
        $container->singleton('cache');
    }

    public function testContainerMockSingletonWithClosure(): void
    {
        $factory = fn() => new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('singleton')
            ->with('cache', $factory);
        $container->singleton('cache', $factory);
    }

    public function testContainerMockInstance(): void
    {
        $obj = new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('instance')
            ->with('config', $obj);
        $container->instance('config', $obj);
    }

    public function testContainerMockMake(): void
    {
        $result = new \stdClass();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('make')->with('service', ['param' => 1])->willReturn($result);
        $this->assertSame($result, $container->make('service', ['param' => 1]));
    }

    public function testContainerMockMakeWithDefaults(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('make')->with('simple', [])->willReturn('resolved');
        $this->assertSame('resolved', $container->make('simple'));
    }

    // ─── DatabaseInterface ──────────────────────────────────
    public function testDatabaseMockPrepare(): void
    {
        $stmt = $this->createMock(Statement::class);
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('prepare')->willReturn($stmt);
        $this->assertInstanceOf(Statement::class, $db->prepare());
    }

    public function testDatabaseMockPrepareWithSQL(): void
    {
        $stmt = $this->createMock(Statement::class);
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('prepare')->with('SELECT * FROM users')->willReturn($stmt);
        $this->assertInstanceOf(Statement::class, $db->prepare('SELECT * FROM users'));
    }

    public function testDatabaseMockExecute(): void
    {
        $query = $this->createMock(Query::class);
        $stmt = $this->createMock(Statement::class);
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('execute')->with($stmt)->willReturn($query);
        $this->assertInstanceOf(Query::class, $db->execute($stmt));
    }

    public function testDatabaseMockIsTableExists(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('isTableExists')->willReturnMap([
            ['users', true],
            ['nonexistent', false],
        ]);
        $this->assertTrue($db->isTableExists('users'));
        $this->assertFalse($db->isTableExists('nonexistent'));
    }

    public function testDatabaseMockGetPrefix(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getPrefix')->willReturn('app_');
        $this->assertSame('app_', $db->getPrefix());
    }

    public function testDatabaseMockPrepareAndExecuteChain(): void
    {
        $query = $this->createMock(Query::class);
        $stmt = $this->createMock(Statement::class);
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('prepare')->willReturn($stmt);
        $db->method('execute')->willReturn($query);

        $prepared = $db->prepare('INSERT INTO logs (msg) VALUES (?)');
        $result = $db->execute($prepared);
        $this->assertInstanceOf(Query::class, $result);
    }

    // ─── TemplateInterface ──────────────────────────────────
    public function testTemplateMockLoad(): void
    {
        $source = $this->createMock(Source::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->with('views/home.tpl', null)->willReturn($source);
        $this->assertInstanceOf(Source::class, $tpl->load('views/home.tpl'));
    }

    public function testTemplateMockLoadWithModuleInfo(): void
    {
        $source = $this->createMock(Source::class);
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->with('views/home.tpl', $moduleInfo)->willReturn($source);
        $this->assertInstanceOf(Source::class, $tpl->load('views/home.tpl', $moduleInfo));
    }

    public function testTemplateMockAssignSingleVar(): void
    {
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('assign')->willReturnSelf();
        $result = $tpl->assign('title', 'Hello World');
        $this->assertSame($tpl, $result);
    }

    public function testTemplateMockAssignArray(): void
    {
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('assign')->willReturnSelf();
        $result = $tpl->assign(['title' => 'Hello', 'body' => 'World']);
        $this->assertSame($tpl, $result);
    }

    public function testTemplateMockAssignChaining(): void
    {
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('assign')->willReturnSelf();
        $result = $tpl->assign('a', 1)->assign('b', 2)->assign('c', 3);
        $this->assertSame($tpl, $result);
    }

    // ─── ModuleInterface ────────────────────────────────────
    public function testModuleMockGetModuleInfo(): void
    {
        $info = $this->createMock(ModuleInfo::class);
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getModuleInfo')->willReturn($info);
        $this->assertInstanceOf(ModuleInfo::class, $module->getModuleInfo());
    }

    public function testModuleMockGetStatus(): void
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getStatus')->willReturn(ModuleStatus::Loaded);
        $this->assertSame(ModuleStatus::Loaded, $module->getStatus());
    }

    #[DataProvider('moduleStatusProvider')]
    public function testModuleMockGetStatusAllValues(ModuleStatus $status): void
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getStatus')->willReturn($status);
        $this->assertSame($status, $module->getStatus());
    }

    public static function moduleStatusProvider(): array
    {
        $cases = [];
        foreach (ModuleStatus::cases() as $status) {
            $cases[$status->name] = [$status];
        }
        return $cases;
    }

    public function testModuleMockExecute(): void
    {
        $info = $this->createMock(ModuleInfo::class);
        $module = $this->createMock(ModuleInterface::class);
        $module->method('execute')
            ->with($info, 'getUserById', [42])
            ->willReturn(['id' => 42, 'name' => 'Alice']);
        $result = $module->execute($info, 'getUserById', [42]);
        $this->assertSame(['id' => 42, 'name' => 'Alice'], $result);
    }

    public function testModuleMockExecuteReturnsNull(): void
    {
        $info = $this->createMock(ModuleInfo::class);
        $module = $this->createMock(ModuleInterface::class);
        $module->method('execute')
            ->with($info, 'unknownCommand', [])
            ->willReturn(null);
        $this->assertNull($module->execute($info, 'unknownCommand', []));
    }

    // ─── EventDispatcherInterface ───────────────────────────
    public function testEventDispatcherMockListen(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('listen')
            ->with('onReady', $this->isType('string'));
        $dispatcher->listen('onReady', 'path/to/handler.php');
    }

    public function testEventDispatcherMockListenWithClosure(): void
    {
        $handler = function () { return true; };
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('listen')
            ->with('onInit', $handler);
        $dispatcher->listen('onInit', $handler);
    }

    public function testEventDispatcherMockIsEventListening(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')->willReturnMap([
            ['moduleA', 'onReady', true],
            ['moduleA', 'onDispose', false],
            ['moduleB', 'onReady', false],
        ]);
        $this->assertTrue($dispatcher->isEventListening('moduleA', 'onReady'));
        $this->assertFalse($dispatcher->isEventListening('moduleA', 'onDispose'));
        $this->assertFalse($dispatcher->isEventListening('moduleB', 'onReady'));
    }

    public function testEventDispatcherMockMultipleListens(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->exactly(3))->method('listen');
        $dispatcher->listen('onInit', 'handler1.php');
        $dispatcher->listen('onReady', 'handler2.php');
        $dispatcher->listen('onDispose', fn() => null);
    }

    // ─── Cross-Interface Integration Mock ───────────────────
    public function testContainerResolvesDatabase(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getPrefix')->willReturn('test_');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('database')->willReturn($db);
        $container->method('has')->with('database')->willReturn(true);

        $this->assertTrue($container->has('database'));
        $resolved = $container->get('database');
        $this->assertInstanceOf(DatabaseInterface::class, $resolved);
        $this->assertSame('test_', $resolved->getPrefix());
    }

    public function testContainerResolvesTemplate(): void
    {
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('assign')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('make')->with('template')->willReturn($tpl);

        $resolved = $container->make('template');
        $this->assertInstanceOf(TemplateInterface::class, $resolved);
        $result = $resolved->assign('key', 'value');
        $this->assertSame($tpl, $result);
    }

    public function testModuleWithEventDispatcher(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')
            ->with('myModule', 'onReady')
            ->willReturn(true);

        $info = $this->createMock(ModuleInfo::class);
        $info->method('getCode')->willReturn('myModule');

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('getStatus')->willReturn(ModuleStatus::Loaded);

        $this->assertTrue($dispatcher->isEventListening($module->getModuleInfo()->getCode(), 'onReady'));
        $this->assertSame(ModuleStatus::Loaded, $module->getStatus());
    }

    // ─── Stub (configureStub) Variants ──────────────────────
    public function testContainerStub(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn(new \stdClass());
        $this->assertTrue($container->has('anything'));
        $this->assertInstanceOf(\stdClass::class, $container->get('anything'));
    }

    public function testDatabaseStub(): void
    {
        $stmt = $this->createStub(Statement::class);
        $query = $this->createStub(Query::class);
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('prepare')->willReturn($stmt);
        $db->method('execute')->willReturn($query);
        $db->method('getPrefix')->willReturn('');
        $db->method('isTableExists')->willReturn(false);

        $this->assertInstanceOf(Statement::class, $db->prepare());
        $this->assertInstanceOf(Query::class, $db->execute($stmt));
        $this->assertSame('', $db->getPrefix());
        $this->assertFalse($db->isTableExists('any'));
    }

    public function testTemplateStub(): void
    {
        $source = $this->createStub(Source::class);
        $tpl = $this->createStub(TemplateInterface::class);
        $tpl->method('load')->willReturn($source);
        $tpl->method('assign')->willReturnSelf();

        $this->assertInstanceOf(Source::class, $tpl->load('any.tpl'));
        $this->assertSame($tpl, $tpl->assign('key', 'value'));
    }

    public function testModuleStub(): void
    {
        $info = $this->createStub(ModuleInfo::class);
        $module = $this->createStub(ModuleInterface::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('getStatus')->willReturn(ModuleStatus::InQueue);
        $module->method('execute')->willReturn(null);

        $this->assertInstanceOf(ModuleInfo::class, $module->getModuleInfo());
        $this->assertSame(ModuleStatus::InQueue, $module->getStatus());
        $this->assertNull($module->execute($info, 'cmd', []));
    }

    public function testEventDispatcherStub(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')->willReturn(false);

        $this->assertFalse($dispatcher->isEventListening('any', 'event'));
    }
}
