<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Module;
use Razy\ModuleInfo;
use Razy\Route;
use Razy\ThreadManager;
use ReflectionClass;
use TypeError;

/**
 * Comprehensive unit tests for the Agent class.
 *
 * Agent is the public interface for module initialization: it validates
 * inputs and delegates to the underlying Module instance.
 *
 * Strategy: Mock the Module dependency to isolate Agent logic. Where Module
 * methods are called, verify delegation via mock expectations.
 */
#[CoversClass(Agent::class)]
class AgentTest extends TestCase
{
    private Module $mockModule;

    private ModuleInfo $mockModuleInfo;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockModuleInfo = $this->createMock(ModuleInfo::class);
        $this->mockModuleInfo->method('getCode')->willReturn('test/AgentModule');

        $this->mockModule = $this->createMock(Module::class);
        $this->mockModule->method('getModuleInfo')->willReturn($this->mockModuleInfo);

        $this->agent = new Agent($this->mockModule);
    }

    public static function invalidAPICommandNames(): array
    {
        return [
            'starts with digit' => ['1command'],
            'starts with underscore' => ['_command'],
            'contains hyphen' => ['my-command'],
            'contains dot' => ['my.command'],
            'contains space' => ['my command'],
            'empty string' => [''],
            'only hash' => ['#'],
            'hash with digit start' => ['#1cmd'],
            'special characters' => ['cmd!@#'],
            'starts with uppercase digit' => ['0Cmd'],
        ];
    }

    public static function validAPICommandNames(): array
    {
        return [
            'lowercase' => ['command'],
            'uppercase' => ['Command'],
            'with underscore' => ['my_command'],
            'with digits' => ['cmd123'],
            'single letter' => ['x'],
            'hash prefix' => ['#myCmd'],
            'hash with underscore' => ['#my_cmd'],
            'mixed case' => ['MyCommand'],
        ];
    }

    public static function invalidBridgeCommandNames(): array
    {
        return [
            'starts with digit' => ['1bridge'],
            'starts with underscore' => ['_bridge'],
            'hash prefix not allowed' => ['#bridge'],
            'contains hyphen' => ['my-bridge'],
            'contains dot' => ['my.bridge'],
            'contains space' => ['my bridge'],
            'empty string' => [''],
            'special characters' => ['cmd!@'],
        ];
    }

    public static function validBridgeCommandNames(): array
    {
        return [
            'lowercase' => ['bridge'],
            'uppercase' => ['Bridge'],
            'with underscore' => ['my_bridge'],
            'with digits' => ['bridge123'],
            'single letter' => ['b'],
            'mixed case' => ['MyBridge'],
        ];
    }

    public static function invalidEventNames(): array
    {
        return [
            'no colon separator' => ['vendorModule'],
            'empty event name' => ['vendor/Module:'],
            'event starts with digit' => ['vendor/Module:1event'],
            'invalid module code' => ['InvalidCode:eventName'],
            'empty module code' => [':eventName'],
            'event with spaces' => ['vendor/Module:my event'],
            'event with special chars' => ['vendor/Module:evt!@#'],
            'no event name after colon' => ['vendor/Module:'],
        ];
    }

    // ==================== addAPICommand ====================

    #[Test]
    public function addAPICommandWithValidNameDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand')
            ->with('myCommand', 'handler');

        $result = $this->agent->addAPICommand('myCommand', 'handler');
        $this->assertSame($this->agent, $result, 'addAPICommand should return $this for fluent interface');
    }

    #[Test]
    public function addAPICommandWithHashPrefixIsValid(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand')
            ->with('#privateCmd', 'handler');

        $this->agent->addAPICommand('#privateCmd', 'handler');
    }

    #[Test]
    public function addAPICommandTrimsWhitespace(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand')
            ->with('trimmed', 'path');

        $this->agent->addAPICommand('  trimmed  ', 'path');
    }

    #[Test]
    public function addAPICommandBatchRegistration(): void
    {
        $this->mockModule->expects($this->exactly(2))
            ->method('addAPICommand');

        $this->agent->addAPICommand([
            'cmdOne' => 'pathOne',
            'cmdTwo' => 'pathTwo',
        ]);
    }

    #[Test]
    public function addAPICommandBatchSkipsNonStringPaths(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand')
            ->with('validCmd', 'validPath');

        $this->agent->addAPICommand([
            'validCmd' => 'validPath',
            'invalidCmd' => 123, // non-string path, should be skipped
        ]);
    }

    #[Test]
    #[DataProvider('invalidAPICommandNames')]
    public function addAPICommandWithInvalidNameThrowsException(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command name format');

        $this->agent->addAPICommand($name, 'handler');
    }

    #[Test]
    #[DataProvider('validAPICommandNames')]
    public function addAPICommandWithValidNamesSucceeds(string $name): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand');

        $this->agent->addAPICommand($name, 'handler');
    }

    // ==================== addBridgeCommand ====================

    #[Test]
    public function addBridgeCommandWithValidNameDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addBridgeCommand')
            ->with('bridgeCmd', 'handler');

        $result = $this->agent->addBridgeCommand('bridgeCmd', 'handler');
        $this->assertSame($this->agent, $result, 'addBridgeCommand should return $this');
    }

    #[Test]
    public function addBridgeCommandTrimsWhitespace(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addBridgeCommand')
            ->with('trimmed', 'path');

        $this->agent->addBridgeCommand('  trimmed  ', 'path');
    }

    #[Test]
    public function addBridgeCommandBatchRegistration(): void
    {
        $this->mockModule->expects($this->exactly(3))
            ->method('addBridgeCommand');

        $this->agent->addBridgeCommand([
            'cmdA' => 'pathA',
            'cmdB' => 'pathB',
            'cmdC' => 'pathC',
        ]);
    }

    #[Test]
    public function addBridgeCommandBatchSkipsNonStringPaths(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addBridgeCommand')
            ->with('validBridge', 'validPath');

        $this->agent->addBridgeCommand([
            'validBridge' => 'validPath',
            'invalidBridge' => null,
        ]);
    }

    #[Test]
    #[DataProvider('invalidBridgeCommandNames')]
    public function addBridgeCommandWithInvalidNameThrowsException(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid bridge command name format');

        $this->agent->addBridgeCommand($name, 'handler');
    }

    #[Test]
    #[DataProvider('validBridgeCommandNames')]
    public function addBridgeCommandWithValidNamesSucceeds(string $name): void
    {
        $this->mockModule->expects($this->once())
            ->method('addBridgeCommand');

        $this->agent->addBridgeCommand($name, 'handler');
    }

    // ==================== listen ====================

    #[Test]
    public function listenWithValidEventDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->with('vendor/Module:eventName', $this->anything())
            ->willReturn(true);

        $result = $this->agent->listen('vendor/Module:eventName', 'handler');
        $this->assertTrue($result);
    }

    #[Test]
    public function listenReturnsFalseWhenModuleNotLoaded(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->willReturn(false);

        $result = $this->agent->listen('vendor/Module:someEvent', 'handler');
        $this->assertFalse($result);
    }

    #[Test]
    public function listenBatchRegistrationReturnsArray(): void
    {
        $this->mockModule->method('listen')
            ->willReturnOnConsecutiveCalls(true, false);

        $results = $this->agent->listen([
            'vendor/ModA:evtA' => 'handlerA',
            'vendor/ModB:evtB' => 'handlerB',
        ]);

        $this->assertIsArray($results);
        $this->assertTrue($results['vendor/ModA:evtA']);
        $this->assertFalse($results['vendor/ModB:evtB']);
    }

    #[Test]
    public function listenWithClosureConvertsToFirstClassClosure(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->with('vendor/Module:evt', $this->isInstanceOf(Closure::class))
            ->willReturn(true);

        $this->agent->listen('vendor/Module:evt', function () {
            return 'test';
        });
    }

    #[Test]
    #[DataProvider('invalidEventNames')]
    public function listenWithInvalidEventNameThrowsException(string $event): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event name format');

        $this->agent->listen($event, 'handler');
    }

    #[Test]
    public function listenWithDotSeparatedEventNameSucceeds(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->with('vendor/Module:some.nested.event', $this->anything())
            ->willReturn(true);

        $result = $this->agent->listen('vendor/Module:some.nested.event', 'handler');
        $this->assertTrue($result);
    }

    #[Test]
    public function listenWithHyphenInEventSegmentSucceeds(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->with('vendor/Module:some.my-event', $this->anything())
            ->willReturn(true);

        $result = $this->agent->listen('vendor/Module:some.my-event', 'handler');
        $this->assertTrue($result);
    }

    // ==================== addShadowRoute ====================

    #[Test]
    public function addShadowRouteDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addShadowRoute')
            ->with('myRoute', 'other/Module', 'targetPath');

        $result = $this->agent->addShadowRoute('myRoute', 'other/Module', 'targetPath');
        $this->assertSame($this->agent, $result, 'addShadowRoute should return $this');
    }

    #[Test]
    public function addShadowRouteDefaultsPathToRoute(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addShadowRoute')
            ->with('defaultPath', 'other/Module', 'defaultPath');

        $this->agent->addShadowRoute('defaultPath', 'other/Module');
    }

    #[Test]
    public function addShadowRouteToSelfModuleThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot add a shadow route');

        // Module code matches the mocked module info code 'test/AgentModule'
        $this->agent->addShadowRoute('route', 'test/AgentModule');
    }

    #[Test]
    public function addShadowRouteTrimsInputs(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addShadowRoute')
            ->with('trimmedRoute', 'other/Module', 'trimmedPath');

        $this->agent->addShadowRoute('  trimmedRoute  ', '  other/Module  ', '  trimmedPath  ');
    }

    // ==================== addRoute ====================

    #[Test]
    public function addRouteWithStringPathDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addRoute');

        $result = $this->agent->addRoute('myRoute', 'handler');
        $this->assertSame($this->agent, $result, 'addRoute should return $this');
    }

    #[Test]
    public function addRouteWithRouteEntityDelegatesToModule(): void
    {
        $routeEntity = new Route('my/closure/path');
        $this->mockModule->expects($this->once())
            ->method('addRoute')
            ->with('myRoute', $routeEntity);

        $this->agent->addRoute('myRoute', $routeEntity);
    }

    #[Test]
    public function addRouteBatchRegistration(): void
    {
        $this->mockModule->expects($this->exactly(2))
            ->method('addRoute');

        $this->agent->addRoute([
            'routeA' => 'handlerA',
            'routeB' => 'handlerB',
        ]);
    }

    #[Test]
    public function addRouteBatchSkipsInvalidPaths(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addRoute');

        $this->agent->addRoute([
            'validRoute' => 'validHandler',
            'invalidRoute' => 123, // not string or Route, should be skipped
        ]);
    }

    #[Test]
    public function addRouteWithInvalidPathThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The route must be a string or a Route entity');

        $this->agent->addRoute('myRoute', 12345);
    }

    #[Test]
    public function addRouteWithNullPathThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The route must be a string or a Route entity');

        $this->agent->addRoute('myRoute', null);
    }

    #[Test]
    public function addRouteWithEmptyStringPathDoesNotDelegate(): void
    {
        // An empty closure path after trim should not trigger addRoute on Module
        $this->mockModule->expects($this->never())
            ->method('addRoute');

        $this->agent->addRoute('myRoute', '');
    }

    // ==================== addLazyRoute ====================

    #[Test]
    public function addLazyRouteWithStringPathDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addLazyRoute');

        $result = $this->agent->addLazyRoute('lazyRoute', 'handler');
        $this->assertSame($this->agent, $result, 'addLazyRoute should return $this');
    }

    #[Test]
    public function addLazyRouteBatchRegistration(): void
    {
        $this->mockModule->expects($this->exactly(2))
            ->method('addLazyRoute');

        $this->agent->addLazyRoute([
            'lazyA' => 'pathA',
            'lazyB' => 'pathB',
        ]);
    }

    #[Test]
    public function addLazyRouteWithNonStringRouteKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The route must be a string or an array');

        $this->agent->addLazyRoute(12345, 'handler');
    }

    #[Test]
    public function addLazyRouteWithRouteEntityIsPassedToModule(): void
    {
        // Module::addLazyRoute expects string $path, so passing a Route entity
        // triggers a TypeError at the Module level. Agent does allow Route through
        // addRoutePath but Module rejects it.
        $routeEntity = new Route('lazy/closure');
        $this->expectException(TypeError::class);

        $this->agent->addLazyRoute('lazyRoute', $routeEntity);
    }

    #[Test]
    public function addLazyRouteWithInvalidPathTypeThrowsNoExceptionButDoesNotDelegate(): void
    {
        // When path is not string, Route, or array, nothing happens (no delegation)
        $this->mockModule->expects($this->never())
            ->method('addLazyRoute');

        $this->agent->addLazyRoute('lazyRoute', 12345);
    }

    // ==================== addScript ====================

    #[Test]
    public function addScriptWithStringPathDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addScript');

        $result = $this->agent->addScript('scriptRoute', 'handler');
        $this->assertSame($this->agent, $result, 'addScript should return $this (self)');
    }

    #[Test]
    public function addScriptBatchRegistration(): void
    {
        $this->mockModule->expects($this->exactly(2))
            ->method('addScript');

        $this->agent->addScript([
            'scriptA' => 'pathA',
            'scriptB' => 'pathB',
        ]);
    }

    #[Test]
    public function addScriptWithNonStringRouteThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The route must be a string or an array');

        $this->agent->addScript(999, 'handler');
    }

    #[Test]
    public function addScriptWithRouteEntityTriggersTypeError(): void
    {
        // Module::addScript expects string $path, so passing a Route entity
        // triggers a TypeError at the Module level.
        $routeEntity = new Route('script/closure');
        $this->expectException(TypeError::class);

        $this->agent->addScript('scriptRoute', $routeEntity);
    }

    // ==================== addRoutePath nested arrays ====================

    #[Test]
    public function addRouteWithArrayPathThrowsException(): void
    {
        // addRoute does not support nested arrays as path (unlike addLazyRoute/addScript)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The route must be a string or a Route entity');

        $this->agent->addRoute('base', [
            'sub' => 'closure_file',
        ]);
    }

    #[Test]
    public function addLazyRouteWithSelfKeyInNestedArray(): void
    {
        $this->mockModule->expects($this->atLeastOnce())
            ->method('addLazyRoute');

        $this->agent->addLazyRoute('base', [
            '@self' => 'index_handler',
            'child' => 'child_handler',
        ]);
    }

    #[Test]
    public function addLazyRouteNestedArraySkipsEmptyPaths(): void
    {
        // Empty string path should not trigger delegation
        $this->mockModule->expects($this->never())
            ->method('addLazyRoute');

        $this->agent->addLazyRoute('base', [
            'sub' => '',
        ]);
    }

    #[Test]
    public function addLazyRouteWithNestedArrayExpandsRoutes(): void
    {
        $this->mockModule->expects($this->atLeastOnce())
            ->method('addLazyRoute');

        $this->agent->addLazyRoute('lazy', [
            'nested' => 'handler',
        ]);
    }

    #[Test]
    public function addScriptWithNestedArrayExpandsRoutes(): void
    {
        $this->mockModule->expects($this->atLeastOnce())
            ->method('addScript');

        $this->agent->addScript('script', [
            'nested' => 'handler',
        ]);
    }

    // ==================== await ====================

    #[Test]
    public function awaitDelegatesToModule(): void
    {
        $called = false;
        $callback = function () use (&$called) {
            $called = true;
        };

        $this->mockModule->expects($this->once())
            ->method('await')
            ->with('vendor/OtherModule', $this->isInstanceOf(Closure::class));

        $result = $this->agent->await('vendor/OtherModule', $callback);
        $this->assertSame($this->agent, $result, 'await should return $this');
    }

    // ==================== thread ====================

    #[Test]
    public function threadReturnsThreadManagerFromModule(): void
    {
        $mockTM = $this->createMock(ThreadManager::class);
        $this->mockModule->expects($this->once())
            ->method('getThreadManager')
            ->willReturn($mockTM);

        $result = $this->agent->thread();
        $this->assertSame($mockTM, $result);
    }

    // ==================== Fluent interface ====================

    #[Test]
    public function fluentChainingWorksForMultipleRegistrations(): void
    {
        $this->mockModule->method('addAPICommand')->willReturn($this->mockModule);
        $this->mockModule->method('addBridgeCommand')->willReturn($this->mockModule);

        $result = $this->agent
            ->addAPICommand('cmd1', 'path1')
            ->addBridgeCommand('bridge1', 'path1');

        $this->assertSame($this->agent, $result);
    }

    // ==================== Edge cases ====================

    #[Test]
    public function addAPICommandWithUnderscoresAndDigitsIsValid(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addAPICommand')
            ->with('my_Command_123', 'path');

        $this->agent->addAPICommand('my_Command_123', 'path');
    }

    #[Test]
    public function addBridgeCommandSingleCharacterIsValid(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addBridgeCommand')
            ->with('x', 'path');

        $this->agent->addBridgeCommand('x', 'path');
    }

    #[Test]
    public function addAPICommandEmptyBatchArrayDoesNothing(): void
    {
        $this->mockModule->expects($this->never())
            ->method('addAPICommand');

        $result = $this->agent->addAPICommand([]);
        $this->assertSame($this->agent, $result);
    }

    #[Test]
    public function addBridgeCommandEmptyBatchArrayDoesNothing(): void
    {
        $this->mockModule->expects($this->never())
            ->method('addBridgeCommand');

        $result = $this->agent->addBridgeCommand([]);
        $this->assertSame($this->agent, $result);
    }

    #[Test]
    public function addShadowRouteWithEmptyPathDefaultsToRoute(): void
    {
        $this->mockModule->expects($this->once())
            ->method('addShadowRoute')
            ->with('myRoute', 'other/Module', 'myRoute');

        $this->agent->addShadowRoute('myRoute', 'other/Module', '');
    }

    #[Test]
    public function listenWithStringPathDelegatesToModule(): void
    {
        $this->mockModule->expects($this->once())
            ->method('listen')
            ->with('vendor/Module:strEvt', 'handlerPath')
            ->willReturn(true);

        $result = $this->agent->listen('vendor/Module:strEvt', 'handlerPath');
        $this->assertTrue($result);
    }

    #[Test]
    public function addRouteWithRouteEntityContainingData(): void
    {
        $routeEntity = (new Route('data/path'))->contain(['key' => 'value']);
        $this->mockModule->expects($this->once())
            ->method('addRoute')
            ->with('dataRoute', $routeEntity);

        $this->agent->addRoute('dataRoute', $routeEntity);
    }

    #[Test]
    public function addRouteBatchWithMixedRouteEntitiesAndStrings(): void
    {
        $routeEntity = new Route('entity/path');
        $this->mockModule->expects($this->exactly(2))
            ->method('addRoute');

        $this->agent->addRoute([
            'stringRoute' => 'stringHandler',
            'entityRoute' => $routeEntity,
        ]);
    }

    #[Test]
    public function addLazyRouteWithDeeplyNestedArrayExpandsCorrectly(): void
    {
        $this->mockModule->expects($this->atLeastOnce())
            ->method('addLazyRoute');

        $this->agent->addLazyRoute('root', [
            'level1' => [
                'level2' => [
                    'level3' => 'deep_handler',
                ],
            ],
        ]);
    }

    #[Test]
    public function constructorStoresModuleViaReflection(): void
    {
        $ref = new ReflectionClass(Agent::class);
        $prop = $ref->getProperty('module');
        $prop->setAccessible(true);

        $this->assertSame($this->mockModule, $prop->getValue($this->agent));
    }
}
