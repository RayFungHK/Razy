<?php

namespace Razy\FlowManager\Flow;

use Closure;
use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        protected ?Closure $closure = null;

        /**
         * Constructor
         *
         * @param string $tableName
         * @param string $indexColumn
         * @param callable|null $closure
         * @param string $errorCode
         */
        public function __construct(protected string $tableName,
                                    protected string $indexColumn,
                                    callable         $closure = null,
                                    protected string $errorCode = '')
        {
            if (is_callable($closure)) {
                $this->closure = $closure(...);
            }
        }

        /**
         * Validate that the parent Flow is allowed to connect
         *
         * @param string $typeOfFlow
         * @return bool
         */
        public function request(string $typeOfFlow = ''): bool
        {
            // Only allow to create from Flow validate from
            if ($typeOfFlow === 'Validate') {
                return true;
            }
            return false;
        }

        /**
         * Start process
         *
         * @param mixed $value
         * @param mixed|null $compare
         * @return bool
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            if ($this->tableName && $this->indexColumn) {
                $worker = $this->getParent('FormWorker');
                $filter = $this->indexColumn . '=?';
                $statement = $worker->getDatabase()->prepare()->from($this->tableName)->where($filter);

                if ($this->closure instanceof Closure) {
                    call_user_func($this->closure, $statement);
                }

                $parameters[$this->indexColumn] = $value;
                if (!$result = $statement->lazy($parameters)) {
                    if ($this->errorCode) {
                        $this->reject($this->errorCode);
                    }
                }
                $this->getParent()->setStorage($result, $this->getIdentifier());
            }

            return $value;
        }
    };
};