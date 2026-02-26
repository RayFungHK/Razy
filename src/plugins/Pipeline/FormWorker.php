<?php
/**
 * Pipeline Action Plugin: FormWorker
 *
 * A comprehensive form processing action that manages CRUD operations (create, edit,
 * delete, kvp) with database integration. FormWorker serves as the parent action for
 * Validate child actions, coordinating field validation, data collection, rejection
 * handling, and database persistence.
 *
 * Features:
 * - Supports create, edit, delete, and key-value pair (kvp) modes
 * - Manages toggle columns for soft-delete functionality
 * - Provides lifecycle hooks: onFetch, onBeforeProcess, onProcess, onExecute, onDelete
 * - Handles rejection aggregation from child Validate actions
 * - Supports custom reject processing callbacks
 * - Manages field storage and identified storage for child actions
 *
 * Usage:
 * ```php
 * $pipeline = new Pipeline();
 * $worker = $manager->pipe('FormWorker', $db, 'users', 'user_id', 'disabled');
 * $worker->setMode('edit');
 * $worker->setRejectProcess([$api, 'getText']);
 *
 * $worker->then('Validate', 'username')->then('NoEmpty')->then('Unique');
 * $worker->then('Validate', 'email')->then('NoEmpty');
 * $worker->then('Validate', 'password')->then('Password', 6);
 *
 * if ($worker->process($_POST, $userId) && $worker->execute()) {
 *     $xhr->data(['id' => $worker->getUniqueKey()])->send(true);
 * }
 * ```
 *
 * @package Razy
 * @license MIT
 */

use Razy\Collection;
use Razy\Database;
use Razy\Error;
use Razy\Pipeline;
use Razy\Pipeline\Action;

/**
 * Factory closure that creates and returns the FormWorker action instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous Action class constructor
 *
 * @return Action The FormWorker action instance
 */
