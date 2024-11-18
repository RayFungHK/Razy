<?php
/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\FlowManager\Flow;

use Razy\Error;
use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        private string $alias = '';
        private bool $bypass = false;

        /**
         * Constructor
         *
         * @param string $name
         */
        public function __construct(private readonly string $name = '')
        {
            $this->recursive(true);
        }

        /**
         * Get the alias, if not set return the name
         *
         * @return string
         */
        public function getAlias(): string
        {
            return $this->alias ?: $this->name;
        }

        /**
         * Pass value to FormWorker to set storage
         *
         * @param mixed $value
         * @param string $identifier
         * @return Flow
         */
        public function setStorage(mixed $value, string $identifier = ''): Flow
        {
            $this->parent->setStorage($this->name, $value, $identifier);
            return $this;
        }

        /**
         * Get the stored data from FormWorker
         *
         * @param string $identifier
         * @return mixed
         */
        public function getStorage(string $identifier = ''): mixed
        {
            return $this->parent->getStorage($this->name, $identifier);
        }

        /**
         * Set the Validation value
         *
         * @param mixed $value
         * @return Flow
         */
        public function setValue(mixed $value): Flow
        {
            Error::DebugConsoleWrite($value);
            $this->parent->setValue($this->name, $value);
            return $this;
        }

        /**
         * Get the Validation value
         *
         * @return mixed
         */
        public function getValue(): mixed
        {
            return $this->parent->getValue($this->name);
        }

        /**
         * Set the alias
         *
         * @param string $alias
         * @return Flow
         */
        public function setAlias(string $alias): Flow
        {
            $this->alias = $alias;
            return $this;
        }

        /**
         * Set the validate is bypass to submit in query
         *
         * @param bool $bypass
         * @return Flow
         */
        public function setBypass(bool $bypass): Flow
        {
            $this->bypass = $bypass;
            return $this;
        }

        /**
         * Check if the validate is bypass
         *
         * @return bool
         */
        public function isBypass(): bool
        {
            return $this->bypass;
        }

        /**
         * Flow request checking
         *
         * @param string $typeOfFlow
         * @return bool
         */
        public function request(string $typeOfFlow = ''): bool
        {
            if ($typeOfFlow === 'FormWorker') {
                return true;
            }
            return false;
        }

        /**
         * Reject the validation
         *
         * @param string $message
         * @return Flow
         */
        public function reject(mixed $message = ''): Flow
        {
            $this->parent->reject($this->name, $message);
            return $this;
        }

        /**
         * Get the validate name.
         *
         * @return string
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Pass the value to validate flows
         *
         * @param mixed $value
         * @return bool
         */
        public function process(mixed $value = null): mixed
        {
            $record = $this->parent->getRecord();
            foreach ($this->flows as $flow) {
                if ($this->parent->hasRejected($this->name)) {
                    break;
                }
                $value = $flow->process($value, $record[$this->name] ?? null);
            }

            return $value;
        }
    };
};