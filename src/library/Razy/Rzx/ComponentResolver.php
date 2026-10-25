<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

/**
 * Finds the `.rzx` source of a component name (`Card`, `Kit.ManagementList`).
 */
interface ComponentResolver
{
    /**
     * @return string|null absolute path of the `.rzx` file, or null when unknown
     */
    public function resolve(string $name): ?string;

    /**
     * The Profile name the component's namespace was registered with, or null.
     */
    public function profile(string $name): ?string;
}
