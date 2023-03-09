<?php

namespace Razy\Database;

abstract class Preset
{
    protected ?Statement $statement = null;
    protected string $table         = '';
    protected string $alias         = '';
    protected array $params = [];

    public function __construct(Statement $statement, string $table, string $alias)
    {
        $this->statement = $statement;
    }

    public function init(array $params = []): Preset
    {
        $this->params = $params;
        return $this;
    }

    public function getStatement(): Statement
    {
        return $this->statement;
    }
}