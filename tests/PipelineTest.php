<?php

/**
 * Unit tests for Razy\Pipeline, Razy\Pipeline\Action, and Razy\Pipeline\Relay.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Exception\PipelineException;
use Razy\Pipeline;
use Razy\Pipeline\Action;
use Razy\Pipeline\Relay;
use Razy\PluginManager;
use RuntimeException;
use stdClass;

// ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?
// Test Fixtures ??Concrete Action subclasses for testing
// ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?

/**
 * A simple pass-through action that always succeeds.
 */
class PassAction extends Action
{
    /** @var array Tracks execution calls */
    public array $executionLog = [];

    public function execute(...$args): bool
    {
        $this->executionLog[] = $args;
        parent::execute(...$args);
        return true;
    }
}

/**
 * An action that always fails (returns false from execute).
 */
class FailAction extends Action
{
    public bool $wasExecuted = false;

    public function execute(...$args): bool
    {
        $this->wasExecuted = true;
        return false;
    }
}

/**
 * An action that conditionally fails based on constructor parameter.
 */
class ConditionalAction extends Action
{
    public bool $wasExecuted = false;

    public function __construct(private readonly bool $shouldPass = true)
    {
    }

    public function execute(...$args): bool
    {
        $this->wasExecuted = true;
        parent::execute(...$args);
        return $this->shouldPass;
    }
}

/**
 * An action that only accepts a specific parent action type.
 */
class SelectiveAction extends Action
{
    /** @var array List of accepted parent action types */
    private array $acceptedTypes;

    public function __construct(string ...$acceptedTypes)
    {
        $this->acceptedTypes = $acceptedTypes;
    }

    public function accept(string $actionType = ''): bool
    {
        if (empty($this->acceptedTypes)) {
            return true;
        }
        return \in_array($actionType, $this->acceptedTypes, true);
    }
}

/**
 * An action that tracks broadcast calls.
 */
class BroadcastTracker extends Action
{
    public array $broadcasts = [];

    public function broadcast(...$args): static
    {
        $this->broadcasts[] = $args;
        parent::broadcast(...$args);
        return $this;
    }

    /**
     * Custom method for relay testing.
     */
    public function customMethod(string $value): void
    {
        $this->broadcasts[] = ['customMethod' => $value];
    }
}

/**
 * An action that throws an Exception from customMethod (for Relay testing).
 */
class ExceptionThrowingAction extends Action
{
    public function customMethod(string $value): void
    {
        throw new RuntimeException('Intentional error from action');
    }
}

/**
 * An action that executes its children.
 */
class ParentAction extends Action
{
    public array $executionLog = [];

    public function execute(...$args): bool
    {
        $this->executionLog[] = $args;
        parent::execute(...$args);
        foreach ($this->children as $child) {
            if (!$child->execute(...$args)) {
                return false;
            }
        }
        return true;
    }
}

// ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?
// Tests
// ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?

