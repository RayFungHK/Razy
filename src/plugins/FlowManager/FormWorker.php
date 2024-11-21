<?php
/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Collection;
use Razy\Database;
use Razy\Error;
use Razy\FlowManager;
use Razy\FlowManager\Flow;

return function (...$arguments) {
    return new class(...$arguments) extends Flow {
        protected Collection $data;
        protected Collection $storage;
        protected Collection $identifyStorage;
        protected array $rejected = [];
        protected string $mode = 'create';
        private ?Closure $rejectProcess = null;
        private string $uniqueKey = '';
        private ?Closure $onFetchClosure = null;
        private mixed $record = null;
        private ?Closure $onProcessClosure = null;
        private string $errorCode = '';
        private ?Closure $onResolveClosure = null;
        private array $parameters = [];
        private string $valueColumn = '';
        private ?Closure $onExecuteClosure = null;
        private ?Closure $onDeleteClosure = null;
        private ?array $toggleValue = null;

        /**
         * Constructor
         *
         * @param Database|null $database
         * @param string $tableName
         * @param string $idColumn
         * @param string|array $toggleColumn
         */
        public function __construct(private readonly ?Database    $database = null,
                                    private readonly string       $tableName = '',
                                    private readonly string       $idColumn = '',
                                    private readonly string|array $toggleColumn = '')
        {
            $this->data = new Collection([]);
            $this->storage = new Collection([]);
            $this->identifyStorage = new Collection([]);
        }

        /**
         * Set the toggle column
         *
         * @param string $search
         * @param string|Closure $toggleValue
         * @return $this
         */
        public function setToggle(string $search, string|Closure $toggleValue): Flow
        {
            $this->toggleValue = [$search, $toggleValue];
            return $this;
        }

        /**
         * Set the reject closure
         *
         * @param callable $callback
         * @return $this
         */
        public function setRejectProcess(callable $callback): Flow
        {
            $this->rejectProcess = $callback(...);

            return $this;
        }

        /**
         * Get the stored data from storage by given name
         *
         * @param string $name
         * @param string $identifier
         * @return mixed
         */
        public function getStorage(string $name, string $identifier = ''): mixed
        {
            if ($identifier) {
                return $this->identifyStorage[$identifier][$name] ?? null;
            }
            return $this->storage[$name] ?? null;
        }

        /**
         * Set the storage data with specified name
         *
         * @param string $name
         * @param mixed $value
         * @param string $identifier
         * @return $this
         */
        public function setStorage(string $name, mixed $value, string $identifier = ''): Flow
        {
            if ($value instanceof Closure) {
                $value = call_user_func($value, $this->storage[$name] ?? null);
            }
            if ($identifier) {
                $this->identifyStorage[$identifier] = $this->identifyStorage[$identifier] ?? [];
                $this->identifyStorage[$identifier][$name] = $value;
            } else {
                $this->storage[$name] = $value;
            }
            return $this;
        }

        /**
         * Set the onFetch event
         *
         * @param callable $closure
         * @return $this
         */
        public function onFetch(callable $closure): Flow
        {
            $this->onFetchClosure = $closure(...);
            return $this;
        }

        /**
         * Set the data value by given name
         *
         * @param string $name
         * @param mixed $value
         * @return $this
         */
        public function setValue(string $name, mixed $value): Flow
        {
            $this->data[$name] = $value;
            return $this;
        }

        /**
         * Get the unique key
         *
         * @return string
         */
        public function getUniqueKey(): string
        {
            return $this->uniqueKey;
        }

        /**
         * Set the value column
         *
         * @param string $valueColumn
         * @return $this
         */
        public function setValueColumn(string $valueColumn): Flow
        {
            $valueColumn = trim($valueColumn);
            if (!$valueColumn) {
                throw new Error('Value column name cannot be empty.');
            }
            $this->valueColumn = $valueColumn;

            return $this;
        }

        /**
         * Set the onProcess event
         *
         * @param callable $closure
         * @return $this
         */
        public function onProcess(callable $closure): Flow
        {
            $this->onProcessClosure = $closure(...);
            return $this;
        }

        /**
         * Set the onExecute event
         *
         * @param callable $closure
         * @return $this
         */
        public function onExecute(callable $closure): Flow
        {
            $this->onExecuteClosure = $closure(...);
            return $this;
        }

        /**
         * Start process
         *
         * @param mixed $postData
         * @param string $uniqueKey
         * @param string $error
         * @return bool
         * @throws Throwable
         */
        public function process(mixed $postData, string $uniqueKey = '', string &$error = ''): bool
        {
            if ($uniqueKey) {
                $this->uniqueKey = $uniqueKey;
            }

            if ($this->mode === 'edit' || $this->mode === 'delete') {
                $statement = $this->database->prepare()->from($this->tableName);
                if ($this->onFetchClosure) {
                    $closure = $this->onFetchClosure->bindTo($this);
                    if (!($this->record = call_user_func($closure, $uniqueKey, $statement))) {
                        $error = $this->errorCode = 'not_found';
                        return false;
                    }
                } else {
                    $parameters = [];
                    $parameters[$this->idColumn] = $uniqueKey;
                    if ($this->toggleColumn) {
                        if (is_string($this->toggleColumn)) {
                            $toggleStatement = ($this->toggleValue) ? $this->toggleColumn . '=?' : '!' . $this->toggleColumn;
                            if ($this->toggleValue) {
                                $parameters[$this->toggleColumn] = $this->toggleValue[0];
                            }
                        } else {
                            $toggleStatement = [];
                            foreach ($this->toggleColumn as $column => $value) {
                                $toggleStatement[] = $column . (is_array($value) ? '|' : '') . '=?';
                                $parameters[$column] = $value;
                            }
                            $toggleStatement = implode(',', $toggleStatement);
                        }
                    }
                    if (!($this->record = $statement->where($this->idColumn . '=?' . ($toggleStatement ? ',' . $toggleStatement : ''))->lazy($parameters))) {
                        $error = $this->errorCode = 'not_found';
                        return false;
                    }
                }
            } elseif ($this->mode === 'kvp') {
                $this->record = $this->database->prepare()->from($this->tableName)->lazyGroup([], $this->idColumn);
            }

            // Only do the validation in create, edit or key value update mode
            if ($this->mode !== 'delete') {
                foreach ($postData as $key => $value) {
                    if (!isset($this->data[$key])) {
                        $this->data[$key] = $value;
                    }
                }

                foreach ($this->flows as $flow) {
                    $name = $flow->getName();
                    $value = isset($this->data[$name]) ? $this->data[$name] : null;
                    $compare = $this->record[$name] ?? null;
                    $value = $flow->process($value, $compare);

                    $this->data[$name] = $value;
                }

                if ($this->onProcessClosure) {
                    $closure = $this->onProcessClosure->bindTo($this);
                    call_user_func($closure);
                }
            }

            return !$this->errorCode && !count($this->rejected);
        }

        /**
         * Get the fetched record
         *
         * @return mixed
         */
        public function getRecord(): mixed
        {
            return $this->record;
        }

        /**
         * Get the Database object
         *
         * @return Database|null
         */
        public function getDatabase(): ?Database
        {
            return $this->database;
        }

        /**
         * Get the value column
         *
         * @return string
         */
        public function getValueColumn(): string
        {
            return $this->valueColumn;
        }

        /**
         * Get the dataset
         *
         * @return Collection
         */
        public function getData(): Collection
        {
            return $this->data;
        }

        /**
         * Get the value by given name
         *
         * @param string $name
         * @return mixed
         */
        public function getValue(string $name): mixed
        {
            return $this->data[$name] ?? null;
        }

        /**
         * Get the ID column
         *
         * @return string
         */
        public function getIDColumn(): string
        {
            return $this->idColumn;
        }

        /**
         * Get the toggle column
         *
         * @return string
         */
        public function getToggleColumn(): string
        {
            return $this->toggleColumn;
        }

        /**
         * Get the error code
         *
         * @return string
         */
        public function getErrorCode(): string
        {
            return $this->errorCode;
        }

        /**
         * Get the table name
         *
         * @return string
         */
        public function getTableName(): string
        {
            return $this->tableName;
        }

        /**
         * Get the array of parameters that passed to statement
         *
         * @return array
         */
        public function getParameters(): array
        {
            return $this->parameters;
        }

        /**
         * Set the parameters value by given name
         *
         * @param array|string $parameters
         * @param mixed|null $value
         * @return $this
         */
        public function setParameters(array|string $parameters, mixed $value = null): Flow
        {
            if (is_array($parameters)) {
                foreach ($parameters as $parameter => $value) {
                    $this->setParameters($parameter, $value);
                }
            } else {
                if ($value instanceof Closure) {
                    $value = call_user_func($value, $this->parameters[$parameters] ?? null);
                }

                $this->parameters[$parameters] = $value;
            }

            return $this;
        }

        /**
         * Handling reject from flow
         *
         * @param string|array $name
         * @param string|array $code
         * @param string $alias
         * @return Flow|null
         */
        public function reject(mixed $name, string|array $code = '', string $alias = ''): ?Flow
        {
            if (is_array($name)) {
                foreach ($name as $_name) {
                    $this->reject($_name, $code, $alias);
                }
            } else {
                if (is_string($code)) {
                    // Beginning with @ means absolute path, no prefix will be added.
                    $errorCode = (strlen($code) > 0 && $code[0] === '@') ? substr($code, 1) : $name . '_' . $code;
                } else {
                    $errorCode = $code;
                }
                $this->rejected[($alias) ?: $name] = ($this->rejectProcess) ? call_user_func($this->rejectProcess, $errorCode) : $errorCode;
            }

            return $this;
        }

        /**
         * Set the unique key
         *
         * @param string $uniqueKey
         * @return $this
         */
        public function setUniqueKey(string $uniqueKey): Flow
        {
            $this->uniqueKey = $uniqueKey;
            return $this;
        }

        /**
         * Return true is any child Flow is rejected
         *
         * @param string $name
         * @return bool
         */
        public function hasRejected(string $name): bool
        {
            return isset($this->rejected[$name]);
        }

        /**
         * Get the list of rejected message
         *
         * @return array
         */
        public function getRejected(): array
        {
            return $this->rejected;
        }

        /**
         * Set the processing mode
         *
         * @param string $mode
         * @return Flow
         */
        public function setMode(string $mode): Flow
        {
            if (in_array($mode, ['create', 'edit', 'delete', 'kvp'])) {
                $this->mode = $mode;
            }
            return $this;
        }

        /**
         * Get the processing mode
         *
         * @return string
         */
        public function getMode(): string
        {
            return $this->mode;
        }

        /**
         * Set the onResolve event
         *
         * @param callable $callback
         * @return $this
         */
        public function onResolve(callable $callback): Flow
        {
            $this->onResolveClosure = $callback(...);
            return $this;
        }

        /**
         * Set the onDelete event
         *
         * @param callable $callback
         * @return $this
         */
        public function onDelete(callable $callback): Flow
        {
            $this->onDeleteClosure = $callback;
            return $this;
        }

        /**
         * Start resolve
         *
         * @param ...$args
         * @return bool
         * @throws Throwable
         */
        public function resolve(...$args): bool
        {
            if ($this->errorCode || count($this->rejected)) {
                return false;
            }

            if ($this->mode !== 'delete') {
                // Return false if no validation has registered
                if (!count($this->flows)) {
                    $this->errorCode = 'no_validation';
                    return false;
                }
            }

            foreach ($this->flows as $flow) {
                if (!$flow->isBypass()) {
                    $this->parameters[$flow->getAlias()] = $this->data[$flow->getName()] ?? null;
                }
            }

            if ($this->mode === 'delete' && $this->onResolveClosure) {
                $closure = $this->onResolveClosure->bindTo($this);
                $this->parameters = call_user_func($closure, $this->parameters);
            }

            if ($this->mode === 'kvp') {
                if (!$this->valueColumn) {
                    $this->errorCode = 'key_value_not_configure';
                    return false;
                }

                foreach ($this->parameters as $key => $value) {
                    $parameters = [];
                    $parameters[$this->idColumn] = $key;
                    $parameters[$this->valueColumn] = $value;
                    $statement = $this->database->insert($this->tableName, array_keys($parameters), [$this->valueColumn]);
                    if ($this->onExecuteClosure) {
                        $queueClosure = $this->onExecuteClosure->bindTo($this);
                        call_user_func($queueClosure, $statement);

                        // Double-check the rejected list after onExecute is triggered
                        if (count($this->rejected)) {
                            return false;
                        }
                    }

                    $statement->query($parameters);
                }

                return true;
            }

            if ($this->mode === 'edit' || $this->mode === 'delete') {
                if (!$this->uniqueKey) {
                    $this->errorCode = 'missing_unique_key';
                    return false;
                }

                if ($this->mode === 'edit') {
                    $this->parameters[$this->idColumn] = $this->uniqueKey;
                }
            }

            $statement = null;
            if ($this->mode === 'delete') {
                $this->parameters[$this->idColumn] = $this->uniqueKey;
                if ($this->toggleColumn) {
                    if ($this->toggleValue) {
                        $toggleValue = ($this->toggleValue[1] instanceof Closure) ? $this->toggleValue[1]() : $this->toggleValue[1];
                    } else {
                        $toggleValue = 1;
                    }
                    $this->parameters[$this->toggleColumn] = $toggleValue;
                }

                if ($this->onDeleteClosure) {
                    $closure = $this->onDeleteClosure->bindTo($this);
                    $this->parameters = call_user_func($closure, $this->parameters);
                }

                // If delete column is provided, mark the delete column as true
                if ($this->toggleColumn) {
                    $statement = $this->database->update($this->tableName, array_keys($this->parameters))->where($this->idColumn . '=?');
                } else {
                    $this->database->delete($this->tableName, $this->parameters)->query();
                    return true;
                }
            }

            if ($this->onExecuteClosure) {
                $queueClosure = $this->onExecuteClosure->bindTo($this);
                call_user_func($queueClosure, $statement);

                // Double-check the rejected list after onExecute is triggered
                if (count($this->rejected)) {
                    return false;
                }
            }

            if ($this->mode === 'create') {
                $statement = $this->database->insert($this->tableName, array_keys($this->parameters));
            } elseif ($this->mode === 'edit') {
                $this->parameters[$this->idColumn] = $this->uniqueKey;
                $statement = $this->database->update($this->tableName, array_keys($this->parameters))->where($this->idColumn . '=?');
            }

            $statement->query($this->parameters);
            if ($this->mode === 'create') {
                $this->uniqueKey = $this->database->lastID();
            }

            return true;
        }
    };
};