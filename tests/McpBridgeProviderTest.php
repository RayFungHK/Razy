<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\BridgeSignature;
use Razy\Controller;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Mcp\BridgeProvider;
use Razy\Mcp\UnknownToolException;
use Razy\Module;
use ReflectionProperty;
use Throwable;

/**
 * BridgeProvider pins (RZ-014): the MCP tool surface is the bridge
 * surface â€” real handler reflection (names, schemas, descriptions), real
 * Module::executeBridgeCommand execution (including the RAZY_BRIDGE_SECRET
 * self-sign path), and the positional-binding hazards handled honestly.
 */
#[CoversClass(BridgeProvider::class)]
class McpBridgeProviderTest extends TestCase
{
    private const SECRET = 'mcp-provider-secret-otter-3';

    private string $tempDir;

    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_mcp_test_' . \uniqid();
        $this->modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_module';
        $controllerDir = $this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'controller';

        \mkdir($controllerDir . DIRECTORY_SEPARATOR . 'bridge', 0o777, true);

        \file_put_contents($this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'package.php', '<?php return ["alias" => "testmod"];');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'TestModule.php', '<?php
use Razy\Controller;
return new class(null) extends Controller {
    public function __onInit($agent): bool { return true; }

    /**
     * Answers a direct ping.
     */
    public function directPing(): string
    {
        return "direct:pong";
    }
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'bridge' . DIRECTORY_SEPARATOR . 'ping.php', '<?php

/**
 * Answers a ping with a pong.
 */
return function (string $msg = "x"): string {
    return "pong:" . $msg;
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'bridge' . DIRECTORY_SEPARATOR . 'gather.php', '<?php

return function (string $first, string $second, int $count = 2): string {
    return $first . "|" . $second . "|" . $count;
};');
    }

    protected function tearDown(): void
    {
        unset($_ENV['RAZY_BRIDGE_SECRET'], $_SERVER['RAZY_BRIDGE_SECRET']);
        \putenv('RAZY_BRIDGE_SECRET');

        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // â”€â”€ listing â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testBridgeCommandsBecomeToolsWithHonestNamesAndSchemas(): void
    {
        $provider = new BridgeProvider($this->distributorWithModule());

        $tools = $provider->describeTools();
        $byName = \array_column($tools, null, 'name');

        self::assertArrayHasKey('test_TestModule_ping', $byName, 'module code separators map into MCP-legal tool names');
        self::assertSame('Answers a ping with a pong.', $byName['test_TestModule_ping']['description'], 'description comes from the handler file comment, not a side registry');

        $schema = $byName['test_TestModule_ping']['inputSchema'];
        self::assertSame('object', $schema['type']);
        self::assertSame('string', $schema['properties']['msg']['type']);
        self::assertArrayNotHasKey('required', $schema, '$msg has a default â€” requiring it would be a lie');

        $gather = $byName['test_TestModule_gather']['inputSchema'];
        self::assertSame(['first', 'second'], $gather['required'], 'defaultless parameters ARE required');
        self::assertSame('integer', $gather['properties']['count']['type']);
    }

    public function testControllerMethodPriorityMirrorsExecution(): void
    {
        $module = $this->module();
        // Registered with a closure path, but the controller method of the
        // same name wins â€” CommandRegistry::executeCommand precedence (no '/'
        // in command + method_exists) mirrored by the provider.
        $module->addBridgeCommand('directPing', 'bridge/ping');

        $provider = new BridgeProvider($this->distributorWithModule($module));
        $tools = \array_column($provider->describeTools(), null, 'name');

        self::assertArrayHasKey('test_TestModule_directPing', $tools);
        $answer = $provider->callTool('test_TestModule_directPing', []);
        self::assertTrue($answer['ok']);
        self::assertSame('"direct:pong"', $answer['text']);
    }

    // â”€â”€ calling â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function testCallBindsNamedArgumentsPositionally(): void
    {
        $provider = new BridgeProvider($this->distributorWithModule());

        $answer = $provider->callTool('test_TestModule_ping', ['msg' => 'hi']);

        self::assertTrue($answer['ok']);
        self::assertSame('"pong:hi"', $answer['text']);
    }

    public function testOptionalParametersAreMaterializedFromDefaults(): void
    {
        $provider = new BridgeProvider($this->distributorWithModule());

        $answer = $provider->callTool('test_TestModule_gather', ['first' => 'A', 'second' => 'B']);

        self::assertTrue($answer['ok'], 'omitted-with-default count materializes as 2');
        self::assertSame('"A|B|2"', $answer['text']);
    }

    public function testNamedHoleBeforeGivenValueIsRefusedNeverShifted(): void
    {
        $provider = new BridgeProvider($this->distributorWithModule());

        $answer = $provider->callTool('test_TestModule_gather', ['first' => 'A', 'count' => 5]);

        self::assertFalse($answer['ok'], 'a hole in the middle cannot be positionalized honestly');
        self::assertStringContainsString('$second', $answer['text'], 'the refusal names the missing parameter');
    }

    public function testBridgeGateDenialCollapsesToUnverifiedNull(): void
    {
        $module = $this->module();
        $this->controllerOf($module)->bridgeAllow = false;

        $provider = new BridgeProvider($this->distributorWithModule($module));
        $answer = $provider->callTool('test_TestModule_ping', ['msg' => 'hi']);

        self::assertFalse($answer['ok']);
        self::assertStringContainsString('unverified', $answer['text'], 'the bridge null-collapse must not masquerade as a successful tool run');
    }

    public function testSelfSignedCallPassesTheBridgeSecretGate(): void
    {
        $_ENV['RAZY_BRIDGE_SECRET'] = self::SECRET;
        $_SERVER['RAZY_BRIDGE_SECRET'] = self::SECRET;
        $this->assertSame(self::SECRET, BridgeSignature::secretFromEnv(), 'fixture wiring: the gate must observe the secret');

        $provider = new BridgeProvider($this->distributorWithModule());
        $answer = $provider->callTool('test_TestModule_ping', ['msg' => 'hi']);

        self::assertTrue($answer['ok'], 'with RAZY_BRIDGE_SECRET set the provider self-signs and real Module-level HMAC verification accepts it');
        self::assertSame('"pong:hi"', $answer['text']);
    }

    public function testUnknownToolThrowsTheProtocolLevelSignal(): void
    {
        $this->expectException(UnknownToolException::class);

        (new BridgeProvider($this->distributorWithModule()))->callTool('no.such.tool', []);
    }

    // â”€â”€ fixtures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function module(): Module
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test-dist');
        $dist->method('getSiteURL')->willReturn('/site/');
        $dist->method('getContainer')->willReturn(null);
        $dist->method('getRouter')->willReturn($this->createMock(RouteDispatcher::class));
        $dist->method('getRegistry')->willReturn($this->createMock(ModuleRegistry::class));
        $dist->method('getPrerequisites')->willReturn($this->createMock(PrerequisiteResolver::class));

        $module = new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);

        $controller = new class($module) extends Controller {
            public bool $bridgeAllow = true;

            public function __onInit($agent): bool
            {
                return true;
            }

            public function __onBridgeCall(string $sourceDistributor, string $command): bool
            {
                return $this->bridgeAllow;
            }

            public function __onError(string $path, Throwable $exception): void
            {
            }

            public function directPing(): string
            {
                return 'direct:pong';
            }
        };

        $ref = new ReflectionProperty(Module::class, 'controller');
        $ref->setAccessible(true);
        $ref->setValue($module, $controller);

        $module->addBridgeCommand('ping', 'bridge/ping');
        $module->addBridgeCommand('gather', 'bridge/gather');

        return $module;
    }

    private function distributorWithModule(?Module $module = null): Distributor
    {
        $module ??= $this->module();

        $registry = $this->createMock(ModuleRegistry::class);
        $registry->method('getLoadedModulesInfo')->willReturn([
            'test/TestModule' => [
                'code' => 'test/TestModule',
                'alias' => 'testmod',
                'path' => $this->modulePath,
                'version' => '1.0.0',
                'api_name' => 'testmod',
                'module' => $module,
            ],
        ]);
        $registry->method('get')->willReturn($module);

        $dist = $this->createMock(Distributor::class);
        $dist->method('getRegistry')->willReturn($registry);

        return $dist;
    }

    private function controllerOf(Module $module): Controller
    {
        $ref = new ReflectionProperty(Module::class, 'controller');
        $ref->setAccessible(true);

        return $ref->getValue($module);
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($path) ? $this->removeDirectory($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
