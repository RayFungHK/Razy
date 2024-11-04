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
         * @param mixed|null $value
         * @param mixed|null $compare
         * @return mixed
         */
        public function process(mixed $value = null, mixed $compare = null): string
        {
            $value = trim($value);
            if (!$value) {
                $this->parent->reject('not_empty');
            }

            return $value;
        }
    };
};