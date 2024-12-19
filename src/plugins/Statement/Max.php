<?php

use Razy\Database\Statement;
use Razy\Database\Statement\Builder;

return function (...$args) {
    return new class(...$args) extends Builder {
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
            if (is_string($indexColumns)) {
                $this->indexColumns = [$indexColumns];
            } else {
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
            $tableNameA = 'a.' . $tableName;
            $tableNameB = 'b.' . $tableName;
            $indexMatch = [];
            foreach ($this->indexColumns as $indexColumn) {
                $indexMatch[] = 'a.' . $indexColumn . '=b.' . $indexColumn;
            }

            $clips = [];
            $parameters = [];
            foreach ($this->toggleColumn as $column => $value) {
                $isExpr = false;
                if ($column[0] === '@') {
                    $column = substr($column, 1);
                    $isExpr = true;
                }

                if (preg_match(Statement::REGEX_COLUMN, $column = trim($column))) {
                    if (is_bool($value)) {
                        $value = (int)$value;
                    }
                    if ($isExpr) {
                        $clips[$column] = $value;
                    } elseif (is_scalar($value) || is_array($value)) {
                        $clips[$column] = $column . (is_array($value) ? '=|?' : '=' . '"' . preg_quote($value) . '"');
                        if (is_array($value)) {
                            $parameters[$column] = $value;
                        }
                    }
                }
            }

            $statement = $this->statement
                ->select('a.*')->from($tableNameA . '<' . $tableNameB . '[?' . implode(',', $indexMatch) . ',a.' . $this->compareColumn . '<b.' . $this->compareColumn . ']')
                ->where('b.' . $this->compareColumn . '=NULL')->assign($parameters);

            $comparisonA = $statement->alias('a');
            $comparisonA->from($tableName)->where(implode(',', $clips));

            $comparisonB = $statement->alias('b');
            $comparisonB->from($tableName)->where(implode(',', $clips));
        }
    };
};