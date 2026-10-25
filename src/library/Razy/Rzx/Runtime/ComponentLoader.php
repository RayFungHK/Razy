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

namespace Razy\Rzx\Runtime;

/**
 * Supplies compiled components by name (the Compiler and its cache).
 */
interface ComponentLoader
{
    public function load(string $name): Compiled;

    /** A screen or layout, loaded by path; $profile names its Profile. */
    public function loadFile(string $path, ?string $profile = null): Compiled;
}
