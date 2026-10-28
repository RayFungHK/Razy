<?php

/**
 * RZ-48 — razymod/apps and razymod/permissions boot on a real Module + Agent.
 *
 * Their other tests mock Agent and require the api/ closures directly, so two
 * defects that stopped both modules from loading on a real distributor went
 * unseen: the controller file returned a named instance (Module::buildController()
 * accepts only an anonymous class) and the commands had hyphenated names that
 * Agent::addAPICommand() refuses. Here the real Module reads the shipped
 * module.php / package.php, builds the shipped controller, runs __onInit with
 * the real Agent and executes one command through the real command registry.
 * Only the Distributor is a stub: these modules register nothing on it.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\ModuleScanner;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ModuleStatus;

#[CoversClass(Module::class)]
#[CoversClass(Agent::class)]
class RazymodBootTest extends TestCase
{
    /** @var (Closure(string): bool)|null */
    private ?Closure $autoloader = null;

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            \spl_autoload_unregister($this->autoloader);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function modules(): array
    {
        return [
            'apps' => ['apps', ['validate', 'compile', 'hash', 'assignIds', 'weight']],
            'permissions' => ['permissions', ['can', 'canAny', 'abilities', 'defineAbility', 'rolesOf', 'assignRole', 'revokeRole', 'auditActor']],
        ];
    }

    /**
     * @param list<string> $commands
     */
    #[DataProvider('modules')]
    public function testModuleBootsAndRegistersEveryCommand(string $folder, array $commands): void
    {
        $module = $this->boot($folder);

        $this->assertNotSame(ModuleStatus::Failed, $module->getStatus(), '__onInit returned false');
        $this->assertSame($commands, \array_keys($module->getAPICommands()));
    }

    public function testAppsCommandRunsThroughTheRealRegistry(): void
    {
        $result = $this->boot('apps')->executeInternalCommand('hash', [['schema' => 1, 'code' => 'crm']]);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $result['hash']);
    }

    public function testPermissionsCommandRunsThroughTheRealRegistry(): void
    {
        // No database configured: the hot path fails closed, as its contract says (a plain bool, never throws)
        $result = $this->boot('permissions')->executeInternalCommand('canAny', [['reports.view'], 'user:1']);

        $this->assertFalse($result);
    }

    private function boot(string $folder): Module
    {
        $path = SYSTEM_ROOT . '/modules/' . $folder;
        $dist = $this->createStub(Distributor::class);
        $dist->method('isStrict')->willReturn(false);
        $dist->method('getCode')->willReturn('boot-test');
        $dist->method('getSiteURL')->willReturn('/');
        $dist->method('getContainer')->willReturn(null);
        $dist->method('getRouter')->willReturn($this->createStub(RouteDispatcher::class));
        $dist->method('getRegistry')->willReturn($this->createStub(ModuleRegistry::class));
        $dist->method('getPrerequisites')->willReturn($this->createStub(PrerequisiteResolver::class));

        $module = new Module($dist, $path, require $path . '/module.php');
        // The distributor's own module autoloader (library/ classes), as at runtime
        $code = $module->getModuleInfo()->getCode();
        $scanner = new ModuleScanner($dist);
        $this->autoloader = static fn (string $class): bool => $scanner->autoload($class, [$code => $module], 'boot-test');
        \spl_autoload_register($this->autoloader);
        $this->assertTrue($module->initialize());

        return $module;
    }
}
