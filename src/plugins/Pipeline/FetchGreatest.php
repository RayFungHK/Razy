<?php
/**
 * Pipeline Action Plugin: FetchGreatest
 *
 * Fetches the record with the greatest (highest) value in a comparison column
 * from a database table, filtered by an index column match.
 * Supports an optional callback closure to customize the query statement,
 * and an optional error code to reject validation if no record is found.
 * The fetched result is stored in the parent Validate action's storage.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Closure;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the FetchGreatest action instance.
 *
 * @return Action
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /** @var Closure|null Optional callback to customize the query statement */
        protected ?Closure $closure = null;

        /**
         * @param string $tableName The database table to query
         * @param string $indexColumn The column to filter by
         * @param string $compareColumn The column to order by descending (greatest first)
         * @param callable|null $closure Optional query customization callback
         * @param string $errorCode Error code to reject with if no record found
         */
        public function __construct(
            protected string $tableName,
            protected string $indexColumn,
            protected string $compareColumn,
            callable         $closure = null,
            protected string $errorCode = ''
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
         * Fetch the record with the greatest value and store the result.
         *
         * @param mixed|null $value The current field value
         * @param mixed|null $compare Unused
         * @return mixed The field value (cast to int)
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            if ($this->tableName && $this->indexColumn) {
                $worker = $this->findOwner('FormWorker');

                $value = (int) $value;

                $filter = $this->indexColumn . '=?';
                $statement = $worker->getDatabase()->prepare()->from($this->tableName)->where($filter);

                if ($this->closure instanceof Closure) {
                    call_user_func($this->closure, $statement);
                }

                $parameters[$this->indexColumn] = $value;
                if (!$result = $statement->order('>' . $this->compareColumn)->lazy($parameters)) {
                    if ($this->errorCode) {
                        $this->reject($this->errorCode);
                    }
                }

                $this->findOwner()->setStorage($result, $this->getIdentifier());
            }

            return $value;
        }
    };
};
