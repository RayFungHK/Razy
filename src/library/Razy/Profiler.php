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

class Profiler
{
    /**
     * An array contains initialize statistic.
     *
     * @var array
     */
    private array $init;

    /**
     * An array contains each check point statistic.
     *
     * @var array
     */
    private array $checkpoints = [];

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
     * @throws Error
     *
     */
    public function checkpoint(string $label = ''): Profiler
    {
        $label = trim($label);
        if (!$label) {
            throw new Error('You should give the checkpoint with a label.');
        }

        if (isset($this->checkpoints[$label])) {
            throw new Error('The checkpoint ' . $label . ' already exists, please choose another label.');
        }

        $this->checkpoints[$label] = $this->createSample();

        return $this;
    }

    /**
     * Get the report that compare the given label with the initialized checkpoint.
     *
     * @param string $label
     *
     * @return array
     * @throws Error
     *
     */
    public function reportTo(string $label): array
    {
        if (!isset($this->checkpoints[$label])) {
            throw new Error('Checkpoint ' . $label . ' was not found.');
        }

        $compare = $this->checkpoints[$label];
        $report  = [];
        foreach ($this->init as $parameter => $value) {
            if ('index' === $parameter || !array_key_exists($parameter, $compare)) {
                continue;
            }

            if (is_numeric($value)) {
                $report[$parameter] = $compare[$parameter] - $value;
            } elseif (is_array($value)) {
                $report[$parameter] = array_diff($compare[$parameter], $value);
            }
        }

        return $report;
    }

    /**
     * Get the checkpoint report by the given labels.
     *
     * @param bool   $compareWithInit
     * @param string ...$labels
     *
     * @return array
     * @throws Error
     *
     */
    public function report(bool $compareWithInit = false, string ...$labels): array
    {
        $statistics = [];
        if (count($labels)) {
            if (1 < count($labels)) {
                $checkpoints = array_intersect_key($this->checkpoints, array_flip($labels));
                if (!count($checkpoints)) {
                    throw new Error('There is no checkpoint for generate the report.');
                }
            } else {
                $checkpoints = $this->checkpoints;
            }

            // If $compareWithInit set to true, put init checkpoint into checkpoint list
            if ($compareWithInit) {
                $checkpoints['@init'] = $this->init;
            }

            if (count($checkpoints) < 2) {
                throw new Error('Not enough checkpoints to generate a report.');
            }

            uasort($checkpoints, function ($a, $b) {
                return ($a['index'] < $b['index']) ? -1 : 1;
            });

            $previous = null;
            foreach ($checkpoints as $label => $stats) {
                if (!$previous) {
                    $previous = $stats;

                    continue;
                }
                $report = [];

                foreach ($previous as $parameter => $value) {
                    if ('index' === $parameter || !array_key_exists($parameter, $stats)) {
                        continue;
                    }

                    if (is_numeric($value)) {
                        $report[$parameter] = $stats[$parameter] - $value;
                    } elseif (is_array($value)) {
                        $report[$parameter] = array_diff($stats[$parameter], $value);
                    } else {
                        $report[$parameter] = $value;
                    }
                }

                $statistics[$label] = $report;
                $previous           = $stats;
            }

            return $statistics;
        }

        // If $compareWithInit set to true, put init checkpoint into checkpoint list
        if (!$compareWithInit && count($this->checkpoints) < 2) {
            throw new Error('Not enough checkpoints to generate a report.');
        }

        $start   = ($compareWithInit) ? $this->init : reset($this->checkpoints);
        $compare = (0 === count($this->checkpoints)) ? $this->createSample() : end($this->checkpoints);
        $report  = [];
        foreach ($start as $parameter => $value) {
            if ('index' === $parameter || !array_key_exists($parameter, $compare)) {
                continue;
            }

            if (is_numeric($value)) {
                $report[$parameter] = $compare[$parameter] - $value;
            } elseif (is_array($value)) {
                $report[$parameter] = array_diff($compare[$parameter], $value);
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
        $ru                = getrusage();
        $defined_functions = get_defined_functions(true);

        return [
            'index'             => count($this->checkpoints),
            'memory_usage'      => memory_get_usage(),
            'memory_allocated'  => memory_get_usage(true),
            'output_buffer'     => ob_get_length(),
            'user_mode_time'    => (int) $ru['ru_utime.tv_sec'] + ((int) $ru['ru_utime.tv_usec'] / 1000000),
            'system_mode_time'  => (int) $ru['ru_stime.tv_sec'] + ((int) $ru['ru_stime.tv_usec'] / 1000000),
            'execution_time'    => microtime(true),
            'defined_functions' => $defined_functions['user'] ?? [],
            'declared_classes'  => get_declared_classes(),
        ];
    }
}
