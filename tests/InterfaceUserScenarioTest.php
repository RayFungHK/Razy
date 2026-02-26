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
 * User-scenario tests simulating how module developers would use
 * Razy's core interfaces through mocks in their own test suites.
 *
 * These tests validate that the interface contracts are sufficient
 * for typical usage patterns: service registries, CRUD repositories,
 * template rendering, cross-module communication, and event-driven
 * architectures.
 */
#[CoversClass(ContainerInterface::class)]
class InterfaceUserScenarioTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // Scenario 1: Service Registry / DI Container Usage
    // ═══════════════════════════════════════════════════════════

    /**
     * A module developer registers a service class and resolves it.
     */
    public function testServiceRegistrationAndResolution(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $service = new \stdClass();
        $service->name = 'UserService';

        $container->method('has')->willReturnCallback(fn(string $id) => $id === 'userService');
        $container->method('get')->willReturnCallback(function (string $id) use ($service) {
            if ($id === 'userService') return $service;
            throw new \RuntimeException("Service not found: $id");
        });

        // Developer checks and resolves
        $this->assertTrue($container->has('userService'));
        $this->assertFalse($container->has('nonExistent'));
        $this->assertSame($service, $container->get('userService'));
        $this->assertSame('UserService', $container->get('userService')->name);
    }

    /**
     * Developer registers a factory via bind() and resolves fresh instances via make().
     */
    public function testFactoryBindingCreatesNewInstances(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            $obj = new \stdClass();
            $obj->id = $callCount;
            return $obj;
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('bind')->with('widget', $this->isInstanceOf(Closure::class));
        $container->method('make')->willReturnCallback(fn() => $factory());

        $container->bind('widget', $factory);
        $a = $container->make('widget');
        $b = $container->make('widget');

        $this->assertNotSame($a, $b, 'Factory should produce distinct instances');
        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    /**
     * Developer registers a singleton for a shared cache service.
     */
    public function testSingletonReturnsSharedInstance(): void
    {
        $shared = new \stdClass();
        $shared->connections = 0;

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('singleton')->with('cache', $this->isInstanceOf(Closure::class));
        $container->method('get')->with('cache')->willReturn($shared);

        $container->singleton('cache', fn() => $shared);

        $first = $container->get('cache');
        $second = $container->get('cache');
        $this->assertSame($first, $second, 'Singleton should return same instance');
    }

    /**
     * Developer sets a pre-built config object via instance().
     */
    public function testInstanceRegistration(): void
    {
        $config = new \stdClass();
        $config->debug = true;
        $config->env = 'testing';

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('instance')->with('config', $config);
        $container->method('get')->with('config')->willReturn($config);

        $container->instance('config', $config);
        $resolved = $container->get('config');
        $this->assertTrue($resolved->debug);
        $this->assertSame('testing', $resolved->env);
    }

    /**
     * Developer uses make() with constructor parameters.
     */
    public function testMakeWithParameters(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('make')->willReturnCallback(function (string $abstract, array $params) {
            $obj = new \stdClass();
            $obj->class = $abstract;
            $obj->params = $params;
            return $obj;
        });

        $mailer = $container->make('Mailer', ['host' => 'smtp.example.com', 'port' => 587]);
        $this->assertSame('Mailer', $mailer->class);
        $this->assertSame('smtp.example.com', $mailer->params['host']);
        $this->assertSame(587, $mailer->params['port']);
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 2: CRUD Repository with DatabaseInterface
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer builds a User repository using DatabaseInterface.
     * Simulates: check table → prepare → execute → read results.
     */
    public function testCrudRepositoryFindById(): void
    {
        $stmt = $this->createMock(Statement::class);
        $query = $this->createMock(Query::class);

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('isTableExists')->with('users')->willReturn(true);
        $db->method('getPrefix')->willReturn('app_');
        $db->method('prepare')->with('SELECT * FROM app_users WHERE id = ?')->willReturn($stmt);
        $db->method('execute')->with($stmt)->willReturn($query);

        // Repository find flow
        $this->assertTrue($db->isTableExists('users'));
        $prefix = $db->getPrefix();
        $prepared = $db->prepare("SELECT * FROM {$prefix}users WHERE id = ?");
        $this->assertInstanceOf(Statement::class, $prepared);
        $result = $db->execute($prepared);
        $this->assertInstanceOf(Query::class, $result);
    }

    /**
     * Developer checks for table existence before migration.
     */
    public function testMigrationCheckTablesExist(): void
    {
        $tables = ['users', 'posts', 'comments', 'sessions'];
        $existing = ['users', 'posts'];

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('isTableExists')->willReturnCallback(fn(string $t) => in_array($t, $existing));

        $missing = [];
        foreach ($tables as $table) {
            if (!$db->isTableExists($table)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(['comments', 'sessions'], $missing);
    }

    /**
     * Developer inserts a record and verifies prepare/execute cycle.
     */
    public function testInsertRecordFlow(): void
    {
        $stmt = $this->createMock(Statement::class);
        $query = $this->createMock(Query::class);

        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->once())->method('prepare')
            ->with('INSERT INTO app_users (name, email) VALUES (?, ?)')
            ->willReturn($stmt);
        $db->expects($this->once())->method('execute')->with($stmt)->willReturn($query);

        $prepared = $db->prepare('INSERT INTO app_users (name, email) VALUES (?, ?)');
        $result = $db->execute($prepared);
        $this->assertInstanceOf(Query::class, $result);
    }

    /**
     * Developer executes multiple queries in a batch scenario.
     */
    public function testBatchQueryExecution(): void
    {
        $queries = [
            'DELETE FROM app_sessions WHERE expired < NOW()',
            'UPDATE app_users SET last_cleanup = NOW()',
            'INSERT INTO app_logs (action) VALUES (?)',
        ];

        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->exactly(3))->method('prepare')->willReturnCallback(function () {
            return $this->createMock(Statement::class);
        });
        $db->expects($this->exactly(3))->method('execute')->willReturnCallback(function () {
            return $this->createMock(Query::class);
        });

        foreach ($queries as $sql) {
            $stmt = $db->prepare($sql);
            $result = $db->execute($stmt);
            $this->assertInstanceOf(Query::class, $result);
        }
    }

    /**
     * Prefixed table access pattern (multi-tenant).
     */
    #[DataProvider('tablePrefixProvider')]
    public function testPrefixedTableAccess(string $prefix, string $tableName, string $expectedFullName): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getPrefix')->willReturn($prefix);

        $fullName = $db->getPrefix() . $tableName;
        $this->assertSame($expectedFullName, $fullName);
    }

    public static function tablePrefixProvider(): array
    {
        return [
            'standard prefix' => ['app_', 'users', 'app_users'],
            'empty prefix' => ['', 'users', 'users'],
            'tenant prefix' => ['tenant42_', 'orders', 'tenant42_orders'],
            'underscore only' => ['_', 'config', '_config'],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 3: Template Rendering Workflow
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer loads a template, assigns vars, simulating a page render.
     */
    public function testPageRenderWorkflow(): void
    {
        $source = $this->createMock(Source::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->willReturn($source);
        $tpl->method('assign')->willReturnSelf();

        // Load layout template
        $layout = $tpl->load('views/layout.tpl');
        $this->assertInstanceOf(Source::class, $layout);

        // Assign page variables via chaining
        $result = $tpl->assign('pageTitle', 'Dashboard')
                      ->assign('userName', 'Alice')
                      ->assign('notifications', 5);
        $this->assertSame($tpl, $result);
    }

    /**
     * Developer assigns bulk variables from an associative array.
     */
    public function testBulkVariableAssignment(): void
    {
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->expects($this->once())->method('assign')
            ->with([
                'title' => 'My App',
                'year' => 2025,
                'items' => ['a', 'b', 'c'],
            ])
            ->willReturnSelf();

        $result = $tpl->assign([
            'title' => 'My App',
            'year' => 2025,
            'items' => ['a', 'b', 'c'],
        ]);
        $this->assertSame($tpl, $result);
    }

    /**
     * Developer loads module-specific template with ModuleInfo context.
     */
    public function testModuleScopedTemplateLoading(): void
    {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getCode')->willReturn('blog');

        $source = $this->createMock(Source::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->with('views/post.tpl', $moduleInfo)->willReturn($source);

        $loaded = $tpl->load('views/post.tpl', $moduleInfo);
        $this->assertInstanceOf(Source::class, $loaded);
    }

    /**
     * Developer loads multiple templates for different page sections.
     */
    public function testMultipleTemplateLoading(): void
    {
        $headerSource = $this->createMock(Source::class);
        $bodySource = $this->createMock(Source::class);
        $footerSource = $this->createMock(Source::class);

        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->willReturnMap([
            ['views/header.tpl', null, $headerSource],
            ['views/body.tpl', null, $bodySource],
            ['views/footer.tpl', null, $footerSource],
        ]);

        $header = $tpl->load('views/header.tpl');
        $body = $tpl->load('views/body.tpl');
        $footer = $tpl->load('views/footer.tpl');

        // All are distinct Source instances
        $this->assertNotSame($header, $body);
        $this->assertNotSame($body, $footer);
        $this->assertInstanceOf(Source::class, $header);
        $this->assertInstanceOf(Source::class, $body);
        $this->assertInstanceOf(Source::class, $footer);
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 4: Cross-Module Communication
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer's module calls another module's API command.
     */
    public function testCrossModuleApiCall(): void
    {
        $callerInfo = $this->createMock(ModuleInfo::class);
        $callerInfo->method('getCode')->willReturn('orderModule');

        $targetModule = $this->createMock(ModuleInterface::class);
        $targetModule->method('getStatus')->willReturn(ModuleStatus::Loaded);
        $targetModule->method('execute')
            ->with($callerInfo, 'getUser', [42])
            ->willReturn(['id' => 42, 'name' => 'Alice', 'email' => 'alice@example.com']);

        // Verify target is loaded before calling
        $this->assertSame(ModuleStatus::Loaded, $targetModule->getStatus());

        $user = $targetModule->execute($callerInfo, 'getUser', [42]);
        $this->assertIsArray($user);
        $this->assertSame(42, $user['id']);
        $this->assertSame('Alice', $user['name']);
    }

    /**
     * Developer handles the case where target module's command returns null.
     */
    public function testCrossModuleApiReturnsNull(): void
    {
        $callerInfo = $this->createMock(ModuleInfo::class);
        $targetModule = $this->createMock(ModuleInterface::class);
        $targetModule->method('execute')->willReturn(null);

        $result = $targetModule->execute($callerInfo, 'unknownCommand', []);
        $this->assertNull($result);

        // Developer provides fallback
        $fallback = $result ?? 'default_value';
        $this->assertSame('default_value', $fallback);
    }

    /**
     * Developer checks module status before interaction.
     */
    #[DataProvider('moduleStatusCheckProvider')]
    public function testModuleStatusGating(ModuleStatus $status, bool $shouldProceed): void
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getStatus')->willReturn($status);

        $canCall = $module->getStatus() === ModuleStatus::Loaded;
        $this->assertSame($shouldProceed, $canCall);
    }

    public static function moduleStatusCheckProvider(): array
    {
        return [
            'Loaded – proceed'    => [ModuleStatus::Loaded, true],
            'InQueue – wait'      => [ModuleStatus::InQueue, false],
            'Processing – wait'   => [ModuleStatus::Processing, false],
            'Pending – wait'      => [ModuleStatus::Pending, false],
            'Unloaded – skip'     => [ModuleStatus::Unloaded, false],
            'Disabled – skip'     => [ModuleStatus::Disabled, false],
            'Failed – skip'       => [ModuleStatus::Failed, false],
        ];
    }

    /**
     * Developer queries module info for routing decisions.
     */
    public function testModuleInfoInspection(): void
    {
        $info = $this->createMock(ModuleInfo::class);
        $info->method('getCode')->willReturn('authModule');
        $info->method('getVersion')->willReturn('2.1.0');

        $module = $this->createMock(ModuleInterface::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('getStatus')->willReturn(ModuleStatus::Loaded);

        $moduleInfo = $module->getModuleInfo();
        $this->assertSame('authModule', $moduleInfo->getCode());
        $this->assertSame('2.1.0', $moduleInfo->getVersion());
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 5: Event-Driven Architecture
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer registers multiple event handlers during module init.
     */
    public function testModuleInitRegistersEvents(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->exactly(4))->method('listen')
            ->willReturnCallback(function (string $event, string|Closure $handler): void {
                // Just accept the registration
            });

        // Simulating __onInit registering handlers
        $dispatcher->listen('onUserCreated', 'handlers/user_created.php');
        $dispatcher->listen('onUserDeleted', 'handlers/user_deleted.php');
        $dispatcher->listen('onOrderPlaced', fn() => null);
        $dispatcher->listen('onPaymentReceived', 'handlers/payment.php');
    }

    /**
     * Developer checks if another module is listening to an event
     * before firing it (optimization pattern).
     */
    public function testConditionalEventFiring(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')->willReturnMap([
            ['notificationModule', 'onOrderPlaced', true],
            ['analyticsModule', 'onOrderPlaced', true],
            ['inactiveModule', 'onOrderPlaced', false],
        ]);

        $listeners = ['notificationModule', 'analyticsModule', 'inactiveModule'];
        $activeListeners = array_filter(
            $listeners,
            fn(string $mod) => $dispatcher->isEventListening($mod, 'onOrderPlaced')
        );

        $this->assertSame(['notificationModule', 'analyticsModule'], array_values($activeListeners));
    }

    /**
     * Developer registers a Closure-based event handler with state.
     */
    public function testClosureEventHandlerWithState(): void
    {
        $log = [];
        $handler = function () use (&$log) {
            $log[] = 'event_fired';
        };

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('listen')
            ->with('onCacheCleared', $this->isInstanceOf(Closure::class));

        $dispatcher->listen('onCacheCleared', $handler);

        // Simulate the handler being called
        $handler();
        $this->assertSame(['event_fired'], $log);
    }

    /**
     * Developer checks that no module listens to a deprecated event.
     */
    public function testDeprecatedEventNoListeners(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')->willReturn(false);

        $modules = ['core', 'auth', 'blog', 'shop'];
        foreach ($modules as $module) {
            $this->assertFalse(
                $dispatcher->isEventListening($module, 'onLegacyEvent'),
                "Module '$module' should not listen to deprecated event"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 6: Full Module Lifecycle Simulation
    // ═══════════════════════════════════════════════════════════

    /**
     * Simulates a module's complete lifecycle from init through disposal,
     * showing how a developer would validate their module through interfaces.
     */
    public function testFullModuleLifecycle(): void
    {
        // 1. Module starts pending
        $module = $this->createMock(ModuleInterface::class);
        $statusSequence = [
            ModuleStatus::Pending,
            ModuleStatus::Processing,
            ModuleStatus::InQueue,
            ModuleStatus::Loaded,
        ];
        $callIndex = 0;
        $module->method('getStatus')->willReturnCallback(function () use (&$callIndex, $statusSequence) {
            return $statusSequence[min($callIndex++, count($statusSequence) - 1)];
        });

        // Verify status progression
        $this->assertSame(ModuleStatus::Pending, $module->getStatus());
        $this->assertSame(ModuleStatus::Processing, $module->getStatus());
        $this->assertSame(ModuleStatus::InQueue, $module->getStatus());
        $this->assertSame(ModuleStatus::Loaded, $module->getStatus());
    }

    /**
     * Simulates a failed module that never reaches Loaded state.
     */
    public function testFailedModuleLifecycle(): void
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getStatus')->willReturn(ModuleStatus::Failed);
        $module->method('execute')->willReturn(null);

        $info = $this->createMock(ModuleInfo::class);
        $this->assertSame(ModuleStatus::Failed, $module->getStatus());

        // Attempting to call a failed module should return null
        $result = $module->execute($info, 'anyCommand', []);
        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 7: Multi-Service Integration (Realistic Controller)
    // ═══════════════════════════════════════════════════════════

    /**
     * Simulates a typical controller action: resolve DB from container,
     * query data, assign to template, handle events.
     */
    public function testControllerActionFullFlow(): void
    {
        // Setup container with DB + Template
        $stmt = $this->createMock(Statement::class);
        $query = $this->createMock(Query::class);

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getPrefix')->willReturn('shop_');
        $db->method('prepare')->willReturn($stmt);
        $db->method('execute')->willReturn($query);

        $source = $this->createMock(Source::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $tpl->method('load')->willReturn($source);
        $tpl->method('assign')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => in_array($id, ['db', 'template']));
        $container->method('get')->willReturnMap([
            ['db', $db],
            ['template', $tpl],
        ]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('isEventListening')->willReturn(true);

        // --- Controller action begins ---

        // 1. Resolve services
        $this->assertTrue($container->has('db'));
        $this->assertTrue($container->has('template'));
        $database = $container->get('db');
        $template = $container->get('template');

        // 2. Query database
        $prefix = $database->getPrefix();
        $this->assertSame('shop_', $prefix);
        $prepared = $database->prepare("SELECT * FROM {$prefix}products WHERE active = 1");
        $result = $database->execute($prepared);
        $this->assertInstanceOf(Query::class, $result);

        // 3. Render template
        $page = $template->load('views/products/list.tpl');
        $this->assertInstanceOf(Source::class, $page);
        $template->assign('products', [])
                 ->assign('total', 0)
                 ->assign('page', 1);

        // 4. Check event listeners
        $this->assertTrue($dispatcher->isEventListening('analytics', 'onPageView'));
    }

    /**
     * Developer builds a paginated list endpoint using Container + DB.
     */
    public function testPaginatedListEndpoint(): void
    {
        $stmtCount = $this->createMock(Statement::class);
        $stmtSelect = $this->createMock(Statement::class);
        $queryCount = $this->createMock(Query::class);
        $querySelect = $this->createMock(Query::class);

        $prepareIndex = 0;
        $stmts = [$stmtCount, $stmtSelect];
        $queries = [$queryCount, $querySelect];

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getPrefix')->willReturn('');
        $db->method('prepare')->willReturnCallback(function () use (&$prepareIndex, $stmts) {
            return $stmts[$prepareIndex++] ?? $this->createMock(Statement::class);
        });
        $db->method('execute')->willReturnCallback(function () use (&$prepareIndex, $queries) {
            return $queries[($prepareIndex - 1)] ?? $this->createMock(Query::class);
        });

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('db')->willReturn($db);

        // Simulate pagination: first COUNT query, then SELECT
        $resolved = $container->get('db');
        $countStmt = $resolved->prepare('SELECT COUNT(*) FROM articles');
        $countResult = $resolved->execute($countStmt);
        $this->assertInstanceOf(Query::class, $countResult);

        $selectStmt = $resolved->prepare('SELECT * FROM articles LIMIT 10 OFFSET 0');
        $selectResult = $resolved->execute($selectStmt);
        $this->assertInstanceOf(Query::class, $selectResult);
    }

    /**
     * Developer builds an authentication middleware that checks module status.
     */
    public function testAuthMiddlewarePattern(): void
    {
        $authModule = $this->createMock(ModuleInterface::class);
        $authModule->method('getStatus')->willReturn(ModuleStatus::Loaded);

        $callerInfo = $this->createMock(ModuleInfo::class);
        $callerInfo->method('getCode')->willReturn('dashboard');

        // Auth check via execute
        $authModule->method('execute')
            ->willReturnCallback(function (ModuleInfo $caller, string $cmd, array $args) {
                if ($cmd === 'checkPermission') {
                    $user = $args[0] ?? '';
                    $resource = $args[1] ?? '';
                    // Simulate permission check
                    return $user === 'admin' && $resource === 'settings';
                }
                if ($cmd === 'isAuthenticated') {
                    return ($args[0] ?? '') === 'valid_token';
                }
                return null;
            });

        // Test auth flow
        $this->assertTrue($authModule->execute($callerInfo, 'isAuthenticated', ['valid_token']));
        $this->assertFalse($authModule->execute($callerInfo, 'isAuthenticated', ['']));
        $this->assertTrue($authModule->execute($callerInfo, 'checkPermission', ['admin', 'settings']));
        $this->assertFalse($authModule->execute($callerInfo, 'checkPermission', ['user', 'settings']));
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 8: Error Handling & Edge Cases
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer handles container miss gracefully.
     */
    public function testContainerServiceMissHandling(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willThrowException(new \RuntimeException('Service not found'));

        $this->assertFalse($container->has('missingService'));

        $fallbackUsed = false;
        try {
            $container->get('missingService');
        } catch (\RuntimeException $e) {
            $fallbackUsed = true;
            $this->assertSame('Service not found', $e->getMessage());
        }
        $this->assertTrue($fallbackUsed);
    }

    /**
     * Developer performs a has-check before get (guard pattern).
     */
    public function testGuardedServiceResolution(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn(string $id) => $id === 'mailer');
        $container->method('get')->with('mailer')->willReturn(new \stdClass());

        // Guard pattern
        $service = $container->has('mailer') ? $container->get('mailer') : null;
        $this->assertNotNull($service);

        $service2 = $container->has('sms') ? $container->get('sms') : null;
        $this->assertNull($service2);
    }

    /**
     * Developer handles database operations when table doesn't exist.
     */
    public function testDatabaseTableNotExistsFallback(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('isTableExists')->with('legacy_users')->willReturn(false);

        if (!$db->isTableExists('legacy_users')) {
            // Developer would run migration
            $this->addToAssertionCount(1); // migration path taken
        } else {
            $this->fail('Should have detected missing table');
        }
    }

    /**
     * Developer uses make() returning null for optional dependency.
     */
    public function testOptionalDependencyViaContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('make')->willReturnMap([
            ['Logger', [], new \stdClass()],
            ['Profiler', [], null],
        ]);

        $logger = $container->make('Logger');
        $profiler = $container->make('Profiler');

        $this->assertNotNull($logger);
        $this->assertNull($profiler);
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 9: Module Discovery & Multi-Module Orchestration
    // ═══════════════════════════════════════════════════════════

    /**
     * Developer orchestrates calls across multiple modules.
     */
    public function testMultiModuleOrchestration(): void
    {
        $callerInfo = $this->createMock(ModuleInfo::class);
        $callerInfo->method('getCode')->willReturn('orchestrator');

        $userModule = $this->createMock(ModuleInterface::class);
        $userModule->method('getStatus')->willReturn(ModuleStatus::Loaded);
        $userModule->method('execute')
            ->with($callerInfo, 'getUser', [42])
            ->willReturn(['id' => 42, 'name' => 'Alice']);

        $emailModule = $this->createMock(ModuleInterface::class);
        $emailModule->method('getStatus')->willReturn(ModuleStatus::Loaded);
        $emailModule->method('execute')
            ->with($callerInfo, 'sendWelcome', $this->callback(fn($args) => $args[0] === 'Alice'))
            ->willReturn(true);

        // Orchestration flow: fetch user → send email
        $user = $userModule->execute($callerInfo, 'getUser', [42]);
        $sent = $emailModule->execute($callerInfo, 'sendWelcome', [$user['name']]);

        $this->assertSame('Alice', $user['name']);
        $this->assertTrue($sent);
    }

    /**
     * Developer collects data from multiple modules via execute.
     */
    public function testAggregateDataFromMultipleModules(): void
    {
        $callerInfo = $this->createMock(ModuleInfo::class);

        $modules = [];
        $data = [
            ['source' => 'blog', 'count' => 15],
            ['source' => 'shop', 'count' => 42],
            ['source' => 'forum', 'count' => 8],
        ];

        foreach ($data as $d) {
            $mod = $this->createMock(ModuleInterface::class);
            $mod->method('getStatus')->willReturn(ModuleStatus::Loaded);
            $mod->method('execute')
                ->with($callerInfo, 'getItemCount', [])
                ->willReturn($d['count']);
            $modules[$d['source']] = $mod;
        }

        // Aggregate counts from all loaded modules
        $total = 0;
        foreach ($modules as $name => $mod) {
            if ($mod->getStatus() === ModuleStatus::Loaded) {
                $count = $mod->execute($callerInfo, 'getItemCount', []);
                $total += $count;
            }
        }

        $this->assertSame(65, $total);
    }

    // ═══════════════════════════════════════════════════════════
    // Scenario 10: Interface Polymorphism Verification
    // ═══════════════════════════════════════════════════════════

    /**
     * Verifies that interface types can be interchanged in type-hinted parameters.
     * This confirms the interfaces work for dependency injection.
     */
    public function testInterfaceAsTypeHint(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $db = $this->createMock(DatabaseInterface::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $module = $this->createMock(ModuleInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        // All implement their respective interfaces
        $this->assertInstanceOf(ContainerInterface::class, $container);
        $this->assertInstanceOf(DatabaseInterface::class, $db);
        $this->assertInstanceOf(TemplateInterface::class, $tpl);
        $this->assertInstanceOf(ModuleInterface::class, $module);
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        // Test as function parameters
        $acceptsContainer = fn(ContainerInterface $c) => true;
        $acceptsDb = fn(DatabaseInterface $d) => true;
        $acceptsTpl = fn(TemplateInterface $t) => true;
        $acceptsModule = fn(ModuleInterface $m) => true;
        $acceptsDispatcher = fn(EventDispatcherInterface $e) => true;

        $this->assertTrue($acceptsContainer($container));
        $this->assertTrue($acceptsDb($db));
        $this->assertTrue($acceptsTpl($tpl));
        $this->assertTrue($acceptsModule($module));
        $this->assertTrue($acceptsDispatcher($dispatcher));
    }

    /**
     * Container can store and return different interface types.
     */
    public function testContainerServesMultipleInterfaceTypes(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $tpl = $this->createMock(TemplateInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['database', $db],
            ['template', $tpl],
            ['events', $dispatcher],
        ]);
        $container->method('has')->willReturn(true);

        $resolvedDb = $container->get('database');
        $resolvedTpl = $container->get('template');
        $resolvedEvents = $container->get('events');

        $this->assertInstanceOf(DatabaseInterface::class, $resolvedDb);
        $this->assertInstanceOf(TemplateInterface::class, $resolvedTpl);
        $this->assertInstanceOf(EventDispatcherInterface::class, $resolvedEvents);
    }
}
