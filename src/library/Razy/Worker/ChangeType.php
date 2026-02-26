<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Worker;

/**
 * Classifies the type of change detected in a module's files.
 *
 * Decision tree:
 *   - If any .php file contains a named class/interface/trait/enum → ClassFile (restart required)
 *   - If .php files changed but only contain anonymous classes/closures → Rebindable (rebind OK)
 *   - If only package.php / templates / assets changed → Config (hot-swap OK)
 *   - If nothing changed → None
 *
 * PHP cannot unload named class definitions once loaded, so any change to a .php
 * file declaring a named class requires a full process restart. Anonymous classes
 * and closures can be safely re-included and rebound via the Container.
 */
enum ChangeType: string
{
    /**
     * Whether a full process restart is required.
     */
    public function requiresRestart(): bool
    {
        return $this === self::ClassFile;
    }

    /**
     * Whether in-process hot-swap is safe (config-only changes).
     */
    public function canHotSwap(): bool
    {
        return $this === self::Config;
    }

    /**
     * Whether in-process rebind is possible (anonymous PHP or config changes).
     */
    public function canRebind(): bool
    {
        return $this === self::Rebindable || $this === self::Config;
    }

    /**
     * Severity level for change type comparison.
     * Higher severity takes precedence when aggregating across modules.
     *
     * @return int 0=None, 1=Config, 2=Rebindable, 3=ClassFile
     */
    public function severity(): int
    {
        return match ($this) {
            self::None => 0,
            self::Config => 1,
            self::Rebindable => 2,
            self::ClassFile => 3,
        };
    }
    /** No changes detected. */
    case None = 'none';

    /**
     * Only configuration / template / asset files changed.
     * Safe for in-process hot-swap (Strategy B or C).
     */
    case Config = 'config';

    /**
     * PHP files changed but contain only anonymous classes or closures.
     * Safe for in-process rebind via Container (Strategy C+).
     * Anonymous classes produce unique internal names on each include,
     * so they do not conflict with previously loaded definitions.
     */
    case Rebindable = 'rebindable';

    /**
     * PHP files changed that contain named class/interface/trait/enum definitions.
     * Requires full process restart (Strategy A) because PHP cannot
     * unload named class definitions from memory.
     */
    case ClassFile = 'class';
}
