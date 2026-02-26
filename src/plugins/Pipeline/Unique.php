<?php
/**
 * Pipeline Action Plugin: Unique
 *
 * Validates that a field value is unique within a database table.
 * Queries the database to check for duplicate entries, excluding the current record
 * by its ID. Supports toggle columns for soft-delete or status filtering.
 * Rejects with 'duplicated' if a matching record is found.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Unique validator action.
 *
 * @return Action
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
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
         * Check uniqueness in the database. Rejects with 'duplicated' if a match is found.
         *
         * @param mixed|null $value The field value to check
         * @param mixed|null $compare Unused
         * @return mixed The original value
         */
        public function process(mixed &$value = null, mixed $compare = null): mixed
        {
            if ($value) {
                $worker = $this->findOwner('FormWorker');

                $parameters = [];
                $parameters[$this->owner->getName()] = $value;
                $parameters[$worker->getIDColumn()] = $worker->getUniqueKey() ?? 0;

                $toggleColumns = $worker->getToggleColumn();
                $filter = [];
                if (is_array($toggleColumns)) {
                    foreach ($toggleColumns as $column => $toggleValue) {
                        $filter[] = $column . (is_array($toggleValue) ? '|' : '') . '=?';
                        $parameters[$column] = $toggleValue;
                    }
                } else {
                    $filter[] = '!' . $toggleColumns;
                }
                $filter = (count($filter)) ? ',' . implode(',', $filter) : '';

                $result = $worker->getDatabase()
                    ->prepare()
                    ->from($worker->getTableName())
                    ->where($this->owner->getName() . '=?,' . $worker->getIDColumn() . '!=?' . $filter)
                    ->lazy($parameters);

                if ($result) {
                    $this->owner->reject('duplicated');
                }
            }
            return $value;
        }
    };
};
