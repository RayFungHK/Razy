<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Runtime performance profiler that captures memory usage, CPU time,
 * execution time, and declared symbols at checkpoints for comparison.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use InvalidArgumentException;

/**
 * Runtime performance profiler.
 *
 * Captures resource usage snapshots (memory, CPU time, output buffer size,
 * declared functions/classes) at labeled checkpoints and generates
 * delta reports between any two checkpoints.
 *
 * @class Profiler
 */
class Profiler
{
    /** @var array<string, array> Named checkpoints storing resource usage snapshots */
    private array $checkpoints = [];

    /** @var array The initial resource usage sample taken at construction time */
    private array $init;

    /**
     * Profiler constructor.
     */
    public function __construct()
    {
        $this->init = $this->createSample();
    }

    /**
     * Create a check point.
     *
     * @param string $label The check point label
     *
     * @return self Chainable
     *
     * @throws InvalidArgumentException
     */
    public function checkpoint(string $label = ''): self
    {
        $label = \trim($label);
        if (!$label) {
            throw new InvalidArgumentException('You should give the checkpoint with a label.');
        }

        if (isset($this->checkpoints[$label])) {
            throw new InvalidArgumentException('The checkpoint ' . $label . ' already exists, please choose another label.');
        }

        $this->checkpoints[$label] = $this->createSample();

        return $this;
    }

    /**
     * Get the checkpoint report by the given labels.
     *
     * @param bool $compareWithInit
     * @param string ...$labels
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    public function report(bool $compareWithInit = false, string ...$labels): array
    {
        $statistics = [];
        if (\count($labels)) {
            // When specific labels are given, filter checkpoints to only those
            if (1 < \count($labels)) {
                $checkpoints = \array_intersect_key($this->checkpoints, \array_flip($labels));
                if (!\count($checkpoints)) {
                    throw new InvalidArgumentException('There is no checkpoint for generate the report.');
                }
            } else {
                $checkpoints = $this->checkpoints;
            }

            // Optionally include the initialization baseline for comparison
            if ($compareWithInit) {
                $checkpoints['@init'] = $this->init;
            }

            if (\count($checkpoints) < 2) {
                throw new InvalidArgumentException('Not enough checkpoints to generate a report.');
            }

            // Sort checkpoints by their creation order (index)
            \uasort($checkpoints, function ($a, $b) {
                return ($a['index'] < $b['index']) ? -1 : 1;
            });

            // Compute sequential deltas between consecutive checkpoints
            $previous = null;
            foreach ($checkpoints as $label => $stats) {
                if (!$previous) {
                    $previous = $stats;

                    continue;
                }
                $report = [];

                // For each metric, compute the difference (numeric) or new entries (array)
                foreach ($previous as $parameter => $value) {
                    if ('index' === $parameter || !\array_key_exists($parameter, $stats)) {
                        continue;
                    }

                    if (\is_numeric($value)) {
                        $report[$parameter] = $stats[$parameter] - $value;
                    } elseif (\is_array($value)) {
                        $report[$parameter] = \array_diff($stats[$parameter], $value);
                    } else {
                        $report[$parameter] = $value;
                    }
                }

                $statistics[$label] = $report;
                $previous = $stats;
            }

            return $statistics;
        }

        // If $compareWithInit set to true, put init checkpoint into checkpoint list
        if (!$compareWithInit && \count($this->checkpoints) < 2) {
            throw new InvalidArgumentException('Not enough checkpoints to generate a report.');
        }

        // Use init as start if requested; otherwise use the first and last checkpoints
        $start = ($compareWithInit) ? $this->init : \reset($this->checkpoints);
        $compare = (0 === \count($this->checkpoints)) ? $this->createSample() : \end($this->checkpoints);
        $report = [];
        // Compute differences for each profiling metric
        foreach ($start as $parameter => $value) {
            if ('index' === $parameter || !\array_key_exists($parameter, $compare)) {
                continue;
            }

            if (\is_numeric($value)) {
                $report[$parameter] = $compare[$parameter] - $value;
            } elseif (\is_array($value)) {
                $report[$parameter] = \array_diff($compare[$parameter], $value);
            }
        }

        return $report;
    }

    /**
     * Get the report that compare the given label with the initialized checkpoint.
     *
     * @param string $label
     *
     * @return array
     *
     * @throws InvalidArgumentException
     */
    public function reportTo(string $label): array
    {
        if (!isset($this->checkpoints[$label])) {
            throw new InvalidArgumentException('Checkpoint ' . $label . ' was not found.');
        }

        $compare = $this->checkpoints[$label];
        $report = [];
        foreach ($this->init as $parameter => $value) {
            if ('index' === $parameter || !\array_key_exists($parameter, $compare)) {
                continue;
            }

            if (\is_numeric($value)) {
                $report[$parameter] = $compare[$parameter] - $value;
            } elseif (\is_array($value)) {
                $report[$parameter] = \array_diff($compare[$parameter], $value);
            }
        }

        return $report;
    }

    /**
     * Create a new statistic sample.
     *
     * @return array An array contains the statistic details
     */
    private function createSample(): array
    {
        // Capture current resource usage statistics from the OS
        $ru = \getrusage();
        $defined_functions = \get_defined_functions();

        return [
            'index' => \count($this->checkpoints),
            'memory_usage' => \memory_get_usage(),          // Current heap usage in bytes
            'memory_allocated' => \memory_get_usage(true),      // Total allocated memory from system
            'output_buffer' => \ob_get_length(),             // Current output buffer size
            'user_mode_time' => (int) $ru['ru_utime.tv_sec'] + ((int) $ru['ru_utime.tv_usec'] / 1000000),   // User-mode CPU seconds
            'system_mode_time' => (int) $ru['ru_stime.tv_sec'] + ((int) $ru['ru_stime.tv_usec'] / 1000000),   // Kernel-mode CPU seconds
            'execution_time' => \microtime(true),             // Wall-clock timestamp
            'defined_functions' => $defined_functions['user'] ?? [],  // User-defined functions at this point
            'declared_classes' => \get_declared_classes(),      // All declared classes at this point
        ];
    }
}
