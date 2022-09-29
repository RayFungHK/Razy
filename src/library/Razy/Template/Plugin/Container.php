<?php

namespace Razy\Template\Plugin;

use Razy\Template\Entity;

class Container
{
    /**
     * The storage of the arguments
     *
     * @var array
     */
    private array $arguments = [];
    /**
     * The string of wrapped content
     *
     * @var string
     */
    private string $content = '';
    /**
     * The Entity entity
     *
     * @var Entity
     */
    private Entity $entity;
    /**
     * The storage of the parameters
     *
     * @var array
     */
    private array $parameters = [];

    /**
     * Container constructor
     *
     * @param Entity $entity
     * @param array  $parameters
     * @param array  $arguments
     * @param string $content
     */
    public function __construct(Entity $entity, array $parameters, array $arguments, string $content)
    {
        $this->entity     = $entity;
        $this->parameters = $parameters;
        $this->arguments  = $arguments;
        $this->content    = $content;
    }

    /**
     * Get the arguments
     *
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Get the wrapped content
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}