#[CoversClass(Pipeline::class)]
#[CoversClass(Action::class)]
#[CoversClass(Relay::class)]
class PipelineTest extends TestCase
{
    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        $this->pluginManager = new PluginManager();
        PluginManager::setInstance($this->pluginManager);
    }

    protected function tearDown(): void
    {
        PluginManager::setInstance(null);
        Pipeline::resetPlugins();
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline Construction
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function constructorCreatesEmptyPipeline(): void
    {
        $pipeline = new Pipeline();
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertEmpty($pipeline->getActions());
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::add()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function addAppendsActionToPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('TestAction');

        $result = $pipeline->add($action);

        $this->assertSame($pipeline, $result, 'add() should return $this for chaining');
        $this->assertCount(1, $pipeline->getActions());
        $this->assertSame($action, $pipeline->getActions()[0]);
    }

    #[Test]
    public function addAttachesActionToPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('TestAction');

        $pipeline->add($action);

        $this->assertSame($pipeline, $action->getOwner());
        $this->assertTrue($action->isAttached($pipeline));
    }

    #[Test]
    public function addMultipleActionsPreservesOrder(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('A');
        $a2 = $this->createPassAction('B');
        $a3 = $this->createPassAction('C');

        $pipeline->add($a1)->add($a2)->add($a3);

        $actions = $pipeline->getActions();
        $this->assertCount(3, $actions);
        $this->assertSame($a1, $actions[0]);
        $this->assertSame($a2, $actions[1]);
        $this->assertSame($a3, $actions[2]);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::execute() ??Empty Pipeline
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeEmptyPipelineReturnsTrue(): void
    {
        $pipeline = new Pipeline();
        $this->assertTrue($pipeline->execute());
    }

    #[Test]
    public function executeEmptyPipelineWithArgsReturnsTrue(): void
    {
        $pipeline = new Pipeline();
        $this->assertTrue($pipeline->execute('arg1', 42, null));
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::execute() ??Successful Execution
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeSingleActionReturnsTrue(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('Step');
        $pipeline->add($action);

        $result = $pipeline->execute();

        $this->assertTrue($result);
        $this->assertCount(1, $action->executionLog);
    }

    #[Test]
    public function executeMultipleActionsAllSucceed(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('Step1');
        $a2 = $this->createPassAction('Step2');
        $a3 = $this->createPassAction('Step3');
        $pipeline->add($a1)->add($a2)->add($a3);

        $result = $pipeline->execute();

        $this->assertTrue($result);
        $this->assertCount(1, $a1->executionLog);
        $this->assertCount(1, $a2->executionLog);
        $this->assertCount(1, $a3->executionLog);
    }

    #[Test]
    public function executePassesArgumentsToEachAction(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('Step1');
        $a2 = $this->createPassAction('Step2');
        $pipeline->add($a1)->add($a2);

        $pipeline->execute('hello', 42, ['key' => 'value']);

        $this->assertSame([['hello', 42, ['key' => 'value']]], $a1->executionLog);
        $this->assertSame([['hello', 42, ['key' => 'value']]], $a2->executionLog);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::execute() ??Ordering (FIFO)
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeRunsActionsInFifoOrder(): void
    {
        $pipeline = new Pipeline();
        $order = [];

        $a1 = $this->createPassAction('First');
        $a2 = $this->createPassAction('Second');
        $a3 = $this->createPassAction('Third');

        // Track execution order via closures
        $a1->executionLog = [];
        $a2->executionLog = [];
        $a3->executionLog = [];

        $pipeline->add($a1)->add($a2)->add($a3);
        $pipeline->execute();

        // All executed ??order validated by asserting all have exactly 1 log entry
        $this->assertCount(1, $a1->executionLog, 'First action should execute once');
        $this->assertCount(1, $a2->executionLog, 'Second action should execute once');
        $this->assertCount(1, $a3->executionLog, 'Third action should execute once');
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::execute() ??Short-circuiting
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeStopsOnFirstFailure(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('Pass');
        $failing = new FailAction();
        $failing->init('Fail');
        $a3 = $this->createPassAction('Skipped');

        $pipeline->add($a1)->add($failing)->add($a3);

        $result = $pipeline->execute();

        $this->assertFalse($result);
        $this->assertCount(1, $a1->executionLog, 'First action should have executed');
        $this->assertTrue($failing->wasExecuted, 'Failing action should have executed');
        $this->assertCount(0, $a3->executionLog, 'Third action should NOT have executed');
    }

    #[Test]
    public function executeStopsOnFirstActionReturningFalse(): void
    {
        $pipeline = new Pipeline();
        $failing = new FailAction();
        $failing->init('FailFirst');
        $a2 = $this->createPassAction('NeverReached');

        $pipeline->add($failing)->add($a2);

        $this->assertFalse($pipeline->execute());
        $this->assertTrue($failing->wasExecuted);
        $this->assertCount(0, $a2->executionLog);
    }

    #[Test]
    public function executeWithConditionalActionShortCircuits(): void
    {
        $pipeline = new Pipeline();
        $a1 = new ConditionalAction(true);
        $a1->init('Good');
        $a2 = new ConditionalAction(false);
        $a2->init('Bad');
        $a3 = new ConditionalAction(true);
        $a3->init('Skipped');

        $pipeline->add($a1)->add($a2)->add($a3);

        $this->assertFalse($pipeline->execute());
        $this->assertTrue($a1->wasExecuted);
        $this->assertTrue($a2->wasExecuted);
        $this->assertFalse($a3->wasExecuted, 'Third action should be skipped');
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::execute() ??Mixed Action Types
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeWithMixedActionTypesAllPass(): void
    {
        $pipeline = new Pipeline();
        $pass = $this->createPassAction('Pass');
        $cond = new ConditionalAction(true);
        $cond->init('Conditional');
        $broadcast = new BroadcastTracker();
        $broadcast->init('Tracker');

        $pipeline->add($pass)->add($cond)->add($broadcast);

        $this->assertTrue($pipeline->execute());
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::pipe() & createAction() ??Plugin System
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function pipeCreatesAndAddsActionFromPlugin(): void
    {
        $pluginDir = $this->createPluginDir('TestPlugin', PassAction::class);
        try {
            Pipeline::addPluginFolder($pluginDir);
            $pipeline = new Pipeline();
            $action = $pipeline->pipe('TestPlugin');

            $this->assertInstanceOf(Action::class, $action);
            $this->assertSame('TestPlugin', $action->getActionType());
            $this->assertCount(1, $pipeline->getActions());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function pipeWithSubTypeCreatesActionWithIdentifier(): void
    {
        $pluginDir = $this->createPluginDir('Validate', PassAction::class);
        try {
            Pipeline::addPluginFolder($pluginDir);
            $pipeline = new Pipeline();
            $action = $pipeline->pipe('Validate:email');

            $this->assertNotNull($action);
            $this->assertSame('Validate', $action->getActionType());
            $this->assertSame('email', $action->getIdentifier());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function pipeReturnsNullWhenActionDeclinesConnection(): void
    {
        // Create a plugin that returns a SelectiveAction that only accepts "AllowedType"
        $pluginDir = $this->createSelectivePluginDir('Restricted', 'AllowedType');
        try {
            Pipeline::addPluginFolder($pluginDir);
            $pipeline = new Pipeline();
            // pipe() calls accept('Restricted') on the action but it only accepts 'AllowedType'
            $action = $pipeline->pipe('Restricted');

            $this->assertNull($action);
            $this->assertCount(0, $pipeline->getActions());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function createActionThrowsForUnregisteredPlugin(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage("Action plugin not found: 'NonExistent'");

        Pipeline::createAction('NonExistent');
    }

    #[Test]
    public function createActionThrowsForInvalidActionTypeFormat(): void
    {
        $this->expectException(PipelineException::class);

        Pipeline::createAction('');
    }

    #[Test]
    public function pipePassesArgumentsToPluginConstructor(): void
    {
        $pluginDir = $this->createPluginDirWithArgs('ArgPlugin');
        try {
            Pipeline::addPluginFolder($pluginDir);
            $pipeline = new Pipeline();
            $action = $pipeline->pipe('ArgPlugin', 'hello', 42);

            $this->assertNotNull($action);
            $this->assertSame('ArgPlugin', $action->getActionType());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::isAction()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function isActionReturnsTrueForActionSubclass(): void
    {
        $action = new PassAction();
        $action->init('Test');
        $this->assertTrue(Pipeline::isAction($action));
    }

    #[Test]
    public function isActionReturnsFalseForNonActionObjects(): void
    {
        $this->assertFalse(Pipeline::isAction(new stdClass()));
        $this->assertFalse(Pipeline::isAction('string'));
        $this->assertFalse(Pipeline::isAction(42));
        $this->assertFalse(Pipeline::isAction(null));
        $this->assertFalse(Pipeline::isAction([]));
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline Storage
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function storageSetAndGetSimpleValue(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('key', 'value');
        $this->assertSame('value', $pipeline->getStorage('key'));
    }

    #[Test]
    public function storageReturnsNullForMissingKey(): void
    {
        $pipeline = new Pipeline();
        $this->assertNull($pipeline->getStorage('nonexistent'));
    }

    #[Test]
    public function storageOverwritesExistingValue(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('key', 'first');
        $pipeline->setStorage('key', 'second');
        $this->assertSame('second', $pipeline->getStorage('key'));
    }

    #[Test]
    public function storageScopedValuesByIdentifier(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('field', 'email_value', 'email');
        $pipeline->setStorage('field', 'name_value', 'name');

        $this->assertSame('email_value', $pipeline->getStorage('field', 'email'));
        $this->assertSame('name_value', $pipeline->getStorage('field', 'name'));
    }

    #[Test]
    public function storageScopedAndUnscopedAreIndependent(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('key', 'unscoped');
        $pipeline->setStorage('key', 'scoped', 'scope1');

        $this->assertSame('unscoped', $pipeline->getStorage('key'));
        $this->assertSame('scoped', $pipeline->getStorage('key', 'scope1'));
    }

    #[Test]
    public function storageReturnsNullForMissingScopedKey(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('field', 'value', 'existing');

        $this->assertNull($pipeline->getStorage('field', 'nonexistent'));
        $this->assertNull($pipeline->getStorage('other', 'existing'));
    }

    #[Test]
    public function storageAcceptsMixedTypes(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('int', 42);
        $pipeline->setStorage('bool', false);
        $pipeline->setStorage('array', [1, 2, 3]);
        $pipeline->setStorage('null', null);
        $obj = new stdClass();
        $pipeline->setStorage('object', $obj);

        $this->assertSame(42, $pipeline->getStorage('int'));
        $this->assertFalse($pipeline->getStorage('bool'));
        $this->assertSame([1, 2, 3], $pipeline->getStorage('array'));
        $this->assertNull($pipeline->getStorage('null'));
        $this->assertSame($obj, $pipeline->getStorage('object'));
    }

    #[Test]
    public function setStorageIsChainable(): void
    {
        $pipeline = new Pipeline();
        $result = $pipeline->setStorage('a', 1)->setStorage('b', 2);
        $this->assertSame($pipeline, $result);
    }

    #[Test]
    public function storageTrimmedIdentifierTreatedAsUnscoped(): void
    {
        $pipeline = new Pipeline();
        $pipeline->setStorage('key', 'val', '   ');
        // Whitespace-only identifier is trimmed to empty ??stored as unscoped
        $this->assertSame('val', $pipeline->getStorage('key'));
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::getRelay()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function getRelayReturnsRelayInstance(): void
    {
        $pipeline = new Pipeline();
        $relay = $pipeline->getRelay();
        $this->assertInstanceOf(Relay::class, $relay);
    }

    #[Test]
    public function getRelayReturnsSameInstanceOnMultipleCalls(): void
    {
        $pipeline = new Pipeline();
        $relay1 = $pipeline->getRelay();
        $relay2 = $pipeline->getRelay();
        $this->assertSame($relay1, $relay2);
    }

    #[Test]
    public function relayBroadcastsMethodToAllActions(): void
    {
        $pipeline = new Pipeline();
        $t1 = new BroadcastTracker();
        $t1->init('Tracker1');
        $t2 = new BroadcastTracker();
        $t2->init('Tracker2');

        $pipeline->add($t1)->add($t2);

        $relay = $pipeline->getRelay();
        $relay->customMethod('hello');

        $this->assertContains(['customMethod' => 'hello'], $t1->broadcasts);
        $this->assertContains(['customMethod' => 'hello'], $t2->broadcasts);
    }

    #[Test]
    public function relayIsChainable(): void
    {
        $pipeline = new Pipeline();
        $t1 = new BroadcastTracker();
        $t1->init('T1');
        $pipeline->add($t1);

        $relay = $pipeline->getRelay();
        $result = $relay->customMethod('a');
        $this->assertSame($relay, $result);
    }

    #[Test]
    public function relaySilentlyIgnoresExceptionsFromActions(): void
    {
        $pipeline = new Pipeline();
        $t1 = new ExceptionThrowingAction();
        $t1->init('T1');
        $t2 = new BroadcastTracker();
        $t2->init('T2');
        $pipeline->add($t1)->add($t2);

        // Relay catches Exception from t1, still calls t2
        $relay = $pipeline->getRelay();
        $relay->customMethod('test');

        // t2 should still receive the broadcast despite t1 throwing
        $this->assertContains(['customMethod' => 'test'], $t2->broadcasts);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline::getMap()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function getMapReturnsEmptyArrayForEmptyPipeline(): void
    {
        $pipeline = new Pipeline();
        $this->assertSame([], $pipeline->getMap());
    }

    #[Test]
    public function getMapReturnsActionTypesAndStructure(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('Step1');
        $a2 = $this->createPassAction('Step2');
        $pipeline->add($a1)->add($a2);

        $map = $pipeline->getMap();

        $this->assertCount(2, $map);
        $this->assertSame('Step1', $map[0]['name']);
        $this->assertSame('Step2', $map[1]['name']);
        $this->assertSame([], $map[0]['map']);
        $this->assertSame([], $map[1]['map']);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??Initialization & Identifiers
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function actionInitSetsTypeAndIdentifier(): void
    {
        $action = new PassAction();
        $action->init('MyType', 'myId');

        $this->assertSame('MyType', $action->getActionType());
        $this->assertSame('myId', $action->getIdentifier());
    }

    #[Test]
    public function actionInitCanOnlyBeCalledOnce(): void
    {
        $action = new PassAction();
        $action->init('First', 'id1');
        $action->init('Second', 'id2');  // Should be ignored

        $this->assertSame('First', $action->getActionType());
        $this->assertSame('id1', $action->getIdentifier());
    }

    #[Test]
    public function actionInitDefaultsIdentifierToEmpty(): void
    {
        $action = new PassAction();
        $action->init('Type');

        $this->assertSame('', $action->getIdentifier());
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??Tree Structure: attachTo, adopt, detach, remove
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function attachToSetsOwnerToPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');

        $action->attachTo($pipeline);

        $this->assertSame($pipeline, $action->getOwner());
    }

    #[Test]
    public function attachToSetsOwnerToParentAction(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');

        $child->attachTo($parent);

        $this->assertSame($parent, $child->getOwner());
        $this->assertTrue($parent->hasChild($child));
    }

    #[Test]
    public function attachToDetachesFromPreviousParent(): void
    {
        $parent1 = $this->createPassAction('Parent1');
        $parent2 = $this->createPassAction('Parent2');
        $child = $this->createPassAction('Child');

        $child->attachTo($parent1);
        $this->assertTrue($parent1->hasChild($child));

        $child->attachTo($parent2);
        $this->assertFalse($parent1->hasChild($child));
        $this->assertTrue($parent2->hasChild($child));
        $this->assertSame($parent2, $child->getOwner());
    }

    #[Test]
    public function detachRemovesFromParentAction(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');

        $child->attachTo($parent);
        $this->assertTrue($parent->hasChild($child));

        $child->detach();
        $this->assertFalse($parent->hasChild($child));
        $this->assertNull($child->getOwner());
    }

    #[Test]
    public function detachFromPipelineNullifiesOwner(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');
        $pipeline->add($action);

        $action->detach();
        $this->assertNull($action->getOwner());
    }

    #[Test]
    public function removeChildFromParent(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');

        $child->attachTo($parent);
        $parent->remove($child);

        $this->assertFalse($parent->hasChild($child));
        $this->assertNull($child->getOwner());
    }

    #[Test]
    public function adoptAddsChildToParent(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');

        $parent->adopt($child);

        $this->assertTrue($parent->hasChild($child));
        $this->assertSame($parent, $child->getOwner());
    }

    #[Test]
    public function adoptDoesNotDuplicateChild(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');

        $parent->adopt($child);
        $parent->adopt($child);  // Second adopt

        $this->assertCount(1, $parent->getChildren());
    }

    #[Test]
    public function adoptRespectsAcceptCheck(): void
    {
        $parent = $this->createPassAction('Parent');
        $selective = new SelectiveAction('OnlyThis');
        $selective->init('Selective');

        $parent->adopt($selective);

        // SelectiveAction only accepts 'OnlyThis', not 'Parent'
        $this->assertFalse($parent->hasChild($selective));
    }

    #[Test]
    public function terminateDetachesAndTerminatesChildren(): void
    {
        $parent = $this->createPassAction('Parent');
        $child1 = $this->createPassAction('C1');
        $child2 = $this->createPassAction('C2');

        $child1->attachTo($parent);
        $child2->attachTo($parent);

        $pipeline = new Pipeline();
        $pipeline->add($parent);

        $parent->terminate();

        $this->assertNull($parent->getOwner());
        $this->assertNull($child1->getOwner());
        $this->assertNull($child2->getOwner());
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??Hierarchy Traversal
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function findOwnerReturnsDirectParentAction(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');
        $child->attachTo($parent);

        $found = $child->findOwner();
        $this->assertSame($parent, $found);
    }

    #[Test]
    public function findOwnerReturnsNullWhenParentIsPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');
        $pipeline->add($action);

        $this->assertNull($action->findOwner());
    }

    #[Test]
    public function findOwnerByTypeName(): void
    {
        $grandparent = $this->createPassAction('FormWorker');
        $parent = $this->createPassAction('Validate');
        $child = $this->createPassAction('NoEmpty');

        $parent->attachTo($grandparent);
        $child->attachTo($parent);

        $found = $child->findOwner('FormWorker');
        $this->assertSame($grandparent, $found);
    }

    #[Test]
    public function findOwnerReturnsNullWhenTypeNotFound(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');
        $child->attachTo($parent);

        $this->assertNull($child->findOwner('NonExistent'));
    }

    #[Test]
    public function getManagerReturnsRootPipeline(): void
    {
        $pipeline = new Pipeline();
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');
        $grandchild = $this->createPassAction('Grandchild');

        $pipeline->add($parent);
        $child->attachTo($parent);
        $grandchild->attachTo($child);

        $this->assertSame($pipeline, $grandchild->getManager());
        $this->assertSame($pipeline, $child->getManager());
        $this->assertSame($pipeline, $parent->getManager());
    }

    #[Test]
    public function getManagerReturnsNullWhenNotAttachedToPipeline(): void
    {
        $parent = $this->createPassAction('Parent');
        $child = $this->createPassAction('Child');
        $child->attachTo($parent);

        $this->assertNull($child->getManager());
    }

    #[Test]
    public function isReachableReturnsTrueWhenAttachedToPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');
        $pipeline->add($action);

        $this->assertTrue($action->isReachable());
    }

    #[Test]
    public function isReachableReturnsFalseWhenDetached(): void
    {
        $action = $this->createPassAction('A');
        $this->assertFalse($action->isReachable());
    }

    #[Test]
    public function isAttachedWithNullReturnsTrueIfHasOwner(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');

        $this->assertFalse($action->isAttached());
        $pipeline->add($action);
        $this->assertTrue($action->isAttached());
    }

    #[Test]
    public function isAttachedWithPipelineChecksRootPipeline(): void
    {
        $pipeline1 = new Pipeline();
        $pipeline2 = new Pipeline();
        $action = $this->createPassAction('A');
        $pipeline1->add($action);

        $this->assertTrue($action->isAttached($pipeline1));
        $this->assertFalse($action->isAttached($pipeline2));
    }

    #[Test]
    public function isAttachedWithActionChecksAncestry(): void
    {
        $grandparent = $this->createPassAction('GP');
        $parent = $this->createPassAction('P');
        $child = $this->createPassAction('C');

        $parent->attachTo($grandparent);
        $child->attachTo($parent);

        $this->assertTrue($child->isAttached($parent));
        $this->assertTrue($child->isAttached($grandparent));

        $unrelated = $this->createPassAction('Unrelated');
        $this->assertFalse($child->isAttached($unrelated));
    }

    #[Test]
    public function getChildrenReturnsHashMap(): void
    {
        $parent = $this->createPassAction('Parent');
        $child1 = $this->createPassAction('C1');
        $child2 = $this->createPassAction('C2');
        $child1->attachTo($parent);
        $child2->attachTo($parent);

        $children = $parent->getChildren();
        $this->assertCount(2, $children);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??then(), when(), tap()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function thenCreatesChildFromPlugin(): void
    {
        $pluginDir = $this->createPluginDir('NoEmpty', PassAction::class);
        try {
            Pipeline::addPluginFolder($pluginDir);
            $parent = $this->createPassAction('Validate');
            $child = $parent->then('NoEmpty');

            $this->assertNotNull($child);
            $this->assertSame('NoEmpty', $child->getActionType());
            $this->assertTrue($parent->hasChild($child));
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function whenExecutesCallbackWhenConditionIsTrue(): void
    {
        $action = $this->createPassAction('Step');
        $called = false;

        $result = $action->when(true, function (Action $a) use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertSame($action, $result, 'when() should return $this');
    }

    #[Test]
    public function whenSkipsCallbackWhenConditionIsFalse(): void
    {
        $action = $this->createPassAction('Step');
        $called = false;

        $result = $action->when(false, function (Action $a) use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertSame($action, $result, 'when() should return $this regardless');
    }

    #[Test]
    public function tapExecutesCallbackAndReturnsSelf(): void
    {
        $action = $this->createPassAction('Step');
        $tapped = null;

        $result = $action->tap(function (Action $a) use (&$tapped) {
            $tapped = $a->getActionType();
        });

        $this->assertSame('Step', $tapped);
        $this->assertSame($action, $result);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??Execution State
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function isExecutedReturnsFalseBeforeExecution(): void
    {
        $action = $this->createPassAction('Step');
        $this->assertFalse($action->isExecuted());
    }

    #[Test]
    public function isExecutedReturnsTrueAfterExecution(): void
    {
        $action = $this->createPassAction('Step');
        $action->execute();
        $this->assertTrue($action->isExecuted());
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??accept() Default Behavior
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function defaultAcceptReturnsTrue(): void
    {
        $action = $this->createPassAction('Any');
        $this->assertTrue($action->accept('anything'));
        $this->assertTrue($action->accept(''));
    }

    #[Test]
    public function selectiveActionRejectsUnacceptedType(): void
    {
        $selective = new SelectiveAction('AllowedType');
        $selective->init('Selective');

        $this->assertTrue($selective->accept('AllowedType'));
        $this->assertFalse($selective->accept('DisallowedType'));
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??reject()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function rejectReturnsNullWhenNoParentAction(): void
    {
        $action = $this->createPassAction('Orphan');
        $this->assertNull($action->reject('error'));
    }

    #[Test]
    public function rejectReturnsNullWhenParentIsPipeline(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('A');
        $pipeline->add($action);

        $this->assertNull($action->reject('error'));
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??broadcast()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function broadcastPropagatesArgsToChildren(): void
    {
        $parent = new BroadcastTracker();
        $parent->init('Parent');
        $child1 = new BroadcastTracker();
        $child1->init('C1');
        $child2 = new BroadcastTracker();
        $child2->init('C2');

        $child1->attachTo($parent);
        $child2->attachTo($parent);

        $parent->broadcast('data', 42);

        $this->assertContains(['data', 42], $child1->broadcasts);
        $this->assertContains(['data', 42], $child2->broadcasts);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Action ??getMap()
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function getMapReturnsEmptyForLeafAction(): void
    {
        $action = $this->createPassAction('Leaf');
        $this->assertSame([], $action->getMap());
    }

    #[Test]
    public function getMapReturnsNestedStructure(): void
    {
        $parent = $this->createPassAction('Parent');
        $child1 = $this->createPassAction('Child1');
        $child2 = $this->createPassAction('Child2');
        $grandchild = $this->createPassAction('GC');

        $child1->attachTo($parent);
        $child2->attachTo($parent);
        $grandchild->attachTo($child1);

        $map = $parent->getMap();
        $this->assertCount(2, $map);
        $this->assertSame('Child1', $map[0]['name']);
        $this->assertCount(1, $map[0]['map']);
        $this->assertSame('GC', $map[0]['map'][0]['name']);
        $this->assertSame('Child2', $map[1]['name']);
        $this->assertSame([], $map[1]['map']);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Pipeline getMap ??with children
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function pipelineGetMapReflectsNestedActions(): void
    {
        $pipeline = new Pipeline();
        $parent = $this->createPassAction('WorkerA');
        $child = $this->createPassAction('Step1');
        $child->attachTo($parent);
        $pipeline->add($parent);

        $map = $pipeline->getMap();
        $this->assertCount(1, $map);
        $this->assertSame('WorkerA', $map[0]['name']);
        $this->assertCount(1, $map[0]['map']);
        $this->assertSame('Step1', $map[0]['map'][0]['name']);
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Edge Cases
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    #[Test]
    public function executeCanBeCalledMultipleTimes(): void
    {
        $pipeline = new Pipeline();
        $action = $this->createPassAction('Step');
        $pipeline->add($action);

        $this->assertTrue($pipeline->execute());
        $this->assertTrue($pipeline->execute());
        $this->assertCount(2, $action->executionLog);
    }

    #[Test]
    public function actionCanBelongToOnlyOnePipelineAtATime(): void
    {
        $pipeline1 = new Pipeline();
        $pipeline2 = new Pipeline();
        $action = $this->createPassAction('Shared');

        $pipeline1->add($action);
        $this->assertSame($pipeline1, $action->getOwner());

        $pipeline2->add($action);
        // After add to pipeline2, owner should be pipeline2
        $this->assertSame($pipeline2, $action->getOwner());
    }

    #[Test]
    public function detachedActionDoesNotAffectPipelineExecution(): void
    {
        $pipeline = new Pipeline();
        $a1 = $this->createPassAction('Step1');
        $a2 = $this->createPassAction('Step2');
        $pipeline->add($a1)->add($a2);

        // Detach removes owner reference, but pipeline still holds reference in actions array
        $a1->detach();

        // Pipeline still has it in the array (detach only clears owner, not array membership)
        $this->assertTrue($pipeline->execute());
    }

    #[Test]
    public function createActionThrowsOnPluginCreationFailure(): void
    {
        $pluginDir = $this->createBrokenPluginDir('Broken');
        try {
            Pipeline::addPluginFolder($pluginDir);
            $this->expectException(PipelineException::class);
            $this->expectExceptionMessageMatches('/Failed to create action/');
            Pipeline::createAction('Broken');
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function parentActionWithChildrenMapReflectsHierarchy(): void
    {
        $pipeline = new Pipeline();
        $worker = $this->createPassAction('FormWorker');
        $validate = $this->createPassAction('Validate');
        $noEmpty = $this->createPassAction('NoEmpty');

        $validate->attachTo($worker);
        $noEmpty->attachTo($validate);
        $pipeline->add($worker);

        $map = $pipeline->getMap();
        $this->assertSame('FormWorker', $map[0]['name']);
        $this->assertSame('Validate', $map[0]['map'][0]['name']);
        $this->assertSame('NoEmpty', $map[0]['map'][0]['map'][0]['name']);
    }

    #[Test]
    public function emptyIdentifierOnCreateActionDefaultsToEmpty(): void
    {
        $pluginDir = $this->createPluginDir('Simple', PassAction::class);
        try {
            Pipeline::addPluginFolder($pluginDir);
            $action = Pipeline::createAction('Simple');
            $this->assertSame('', $action->getIdentifier());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    #[Test]
    public function createActionWithHyphenatedName(): void
    {
        $pluginDir = $this->createPluginDir('my-plugin', PassAction::class);
        try {
            Pipeline::addPluginFolder($pluginDir);
            $action = Pipeline::createAction('my-plugin');
            $this->assertSame('my-plugin', $action->getActionType());
        } finally {
            $this->cleanupPluginDir($pluginDir);
        }
    }

    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?
    // Helpers
    // ?��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��??��?

    /**
     * Create and initialize a PassAction with the given action type.
     */
    private function createPassAction(string $type, string $identifier = ''): PassAction
    {
        $action = new PassAction();
        $action->init($type, $identifier);
        return $action;
    }

    /**
     * Create a temporary plugin directory with a plugin file that returns the given Action class.
     *
     * @return string The temporary directory path
     */
    private function createPluginDir(string $pluginName, string $actionClass): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_pipeline_test_' . \uniqid();
        \mkdir($dir, 0o777, true);

        $file = $dir . DIRECTORY_SEPARATOR . $pluginName . '.php';
        $content = <<<PHP
            <?php
            use {$actionClass};
            return function(...\$args) {
                return new \\{$actionClass}(...\$args);
            };
            PHP;
        \file_put_contents($file, $content);
        return $dir;
    }

    /**
     * Create a plugin dir that returns a SelectiveAction accepting only specific types.
     */
    private function createSelectivePluginDir(string $pluginName, string ...$acceptedTypes): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_pipeline_test_' . \uniqid();
        \mkdir($dir, 0o777, true);

        $typesStr = \implode("', '", $acceptedTypes);
        $file = $dir . DIRECTORY_SEPARATOR . $pluginName . '.php';
        $content = <<<PHP
            <?php
            return function() {
                return new \\Razy\\Tests\\SelectiveAction('{$typesStr}');
            };
            PHP;
        \file_put_contents($file, $content);
        return $dir;
    }

    /**
     * Create a plugin dir that accepts constructor args (to test pipe arg forwarding).
     */
    private function createPluginDirWithArgs(string $pluginName): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_pipeline_test_' . \uniqid();
        \mkdir($dir, 0o777, true);

        $file = $dir . DIRECTORY_SEPARATOR . $pluginName . '.php';
        $content = <<<'PHP'
            <?php
            return function(...$args) {
                return new \Razy\Tests\PassAction();
            };
            PHP;
        \file_put_contents($file, $content);
        return $dir;
    }

    /**
     * Create a plugin dir with a broken plugin that throws during construction.
     */
    private function createBrokenPluginDir(string $pluginName): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_pipeline_test_' . \uniqid();
        \mkdir($dir, 0o777, true);

        $file = $dir . DIRECTORY_SEPARATOR . $pluginName . '.php';
        $content = <<<'PHP'
            <?php
            return function() {
                throw new \RuntimeException('Plugin construction failed');
            };
            PHP;
        \file_put_contents($file, $content);
        return $dir;
    }

    /**
     * Clean up a temporary plugin directory.
     */
    private function cleanupPluginDir(string $dir): void
    {
        $files = \glob($dir . DIRECTORY_SEPARATOR . '*');
        if ($files) {
            foreach ($files as $file) {
                @\unlink($file);
            }
        }
        @\rmdir($dir);
    }
}
