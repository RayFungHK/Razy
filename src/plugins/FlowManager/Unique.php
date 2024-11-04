<?php
namespace Razy\FlowManager\Flow;

use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
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
        public function process(mixed &$value = null, mixed $compare = null): mixed
        {
            if ($value) {
                $worker = $this->getParent('FormWorker');
                $parameters = [];
                $parameters[$this->parent->getName()] = $value;
                $parameters[$worker->getIDColumn()] = $worker->getUniqueKey() ?? 0;

                $result = $worker->getDatabase()
                    ->prepare()
                    ->from($worker->getTableName())
                    ->where($this->parent->getName() . '=?,' . $worker->getIDColumn() . '!=?' . ($worker->getToggleColumn() ? ',!' . $worker->getToggleColumn() : ''))
                    ->lazy($parameters);

                if ($result) {
                    $this->parent->reject('duplicated');
                }
            }
            return $value;
        }
    };
};