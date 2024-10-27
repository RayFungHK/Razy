<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use SplFileObject;

class FileReader
{
    private array $generator = [];

    /**
     * FileReader constructor.
     *
     * @param string $filepath
     *
     * @throws Error
     */
    public function __construct(string $filepath)
    {
        if (!is_file($filepath)) {
            throw new Error('The file ' . $filepath . ' does not exists.');
        }

        $this->generator[] = new SplFileObject($filepath);
    }

    /**
     * Append a new file into generator.
     *
     * @param string $filepath
     *
     * @return FileReader
     * @throws Error
     */
    public function append(string $filepath): FileReader
    {
        if (!is_file($filepath)) {
            throw new Error('The file ' . $filepath . ' does not exists.');
        }

        $this->generator[] = new SplFileObject($filepath);

        return $this;
    }

    /**
     * Fetch the next line of the files in queue.
     *
     * @return null|string
     */
    public function fetch(): ?string
    {
        while (!$this->generator[0]->valid()) {
            array_shift($this->generator);
            if (empty($this->generator)) {
                return null;
            }
        }

        return $this->generator[0]->fgets();
    }

    /**
     * Prepend a new file into generator.
     *
     * @param string $filepath
     *
     * @return FileReader
     * @throws Error
     */
    public function prepend(string $filepath): FileReader
    {
        if (!is_file($filepath)) {
            throw new Error('The file ' . $filepath . ' does not exists.');
        }

        array_unshift($this->generator, new SplFileObject($filepath));

        return $this;
    }
}
