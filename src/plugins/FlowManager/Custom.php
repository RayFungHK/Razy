<?php
use Razy\FlowManager\Flow;
return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        protected Closure $processClosure;

        /**
         * Constructor
         *
         * @param callable|null $callback
         */
        public function __construct(callable $callback = null)
        {
            $this->processClosure = ($callback) ? $callback(...)->bindTo($this) : null;
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
         * @param mixed|null $value
         * @param mixed|null $compare
         * @return mixed
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            return ($this->processClosure) ? call_user_func($this->processClosure, $value, $compare) : $value;
        }
    };
};