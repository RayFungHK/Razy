<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\RouteDispatcher;
use Razy\Exception\HttpException;
use Razy\Exception\RedirectException;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Route;

/**
 * MODULE-LIFECYCLE.md L3: the readiness gate at the dispatcher — the answer
 * the ERP paid 15 handler-whitelist copies for, now enforced by the framework
 * once, before any handler runs.
 */
#[CoversNothing]
final class ModuleLifecycleL3Test extends TestCase
{
    private RouteDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RouteDispatcher();
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        parent::tearDown();
    }

    // ── the Route entity carries the gate ─────────────────────────────────

    public function testRouteEntityCarriesReadyGateFluently(): void
    {
        $route = new Route('main');
        self::assertFalse($route->hasReadyGate());

        $route->ready('erp/holiday');

        self::assertTrue($route->hasReadyGate());
        self::assertSame('erp/holiday', $route->getReadyGate());
    }

    public function testLazyRegistrationStoresTheGate(): void
    {
        $module = $this->moduleMock('acme/shop', 'vendor/shop');
        $this->dispatcher->setLazyRoute($module, 'cart', (new Route('cart_main'))->ready('vendor/ledger'));

        $found = $this->findEntryBySuffix('/acme/shop/cart');
        self::assertNotNull($found, 'lazy route registered under its alias path');
        self::assertSame('vendor/ledger', $found['ready_gate']);

        // a plain string path keeps the entry gate-free (zero-cost majority)
        $this->dispatcher->setLazyRoute($module, 'plain', 'plain_handler');
        $plain = $this->findEntryBySuffix('/acme/shop/plain');
        self::assertNotNull($plain);
        self::assertNull($plain['ready_gate']);
    }

    public function testStandardRegistrationStoresTheGate(): void
    {
        $module = $this->moduleMock('acme/shop', 'vendor/shop');
        $this->dispatcher->setRoute($module, '/checkout', (new Route('checkout_main'))->ready('self'));

        $found = $this->findEntryBySuffix('/checkout');
        self::assertNotNull($found);
        self::assertSame('self', $found['ready_gate']);
    }

    // ── the gate verdict ───────────────────────────────────────────────────

    public function testReadyGatePassesSilentlyWhenProbeSaysReady(): void
    {
        $seen = null;
        $this->dispatcher->setReadinessProbe(function (string $code) use (&$seen): bool {
            $seen = $code;

            return true;
        });

        \ob_start();
        $this->dispatcher->evaluateReadinessGate('erp/holiday', $this->moduleMock(), $this->registryMock([]), 'http://x/');
        self::assertSame('', (string) \ob_get_clean());
        self::assertSame('erp/holiday', $seen, 'the probe answers per module code');
    }

    public function testSelfGateResolvesToTheOwningModuleCode(): void
    {
        $seen = null;
        $this->dispatcher->setReadinessProbe(function (string $code) use (&$seen): bool {
            $seen = $code;

            return true;
        });

        $this->dispatcher->evaluateReadinessGate('self', $this->moduleMock('acme/shop', 'acme/shop'), $this->registryMock([]), 'http://x/');

        self::assertSame('acme/shop', $seen, "'self' is the owning module, resolved at the door");
    }

    public function testNotReadyAnswers503NamingModuleAndFix(): void
    {
        $this->dispatcher->setReadinessProbe(fn (string $code): bool => false);

        \ob_start();

        try {
            $this->dispatcher->evaluateReadinessGate('erp/holiday', $this->moduleMock(), $this->registryMock([]), 'http://x/');
            self::fail('a not-ready gate must end the request');
        } catch (HttpException $e) {
            $out = (string) \ob_get_clean();
            self::assertSame(503, $e->getCode());
            self::assertStringContainsString('erp/holiday', $out, 'the page names the blocking module');
            self::assertStringContainsString('Razy.phar migrate', $out, 'and prints the exact fix command');
        }
    }

    public function testXhrRequesterGetsTheJsonShape(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->dispatcher->setReadinessProbe(fn (string $code): bool => false);

        \ob_start();

        try {
            $this->dispatcher->evaluateReadinessGate('erp/holiday', $this->moduleMock(), $this->registryMock([]), 'http://x/');
            self::fail('a not-ready gate must end the request');
        } catch (HttpException $e) {
            $out = (string) \ob_get_clean();
            $decoded = \json_decode($out, true);
            self::assertIsArray($decoded, 'XHR gets JSON, not an HTML page');
            self::assertSame('module-not-ready', $decoded['error']);
            self::assertSame('erp/holiday', $decoded['module']);
            self::assertArrayHasKey('fix', $decoded);
        }
    }

    public function testDeclaredWizardProvisionFlipsTheSameGateTo302(): void
    {
        // Q2's declared exception: the SAME not-ready fact answers 302 into
        // the framework wizard path when the gated module declares wizard
        // provision. (The token-gated runner itself is L4.)
        $wizard = $this->moduleMock('erp/holiday', 'erp/holiday', provision: 'wizard');
        $this->dispatcher->setReadinessProbe(fn (string $code): bool => false);

        try {
            $this->dispatcher->evaluateReadinessGate('erp/holiday', $this->moduleMock(), $this->registryMock(['erp/holiday' => $wizard]), 'http://x/');
            self::fail('wizard provision must redirect, not 503');
        } catch (RedirectException $e) {
            self::assertSame(302, $e->getCode());
            self::assertStringContainsString('__setup/erp%2Fholiday', $e->getMessage(), 'the L4 runner\'s reserved path, code url-encoded');
        }
    }

    public function testGateWithoutProbeWarnsAndRefuses(): void
    {
        // No probe = framework wiring bug: loud, and closed (never open).
        $warned = false;
        \set_error_handler(static function (int $level) use (&$warned): bool {
            $warned = $level === E_USER_WARNING;

            return true;
        });

        \ob_start();

        try {
            $this->dispatcher->evaluateReadinessGate('erp/holiday', $this->moduleMock(), $this->registryMock([]), 'http://x/');
            self::fail('a probeless gate must refuse');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getCode());
        } finally {
            \restore_error_handler();
            \ob_end_clean();
        }

        self::assertTrue($warned, 'the wiring bug is announced, never silent');
    }

    public function testUngatedRouteReturnsImmediately(): void
    {
        // the zero-cost guarantee for the overwhelming majority
        $this->dispatcher->evaluateReadinessGate(null, $this->moduleMock(), $this->registryMock([]), 'http://x/');
        $this->dispatcher->evaluateReadinessGate('', $this->moduleMock(), $this->registryMock([]), 'http://x/');
        self::assertTrue(true, 'reached here without probe, registry, or output');
    }

    // ── registration-time wiring (Agent) ──────────────────────────────────

    public function testReadyRoutesAppliesModuleWideGateToPlainPaths(): void
    {
        $registered = [];
        $module = $this->moduleMock('acme/shop', 'acme/shop');
        $module->method('addLazyRoute')->willReturnCallback(
            function (string $route, mixed $path) use (&$registered, $module): Module {
                $registered[$route] = $path;

                return $module;
            },
        );

        $agent = new Agent($module);
        $agent->readyRoutes('self')->addLazyRoute('dashboard', 'main');

        self::assertInstanceOf(Route::class, $registered['dashboard'] ?? null, 'the plain path was entity-ized to carry the gate');
        self::assertSame('self', $registered['dashboard']->getReadyGate());
    }

    public function testExplicitRouteGateWinsOverModuleWideDefault(): void
    {
        $registered = [];
        $module = $this->moduleMock('acme/shop', 'acme/shop');
        $module->method('addLazyRoute')->willReturnCallback(
            function (string $route, mixed $path) use (&$registered, $module): Module {
                $registered[$route] = $path;

                return $module;
            },
        );

        $agent = new Agent($module);
        $agent->readyRoutes('self')->addLazyRoute('report', (new Route('rep'))->ready('vendor/ledger'));

        self::assertSame('vendor/ledger', $registered['report']->getReadyGate(), 'explicit beats default');
    }

    // ── framework plumbing ────────────────────────────────────────────────

    public function testDispatchCallsTheGateDoorBeforeExecuting(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor/RouteDispatcher.php');
        self::assertStringContainsString(
            "\$this->evaluateReadinessGate(\$data['ready_gate'] ?? null, \$data['module'], \$registry, \$siteURL);",
            $source,
            'matchRoute passes every matched route through the door',
        );
        $executorAt = \strpos($source, '// Determine the executor');
        $gateAt = \strpos($source, '$this->evaluateReadinessGate($data[\'ready_gate\']');
        self::assertLessThan($executorAt, $gateAt, 'the gate sits BEFORE the executor is even resolved');
    }

    public function testDistributorWiresTheL1PredicateAsTheProbe(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString('setReadinessProbe', $source);
        self::assertStringContainsString('fn (string $moduleCode): bool => $this->moduleReady($moduleCode)', $source, 'one readiness policy, one door (L1 predicate = L3 probe)');
    }

    public function testHandlerSideHelperChainIsDeliveredOnce(): void
    {
        // The helper the L1 commit promised (deferred to L3 as the first
        // real consumer): Controller -> Module proxy (hasModule lineage) ->
        // the L1 predicate. ONE door — handlers must not re-derive readiness.
        $controller = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Controller.php');
        $module = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Module.php');
        self::assertStringContainsString('final public function moduleReady(string $moduleCode): bool', $controller);
        self::assertStringContainsString('return $this->module->moduleReady($moduleCode);', $controller);
        self::assertStringContainsString('return $this->distributor->moduleReady($moduleCode);', $module);
    }

    public function testAgentModuleWideGateIsSourcePinned(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Agent.php');
        self::assertStringContainsString("public function readyRoutes(string \$moduleCode = 'self')", $source);
        self::assertStringContainsString("\$type === 'Script'", $source, 'CLI script routes are never gated');
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findEntryBySuffix(string $suffix): ?array
    {
        foreach ($this->dispatcher->getRoutes() as $routeKey => $data) {
            if (\str_ends_with(\rtrim((string) $routeKey, '/'), \rtrim($suffix, '/'))) {
                return $data;
            }
        }

        return null;
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function moduleMock(string $alias = 'acme/shop', string $code = 'acme/shop', string $provision = 'deploy'): Module
    {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getAlias')->willReturn($alias);
        $moduleInfo->method('getCode')->willReturn($code);
        $moduleInfo->method('getProvision')->willReturn($provision);

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($moduleInfo);
        $module->method('getStatus')->willReturn(ModuleStatus::Loaded);

        return $module;
    }

    /**
     * @param array<string, Module> $modules
     */
    private function registryMock(array $modules): ModuleRegistry
    {
        $registry = $this->createMock(ModuleRegistry::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $code): ?Module => $modules[$code] ?? null,
        );

        return $registry;
    }
}
