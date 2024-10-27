<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

class Route
{
    private mixed $data = null;

    /**
     * Route constructor.
     *
     * @param string $closurePath
     * @throws Error
     */
    public function __construct(private string $closurePath)
    {
        $this->closurePath = trim(tidy($this->closurePath, false, '/'), '/');
        if (strlen($this->closurePath) === 0) {
            throw new Error('The closure path cannot be empty.');
        }

    }

    /**
     * Insert data for passing data to controller that routed in
     *
     * @param $data
     * @return $this
     */
    public function contain($data = null): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Get the data that inserted before.
     *
     * @return mixed
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get the closure path.
     *
     * @return string
     */
    public function getClosurePath(): string
    {
        return $this->closurePath;
    }
}
