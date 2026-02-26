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

use Razy\Exception\ConfigurationException;

/**
 * Configuration extends Collection to load, modify, and persist key-value settings
 * from PHP, JSON, INI, or YAML configuration files.
 *
 * Changes are tracked automatically and only written to disk when save() is called.
 *
 * @class Configuration
 *
 * @package Razy
 *
 * @license MIT
 */
class Configuration extends Collection
{
    /** @var bool Whether any value has been modified since loading */
    private bool $changed = false;

    /** @var string The file extension (php, json, ini, yaml, yml) */
    private string $extension;

    /** @var string The base filename without extension */
    private string $filename;

    /**
     * Configuration constructor.
     *
     * @param string $path
     *
     * @throws ConfigurationException
     */
    public function __construct(private readonly string $path)
    {
        $pathInfo = \pathinfo($this->path);
        $this->filename = \trim($pathInfo['filename']);
        $this->extension = \strtolower($pathInfo['extension'] ?? '');

        if (!$this->filename) {
            throw new ConfigurationException('Config file name cannot be empty.');
        }

        if (\is_file($this->path)) {
            // Reject directories masquerading as file paths
            if (\is_dir($this->path)) {
                throw new ConfigurationException('The config file' . $this->path . ' is not a valid config file.');
            }

            // Try to load from cache for JSON, INI, and YAML files (PHP files benefit from OPcache)
            $realPath = \realpath($this->path);
            if ($realPath !== false && \in_array($this->extension, ['json', 'ini', 'yaml', 'yml'], true)) {
                $cacheKey = 'config.' . \md5($realPath);
                $cached = Cache::getValidated($cacheKey, $realPath);
                if ($cached !== null) {
                    parent::__construct($cached);
                    return;
                }
            }

            // Load configuration data based on file extension
            if ('php' === $this->extension) {
                parent::__construct(require $this->path);
            } elseif ('json' === $this->extension) {
                $data = \json_decode(\file_get_contents($this->path), true);
                parent::__construct($data);
            } elseif ('ini' === $this->extension) {
                $data = \parse_ini_file($this->path, true);
                parent::__construct($data);
            } elseif ('yaml' === $this->extension || 'yml' === $this->extension) {
                $data = YAML::parseFile($this->path);
                parent::__construct($data);
            }

            // Cache the loaded data for non-PHP files
            if (isset($realPath, $cacheKey, $data)) {
                Cache::setValidated($cacheKey, $realPath, $data);
            }
        }
    }

    /**
     * Override offsetSet to track changes for deferred persistence.
     *
     * Marks the configuration as changed if the value is new or differs
     * from the current value, enabling save() to know whether a write is needed.
     *
     * @param mixed $key The configuration key
     * @param mixed $value The value to set
     */
    public function offsetSet($key, mixed $value): void
    {
        // Only flag as changed if the value is actually different
        if (!isset($this[$key]) || $this[$key] !== $value) {
            $this->changed = true;
        }
        parent::offsetSet($key, $value);
    }

    /**
     * Persist the configuration to its source file.
     *
     * Dispatches to the appropriate format-specific writer based on the file extension.
     * No-op if no values have been modified since the last load or save.
     *
     * @return self Fluent interface
     *
     * @throws ConfigurationException If the file cannot be written
     */
    public function save(): self
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
        } elseif ('yaml' === $this->extension || 'yml' === $this->extension) {
            $this->saveAsYAML();
        }

        return $this;
    }

    /**
     * Serialize the configuration as a PHP return-array file.
     *
     * @throws ConfigurationException If writing fails
     */
    private function saveAsPHP(): void
    {
        if (!$this->changed) {
            return;
        }

        $this->writeFile('<?php' . \PHP_EOL . 'return ' . \var_export($this->getArrayCopy(), true) . ';' . \PHP_EOL . '?>');
    }

    /**
     * Write content to the configuration file, creating directories if needed.
     *
     * @param string $content The full file content to write
     *
     * @throws ConfigurationException If the target directory path exists but is not a directory
     */
    private function writeFile(string $content): void
    {
        // Resolve the directory portion of the file path
        $pathInfo = \pathinfo($this->path);

        // Ensure the parent directory exists
        if (!\is_dir($pathInfo['dirname'])) {
            // Recursively create the directory structure
            if (!\mkdir($pathInfo['dirname'], 0o755, true) && !\is_dir($pathInfo['dirname'])) {
                // Path could not be created (e.g., permission issue or a file blocking the path)
                throw new ConfigurationException($pathInfo['dirname'] . ' could not be created or is not a directory.');
            }
        }

        \file_put_contents($this->path, $content);
    }

    /**
     * Serialize the configuration as an INI file.
     *
     * Supports one level of sections (arrays become INI sections).
     *
     * @throws ConfigurationException If writing fails
     */
    private function saveAsINI(): void
    {
        if (!$this->changed) {
            return;
        }

        $content = [];
        foreach ($this->getArrayCopy() as $key => $val) {
            if (\is_array($val)) {
                // Array values become INI sections: [section_name]
                $content[] = '[' . $key . ']';
                foreach ($val as $sKey => $sVal) {
                    // Numeric values are unquoted; strings are double-quoted
                    $content[] = $sKey . ' = ' . (\is_numeric($sVal) ? $sVal : '"' . $sVal . '"');
                }
            } else {
                // Top-level scalar values
                $content[] = $key . ' = ' . (\is_numeric($val) ? $val : '"' . $val . '"');
            }
        }
        $this->writeFile(\implode(\PHP_EOL, $content));
    }

    /**
     * Serialize the configuration as a JSON file.
     *
     * @throws ConfigurationException If writing fails
     */
    private function saveAsJson(): void
    {
        if (!$this->changed) {
            return;
        }

        $this->writeFile(\json_encode($this->getArrayCopy()));
    }

    /**
     * Serialize the configuration as a YAML file.
     *
     * @throws ConfigurationException If writing fails
     */
    private function saveAsYAML(): void
    {
        if (!$this->changed) {
            return;
        }

        $yaml = YAML::dump($this->getArrayCopy());
        $this->writeFile($yaml);
    }
}
