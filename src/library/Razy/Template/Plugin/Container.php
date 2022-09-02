<?php

namespace Razy\Template\Plugin;

use Razy\Template\Entity;

class Container
{
    private array $parameters = [];
    private array $arguments  = [];
    private string $content   = '';
    private Entity $entity;

    public function __construct(Entity $entity, array $parameters, array $arguments, string $content)
    {
        $this->entity     = $entity;
        $this->parameters = $parameters;
        $this->arguments  = $arguments;
        $this->content    = $content;
    }

    /**
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }
}