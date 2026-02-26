<?php
/**
 * Pipeline Action Plugin: NoEmpty
 *
 * Validates that a field value is not empty after trimming whitespace.
 * If the trimmed value is falsy, the validation is rejected with a
 * 'not_empty' error code. Only connectable to Validate flows.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the NoEmpty validator action.
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
         * Validate the value is not empty. Rejects with 'not_empty' if falsy.
         *
         * @param mixed|null $value
         * @param mixed|null $compare
         * @return string The trimmed value
         */
        public function process(mixed $value = null, mixed $compare = null): string
        {
            $value = trim($value);

            if (!$value) {
                $this->owner->reject('not_empty');
            }

            return $value;
        }
    };
};
