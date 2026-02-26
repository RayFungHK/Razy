<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Closure;
use Razy\Exception\QueryException;
use Throwable;

/**
 * Handles lazy-loading and result-set processing for database queries.
 *
 * Extracted from Statement to separate "fetch and process results" from "build SQL".
 * Provides lazy(), lazyGroup(), lazyKeyValuePair() convenience methods, plus
 * row parser callbacks and column value collection.
 *
 *
 * @license MIT
 */
class LazyResultSet
{
    /** @var array Column names to collect values from during fetch, keyed by column name => isUnique */
    private array $collects = [];

    /** @var array Collected values from query results, keyed by column name */
    private array $collections = [];

    /** @var Closure|null Callback to transform each fetched row */
    private ?Closure $parser = null;

    /** @var bool Whether the parser callback should be removed after single use */
    private bool $once = false;

    /**
     * @param StatementExecutor $executor The executor that runs queries
     */
    public function __construct(
        private readonly StatementExecutor $executor,
    ) {
    }

    /**
     * Execute the statement, fetch the first row, and optionally apply the parser callback.
     * If the parser is set to 'once' mode, it will be cleared after this call.
     *
     * @param array $parameters Optional parameters to merge into the statement
     *
     * @return mixed The first result row (possibly transformed by parser), or false/null
     *
     * @throws Throwable
     */
    public function lazy(array $parameters = []): mixed
    {
        $result = $this->executor->query($parameters)->fetch();
        if ($result) {
            $parser = $this->parser;
            if (\is_callable($parser)) {
                $parser($result);
            }
        }

        // If the parser set only execute once, reset the parser.
        if ($this->once) {
            $this->once = false;
            $this->parser = null;
        }

        return $result;
    }

    /**
     * Execute the statement and return all results, optionally grouped by a column.
     * Supports stacking (multiple rows per group key) and collecting column values.
     *
     * @param array $parameters An array of parameter values to merge
     * @param string $column Column name to use as array key for grouping results
     * @param bool $stackable When true, groups with the same key collect into arrays
     * @param string $stackColumn When stacking, use this column as the sub-key within each group
     *
     * @return array The result set, optionally grouped and keyed
     *
     * @throws Throwable
     */
    public function &lazyGroup(array $parameters = [], string $column = '', bool $stackable = false, string $stackColumn = ''): array
    {
        $result = [];
        $query = $this->executor->query($parameters);
        while ($row = $query->fetch()) {
            $parser = $this->parser;
            if (\is_callable($parser)) {
                $parser($row);
            }

            if (\count($this->collects)) {
                foreach ($this->collects as $name => $isUnique) {
                    if (\array_key_exists($name, $row)) {
                        $this->collections[$name] ??= [];
                        if ($isUnique) {
                            $this->collections[$name][$row[$name]] = true;
                        } else {
                            $this->collections[$name][] = $row[$name];
                        }
                    }
                }
            }

            if (!$column || !\array_key_exists($column, $row)) {
                $result[] = $row;
            } else {
                if ($stackable) {
                    if (!isset($result[$row[$column]])) {
                        $result[$row[$column]] = [];
                    }

                    if (!$stackColumn || !\array_key_exists($stackColumn, $row)) {
                        $result[$row[$column]][] = $row;
                    } else {
                        $result[$row[$column]][$row[$stackColumn]] = $row;
                    }
                } else {
                    $result[$row[$column]] = $row;
                }
            }
        }

        // If the parser set only execute once, reset the parser.
        if ($this->once) {
            $this->once = false;
            $this->parser = null;
        }

        return $result;
    }

    /**
     * Execute the statement and build a key-value pair array from two columns.
     *
     * @param string $keyColumn The column to use as array keys
     * @param string $valueColumn The column to use as array values
     * @param array $parameters Optional parameters to merge
     *
     * @return array Associative array of key => value pairs
     *
     * @throws Error If column names are empty or not found in results
     * @throws Throwable
     */
    public function lazyKeyValuePair(string $keyColumn, string $valueColumn, array $parameters = []): array
    {
        $keyColumn = \trim($keyColumn);
        $valueColumn = \trim($valueColumn);
        if (!$valueColumn || !$keyColumn) {
            throw new QueryException('The key or value column name cannot be empty.');
        }
        $result = [];
        $query = $this->executor->query($parameters);
        while ($row = $query->fetch()) {
            $parser = $this->parser;
            if (\is_callable($parser)) {
                $parser($row);
            }

            if (!\array_key_exists($keyColumn, $row)) {
                throw new QueryException('The key column `' . $keyColumn . '` cannot found in fetched result.');
            }
            if (!\array_key_exists($valueColumn, $row)) {
                throw new QueryException('The value column `' . $valueColumn . '` cannot found in fetched result.');
            }
            $result[$row[$keyColumn]] = $row[$valueColumn];
        }

        return $result;
    }

    /**
     * Register columns to collect values from during query execution.
     * During lazyGroup() execution, values from these columns are accumulated
     * into the collections array, optionally ensuring uniqueness.
     *
     * @param string|array $columns Column name or array of column => isUnique pairs
     * @param bool|null $isUnique When true, duplicate values are eliminated (uses array keys)
     *
     * @return $this
     */
    public function collect(string|array $columns, ?bool $isUnique = false): static
    {
        if (\is_array($columns)) {
            foreach ($columns as $column => $isUnique) {
                if (\is_string($column) && ($column = \trim($column))) {
                    $this->collect($column, $isUnique);
                }
            }
        } else {
            $this->collects[$columns] = $isUnique;
        }

        return $this;
    }

    /**
     * Get the collected values for a specific column after executing a query with collect().
     * Returns the accumulated values gathered during lazyGroup() execution.
     *
     * @param string $name The column name that was collected
     *
     * @return array|null The collected values, or null if the column was not collected
     */
    public function getCollection(string $name): ?array
    {
        return $this->collections[$name] ?? null;
    }

    /**
     * Set a callback to transform each fetched row during lazy() and lazyGroup() execution.
     * When $once is true, the parser is automatically cleared after the first query execution.
     *
     * @param callable $closure The row transformation callback, receives row array by reference
     * @param bool $once When true, the parser is removed after a single use
     *
     * @return $this
     */
    public function setParser(callable $closure, bool $once = false): static
    {
        $this->parser = $closure(...);
        $this->once = $once;

        return $this;
    }

    /**
     * Get the current parser callback.
     *
     * @return Closure|null
     */
    public function getParser(): ?Closure
    {
        return $this->parser;
    }

    /**
     * Check if a parser is currently set.
     *
     * @return bool
     */
    public function hasParser(): bool
    {
        return $this->parser !== null;
    }

    /**
     * Clear all collected values.
     *
     * @return $this
     */
    public function clearCollections(): static
    {
        $this->collections = [];
        return $this;
    }
}
