<?php
/**
 * Pipeline Action Plugin: Regroup
 *
 * Fetches and regroups data from a related database table based on an index column.
 * Used to retrieve associated records (e.g., tags, categories) linked to a field value,
 * and returns them as a grouped list or key-value pair. Supports toggle column filtering.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Razy\Pipeline;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Regroup action instance.
 *
 * @return Action
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /**
         * @param string $tableName The related database table
         * @param string $indexColumn The column to filter by
         * @param string $toggleColumn Optional soft-delete/status column to exclude
         * @param string $kvpValue Optional column name for key-value pair mode
         */
        public function __construct(
            protected string $tableName = '',
            protected string $indexColumn = '',
            protected string $toggleColumn = '',
            protected string $kvpValue = ''
        ) {
        }

        /**
         * Only accepts connection to Validate flows.
         *
         * @param string $flowType
         * @return bool
         */
        public function accept(string $actionType = ''): bool
        {
            return $actionType === 'Validate';
        }

        /**
         * Fetch and regroup related records from the database.
         *
         * @param mixed|null $value The field value (used as filter)
         * @param mixed|null $compare Unused
         * @return mixed The regrouped data (array of keys or key-value pairs)
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            $worker = $this->findOwner('FormWorker');

            if ($this->tableName && $this->indexColumn) {
                $parameters = [];
                $parameters[$this->indexColumn] = $value;
                $statement = $worker->getDatabase()
                    ->prepare()
                    ->from($this->tableName)
                    ->where($this->indexColumn . '|=?' . ($this->toggleColumn ? ',!' . $this->toggleColumn : ''));

                $list = ($this->kvpValue)
                    ? $statement->lazyKeyValuePair($this->indexColumn, $this->kvpValue, $parameters)
                    : $statement->lazyGroup($parameters, $this->indexColumn);

                $value = ($this->kvpValue) ? $list : array_keys($list);
            }

            return $value;
        }
    };
};
