<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Contract;

use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\ModuleScanner;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Template;

/**
 * Interface DistributorInterface.
 *
 * Shared contract between Distributor (multisite) and Standalone (lite) mode.
 * Module, API, EventEmitter, and PackageManager depend on this interface rather
 * than the concrete Distributor class, enabling both runtime modes to share the
 * same module lifecycle engine.
 */
interface DistributorInterface
{
    /**
     * Get the distribution code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Check if strict mode is enabled.
     *
     * @return bool
     */
    public function isStrict(): bool;

    /**
     * Check if fallback routing is enabled.
     *
     * @return bool
     */
    public function getFallback(): bool;

    /**
     * Get the DI container.
     *
     * @return ContainerInterface|null
     */
    public function getContainer(): ?ContainerInterface;

    /**
     * Get the ModuleRegistry sub-object.
     *
     * @return ModuleRegistry
     */
    public function getRegistry(): ModuleRegistry;

    /**
     * Get the RouteDispatcher sub-object.
     *
     * @return RouteDispatcher
     */
    public function getRouter(): RouteDispatcher;

    /**
     * Get the PrerequisiteResolver sub-object.
     *
     * @return PrerequisiteResolver
     */
    public function getPrerequisites(): PrerequisiteResolver;

    /**
     * Get the ModuleScanner sub-object.
     *
     * @return ModuleScanner
     */
    public function getScanner(): ModuleScanner;

    /**
     * Get the distributor data folder file path.
     *
     * @param string $module
     * @param bool $isURL
     *
     * @return string
     */
    public function getDataPath(string $module = '', bool $isURL = false): string;

    /**
     * Get the distributor identity string (used for data-path scoping).
     *
     * @return string
     */
    public function getIdentity(): string;

    /**
     * Get the site URL path.
     *
     * @return string
     */
    public function getSiteURL(): string;

    /**
     * Get the distributor configuration folder path.
     *
     * @return string
     */
    public function getFolderPath(): string;

    /**
     * Get the initialized global Template entity.
     *
     * @return Template
     */
    public function getGlobalTemplateEntity(): Template;
}
