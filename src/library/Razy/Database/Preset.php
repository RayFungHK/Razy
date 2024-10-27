<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

abstract class Preset
{
    protected ?Statement $statement = null;
    protected string $table         = '';
    protected string $alias         = '';
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
    }

    /**
     * Initial Preset
     *
     * @param array $params
     * @return $this
     */
    public function init(array $params = []): static
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Ge the Statement entity
     *
     * @return Statement
     */
    public function getStatement(): Statement
    {
        return $this->statement;
    }
}