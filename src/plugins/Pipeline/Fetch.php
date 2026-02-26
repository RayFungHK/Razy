<?php
/**
 * Pipeline Action Plugin: Fetch
 *
 * Fetches a single record from a database table by matching an index column.
 * Supports an optional callback closure to customize the query statement,
 * and an optional error code to reject validation if no record is found.
 * The fetched result is stored in the parent Validate action's storage for later access.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Closure;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Fetch action instance.
 *
 * @return Action
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /** @var Closure|null Optional callback to customize the query statement */
        protected ?Closure $closure = null;

        /**
         * @param string $tableName The database table to fetch from
         * @param string $indexColumn The column to match against the field value
         * @param callable|null $closure Optional query customization callback
         * @param string $errorCode Error code to reject with if no record found
         */
        public function __construct(
            protected string $tableName,
            protected string $indexColumn,
            callable         $closure = null,
            protected string $errorCode = ''
        ) {
            if (is_callable($closure)) {
                $this->closure = $closure(...);
            }
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
         * Execute the database fetch and store the result.
         *
         * @param mixed|null $value The current field value (used as lookup key)
         * @param mixed|null $compare Unused
         * @return mixed The field value (unchanged)
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            if ($this->tableName && $this->indexColumn) {
                $worker = $this->findOwner('FormWorker');

                $filter = $this->indexColumn . '=?';
                $statement = $worker->getDatabase()->prepare()->from($this->tableName)->where($filter);

                if ($this->closure instanceof Closure) {
                    call_user_func($this->closure, $statement);
                }

                $parameters[$this->indexColumn] = $value;
                if (!$result = $statement->lazy($parameters)) {
                    $value = null;
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
