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
 *
 * @license MIT
 */

namespace Razy\Database;

/**
 * Class Preset.
 *
 * Abstract base class for statement presets. Presets provide pre-configured
 * query patterns that can be applied to a Statement via the TableJoinSyntax.
 * Subclasses implement specific query templates (e.g., pagination, search filters).
 *
 * @package Razy
 *
 * @license MIT
 */
abstract class Preset
{
    /** @var Statement|null The Statement instance this preset operates on */
    protected ?Statement $statement = null;

    /** @var string The target table name */
    protected string $table = '';

    /** @var string The alias for the target table in the query */
    protected string $alias = '';

    /** @var array Initialization parameters passed to the preset */
    protected array $params = [];

    /**
     * Preset constructor.
     *
     * @param Statement $statement
     * @param string $table
     * @param string $alias
     */
    public function __construct(Statement $statement, string $table, string $alias)
    {
        $this->statement = $statement;
        $this->table = $table;
        $this->alias = $alias;
    }

    /**
     * Initialize the preset with custom parameters.
     *
     * @param array $params Configuration parameters for the preset
     *
     * @return $this
     */
    public function init(array $params = []): static
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Get the Statement entity bound to this preset.
     *
     * @return Statement The bound Statement instance
     */
    public function getStatement(): Statement
    {
        return $this->statement;
    }
}
