<?php
/**
 * Pipeline Action Plugin: Validate
 *
 * Provides a field validation action that can be attached to a FormWorker.
 * Each Validate instance holds a chain of validator sub-flows (NoEmpty, Unique, etc.)
 * and processes them sequentially against a field value.
 *
 * ```php
 * $worker->then('Validate', 'email')->then('NoEmpty')->then('Unique');
 * ```
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Razy\Error;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Validate action instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous Action class constructor
 *
 * @return Action The Validate action instance for field validation
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /** @var string Optional display alias for this field */
        private string $alias = '';

        /** @var bool Whether this field is bypassed during resolve (excluded from parameters) */
        private bool $bypass = false;

        /**
         * @param string $name The field name to validate
         */
        public function __construct(private readonly string $name = '')
        {
            $this->setRecursive(true);
        }

        /**
         * Get the display alias (falls back to field name).
         *
         * @return string
         */
        public function getAlias(): string
        {
            return $this->alias ?: $this->name;
        }

        /**
         * Store a value in the parent FormWorker's storage under this field name.
         *
         * @param mixed $value The value to store
         * @param string $identifier Optional scope identifier
         * @return Action
         */
        public function setStorage(mixed $value, string $identifier = ''): Action
        {
            $this->owner->setStorage($this->name, $value, $identifier);
            return $this;
        }

        /**
         * Retrieve a value from the parent FormWorker's storage for this field.
         *
         * @param string $identifier Optional scope identifier
         * @return mixed
         */
        public function getStorage(string $identifier = ''): mixed
        {
            return $this->owner->getStorage($this->name, $identifier);
        }

        /**
         * Set this field's value in the parent FormWorker's data collection.
         *
         * @param mixed $value
         * @return Action
         */
        public function setValue(mixed $value): Action
        {
            $this->owner->setValue($this->name, $value);
            return $this;
        }

        /**
         * Get this field's current value from the parent FormWorker's data.
         *
         * @return mixed
         */
        public function getValue(): mixed
        {
            return $this->owner->getValue($this->name);
        }

        /**
         * Set a display alias for this field (used in parameter keys during resolve).
         *
         * @param string $alias
         * @return Action
         */
        public function setAlias(string $alias): Action
        {
            $this->alias = $alias;
            return $this;
        }

        /**
         * Mark this field as bypassed (excluded from parameter collection on resolve).
         *
         * @param bool $bypass
         * @return Action
         */
        public function setBypass(bool $bypass): Action
        {
            $this->bypass = $bypass;
            return $this;
        }

        /**
         * Check if this field is bypassed.
         *
         * @return bool
         */
        public function isBypass(): bool
        {
            return $this->bypass;
        }

        /**
         * Only accepts connection to FormWorker flows.
         *
         * @param string $flowType
         * @return bool
         */
        public function accept(string $actionType = ''): bool
        {
            return $actionType === 'FormWorker';
        }

        /**
         * Reject this field by delegating to the parent FormWorker.
         *
         * @param mixed $message Error code or message
         * @return Action
         */
        public function reject(mixed $message = ''): Action
        {
            $this->owner->reject($this->name, $message);
            return $this;
        }

        /**
         * Get the field name.
         *
         * @return string
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Process the field value through all child validator flows.
         *
         * Iterates child flows sequentially, stopping if the field has been rejected.
         *
         * @param mixed|null $value The current field value
         * @return mixed The processed value
         */
        public function process(mixed $value = null): mixed
        {
            $record = $this->owner->getRecord();

            foreach ($this->children as $action) {
                if ($this->owner->hasRejected($this->name)) {
                    break;
                }
                $value = $action->process($value, $record[$this->name] ?? null);
            }

            return $value;
        }
    };
};
