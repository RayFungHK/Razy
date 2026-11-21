<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\BridgeSignature;
use Razy\Controller;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Error\ErrorRenderer;
use Razy\Exception\ModuleException;
use Razy\Module;
use Razy\ModuleInfo;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Gate-hook execution tests (Lane B0, closes RZ-014 self-debt).
 *
 * Before this file the security gates themselves — __onAPICall / __onBridgeCall —
 * had ZERO execution coverage in the framework suite (grep-verified 2026-07).
 * These tests pin the observable semantics of CommandRegistry/Module gate flows,
 * including the RAZY_BRIDGE_SECRET HMAC pre-gate added in the 2026 security round.
 *
 * Known gap NOT covered here (deliberately): Controller::__onDispatch() is declared
 * (Controller.php:99) but has no caller anywhere in src/ — declared-but-unwired hook,
 * tracked as documentation drift, not asserted as live behavior.
 */
#[CoversClass(Module::class)]
class GateHooksTest extends TestCase
{
    private const SECRET = 'gate-hook-secret-otter-7';

    private string $tempDir;

    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_gate_test_' . \uniqid();
        $this->modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_module';
        $controllerDir = $this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'controller';

        \mkdir($controllerDir . DIRECTORY_SEPARATOR . 'api', 0o777, true);
        \mkdir($controllerDir . DIRECTORY_SEPARATOR . 'bridge', 0o777, true);

