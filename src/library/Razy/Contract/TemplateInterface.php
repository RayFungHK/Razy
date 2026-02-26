<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Core interface contract for the template engine (Phase 2.4).
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Contract;

use Razy\ModuleInfo;
use Razy\Template\Source;

/**
 * Contract for the template engine.
 *
 * Provides the core operations for loading template source files
 * and assigning template variables.
 */
interface TemplateInterface
{
    /**
     * Load a template source file.
     *
     * @param string $path The template file path
     * @param ModuleInfo|null $module Optional module context for path resolution
     *
     * @return Source The loaded template source
     */
    public function load(string $path, ?ModuleInfo $module = null): Source;

    /**
     * Assign one or more template variables.
     *
     * @param mixed $parameter Variable name (string) or array of key-value pairs
     * @param mixed $value The value when $parameter is a string
     *
     * @return self
     */
    public function assign(mixed $parameter, mixed $value = null): self;
}
