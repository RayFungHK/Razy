<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\ContextualBindingBuilder;

// ─── Fixture Interfaces ──────────────────────────────────────────

interface CtxFilesystemInterface
{
    public function type(): string;
}

interface CtxLoggerInterface
{
    public function channel(): string;
}

interface CtxCacheInterface
{
    public function driver(): string;
}

// ─── Fixture Implementations ─────────────────────────────────────

class CtxLocalFilesystem implements CtxFilesystemInterface
{
    public function type(): string
    {
        return 'local';
    }
}

class CtxS3Filesystem implements CtxFilesystemInterface
{
    public function type(): string
    {
        return 's3';
    }
}

class CtxCloudFilesystem implements CtxFilesystemInterface
{
    public function type(): string
    {
        return 'cloud';
    }
}

class CtxFileLogger implements CtxLoggerInterface
{
    public function __construct(private readonly string $path = '/var/log/app.log')
    {
    }

    public function channel(): string
    {
        return 'file:' . $this->path;
    }
}

class CtxDatabaseLogger implements CtxLoggerInterface
{
    public function channel(): string
    {
        return 'database';
    }
}

class CtxRedisCache implements CtxCacheInterface
{
    public function driver(): string
    {
        return 'redis';
    }
}

class CtxFileCache implements CtxCacheInterface
{
    public function driver(): string
    {
        return 'file';
    }
}

// ─── Fixture Consumer Classes ────────────────────────────────────

class CtxPhotoController
{
    public function __construct(public readonly CtxFilesystemInterface $filesystem)
    {
    }
}

class CtxVideoController
{
    public function __construct(public readonly CtxFilesystemInterface $filesystem)
    {
    }
}

class CtxDocumentController
{
    public function __construct(public readonly CtxFilesystemInterface $filesystem)
    {
    }
}

class CtxUserService
{
    public function __construct(public readonly CtxLoggerInterface $logger)
    {
    }
}

class CtxReportService
{
    public function __construct(public readonly CtxLoggerInterface $logger)
    {
    }
}

class CtxMultiDependencyService
{
    public function __construct(
        public readonly CtxFilesystemInterface $filesystem,
        public readonly CtxLoggerInterface $logger,
        public readonly CtxCacheInterface $cache,
    ) {
    }
}

class CtxMixedParamsService
{
    public function __construct(
        public readonly CtxFilesystemInterface $filesystem,
        public readonly string $name = 'default',
    ) {
    }
}

class CtxNoContextService
{
    public function __construct(public readonly CtxFilesystemInterface $filesystem)
    {
    }
}

/**
 * Tests for P4: Contextual Binding in the DI Container.
 *
 * Covers the when()->needs()->give() fluent API, direct addContextualBinding(),
 * contextual resolution in autoResolve(), closure-based contextual bindings,
 * interaction with global bindings, reset cleanup, and edge cases.
 */
