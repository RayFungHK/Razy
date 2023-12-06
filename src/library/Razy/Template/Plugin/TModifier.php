<?php

namespace Razy\Template\Plugin;

class TModifier
{
    private string $name;

    protected mixed $caller = null;

    /**
     * @param string $entity
     * @return $this
     */
    final public function bind(mixed $entity): static
    {
        $this->caller = $entity;

        return $this;
    }

    /**
     * @param mixed $value
     * @param string ...$args
     * @return string|null
     */
    protected function process(mixed $value, string ...$args): ?string
    {
        return '';
    }

    /**
     * Modify the value.
     *
     * @param mixed $value The value to modify
     * @param string $paramText The well-formatted modifier's parameter string
     *
     * @return mixed
     */
    final public function modify(mixed $value, string $paramText = ''): mixed
    {
        $arguments = [$value];
        preg_match_all('/:(?:(\w+)|(-?\d+(?:\.\d+)?|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $paramText ?? '', $args);
        // The first argument always is the value
        foreach ($args as $arg) {
            $arguments[] = (array_key_exists(4, $arg)) ? $arg[4] : ($arg[2] ?? $arg[1] ?? null);
        }

        return call_user_func_array([$this, 'process'], $arguments);
    }

    /**
     * @return string
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return $this
     */
    final public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}