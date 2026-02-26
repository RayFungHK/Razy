<?php

/**
 * Statement Builder Plugin: Max
 *
 * Builds a SQL query that finds the records with the maximum (greatest) value
 * in a comparison column for each unique combination of index columns.
 * Uses a LEFT JOIN self-join technique to identify the "greatest-n-per-group" records.
 * Supports toggle column filtering (e.g., soft-delete or status columns) to exclude
 * deactivated records from the result set.
 *
 * @package Razy
 * @license MIT
 */

use Razy\Database\Statement;
use Razy\Database\Statement\Builder;

/**
 * Factory closure that creates and returns the Max statement builder instance.
 *
 * @param mixed ...$args Arguments forwarded to the anonymous Builder class constructor
 *
 * @return Builder The Max builder instance for greatest-n-per-group queries
 */
return function (...$args) {
    /**
     * Max statement builder class.
     *
     * Constructs a self-join query to find the record with the maximum value
     * in a comparison column for each group defined by one or more index columns.
     */
    return new class(...$args) extends Builder {
        /** @var array List of index columns that define grouping */
        protected array $indexColumns = [];

        /**
         * Constructor
         *
         * @param string|array $indexColumns
         * @param string $compareColumn
         * @param array $toggleColumn
         */
        public function __construct(string|array            $indexColumns = '',
                                    private readonly string $compareColumn = '',
                                    private readonly array  $toggleColumn = [])
        {
            // Normalize index columns to an array
            if (is_string($indexColumns)) {
                $this->indexColumns = [$indexColumns];
            } else {
                // Filter to only include string column names
                foreach ($indexColumns as $indexColumn) {
                    if (is_string($indexColumn)) {
                        $this->indexColumns[] = $indexColumn;
                    }
                }
            }
        }

        /**
         * Start build
         *
         * @param string $tableName
         * @return void
         */
        public function build(string $tableName): void
        {
            // Create aliased table references for the self-join
            $tableNameA = 'a.' . $tableName;
            $tableNameB = 'b.' . $tableName;

            // Build the index column matching conditions for the self-join
            $indexMatch = [];
            foreach ($this->indexColumns as $indexColumn) {
                $indexMatch[] = 'a.' . $indexColumn . '=b.' . $indexColumn;
            }

            // Build toggle column filter conditions
            $clips = [];
            $parameters = [];
            foreach ($this->toggleColumn as $column => $value) {
                // Check if the column uses expression syntax (prefixed with @)
                $isExpr = false;
                if ($column[0] === '@') {
                    $column = substr($column, 1);
                    $isExpr = true;
                }

                if (preg_match(Statement::REGEX_COLUMN, $column = trim($column))) {
                    // Convert boolean values to integer for SQL compatibility
                    if (is_bool($value)) {
                        $value = (int)$value;
                    }
                    if ($isExpr) {
                        // Use raw expression for the filter
                        $clips[$column] = $value;
                    } elseif (is_scalar($value) || is_array($value)) {
                        // Build parameterized filter clause (supports IN for arrays)
                        $clips[$column] = $column . (is_array($value) ? '=|?' : '=' . '"' . preg_quote($value) . '"');
                        if (is_array($value)) {
                            $parameters[$column] = $value;
                        }
                    }
                }
            }

            // Build the main self-join query: LEFT JOIN where b has a greater compare column value
            $statement = $this->statement
                ->select('a.*')->from($tableNameA . '<' . $tableNameB . '[?' . implode(',', $indexMatch) . ',a.' . $this->compareColumn . '<b.' . $this->compareColumn . ']')
                ->where('b.' . $this->compareColumn . '=NULL')->assign($parameters);

            // Apply toggle column filters to both aliases
            $comparisonA = $statement->alias('a');
            $comparisonA->from($tableName)->where(implode(',', $clips));

            $comparisonB = $statement->alias('b');
            $comparisonB->from($tableName)->where(implode(',', $clips));
        }
    };
};