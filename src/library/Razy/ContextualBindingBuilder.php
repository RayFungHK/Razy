<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Fluent builder for contextual container bindings.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Closure;

/**
 * Fluent builder for defining contextual bindings in the Container.
 *
 * Provides a readable API for binding different implementations of the
 * same interface depending on which class is consuming it.
 *
 * Usage:
 *   $container->when(PhotoController::class)
 *       ->needs(FilesystemInterface::class)
 *       ->give(LocalFilesystem::class);
 *
 *   $container->when(VideoController::class)
 *       ->needs(FilesystemInterface::class)
 *       ->give(S3Filesystem::class);
 *
 *   // With closures:
 *   $container->when(ReportService::class)
 *       ->needs(LoggerInterface::class)
 *       ->give(fn(Container $c) => new FileLogger('/var/log/reports.log'));
 */
class ContextualBindingBuilder
{
    /** @var string The abstract dependency being resolved */
    private string $needs = '';

    /**
     * ContextualBindingBuilder constructor.
     *
     * @param Container $container The container instance
     * @param string    $consumer  The consuming class that triggers this contextual binding
     */
    public function __construct(
        private readonly Container $container,
        private readonly string $consumer
    ) {
    }

    /**
     * Define which abstract type or interface this contextual binding targets.
     *
     * @param string $abstract The abstract type or interface the consumer depends on
     *
     * @return $this
     */
    public function needs(string $abstract): static
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the concrete implementation to provide for the contextual binding.
     *
     * The implementation can be:
     * - A class name string: will be resolved by the container
     * - A Closure: invoked with ($container, $params) to produce the instance
     *
     * @param string|Closure $implementation The concrete class or factory closure
     *
     * @return void
     */
    public function give(string|Closure $implementation): void
    {
        $this->container->addContextualBinding(
            $this->consumer,
            $this->needs,
            $implementation
        );
    }
}