return function (...$arguments) {
    return new class(...$arguments) extends Action {
        /** @var Collection Collected form data (field name → value) */
        protected Collection $data;

        /** @var Collection Shared storage for child flows (name → value) */
        protected Collection $storage;

        /** @var Collection Identifier-scoped storage (identifier → [name → value]) */
        protected Collection $identifyStorage;

        /** @var array<string, mixed> Map of rejected field names to error codes */
        protected array $rejected = [];

        /** @var string Current operation mode: create, edit, delete, or kvp */
        protected string $mode = 'create';

        /** @var Closure|null Optional callback to transform error codes during rejection */
        private ?Closure $rejectProcess = null;

        /** @var string The primary key value for edit/delete operations */
        private string $uniqueKey = '';

        /** @var Closure|null Callback for custom record fetching (edit/delete) */
        private ?Closure $onFetchClosure = null;

        /** @var mixed The fetched database record (for edit/delete modes) */
        private mixed $record = null;

        /** @var Closure|null Callback that runs after validation completes */
        private ?Closure $onProcessClosure = null;

        /** @var Closure|null Callback that runs before validation starts */
        private ?Closure $onBeforeProcessClosure = null;

        /** @var string Internal error code set on critical failures */
        private string $errorCode = '';

        /** @var Closure|null Callback for parameter transformation before resolve (delete mode) */
        private ?Closure $onResolveClosure = null;

        /** @var array<string, mixed> Parameters collected for database operations */
        private array $parameters = [];

        /** @var string The value column name for key-value pair (kvp) mode */
        private string $valueColumn = '';

        /** @var Closure|null Callback that runs during database execution */
        private ?Closure $onExecuteClosure = null;

        /** @var Closure|null Callback that runs during delete operation */
        private ?Closure $onDeleteClosure = null;

        /** @var array|null Toggle column value pair [search, toggleValue] for soft-delete */
        private ?array $toggleValue = null;

        /**
         * FormWorker constructor.
         *
         * @param Database|null $database The database connection instance
         * @param string $tableName The target database table name
         * @param string $idColumn The primary key column name
         * @param string|array $toggleColumn Soft-delete column name(s) for status filtering
         */
        public function __construct(
            private readonly ?Database    $database = null,
            private readonly string       $tableName = '',
            private readonly string       $idColumn = '',
            private readonly string|array $toggleColumn = ''
        ) {
            $this->data = new Collection([]);
            $this->storage = new Collection([]);
            $this->identifyStorage = new Collection([]);
        }

        /**
         * Set the toggle column's search value and replacement value for soft-delete.
         *
         * @param string $search The value to match in the toggle column
         * @param string|Closure $toggleValue The new value (or closure returning one)
         * @return Action
         */
        public function setToggle(string $search, string|Closure $toggleValue): Action
        {
            $this->toggleValue = [$search, $toggleValue];
            return $this;
        }

        /**
         * Set a callback that transforms error codes during rejection.
         *
         * @param callable $callback Receives the error code, returns the transformed code
         * @return Action
         */
        public function setRejectProcess(callable $callback): Action
        {
            $this->rejectProcess = $callback(...);
            return $this;
        }

        /**
         * Get a stored value from the child action storage, optionally scoped by identifier.
         *
         * @param string $name Storage key
         * @param string $identifier Optional scope identifier
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
         * Store a value in the child action storage, optionally scoped by identifier.
         *
         * @param string $name Storage key
         * @param mixed $value Value to store (can be a Closure for computed values)
         * @param string $identifier Optional scope identifier
         * @return Action
         */
        public function setStorage(string $name, mixed $value, string $identifier = ''): Action
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
         * Set a callback for custom record fetching in edit/delete modes.
         *
         * The callback receives ($uniqueKey, $statement) and should return the record
         * or false/null if not found.
         *
         * @param callable $closure
         * @return Action
         */
        public function onFetch(callable $closure): Action
        {
            $this->onFetchClosure = $closure(...);
            return $this;
        }

        /**
         * Set one or more field values in the form data collection.
         *
         * @param array|string $name Field name or associative array of field → value
         * @param mixed $value The value when $name is a string
         * @return Action
         */
        public function setValue(array|string $name, mixed $value = null): Action
        {
            if (is_array($name)) {
                foreach ($name as $_name => $_value) {
                    $this->setValue($_name, $_value);
                }
            } else {
                $this->data[$name] = $value;
            }
            return $this;
        }

        /**
         * Get the current primary key value.
         *
         * @return string
         */
        public function getUniqueKey(): string
        {
            return $this->uniqueKey;
        }

        /**
         * Set the value column name for key-value pair (kvp) mode.
         *
         * @param string $valueColumn The column to use as the value store
         * @return Action
         * @throws Error If the column name is empty
         */
        public function setValueColumn(string $valueColumn): Action
        {
            $valueColumn = trim($valueColumn);
            if (!$valueColumn) {
                throw new Error('Value column name cannot be empty.');
            }
            $this->valueColumn = $valueColumn;
            return $this;
        }

        /**
         * Set a callback that runs after validation completes (before DB write).
         *
         * @param callable $closure
         * @return Action
         */
        public function onProcess(callable $closure): Action
        {
            $this->onProcessClosure = $closure(...);
            return $this;
        }

        /**
         * Set a callback that runs before validation starts.
         *
         * @param callable $closure
         * @return Action
         */
        public function onBeforeProcess(callable $closure): Action
        {
            $this->onBeforeProcessClosure = $closure(...);
            return $this;
        }

        /**
         * Set a callback that runs during database execution (receives the statement).
         *
         * @param callable $closure
         * @return Action
         */
        public function onExecute(callable $closure): Action
        {
            $this->onExecuteClosure = $closure(...);
            return $this;
        }

        /**
         * Process (validate) form data.
         *
         * Fetches existing records for edit/delete modes, runs all Validate child actions
         * sequentially, and collects validation results.
         *
         * @param mixed $postData The form submission data (typically $_POST)
         * @param string $uniqueKey The primary key for edit/delete modes
         * @param string &$error Reference to receive the error code on failure
         * @return bool True if validation passed (no rejections or errors)
         */
        public function process(mixed $postData, string $uniqueKey = '', string &$error = ''): bool
        {
            if ($uniqueKey) {
                $this->uniqueKey = $uniqueKey;
            }

            // Fetch existing record for edit/delete modes
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

            // Run validation (skip for delete mode)
            if ($this->mode !== 'delete') {
                // Merge POST data into the data collection (existing values take precedence)
                foreach ($postData as $key => $value) {
                    if (!isset($this->data[$key])) {
                        $this->data[$key] = $value;
                    }
                }

                // Run onBeforeProcess hook
                if ($this->onBeforeProcessClosure) {
                    $closure = $this->onBeforeProcessClosure->bindTo($this);
                    call_user_func($closure);
                }

                // Process each Validate child action
                foreach ($this->children as $action) {
                    $name = $action->getName();
                    $value = isset($this->data[$name]) ? $this->data[$name] : null;
                    $compare = $this->record[$name] ?? null;
                    $value = $action->process($value, $compare);
                    $this->data[$name] = $value;
                }

                // Run onProcess hook (post-validation)
                if ($this->onProcessClosure) {
                    $closure = $this->onProcessClosure->bindTo($this);
                    call_user_func($closure);
                }
            }

            return !$this->errorCode && !count($this->rejected);
        }

        /**
         * Get the fetched database record.
         *
         * @return mixed
         */
        public function getRecord(): mixed
        {
            return $this->record;
        }

        /**
         * Get the database connection instance.
         *
         * @return Database|null
         */
        public function getDatabase(): ?Database
        {
            return $this->database;
        }

        /**
         * Get the value column name (kvp mode).
         *
         * @return string
         */
        public function getValueColumn(): string
        {
            return $this->valueColumn;
        }

        /**
         * Get the form data collection.
         *
         * @return Collection
         */
        public function getData(): Collection
        {
            return $this->data;
        }

        /**
         * Get a single field's value from the data collection.
         *
         * @param string $name Field name
         * @return mixed
         */
        public function getValue(string $name): mixed
        {
            return $this->data[$name] ?? null;
        }

        /**
         * Get the primary key column name.
         *
         * @return string
         */
        public function getIDColumn(): string
        {
            return $this->idColumn;
        }

        /**
         * Get the toggle column name(s).
         *
         * @return string|array
         */
        public function getToggleColumn(): string|array
        {
            return $this->toggleColumn;
        }

        /**
         * Get the internal error code (e.g., 'not_found', 'no_validation').
         *
         * @return string
         */
        public function getErrorCode(): string
        {
            return $this->errorCode;
        }

        /**
         * Get the target database table name.
         *
         * @return string
         */
        public function getTableName(): string
        {
            return $this->tableName;
        }

        /**
         * Get all parameters collected for the database operation.
         *
         * @return array
         */
        public function getParameters(): array
        {
            return $this->parameters;
        }

        /**
         * Set one or more custom parameters for the database operation.
         *
         * @param array|string $parameters Key or associative array
         * @param mixed $value Value when key is a string (can be Closure for computed values)
         * @return Action
         */
        public function setParameters(array|string $parameters, mixed $value = null): Action
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
         * Reject a field with an error code. Supports batch rejection via array.
         *
         * @param mixed $name Field name or array of field names
         * @param string|array $code Error code suffix (prefix '@' for raw code)
         * @param string $alias Optional alias to use as the rejection key
         * @return Action|null
         */
        public function reject(mixed $name, string|array $code = '', string $alias = ''): ?Action
        {
            if (is_array($name)) {
                foreach ($name as $_name) {
                    $this->reject($_name, $code, $alias);
                }
            } else {
                if (is_string($code)) {
                    $errorCode = (strlen($code) > 0 && $code[0] === '@') ? substr($code, 1) : $name . '_' . $code;
                } else {
                    $errorCode = $code;
                }
                $this->rejected[($alias) ?: $name] = ($this->rejectProcess) ? call_user_func($this->rejectProcess, $errorCode) : $errorCode;
            }
            return $this;
        }

        /**
         * Set the primary key value manually.
         *
         * @param string $uniqueKey
         * @return Action
         */
        public function setUniqueKey(string $uniqueKey): Action
        {
            $this->uniqueKey = $uniqueKey;
            return $this;
        }

        /**
         * Check if a specific field has been rejected.
         *
         * @param string $name Field name
         * @return bool
         */
        public function hasRejected(string $name): bool
        {
            return isset($this->rejected[$name]);
        }

        /**
         * Get all rejected fields with their error codes.
         *
         * @return array<string, mixed>
         */
        public function getRejected(): array
        {
            return $this->rejected;
        }

        /**
         * Set the operation mode.
         *
         * @param string $mode One of: 'create', 'edit', 'delete', 'kvp'
         * @return Action
         */
        public function setMode(string $mode): Action
        {
            if (in_array($mode, ['create', 'edit', 'delete', 'kvp'])) {
                $this->mode = $mode;
            }
            return $this;
        }

        /**
         * Get the current operation mode.
         *
         * @return string
         */
        public function getMode(): string
        {
            return $this->mode;
        }

        /**
         * Set a callback for parameter transformation before resolve (delete mode).
         *
         * @param callable $callback
         * @return Action
         */
        public function onResolve(callable $callback): Action
        {
            $this->onResolveClosure = $callback(...);
            return $this;
        }

        /**
         * Set a callback for the delete operation.
         *
         * @param callable $callback
         * @return Action
         */
        public function onDelete(callable $callback): Action
        {
            $this->onDeleteClosure = $callback;
            return $this;
        }

        /**
         * Execute the database operation (create, edit, delete, or kvp).
         *
         * Called after `process()` has validated the form data. Collects parameters
         * from validated fields and performs the appropriate database write.
         *
         * @param mixed ...$args Unused
         * @return bool True if the database operation succeeded
         */
        public function execute(...$args): bool
        {
            // Abort if validation failed
            if ($this->errorCode || count($this->rejected)) {
                return false;
            }

            // Ensure at least one validation field exists (except delete mode)
            if ($this->mode !== 'delete') {
                if (!count($this->children)) {
                    $this->errorCode = 'no_validation';
                    return false;
                }
            }

            // Collect parameters from validated Validate actions
            foreach ($this->children as $action) {
                if (!$action->isBypass()) {
                    $this->parameters[$action->getAlias()] = $this->data[$action->getName()] ?? null;
                }
            }

            // Run onResolve hook for delete mode parameter transformation
            if ($this->mode === 'delete' && $this->onResolveClosure) {
                $closure = $this->onResolveClosure->bindTo($this);
                $this->parameters = call_user_func($closure, $this->parameters);
            }

            // ── KVP (Key-Value Pair) Mode ────────────────────────────────
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

                        if (count($this->rejected)) {
                            return false;
                        }
                    }

                    $statement->query($parameters);
                }

                return true;
            }

            // ── Edit / Delete Mode Guards ────────────────────────────────
            if ($this->mode === 'edit' || $this->mode === 'delete') {
                if (!$this->uniqueKey) {
                    $this->errorCode = 'missing_unique_key';
                    return false;
                }

                if ($this->mode === 'edit') {
                    $this->parameters[$this->idColumn] = $this->uniqueKey;
                }
            }

            // ── Delete Mode ──────────────────────────────────────────────
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

                if ($this->toggleColumn) {
                    $statement = $this->database->update($this->tableName, array_keys($this->parameters))->where($this->idColumn . '=?');
                } else {
                    $this->database->delete($this->tableName, $this->parameters)->query();
                    return true;
                }
            }

            // ── onExecute Hook ───────────────────────────────────────────
            if ($this->onExecuteClosure) {
                $queueClosure = $this->onExecuteClosure->bindTo($this);
                call_user_func($queueClosure, $statement);

                if (count($this->rejected)) {
                    return false;
                }
            }

            // ── Create / Edit Database Write ─────────────────────────────
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
