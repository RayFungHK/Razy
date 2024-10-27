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

use function is_array;
use const PHP_EOL;

class Configuration extends Collection
{
    private bool $changed = false;
    private string $extension;
    private string $filename;

    /**
     * Configuration constructor.
     *
     * @param string $path
     *
     * @throws Error
     */
    public function __construct(private readonly string $path)
    {
        $pathInfo = pathinfo($this->path);
        $this->filename = trim($pathInfo['filename']);
        $this->extension = strtolower($pathInfo['extension']);

        if (!$this->filename) {
            throw new Error('Config file name cannot be empty.');
        }

        if (is_file($this->path)) {
            // If the config file path is a directory, throw an error
            if (is_dir($this->path)) {
                throw new Error('The config file' . $this->path . ' is not a valid config file.');
            }

            if ('php' === $this->extension) {
                parent::__construct(require $this->path);
            } elseif ('json' === $this->extension) {
                $data = json_decode(file_get_contents($this->path), true);
                parent::__construct($data);
            } elseif ('ini' === $this->extension) {
                $data = parse_ini_file($this->path, true);
                parent::__construct($data);
            }
        }
    }

    /**
     * Overrides offsetSet method from \ArrayObject, pass the value to the rule closure before set the value.
     *
     * @param       $key
     * @param mixed $value The value to set to the iterator
     */
    public function offsetSet($key, mixed $value): void
    {
        if (!isset($this[$key]) || $this[$key] !== $value) {
            $this->changed = true;
        }
        parent::offsetSet($key, $value);
    }

    /**
     * Save the config into the file.
     *
     * @return self Chainable
     * @throws Error
     *
     */
    public function save(): Configuration
    {
        if (!$this->changed) {
            return $this;
        }

        if ('php' === $this->extension) {
            $this->saveAsPHP();
        } elseif ('ini' === $this->extension) {
            $this->saveAsINI();
        } elseif ('json' === $this->extension) {
            $this->saveAsJson();
        }

        return $this;
    }

    /**
     * Save the config file into a PHP file.
     *
     * @throws Error
     */
    private function saveAsPHP(): void
    {
        if (!$this->changed) {
            return;
        }

        $this->writeFile('<?php' . PHP_EOL . 'return ' . var_export($this->getArrayCopy(), true) . ';' . PHP_EOL . '?>');
    }

    /**
     * Write the content to the file.
     *
     * @param string $content The config file content
     *
     * @throws Error
     */
    private function writeFile(string $content): void
    {
        // Get the config file path info
        $pathInfo = pathinfo($this->path);

        // Check the configuration folder does exist
        if (!is_dir($pathInfo['dirname'])) {
            // Create the directory
            mkdir($pathInfo['dirname'], 0755, true);
        } elseif (!is_dir($pathInfo['dirname'])) {
            // If the path does exist but not a directory, throw an error
            throw new Error($pathInfo['dirname'] . ' is not a directory.');
        }

        file_put_contents($this->path, $content);
        if ($handle = fopen($this->path, 'w')) {
            fwrite($handle, $content);

            fclose($handle);
        }
    }

    /**
     * Save the config file into an ini file.
     *
     * @throws Error
     */
    private function saveAsINI(): void
    {
        if (!$this->changed) {
            return;
        }

        $content = [];
        foreach ($this->getArrayCopy() as $key => $val) {
            if (is_array($val)) {
                $content[] = '[' . $key . ']';
                foreach ($val as $sKey => $sVal) {
                    $content[] = $sKey . ' = ' . (is_numeric($sVal) ? $sVal : '"' . $sVal . '"');
                }
            } else {
                $content[] = $key . ' = ' . (is_numeric($val) ? $val : '"' . $val . '"');
            }
        }
        $this->writeFile(implode(PHP_EOL, $content));
    }

    /**
     * Save the config file into a json file.
     *
     * @throws Error
     */
    private function saveAsJSON(): void
    {
        if (!$this->changed) {
            return;
        }

        $this->writeFile(json_encode($this->getArrayCopy()));
    }
}
