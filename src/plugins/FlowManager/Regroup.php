<?php
namespace Razy\FlowManager\Flow;

use Razy\FlowManager;
use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        /**
         * Constructor
         *
         * @param string $tableName
         * @param string $indexColumn
         * @param string $toggleColumn
         * @param string $kvpValue
         */
        public function __construct(protected string                $tableName = '',
                                    protected string                $indexColumn = '',
                                    protected string                $toggleColumn = '',
                                    protected string                $kvpValue = '')
        {
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
            $worker = $this->getParent('FormWorker');
            if ($this->tableName && $this->indexColumn) {
                $parameters = [];
                $parameters[$this->indexColumn] = $value;
                $statement = $worker->getDatabase()
                    ->prepare()
                    ->from($this->tableName)
                    ->where($this->indexColumn . '|=?' . ($this->toggleColumn ? ',!' . $this->toggleColumn : ''));
                $list = ($this->kvpValue) ? $statement->lazyKeyValuePair($this->indexColumn, $this->kvpValue, $parameters) : $statement->lazyGroup($parameters, $this->indexColumn);
                $value = ($this->kvpValue) ? $list : array_keys($list);
            }

            return $value;
        }
    };
};