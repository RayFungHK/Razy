<?php
/**
 * Pipeline Action Plugin: Password
 *
 * Validates and processes password fields during form submission.
 * Handles password length validation, verify-field matching, previous-password
 * comparison, and automatic MD5 hashing. Supports both 'create' and 'edit' modes:
 * - In 'create' mode: password is required and validated.
 * - In 'edit' mode: empty passwords bypass validation; non-empty passwords are
 *   validated against length, verification, and optionally against the previous hash.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline\Action;

use Razy\Pipeline;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the Password validator action.
 *
 * @return Action
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /**
         * @param int $length Minimum password length (default: 6)
         */
        public function __construct(protected int $length = 6)
        {
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
         * Validate the password and return its MD5 hash.
         *
         * @param mixed|null $value The raw password input
         * @param mixed|null $compare The existing hashed password (for edit mode)
         * @return mixed The MD5-hashed password
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            $value = trim($value);

            $worker = $this->findOwner('FormWorker');
            $passwordVerify = $worker->getData()[$this->owner->getName() . '_verify'] ?? '';

            if ($worker->getMode() === 'edit' && !$value) {
                $this->owner->setBypass(true);
            }

            if ($worker->getMode() === 'create' || ($worker->getMode() === 'edit' && $value)) {
                if (strlen($value) < $this->length) {
                    $this->owner->reject('length_too_short');
                } elseif ($value !== $passwordVerify) {
                    $this->owner->reject('verify_not_match');
                } elseif ($worker->getMode() === 'edit' && md5($value) !== $compare) {
                    $this->owner->reject('previous_not_match');
                }
            }

            return md5($value);
        }
    };
};
