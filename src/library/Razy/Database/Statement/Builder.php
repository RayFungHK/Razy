<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database\Statement;

use Closure;
use Razy\Database\Statement;

/**
 * Class Builder
 *
 * Abstract base class for Statement builder plugins that customize SQL generation.
 * Builders are registered via the PluginTrait and initialized through Statement::builder().
 * Subclasses override the build() method to apply custom joins, conditions, select columns,
 * and other SQL components to a Statement targeting a specific table.
 *
 * @package Razy
 * @license MIT
 */
class Builder
{
	/** @var Closure|null Optional post-processing callback applied after statement execution */
	protected ?Closure $postProcess = null;

    /** @var Statement|null The Statement instance this builder is attached to */
    protected ?Statement $statement = null;

    /**
     * Initialize the builder with a Statement instance.
     * Called internally by Statement::builder(). Only binds once — subsequent
     * calls will not overwrite the existing statement reference.
     *
     * @param Statement|null $statement The Statement instance to attach to
     *
     * @return static The builder instance for method chaining
     */
    final public function init(Statement $statement = null): static
    {
        // Only bind on first call to prevent re-initialization
        if (!$this->statement) {
            $this->statement = $statement;
        }
        return $this;
    }

    /**
     * Build and configure the Statement for a specific table.
     * Override this method in subclasses to apply custom SQL generation logic
     * (e.g., adding joins, where clauses, select columns).
     *
     * @param string $tableName The target table name
     *
     * @return void
     */
	public function build(string $tableName): void
	{

	}
}