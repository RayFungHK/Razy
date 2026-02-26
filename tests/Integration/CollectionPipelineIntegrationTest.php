<?php
/**
 * Integration test: Collection → Pipeline → Container data flow.
 *
 * Verifies that data originating in a Collection can flow through Pipeline
 * actions, be transformed, and finally be resolved through the DI Container —
 * exercising three core Razy subsystems in a single end-to-end scenario.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Collection;
use Razy\Container;
use Razy\Controller;
use Razy\Module;
use Razy\Pipeline;
use Razy\Pipeline\Action;
use Razy\PluginManager;

// ──────────────────────────────────────────────────────────
// Test Fixtures — Pipeline actions that transform Collection data
// ──────────────────────────────────────────────────────────

/**
 * Pipeline action that uppercases all string values in a Collection.
 */
class UppercaseAction extends Action
{
    public function execute(...$args): bool
    {
        parent::execute(...$args);
        /** @var Collection $collection */
        $collection = $args[0] ?? null;
        if (!$collection instanceof Collection) {
            return false;
        }

        foreach ($collection as $key => $value) {
            if (is_string($value)) {
                $collection[$key] = strtoupper($value);
            }
        }

        return true;
    }
}

/**
 * Pipeline action that adds a 'processed' flag and timestamp to the Collection.
 */
class StampAction extends Action
{
    public function execute(...$args): bool
    {
        parent::execute(...$args);
        /** @var Collection $collection */
        $collection = $args[0] ?? null;
        if (!$collection instanceof Collection) {
            return false;
        }

        $collection['_processed'] = true;
        $collection['_step_count'] = ($collection['_step_count'] ?? 0) + 1;

        return true;
    }
}

/**
 * Pipeline action that filters out keys starting with underscore.
 */
class FilterAction extends Action
{
    public function execute(...$args): bool
    {
        parent::execute(...$args);
        /** @var Collection $collection */
        $collection = $args[0] ?? null;
        if (!$collection instanceof Collection) {
            return false;
        }

        $filtered = [];
        foreach ($collection as $key => $value) {
            if (!str_starts_with((string) $key, '_')) {
                $filtered[$key] = $value;
            }
        }
        $collection->exchangeArray($filtered);

        return true;
    }
}

/**
 * Pipeline action that halts the pipeline (returns false).
 */
class HaltAction extends Action
{
    public function execute(...$args): bool
    {
        return false;
    }
}

/**
 * Simple service registered in the DI Container for integration testing.
 */
class DataProcessor
{
    public function __construct(
        private readonly string $prefix = 'processed'
    ) {}

    /**
     * Transform a Collection by prefixing all string values.
     */
    public function transform(Collection $collection): Collection
    {
        $result = [];
        foreach ($collection as $key => $value) {
            $result[$key] = is_string($value)
                ? $this->prefix . ':' . $value
                : $value;
        }
        return new Collection($result);
    }
}

// ──────────────────────────────────────────────────────────

#[CoversClass(Collection::class)]
#[CoversClass(Pipeline::class)]
#[CoversClass(Action::class)]
#[CoversClass(Container::class)]
class CollectionPipelineIntegrationTest extends TestCase
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

    // ─── Collection → Pipeline Flow ────────────────────────

    #[Test]
    public function collectionDataFlowsThroughPipelineActions(): void
    {
        $collection = new Collection([
            'name' => 'alice',
            'city' => 'hong kong',
            'age' => 30,
        ]);

        $pipeline = new Pipeline();
        $upper = new UppercaseAction();
        $upper->init('uppercase');
        $stamp = new StampAction();
        $stamp->init('stamp');

        $pipeline->add($upper)->add($stamp);

        $result = $pipeline->execute($collection);

        $this->assertTrue($result, 'Pipeline should succeed');
        $this->assertEquals('ALICE', $collection['name']);
        $this->assertEquals('HONG KONG', $collection['city']);
        $this->assertEquals(30, $collection['age'], 'Non-string values unchanged');
        $this->assertTrue($collection['_processed']);
        $this->assertEquals(1, $collection['_step_count']);
    }

    #[Test]
    public function pipelineHaltsOnFailedAction(): void
    {
        $collection = new Collection(['name' => 'bob']);

        $pipeline = new Pipeline();
        $halt = new HaltAction();
        $halt->init('halt');
        $stamp = new StampAction();
        $stamp->init('stamp');

        $pipeline->add($halt)->add($stamp);

        $result = $pipeline->execute($collection);

        $this->assertFalse($result, 'Pipeline should halt on first failure');
        $this->assertFalse(isset($collection['_processed']), 'StampAction should not have run');
    }

    #[Test]
    public function multiStepPipelineTransformsAndFilters(): void
    {
        $collection = new Collection([
            'title' => 'hello world',
            '_internal' => 'secret',
            'status' => 'active',
        ]);

        $pipeline = new Pipeline();
        $upper = new UppercaseAction();
        $upper->init('uppercase');
        $filter = new FilterAction();
        $filter->init('filter');

        $pipeline->add($upper)->add($filter);
        $result = $pipeline->execute($collection);

        $this->assertTrue($result);
        $copy = $collection->getArrayCopy();

        $this->assertEquals('HELLO WORLD', $copy['title']);
        $this->assertEquals('ACTIVE', $copy['status']);
        $this->assertArrayNotHasKey('_internal', $copy, 'Underscore keys should be filtered');
    }

    // ─── Collection → Pipeline → Container/Controller ──────

    #[Test]
    public function endToEndCollectionPipelineThenContainerResolution(): void
    {
        // 1. Build Collection data
        $collection = new Collection([
            'greeting' => 'hello',
            'target' => 'world',
            'count' => 42,
        ]);

        // 2. Process through Pipeline
        $pipeline = new Pipeline();
        $upper = new UppercaseAction();
        $upper->init('uppercase');
        $stamp = new StampAction();
        $stamp->init('stamp');

        $pipeline->add($upper)->add($stamp);
        $this->assertTrue($pipeline->execute($collection));

        // 3. Register a service in Container and resolve through Controller
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->singleton(DataProcessor::class, fn() => new DataProcessor('output'));

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        /** @var DataProcessor $processor */
        $processor = $controller->resolve(DataProcessor::class);
        $this->assertInstanceOf(DataProcessor::class, $processor);

        // 4. Use the resolved service to transform the pipeline-processed data
        $finalResult = $processor->transform($collection);

        $this->assertEquals('output:HELLO', $finalResult['greeting']);
        $this->assertEquals('output:WORLD', $finalResult['target']);
        $this->assertEquals(42, $finalResult['count'], 'Non-string values pass through');
        $this->assertTrue($finalResult['_processed']);
    }

    #[Test]
    public function containerResolvedServiceProcessesEmptyCollection(): void
    {
        $collection = new Collection([]);

        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $processor = $controller->resolve(DataProcessor::class);
        $result = $processor->transform($collection);

        $this->assertCount(0, $result);
    }

    #[Test]
    public function pipelineSharedStateVisibleAcrossActions(): void
    {
        $collection = new Collection(['value' => 'test']);

        $pipeline = new Pipeline();
        $stamp1 = new StampAction();
        $stamp1->init('stamp1');
        $stamp2 = new StampAction();
        $stamp2->init('stamp2');
        $stamp3 = new StampAction();
        $stamp3->init('stamp3');

        $pipeline->add($stamp1)->add($stamp2)->add($stamp3);
        $pipeline->execute($collection);

        $this->assertEquals(3, $collection['_step_count'],
            'Each StampAction should increment the shared counter');
        $this->assertTrue($collection['_processed']);
    }
}
