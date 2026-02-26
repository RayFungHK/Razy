<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\FileException;
use SplFileObject;

/**
 * Class FileReader.
 *
 * Provides sequential line-by-line reading across multiple files using SplFileObject.
 * Files can be appended or prepended to the reading queue, and lines are fetched
 * in order, automatically advancing to the next file when one is exhausted.
 *
 * @class FileReader
 */
class FileReader
{
    /** @var array<SplFileObject> Queue of file objects for sequential reading */
    private array $generator = [];

    /**
     * FileReader constructor.
     *
     * @param string $filepath
     *
     * @throws FileException
     */
    public function __construct(string $filepath)
    {
        if (!\is_file($filepath)) {
            throw new FileException('The file ' . $filepath . ' does not exists.');
        }

        $this->generator[] = new SplFileObject($filepath);
    }

    /**
     * Append a new file into generator.
     *
     * @param string $filepath
     *
     * @return FileReader
     *
     * @throws FileException
     */
    public function append(string $filepath): self
    {
        if (!\is_file($filepath)) {
            throw new FileException('The file ' . $filepath . ' does not exists.');
        }

        $this->generator[] = new SplFileObject($filepath);

        return $this;
    }

    /**
     * Fetch the next line of the files in queue.
     *
     * @return string|null
     */
    public function fetch(): ?string
    {
        // Skip exhausted file objects and advance to the next file in the queue
        while (!$this->generator[0]->valid()) {
            \array_shift($this->generator);
            if (empty($this->generator)) {
                return null;
            }
        }

        // Read and return the next line from the current file
        return $this->generator[0]->fgets();
    }

    /**
     * Prepend a new file into generator.
     *
     * @param string $filepath
     *
     * @return FileReader
     *
     * @throws FileException
     */
    public function prepend(string $filepath): self
    {
        if (!\is_file($filepath)) {
            throw new FileException('The file ' . $filepath . ' does not exists.');
        }

        \array_unshift($this->generator, new SplFileObject($filepath));

        return $this;
    }
}
