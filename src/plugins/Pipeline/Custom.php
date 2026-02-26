<?php
/**
 * Pipeline Action Plugin: Custom
 *
 * Allows attaching a user-defined callback closure as an action process step.
 * The callback receives the current value and comparison value, and can
 * transform or validate the value as needed. Only connectable to Validate flows.
 *
 * @package Razy
 * @license MIT
 */

use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Custom action instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous Action class constructor
 *
 * @return Action The Custom action instance with user-defined processing logic
 */
return function (...$arguments) {
    /**
     * Custom action class.
     *
     * Executes a user-provided callback closure as the processing step.
     * The closure is bound to the action instance for access to action context.
     */
    return new class(...$arguments) extends Action {
        /** @var Closure|null The user-defined process closure bound to this action instance */
        protected ?Closure $processClosure;

        /**
         * Constructor
         *
         * @param callable|null $callback The user-defined processing callback
         */
        public function __construct(callable $callback = null)
        {
            // Convert the callback to a closure and bind it to this action instance
            $this->processClosure = ($callback) ? $callback(...)->bindTo($this) : null;
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
         * Execute the user-defined closure to process the value.
         *
         * @param mixed|null $value The current value
         * @param mixed|null $compare The comparison value from database record
         * @return mixed The processed value
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            return ($this->processClosure) ? call_user_func($this->processClosure, $value, $compare) : $value;
        }
    };
};
