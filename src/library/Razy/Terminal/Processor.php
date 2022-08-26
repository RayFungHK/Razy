<?php

namespace Razy\Terminal;

use Closure;

class Processor
{
    private ?Closure $processor = null;
    private array $parameters = [];

    /**
     * @param Closure $processor
     * @param array $parameters
     */
    public function __construct(Closure $processor, array $parameters = [])
    {
        $this->processor = $processor->bindTo($this);
        $this->parameters = $parameters;
    }

    /**
     * @param array $args
     * @return void
     */
    public function run(array $args = [])
    {
        return call_user_func_array($this->processor, $args);
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
