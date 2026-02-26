<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Module;

/**
 * Enum ModuleStatus
 *
 * Represents the lifecycle status of a Module within the Distributor context.
 * Replaces the legacy Module::STATUS_* integer constants with a type-safe enum.
 */
enum ModuleStatus: int
{
    /** Module initialization or loading has failed */
    case Failed = -3;

    /** Module is explicitly disabled */
    case Disabled = -2;

    /** Module has been explicitly unloaded */
    case Unloaded = -1;

    /** Module has not yet started processing */
    case Pending = 0;

    /** Module is in the initialization phase */
    case Initialing = 1;

    /** Module is actively being processed/loaded */
    case Processing = 2;

    /** Module is queued and awaiting readiness */
    case InQueue = 3;

    /** Module is fully loaded and operational */
    case Loaded = 4;
}
