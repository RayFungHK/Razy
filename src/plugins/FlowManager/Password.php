<?php

namespace Razy\FlowManager\Flow;

use Razy\FlowManager;
use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        /**
         * Constructor
         *
         * @param int $length
         */
        public function __construct(protected int $length = 6)
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
         * @param mixed|null $value
         * @param mixed|null $compare
         * @return mixed
         */
        public function process(mixed $value = null, mixed $compare = null): mixed
        {
            $value = trim($value);
            $worker = $this->getParent('FormWorker');
            $passwordVerify = $worker->getData()[$this->parent->getName() . '_verify'] ?? '';

            if ($worker->getMode() === 'create' && !$value) {
                $this->parent->setBypass(true);
            }

            if ($worker->getMode() === 'create' || ($worker->getMode() === 'edit' && $value)) {
                if (strlen($value) < $this->length) {
                    $this->parent->reject('length_too_short');
                } elseif ($value !== $passwordVerify) {
                    $this->parent->reject('verify_not_match');
                } elseif ($worker->getMode() === 'edit' && $value !== $compare) {
                    $this->parent->reject('previous_not_match');
                }
            }

            return md5($value);
        }
    };
};