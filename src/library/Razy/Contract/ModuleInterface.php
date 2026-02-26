<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Core interface contract for modules (Phase 2.4).
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Contract;

use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;

/**
 * Contract for module instances.
 *
 * Provides the core operations for inspecting module status,
 * accessing metadata, and executing API commands.
 */
interface ModuleInterface
{
    /**
     * Get the module metadata information.
     *
     * @return ModuleInfo The module's metadata
     */
    public function getModuleInfo(): ModuleInfo;

    /**
     * Get the current lifecycle status of the module.
     *
     * @return ModuleStatus The module lifecycle status
     */
    public function getStatus(): ModuleStatus;

    /**
     * Execute an API command on this module.
     *
     * @param ModuleInfo $module The requesting module's info
     * @param string $command The API command name
     * @param array $args The arguments to pass
     *
     * @return mixed The command result
     */
    public function execute(ModuleInfo $module, string $command, array $args): mixed;
}
