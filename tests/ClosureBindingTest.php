<?php

declare(strict_types=1);

namespace Razy\Tests;

use BadMethodCallException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
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
use ReflectionProperty;

/**
 * Internal closure binding ({@see Agent::bind()}, {@see Controller::__call()}).
 */
#[CoversClass(ClosureLoader::class)]
class ClosureBindingTest extends TestCase
{
    private string $tempDir;

    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_bind_test_' . \uniqid();
        $this->modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_module';
        $controllerDir = $this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'controller';

        \mkdir($controllerDir . DIRECTORY_SEPARATOR . 'api', 0o777, true);

        \file_put_contents($this->modulePath . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'package.php', '<?php return ["alias" => "testmod"];');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'TestModule.php', '<?php
use Razy\Controller;
return new class(null) extends Controller {
    public function __onInit($agent): bool { return true; }
    public function __onLoad($agent): bool { return true; }
    public function __onReady(): void {}
    public function __onRequire(): bool { return true; }
    public function __onDispose(): void {}
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'internal_helper.php', '<?php
return function (string $token): string {
    return "helper:{$token}";
};');

        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'caller.php', '<?php
return function (): string {
    return $this->internalHelper("nested");
};');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testAgentBindArrayRegistersMultipleMethods(): void
    {
        $module = $this->createModule();
        $agent = $this->getAgent($module);

        $agent->bind([
            'alpha' => 'api/internal_helper',
            'beta' => 'api/caller',
        ]);

        $this->assertSame(
            \str_replace('/', DIRECTORY_SEPARATOR, 'api/internal_helper'),
            $module->getBinding('alpha'),
        );
        $this->assertSame(
            \str_replace('/', DIRECTORY_SEPARATOR, 'api/caller'),
            $module->getBinding('beta'),
        );
    }

    public function testControllerMagicCallResolvesBoundClosure(): void
    {
        $module = $this->createModule();
        $controller = $this->createTestController($module);
        $this->injectController($module, $controller);

        $agent = $this->getAgent($module);
        $agent->bind('internalHelper', 'api/internal_helper');

        $this->assertSame('helper:ok', $controller->internalHelper('ok'));
    }

    public function testBoundClosureCanCallAnotherBoundMethod(): void
    {
        $module = $this->createModule();
        $controller = $this->createTestController($module);
        $this->injectController($module, $controller);

        $agent = $this->getAgent($module);
        $agent->bind([
            'internalHelper' => 'api/internal_helper',
            'runCaller' => 'api/caller',
        ]);

        $this->assertSame('helper:nested', $controller->runCaller());
    }

    public function testBindIsNotRegisteredAsApiCommand(): void
    {
        $module = $this->createModule();
        $controller = $this->createTestController($module);
        $this->injectController($module, $controller);

        $registry = $this->getCommandRegistry($module);
        $loader = $this->getClosureLoader($module);

        $module->bind('internalHelper', 'api/internal_helper');

        $this->assertNull(
            $registry->execute(
                $module->getModuleInfo(),
                'internalHelper',
                ['x'],
                $controller,
                $loader,
            ),
        );
    }

    public function testHashPrefixRegistersBothApiAndInternalBinding(): void
    {
        $module = $this->createModule();
        $controller = $this->createTestController($module);
        $this->injectController($module, $controller);

        $registry = $this->getCommandRegistry($module);
        $loader = $this->getClosureLoader($module);

        $module->addAPICommand('#dualHelper', 'api/internal_helper', $loader);

        $this->assertSame('helper:via-api', $registry->execute(
            $module->getModuleInfo(),
            'dualHelper',
            ['via-api'],
            $controller,
            $loader,
        ));
        $this->assertSame('helper:via-this', $controller->dualHelper('via-this'));
    }

    public function testUnboundMagicCallThrows(): void
    {
        $module = $this->createModule();
        $controller = $this->createTestController($module);
        $this->injectController($module, $controller);

        $this->expectException(BadMethodCallException::class);
        $controller->missingMethod();
    }

    public function testAgentBindRejectsInvalidMethodName(): void
    {
        $module = $this->createModule();
        $agent = $this->getAgent($module);

        $this->expectException(InvalidArgumentException::class);
        $agent->bind('9bad', 'api/internal_helper');
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

    private function getAgent(Module $module): Agent
    {
        $ref = new ReflectionProperty(Module::class, 'agent');
        $ref->setAccessible(true);

        return $ref->getValue($module);
    }

    private function getCommandRegistry(Module $module): CommandRegistry
    {
        $ref = new ReflectionProperty(Module::class, 'commands');
        $ref->setAccessible(true);

        return $ref->getValue($module);
    }

    private function getClosureLoader(Module $module): ClosureLoader
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

    private function createTestController(Module $module): Controller
    {
        return new class($module) extends Controller {
        };
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