#[CoversClass(Container::class)]
#[CoversClass(ContextualBindingBuilder::class)]
class ContextualBindingTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 1: Fluent API — when()->needs()->give()
    // ═══════════════════════════════════════════════════════════════

    public function testWhenReturnsContextualBindingBuilder(): void
    {
        $builder = $this->container->when(CtxPhotoController::class);
        $this->assertInstanceOf(ContextualBindingBuilder::class, $builder);
    }

    public function testWhenNeedsGiveRegistersBinding(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $binding = $this->container->getContextualBinding(
            CtxPhotoController::class,
            CtxFilesystemInterface::class,
        );

        $this->assertSame(CtxLocalFilesystem::class, $binding);
    }

    public function testWhenNeedsGiveWithClosure(): void
    {
        $closure = fn (Container $c) => new CtxFileLogger('/custom/path.log');

        $this->container->when(CtxUserService::class)
            ->needs(CtxLoggerInterface::class)
            ->give($closure);

        $binding = $this->container->getContextualBinding(
            CtxUserService::class,
            CtxLoggerInterface::class,
        );

        $this->assertSame($closure, $binding);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Direct API — addContextualBinding()
    // ═══════════════════════════════════════════════════════════════

    public function testAddContextualBindingString(): void
    {
        $this->container->addContextualBinding(
            CtxPhotoController::class,
            CtxFilesystemInterface::class,
            CtxLocalFilesystem::class,
        );

        $result = $this->container->getContextualBinding(
            CtxPhotoController::class,
            CtxFilesystemInterface::class,
        );

        $this->assertSame(CtxLocalFilesystem::class, $result);
    }

    public function testAddContextualBindingClosure(): void
    {
        $closure = fn () => new CtxS3Filesystem();

        $this->container->addContextualBinding(
            CtxVideoController::class,
            CtxFilesystemInterface::class,
            $closure,
        );

        $result = $this->container->getContextualBinding(
            CtxVideoController::class,
            CtxFilesystemInterface::class,
        );

        $this->assertSame($closure, $result);
    }

    public function testGetContextualBindingReturnsNullWhenNotSet(): void
    {
        $this->assertNull(
            $this->container->getContextualBinding(
                CtxPhotoController::class,
                CtxFilesystemInterface::class,
            ),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Contextual Resolution
    // ═══════════════════════════════════════════════════════════════

    public function testContextualBindingResolvesPerConsumer(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->when(CtxVideoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxS3Filesystem::class);

        $photo = $this->container->make(CtxPhotoController::class);
        $video = $this->container->make(CtxVideoController::class);

        $this->assertInstanceOf(CtxLocalFilesystem::class, $photo->filesystem);
        $this->assertInstanceOf(CtxS3Filesystem::class, $video->filesystem);
        $this->assertSame('local', $photo->filesystem->type());
        $this->assertSame('s3', $video->filesystem->type());
    }

    public function testContextualBindingClosureResolution(): void
    {
        $this->container->when(CtxUserService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(fn (Container $c) => new CtxFileLogger('/custom/user.log'));

        $service = $this->container->make(CtxUserService::class);

        $this->assertInstanceOf(CtxFileLogger::class, $service->logger);
        $this->assertSame('file:/custom/user.log', $service->logger->channel());
    }

    public function testContextualBindingClosureReceivesContainer(): void
    {
        $receivedContainer = null;

        $this->container->when(CtxUserService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(function (Container $c) use (&$receivedContainer) {
                $receivedContainer = $c;
                return new CtxDatabaseLogger();
            });

        $this->container->make(CtxUserService::class);

        $this->assertSame($this->container, $receivedContainer);
    }

    public function testContextualBindingDoesNotAffectOtherConsumers(): void
    {
        // Only Photo gets contextual binding
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        // Global binding for the interface
        $this->container->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);

        $photo = $this->container->make(CtxPhotoController::class);
        $video = $this->container->make(CtxVideoController::class);

        // Photo uses contextual (Local), Video uses global (S3)
        $this->assertInstanceOf(CtxLocalFilesystem::class, $photo->filesystem);
        $this->assertInstanceOf(CtxS3Filesystem::class, $video->filesystem);
    }

    public function testContextualBindingOverridesGlobal(): void
    {
        // Global binding
        $this->container->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);

        // Contextual override for Photo
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $photo = $this->container->make(CtxPhotoController::class);
        $this->assertInstanceOf(CtxLocalFilesystem::class, $photo->filesystem);

        // Direct resolution still uses global
        $fs = $this->container->make(CtxFilesystemInterface::class);
        $this->assertInstanceOf(CtxS3Filesystem::class, $fs);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Multiple Contextual Dependencies
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleContextualBindingsPerConsumer(): void
    {
        $this->container->when(CtxMultiDependencyService::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxCloudFilesystem::class);

        $this->container->when(CtxMultiDependencyService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxDatabaseLogger::class);

        $this->container->when(CtxMultiDependencyService::class)
            ->needs(CtxCacheInterface::class)
            ->give(CtxRedisCache::class);

        $service = $this->container->make(CtxMultiDependencyService::class);

        $this->assertSame('cloud', $service->filesystem->type());
        $this->assertSame('database', $service->logger->channel());
        $this->assertSame('redis', $service->cache->driver());
    }

    public function testMixedContextualAndDefaultParams(): void
    {
        $this->container->when(CtxMixedParamsService::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $service = $this->container->make(CtxMixedParamsService::class);

        $this->assertInstanceOf(CtxLocalFilesystem::class, $service->filesystem);
        $this->assertSame('default', $service->name);
    }

    public function testMixedContextualAndExplicitParams(): void
    {
        $this->container->when(CtxMixedParamsService::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxS3Filesystem::class);

        $service = $this->container->make(CtxMixedParamsService::class, [
            'name' => 'custom',
        ]);

        $this->assertInstanceOf(CtxS3Filesystem::class, $service->filesystem);
        $this->assertSame('custom', $service->name);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Different Consumers, Same Interface
    // ═══════════════════════════════════════════════════════════════

    public function testThreeConsumersDifferentImplementations(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->when(CtxVideoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxS3Filesystem::class);

        $this->container->when(CtxDocumentController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxCloudFilesystem::class);

        $photo = $this->container->make(CtxPhotoController::class);
        $video = $this->container->make(CtxVideoController::class);
        $doc = $this->container->make(CtxDocumentController::class);

        $this->assertSame('local', $photo->filesystem->type());
        $this->assertSame('s3', $video->filesystem->type());
        $this->assertSame('cloud', $doc->filesystem->type());
    }

    public function testDifferentInterfacesPerConsumer(): void
    {
        $this->container->when(CtxUserService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxFileLogger::class);

        $this->container->when(CtxReportService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxDatabaseLogger::class);

        $user = $this->container->make(CtxUserService::class);
        $report = $this->container->make(CtxReportService::class);

        $this->assertInstanceOf(CtxFileLogger::class, $user->logger);
        $this->assertInstanceOf(CtxDatabaseLogger::class, $report->logger);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Contextual Binding with Global Fallback
    // ═══════════════════════════════════════════════════════════════

    public function testConsumerWithoutContextualUsesGlobal(): void
    {
        $this->container->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);

        // No contextual binding for NoContextService
        $service = $this->container->make(CtxNoContextService::class);

        $this->assertInstanceOf(CtxS3Filesystem::class, $service->filesystem);
    }

    public function testContextualWithSingleton(): void
    {
        // Global singleton
        $this->container->singleton(CtxLoggerInterface::class, CtxFileLogger::class);

        // Contextual override
        $this->container->when(CtxReportService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxDatabaseLogger::class);

        $user = $this->container->make(CtxUserService::class);
        $report = $this->container->make(CtxReportService::class);

        // User gets the global singleton (FileLogger)
        $this->assertInstanceOf(CtxFileLogger::class, $user->logger);

        // Report gets contextual (DatabaseLogger)
        $this->assertInstanceOf(CtxDatabaseLogger::class, $report->logger);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Contextual Binding — Overwrite / Replace
    // ═══════════════════════════════════════════════════════════════

    public function testContextualBindingCanBeOverwritten(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        // Overwrite with different implementation
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxS3Filesystem::class);

        $photo = $this->container->make(CtxPhotoController::class);
        $this->assertInstanceOf(CtxS3Filesystem::class, $photo->filesystem);
    }

    public function testDirectAddContextualBindingOverwrites(): void
    {
        $this->container->addContextualBinding(
            CtxPhotoController::class,
            CtxFilesystemInterface::class,
            CtxLocalFilesystem::class,
        );

        $this->container->addContextualBinding(
            CtxPhotoController::class,
            CtxFilesystemInterface::class,
            CtxCloudFilesystem::class,
        );

        $photo = $this->container->make(CtxPhotoController::class);
        $this->assertInstanceOf(CtxCloudFilesystem::class, $photo->filesystem);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Reset & Cleanup
    // ═══════════════════════════════════════════════════════════════

    public function testResetClearsContextualBindings(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->reset();

        $this->assertNull(
            $this->container->getContextualBinding(
                CtxPhotoController::class,
                CtxFilesystemInterface::class,
            ),
        );
    }

    public function testForgetDoesNotAffectContextualBindings(): void
    {
        $this->container->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->forget(CtxFilesystemInterface::class);

        // Contextual binding should still be there
        $this->assertSame(
            CtxLocalFilesystem::class,
            $this->container->getContextualBinding(
                CtxPhotoController::class,
                CtxFilesystemInterface::class,
            ),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testContextualBindingTransientCreatesNewInstances(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $photo1 = $this->container->make(CtxPhotoController::class);
        $photo2 = $this->container->make(CtxPhotoController::class);

        // Each resolution creates a new filesystem instance (transient)
        $this->assertNotSame($photo1->filesystem, $photo2->filesystem);
    }

    public function testContextualBindingWithClosureCreatesNewInstances(): void
    {
        $callCount = 0;

        $this->container->when(CtxUserService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(function () use (&$callCount) {
                $callCount++;
                return new CtxFileLogger("/log/{$callCount}.log");
            });

        $svc1 = $this->container->make(CtxUserService::class);
        $svc2 = $this->container->make(CtxUserService::class);

        $this->assertSame(2, $callCount);
        $this->assertNotSame($svc1->logger, $svc2->logger);
        $this->assertSame('file:/log/1.log', $svc1->logger->channel());
        $this->assertSame('file:/log/2.log', $svc2->logger->channel());
    }

    public function testExplicitParamOverridesContextualBinding(): void
    {
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $s3 = new CtxS3Filesystem();
        $photo = $this->container->make(CtxPhotoController::class, [
            'filesystem' => $s3,
        ]);

        // Explicit param wins over contextual binding
        $this->assertSame($s3, $photo->filesystem);
        $this->assertSame('s3', $photo->filesystem->type());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Parent Container Interaction
    // ═══════════════════════════════════════════════════════════════

    public function testContextualBindingInChildContainer(): void
    {
        $parent = new Container();
        $parent->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);

        $child = new Container($parent);
        $child->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $photo = $child->make(CtxPhotoController::class);
        $this->assertInstanceOf(CtxLocalFilesystem::class, $photo->filesystem);
    }

    public function testChildContextualDoesNotAffectParent(): void
    {
        $parent = new Container();
        $parent->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);

        $child = new Container($parent);
        $child->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        // Parent should still resolve S3
        $fs = $parent->make(CtxFilesystemInterface::class);
        $this->assertInstanceOf(CtxS3Filesystem::class, $fs);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: ContextualBindingBuilder Unit Tests
    // ═══════════════════════════════════════════════════════════════

    public function testBuilderNeedsReturnsItself(): void
    {
        $builder = $this->container->when(CtxPhotoController::class);
        $result = $builder->needs(CtxFilesystemInterface::class);

        $this->assertSame($builder, $result);
    }

    public function testBuilderGiveCallsAddContextualBinding(): void
    {
        $builder = $this->container->when(CtxPhotoController::class);
        $builder->needs(CtxFilesystemInterface::class)->give(CtxLocalFilesystem::class);

        $this->assertSame(
            CtxLocalFilesystem::class,
            $this->container->getContextualBinding(
                CtxPhotoController::class,
                CtxFilesystemInterface::class,
            ),
        );
    }

    public function testBuilderChainedForMultipleNeeds(): void
    {
        // Two separate when() calls for the same consumer
        $this->container->when(CtxMultiDependencyService::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->when(CtxMultiDependencyService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxFileLogger::class);

        $this->assertSame(
            CtxLocalFilesystem::class,
            $this->container->getContextualBinding(
                CtxMultiDependencyService::class,
                CtxFilesystemInterface::class,
            ),
        );

        $this->assertSame(
            CtxFileLogger::class,
            $this->container->getContextualBinding(
                CtxMultiDependencyService::class,
                CtxLoggerInterface::class,
            ),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: Integration — Full Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testFullContextualLifecycle(): void
    {
        // 1. Set up global fallback
        $this->container->bind(CtxFilesystemInterface::class, CtxS3Filesystem::class);
        $this->container->bind(CtxLoggerInterface::class, CtxFileLogger::class);

        // 2. Override for specific consumers
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxLocalFilesystem::class);

        $this->container->when(CtxReportService::class)
            ->needs(CtxLoggerInterface::class)
            ->give(CtxDatabaseLogger::class);

        // 3. Resolve — contextual should be used where registered
        $photo = $this->container->make(CtxPhotoController::class);
        $video = $this->container->make(CtxVideoController::class);
        $user = $this->container->make(CtxUserService::class);
        $report = $this->container->make(CtxReportService::class);

        $this->assertSame('local', $photo->filesystem->type());      // contextual
        $this->assertSame('s3', $video->filesystem->type());          // global fallback
        $this->assertInstanceOf(CtxFileLogger::class, $user->logger); // global fallback
        $this->assertSame('database', $report->logger->channel());    // contextual

        // 4. Direct resolution still uses global
        $fs = $this->container->make(CtxFilesystemInterface::class);
        $this->assertInstanceOf(CtxS3Filesystem::class, $fs);

        // 5. Overwrite contextual
        $this->container->when(CtxPhotoController::class)
            ->needs(CtxFilesystemInterface::class)
            ->give(CtxCloudFilesystem::class);

        $photo2 = $this->container->make(CtxPhotoController::class);
        $this->assertSame('cloud', $photo2->filesystem->type());

        // 6. Reset clears everything
        $this->container->reset();
        $this->assertNull(
            $this->container->getContextualBinding(
                CtxPhotoController::class,
                CtxFilesystemInterface::class,
            ),
        );
    }
}