        \file_put_contents($this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'package.php', '<?php return ["alias" => "testmod"];');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'TestModule.php', '<?php
use Razy\Controller;
return new class(null) extends Controller {
    public function __onInit($agent): bool { return true; }
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'publish.php', '<?php
return function (string $what): string {
    return "published:" . $what;
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'throws.php', '<?php
return function (): string {
    throw new \RuntimeException("handler exploded");
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'nothing.php', '<?php
return function (): mixed {
    return null;
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'bridge' . DIRECTORY_SEPARATOR . 'ping.php', '<?php
return function (string $msg = "x"): string {
    return "pong:" . $msg;
};');
    }

    protected function tearDown(): void
    {
        unset($_ENV['RAZY_BRIDGE_SECRET'], $_SERVER['RAZY_BRIDGE_SECRET']);
        \putenv('RAZY_BRIDGE_SECRET');

        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function controllerMethodNames(): array
    {
        $cases = [];
        foreach (['render', 'view', 'api', 'xhr', 'fork', 'resolve', 'trigger', 'Render', '#render'] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    // ---------------------------------------------------------------- API gate

    public function testAPIGateDefaultIsOpenAndExecutes(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        $this->registerAPI($module, 'publish', 'api/publish');

        $result = $this->executeAPI($module, 'publish', ['doc1']);

        $this->assertSame('published:doc1', $result, 'default __onAPICall must allow (RZ-002 trap: default-open by design)');
    }

    public function testAPIGateDenialReturnsNullAndSeesCallerAndCommand(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $controller->apiAllow = false;
        $this->injectController($module, $controller);

        $this->registerAPI($module, 'publish', 'api/publish');

        $result = $this->executeAPI($module, 'publish', ['doc1']);

        $this->assertNull($result, 'gate denial must surface as null, never execute the handler');
        $this->assertCount(1, $controller->apiSeen);
        $this->assertSame($module->getModuleInfo(), $controller->apiSeen[0]['module'], 'gate must receive the CALLER ModuleInfo');
        $this->assertSame('publish', $controller->apiSeen[0]['method']);
    }

    public function testAPIGateIsNotConsultedForUnknownCommand(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        $result = $this->executeAPI($module, 'ghost', []);

        $this->assertNull($result);
        $this->assertSame([], $controller->apiSeen, 'unknown commands must not even reach the gate');
    }

    public function testAPIDirectControllerMethodPreferredOverClosurePath(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        // Handler file deliberately nonexistent: the controller method must win.
        $this->registerAPI($module, 'directPing', 'api/no_such_file');

        $result = $this->executeAPI($module, 'directPing', []);

        $this->assertSame('direct:pong', $result);
    }

    public function testInternalCommandBypassesAPIGateByDesign(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $controller->apiAllow = false;
        $this->injectController($module, $controller);

        $this->registerAPI($module, 'publish', 'api/publish');

        // Documents the operator-trust CLI/internal-IPC path: NO gate by design
        // (CommandRegistry::executeInternalCommand). Not a defect — pinned semantics.
        $result = $this->registry($module)->executeInternalCommand('publish', ['doc2'], $controller, $this->closureLoader($module));

        $this->assertSame('published:doc2', $result);
        $this->assertSame([], $controller->apiSeen, 'internal path must not consult __onAPICall');
    }

    public function testHandlerThrowableIsRoutedToOnErrorHandler(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        $this->registerAPI($module, 'boom', 'api/throws');

        $result = $this->executeAPI($module, 'boom', []);

        $this->assertNull($result, 'a throwing handler must degrade to null for the caller');
        $this->assertCount(1, $controller->errors);
        $this->assertSame('boom', $controller->errors[0]['path']);
        $this->assertInstanceOf(RuntimeException::class, $controller->errors[0]['exception']);
    }

    // ------------------------------------------------- Checked outcome (RZ-53)

    public function testCheckedOutcomeTellsOkDeniedUnknownAndFailedApart(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $this->registerAPI($module, 'publish', 'api/publish');
        $this->registerAPI($module, 'nothing', 'api/nothing');
        $this->registerAPI($module, 'boom', 'api/throws');

        $ok = $this->executeChecked($module, 'publish', ['doc1']);
        $nothing = $this->executeChecked($module, 'nothing', []);
        $failed = $this->executeChecked($module, 'boom', []);
        $unknown = $this->executeChecked($module, 'ghost', []);
        $controller->apiAllow = false;
        $denied = $this->executeChecked($module, 'publish', ['doc1']);

        $this->assertSame(['ok', 'published:doc1', null], [$ok['status'], $ok['value'], $ok['error']]);
        $this->assertSame(['ok', null, null], [$nothing['status'], $nothing['value'], $nothing['error']], 'a command that returned null is ok, not a failure');
        $this->assertSame(['failed', null], [$failed['status'], $failed['value']]);
        $this->assertInstanceOf(RuntimeException::class, $failed['error']);
        $this->assertSame('handler exploded', $failed['error']->getMessage());
        $this->assertSame(['unknown', null, null], [$unknown['status'], $unknown['value'], $unknown['error']]);
        $this->assertSame(['denied', null, null], [$denied['status'], $denied['value'], $denied['error']]);
        $this->assertCount(4, \array_unique([$ok['status'], $failed['status'], $unknown['status'], $denied['status']]));
    }

    public function testCheckedInternalPathHasNoGateButSeparatesFailedAndUnknown(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $controller->apiAllow = false;
        $this->injectController($module, $controller);
        $this->registerAPI($module, 'publish', 'api/publish');
        $this->registerAPI($module, 'boom', 'api/throws');

        $this->assertSame('ok', $module->executeInternalChecked('publish', ['x'])['status']);
        $this->assertSame('failed', $module->executeInternalChecked('boom', [])['status']);
        $this->assertSame('unknown', $module->executeInternalChecked('ghost', [])['status']);
        $this->assertSame([], $controller->apiSeen, 'internal path must not consult __onAPICall');
    }

    public function testCheckedFailureStillRunsTheOnErrorHook(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $this->registerAPI($module, 'boom', 'api/throws');

        $outcome = $this->executeChecked($module, 'boom', []);

        $this->assertSame('failed', $outcome['status']);
        $this->assertCount(1, $controller->errors);
        $this->assertSame($outcome['error'], $controller->errors[0]['exception']);
    }

    public function testMixedResultContractIsUnchangedForTheOldMethods(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $this->registerAPI($module, 'publish', 'api/publish');
        $this->registerAPI($module, 'boom', 'api/throws');

        $this->assertSame('published:a', $module->executeInternalCommand('publish', ['a']));
        $this->assertNull($module->executeInternalCommand('boom', []));
        $this->assertNull($module->executeInternalCommand('ghost', []));
        $controller->apiAllow = false;
        $this->assertNull($module->execute($module->getModuleInfo(), 'publish', ['a']));
    }

    public function testThrowingCommandWritesNothingToStdoutInCliMode(): void
    {
        $module = $this->createModule();
        $controller = new class($module) extends Controller {};
        $this->injectController($module, $controller);
        $this->registerAPI($module, 'boom', 'api/throws');
        $stderr = \fopen('php://memory', 'w+b');
        ErrorRenderer::useCliStream($stderr);

        try {
            \ob_start();
            $result = $module->executeInternalCommand('boom', []);
            $stdout = (string) \ob_get_clean();
            \rewind($stderr);
            $trace = (string) \stream_get_contents($stderr);
        } finally {
            ErrorRenderer::useCliStream(null);
        }

        $this->assertNull($result);
        $this->assertSame('', $stdout, 'a command failure must never put a trace on stdout');
        $this->assertStringContainsString('handler exploded', $trace);
        $this->assertStringContainsString('Stack trace', $trace);
    }

    #[DataProvider('controllerMethodNames')]
    public function testRegisteringACommandNamedLikeAControllerMethodThrows(string $name): void
    {
        $module = $this->createModule();

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('`' . \ltrim($name, '#') . '`');
        $module->addAPICommand($name, 'api/publish');
    }

    public function testEveryPublicControllerMethodIsReservedWithoutAHandKeptList(): void
    {
        $module = $this->createModule();
        $methods = (new ReflectionClass(Controller::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $this->assertGreaterThan(20, \count($methods), 'fixture wiring: Controller must expose its methods');

        foreach ($methods as $method) {
            if ($method->isStatic() || !\preg_match('/^[a-z]\w*$/i', $method->getName())) {
                continue;
            }
            try {
                $module->addAPICommand($method->getName(), 'api/publish');
                $this->fail('`' . $method->getName() . '` is a Controller method and must not register');
            } catch (ModuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNameOfASubclassMethodAndOrdinaryNamesStillRegister(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        $module->addAPICommand('directPing', 'api/no_such_file');
        $module->addAPICommand('publish', 'api/publish');

        $this->assertSame('direct:pong', $this->executeAPI($module, 'directPing', []));
        $this->assertTrue($module->hasAPICommand('publish'));
    }

    // ------------------------------------------------------------- Bridge gate

    public function testBridgeGateDefaultIsOpenAndExecutes(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);

        $module->addBridgeCommand('ping', 'bridge/ping');

        $result = $module->executeBridgeCommand('siteB@1.0.0', 'ping', ['hi']);

        $this->assertSame('pong:hi', $result);
        $this->assertSame([['siteB@1.0.0', 'ping']], $controller->bridgeSeen);
    }

    public function testBridgeGateDenialReturnsNullBeforeHandler(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $controller->bridgeAllow = false;
        $this->injectController($module, $controller);

        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->assertNull($module->executeBridgeCommand('attacker@0.0.1', 'ping', ['hi']));
        $this->assertCount(1, $controller->bridgeSeen, 'denied calls must still be visible to the gate');
    }

    public function testBridgeSecretSetDeniesUnsignedCallBeforeGate(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->setSecret();

        $this->assertNull(
            $module->executeBridgeCommand('siteB@1.0.0', 'ping', ['hi']),
            'with RAZY_BRIDGE_SECRET set, unsigned calls are denied',
        );
        $this->assertSame([], $controller->bridgeSeen, 'HMAC check must run BEFORE __onBridgeCall');
    }

    public function testBridgeValidSignatureExecutesThroughGate(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->setSecret();

        $env = BridgeSignature::signedPayload(self::SECRET, 'siteB@1.0.0', $this->moduleCode($module), 'ping', ['hi']);

        $result = $module->executeBridgeCommand('siteB@1.0.0', 'ping', ['hi'], $this->meta($env));

        $this->assertSame('pong:hi', $result);
        $this->assertSame([['siteB@1.0.0', 'ping']], $controller->bridgeSeen, 'a valid signature still passes the module gate (two layers)');
    }

    public function testBridgeWrongSecretDenied(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->setSecret();

        $forged = BridgeSignature::signedPayload('attacker-guessed-secret', 'siteB@1.0.0', $this->moduleCode($module), 'ping', ['hi']);

        $this->assertNull($module->executeBridgeCommand('siteB@1.0.0', 'ping', ['hi'], $this->meta($forged)));
    }

    public function testBridgeTamperedArgsDenied(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->setSecret();

        $env = BridgeSignature::signedPayload(self::SECRET, 'siteB@1.0.0', $this->moduleCode($module), 'ping', ['hi']);

        // Wire-level mutation: signature valid for ['hi'], delivered args differ.
        $this->assertNull($module->executeBridgeCommand('siteB@1.0.0', 'ping', ['EVIL'], $this->meta($env)));
    }

    public function testBridgeSourceSwapDenied(): void
    {
        $module = $this->createModule();
        $controller = $this->createGateController($module);
        $this->injectController($module, $controller);
        $module->addBridgeCommand('ping', 'bridge/ping');

        $this->setSecret();

        // Attacker holds a legitimately signed envelope for 'low-priv@1.0' and
        // replays it claiming a trusted source — the classic self-declared-source hole.
        $env = BridgeSignature::signedPayload(self::SECRET, 'low-priv@1.0', $this->moduleCode($module), 'ping', ['hi']);

        $this->assertNull($module->executeBridgeCommand('trusted@9.9.9', 'ping', ['hi'], $this->meta($env)));
    }

    // ------------------------------------------------------------------ helpers

    private function setSecret(): void
    {
        $_ENV['RAZY_BRIDGE_SECRET'] = self::SECRET;
        $_SERVER['RAZY_BRIDGE_SECRET'] = self::SECRET;

        $this->assertSame(self::SECRET, BridgeSignature::secretFromEnv(), 'fixture wiring: secret must be observable by the gate');
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @return array{ts: int, nonce: string, sig: string}
     */
    private function meta(array $envelope): array
    {
        return \array_intersect_key($envelope, \array_flip(['ts', 'nonce', 'sig']));
    }

    private function moduleCode(Module $module): string
    {
        return $module->getModuleInfo()->getCode();
    }

    private function registerAPI(Module $module, string $command, string $path): void
    {
        $this->registry($module)->addAPICommand($command, $path, $this->closureLoader($module));
    }

    /**
     * @param list<mixed> $args
     */
    private function executeAPI(Module $module, string $command, array $args): mixed
    {
        return $this->registry($module)->execute(
            $module->getModuleInfo(),
            $command,
            $args,
            $this->controllerOf($module),
            $this->closureLoader($module),
        );
    }

    /**
     * @param list<mixed> $args
     *
     * @return array{status: string, value: mixed, error: ?Throwable}
     */
    private function executeChecked(Module $module, string $command, array $args): array
    {
        return $module->executeChecked($module->getModuleInfo(), $command, $args);
    }

    /**
     * Gate-recording controller. Properties are public so tests steer decisions.
     */
    private function createGateController(Module $module): Controller
    {
        return new class($module) extends Controller {
            public bool $apiAllow = true;

            public bool $bridgeAllow = true;

            /** @var list<array{module: ModuleInfo, method: string}> */
            public array $apiSeen = [];

            /** @var list<array{0: string, 1: string}> */
            public array $bridgeSeen = [];

            /** @var list<array{path: string, exception: Throwable}> */
            public array $errors = [];

            public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
            {
                $this->apiSeen[] = ['module' => $module, 'method' => $method];

                return $this->apiAllow;
            }

            public function __onBridgeCall(string $sourceDistributor, string $command): bool
            {
                $this->bridgeSeen[] = [$sourceDistributor, $command];

                return $this->bridgeAllow;
            }

            public function __onError(string $path, Throwable $exception): void
            {
                $this->errors[] = ['path' => $path, 'exception' => $exception];
            }

            public function directPing(): string
            {
                return 'direct:pong';
            }
        };
    }

    private function createModule(): Module
    {
        $dist = $this->createMock(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('test-dist');
        $dist->method('getSiteURL')->willReturn('/site/');
        $dist->method('getContainer')->willReturn(null);
        $dist->method('getRouter')->willReturn($this->createMock(RouteDispatcher::class));
        $dist->method('getRegistry')->willReturn($this->createMock(ModuleRegistry::class));
        $dist->method('getPrerequisites')->willReturn($this->createMock(PrerequisiteResolver::class));

        return new Module($dist, $this->modulePath, [
            'module_code' => 'test/TestModule',
            'author' => 'Test Author',
        ]);
    }

    private function registry(Module $module): Module\CommandRegistry
    {
        $ref = new ReflectionProperty(Module::class, 'commands');
        $ref->setAccessible(true);

        return $ref->getValue($module);
    }

    private function closureLoader(Module $module): Module\ClosureLoader
    {
        $ref = new ReflectionProperty(Module::class, 'closureLoader');
        $ref->setAccessible(true);

        return $ref->getValue($module);
    }

    private function injectController(Module $module, Controller $controller): void
    {
        $ref = new ReflectionProperty(Module::class, 'controller');
        $ref->setAccessible(true);
        $ref->setValue($module, $controller);
    }

    private function controllerOf(Module $module): Controller
    {
        $ref = new ReflectionProperty(Module::class, 'controller');
        $ref->setAccessible(true);

        $controller = $ref->getValue($module);
        $this->assertInstanceOf(Controller::class, $controller, 'test wiring: injectController() must run first');

        return $controller;
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
