<?php
/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Razy\Action\Plugin;
use Razy\Action\Validate;
use ReflectionClass;
use Throwable;

class Action
{
	const TYPE_CREATE = 1;
	const TYPE_EDIT = 2;
	const TYPE_DELETE = 3;
	const TYPE_KEYVALUEPAIR = 4;

	const ERROR_NOT_FOUND = 1;
	const ERROR_VALIDATION_FAILED = 2;
	const ERROR_NO_VALIDATION = 3;
	const ERROR_MISSING_UNIQUE_KEY = 4;
	const ERROR_KEY_VALUE_NOT_CONFIGURE = 5;

	/**
	 * The storage of the plugin folder
	 * @var string[]
	 */
	private static array $pluginFolder = [];

	/**
	 * @var Plugin[]
	 */
	private array $plugins = [];

	private array $errors = [];

	/**
	 * @var Validate[]
	 */
	private array $validations = [];

	private Collection $dataset;

	private mixed $fetched;

	private string $uniqueKey = '';

	private int $errorCode = 0;

	private array $parameters = [];

	private null|array|Closure $errorCodeParser = null;

	private string $valueColumn = '';

	private ?Closure $validationClosure = null;

	private ?Closure $queueClosure = null;

	private ?Closure $fetchingClosure = null;
	private ?Closure $onDeleteClosure = null;

	public function __construct(
		private int               $type,
		private readonly Database $database,
		private readonly string   $tableName,
		private readonly string   $idColumn,
		private readonly string   $markerColumn = '')
	{

	}

	/**
	 * Set the action's type
	 *
	 * @param int $type
	 * @return $this
	 */
	public function setType(int $type): self
	{
		$this->type = $type;
		return $this;
	}

	/**
	 * Set up the validation for specified value
	 *
	 * @param string $name
	 * @param Closure|null $preProcess
	 * @return Validate
	 */
	public function validate(string $name, ?Closure $preProcess = null): Validate
	{
		return $this->validations[$name] ?? ($this->validations[$name] = new Validate($this, $name, $preProcess));
	}

	/**
	 * @param Closure $closure
	 * @return $this
	 */
	public function onFetch(Closure $closure): self
	{
		$this->fetchingClosure = $closure;
		return $this;
	}

	/**
	 * Process all the dataset, return true if no errors was found.
	 *
	 * @param array|Collection $dataset
	 * @param string $uniqueKey
	 * @return bool
	 * @throws Throwable
	 */
	public function process(array|Collection $dataset = [], string $uniqueKey = ''): bool
	{
		$this->uniqueKey = $uniqueKey;
		if ($this->type === self::TYPE_EDIT || $this->type === self::TYPE_DELETE) {
			$statement = $this->database->prepare()->from($this->tableName);
			if ($this->fetchingClosure) {
				$closure = $this->fetchingClosure->bindTo($this);
				if (!($this->fetched = call_user_func($closure, $uniqueKey, $statement))) {
					$this->errorCode = self::ERROR_NOT_FOUND;
					return false;
				}
			} else {
				$parameters = [];
				$parameters[$this->idColumn] = $uniqueKey;
				if (!($this->fetched = $statement->where($this->idColumn . '=?' . ($this->markerColumn ? ',!' . $this->markerColumn : ''))->lazy($parameters))) {
					$this->errorCode = self::ERROR_NOT_FOUND;
					return false;
				}
			}
		} elseif ($this->type === self::TYPE_KEYVALUEPAIR) {
			$this->fetched = $this->database->prepare()->from($this->tableName)->lazyGroup([], $this->idColumn);
		}

		// Only do the validation in create, edit or key value update mode
		if ($this->type === self::TYPE_CREATE || $this->type === self::TYPE_EDIT || $this->type === self::TYPE_KEYVALUEPAIR) {
			$this->dataset = collect($dataset);
			// Validation
			foreach ($this->validations as $name => $validation) {
				$value = $this->dataset[$name] ?? null;
				$compare = $this->fetched[$name] ?? null;
				$this->dataset[$name] = $validation->process($value, $compare);
			}

			// Post process
			foreach ($this->validations as $name => $validation) {
				$value = $this->dataset[$name] ?? null;
				$compare = $this->fetched[$name] ?? null;
				$this->dataset[$name] = $validation->postProcess($value, $compare);
			}

			if ($this->validationClosure) {
				$closure = $this->validationClosure->bindTo($this);
				call_user_func($closure);
			}
		}

		if (count($this->errors) > 0) {
			$this->errorCode = self::ERROR_VALIDATION_FAILED;
		}
		return $this->errorCode === 0;
	}

	/**
	 * Get the error code after the action has processed.
	 *
	 * @return int
	 */
	public function getErrorCode(): int
	{
		return $this->errorCode;
	}

	public function onValidation(Closure $closure): self
	{
		$this->validationClosure = $closure;
		return $this;
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	public function getStorage(string $name): mixed
	{
		return (isset($this->validations[$name])) ? $this->validations[$name]->getStorage() : null;
	}

	/**
	 * Reject the data validation and give an error code
	 *
	 * @param array|string $name
	 * @param string|array $code
	 * @param string $alias
	 * @return Action
	 */
	public function reject(array|string $name, string|array $code, string $alias = ''): self
	{
		if (is_array($name)) {
			foreach ($name as $_name) {
				$this->reject($_name, $code, $alias);
			}
		} else {
			if (is_string($code)) {
				// Beginning with @ means absolute path, no prefix will be added.
				$errorCode = ($code && $code[0] === '@') ? substr($code, 1) : $name . '_' . $code;
			} else {
				$errorCode = $code;
			}
			$this->errors[($alias) ?: $name] = ($this->errorCodeParser) ? call_user_func($this->errorCodeParser, $errorCode) : $errorCode;
		}
		return $this;
	}

	/**
	 * Get the list of the validation errors
	 *
	 * @return array
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function hasRejected(string $name): bool
	{
		return isset($this->errors[$name]);
	}

	/**
	 * Get the plugin closure from the plugin pool.
	 *
	 * @param string $name The plugin name
	 *
	 * @return Closure|null The plugin entity
	 * @throws Error
	 */
	public function loadPlugin(string $name): Plugin|null
	{
		$name = trim($name);
		if (!$name) {
			return null;
		}

		if (!isset($this->plugins[$name])) {
			foreach (self::$pluginFolder as $folder => $controller) {
				$pluginFile = append($folder, $name . '.php');
				if (is_file($pluginFile)) {
					try {
						$plugin = require $pluginFile;
						$reflection = new ReflectionClass($plugin);
						if ($reflection->isAnonymous()) {
							$parent = $reflection->getParentClass();
							if ($parent->getName() === 'Razy\Action\Plugin') {
								return ($this->plugins[$name] = $plugin);
							}
						}
						throw new Error('Missing or invalid plugin entity');
					} catch (Throwable $e) {
						throw new Error('Failed to load the plugin');
					}
				}
			}

			if (!isset($this->plugins[$name])) {
				$this->plugins[$name] = null;
			}
		}

		return $this->plugins[$name];
	}

	/**
	 * Add a plugin folder which the plugin is load
	 *
	 * @param string $folder
	 * @param Controller|null $entity
	 * @return void
	 */
	public static function addPluginFolder(string $folder, ?Controller $entity = null): void
	{
		// Setup plugin folder
		$folder = tidy(trim($folder));
		if ($folder && is_dir($folder)) {
			self::$pluginFolder[$folder] = $entity;
		}
	}

	/**
	 * Get the dataset that passed in process.
	 *
	 * @return Collection
	 */
	public function &getDataset(): Collection
	{
		return $this->dataset;
	}

	/**
	 * Get the action type
	 *
	 * @return int
	 */
	public function getType(): int
	{
		return $this->type;
	}

	/**
	 * Get the database entity.
	 *
	 * @return Database|null
	 */
	public function getDB(): ?Database
	{
		return $this->database;
	}

	/**
	 * @param string $valueColumn
	 * @return $this
	 * @throws Error
	 */
	public function setValueColumn(string $valueColumn): self
	{
		$valueColumn = trim($valueColumn);
		if (!$valueColumn) {
			throw new Error('Value column name cannot be empty.');
		}
		$this->valueColumn = $valueColumn;
		return $this;
	}

	/**
	 * Start submit the action, insert, update or delete the record
	 *
	 * @param Closure|null $closure
	 * @return bool
	 * @throws Error
	 * @throws Throwable
	 */
	public function submit(?Closure $closure = null): bool
	{
		if ($this->errorCode !== 0) {
			return false;
		}

		if (self::TYPE_DELETE !== $this->type) {
			// Return false if no validation has registered
			if (!count($this->validations)) {
				$this->errorCode = self::ERROR_NO_VALIDATION;
				return false;
			}
		}

		$this->parameters = [];
		foreach ($this->validations as $name => $validation) {
			if (!$validation->isIgnore()) {
				$boundName = $validation->getBoundName();
				$this->parameters[$boundName ?: $name] = $this->dataset[$name] ?? null;
			}
		}

		if (self::TYPE_DELETE !== $this->type && $closure) {
			$closure = $closure->bindTo($this);
			$this->parameters = call_user_func($closure, $this->parameters);
		}

		if (self::TYPE_KEYVALUEPAIR === $this->type) {
			if (!$this->valueColumn) {
				$this->errorCode = self::ERROR_KEY_VALUE_NOT_CONFIGURE;
				return false;
			}

			foreach ($this->parameters as $key => $value) {
				$parameters = [];
				$parameters[$this->idColumn] = $key;
				$parameters[$this->valueColumn] = $value;
				$statement = $this->database->insert($this->tableName, array_keys($parameters), [$this->idColumn]);
				if ($this->queueClosure) {
					$queueClosure = $this->queueClosure->bindTo($this);
					call_user_func($queueClosure, $statement);
				}
				$statement->query($parameters);
			}
		} elseif (self::TYPE_CREATE === $this->type) {
			$statement = $this->database->insert($this->tableName, array_keys($this->parameters));
			if ($this->queueClosure) {
				$queueClosure = $this->queueClosure->bindTo($this);
				call_user_func($queueClosure, $statement);
			}
			$statement->query($this->parameters);
			$this->uniqueKey = $this->database->lastID();
		} elseif (self::TYPE_EDIT === $this->type) {
			if (!$this->uniqueKey) {
				$this->errorCode = self::ERROR_MISSING_UNIQUE_KEY;
				return false;
			}

			$this->parameters[$this->idColumn] = $this->uniqueKey;
			$statement = $this->database->update($this->tableName, array_keys($this->parameters))->where($this->idColumn . '=?');

			if ($this->queueClosure) {
				$queueClosure = $this->queueClosure->bindTo($this);
				call_user_func($queueClosure, $statement);
			}

			$statement->query($this->parameters);
		} elseif (self::TYPE_DELETE === $this->type) {
			if (!$this->uniqueKey) {
				$this->errorCode = self::ERROR_MISSING_UNIQUE_KEY;
				return false;
			}

			$this->parameters = [];
			// If delete column is provided, mark the delete column as true
			if ($this->markerColumn) {
				$this->parameters[$this->markerColumn] = 1;
				if ($this->onDeleteClosure) {
					$closure = $this->onDeleteClosure->bindTo($this);
					$this->parameters = call_user_func($closure, $this->parameters);
				}

				$statement = $this->database->update($this->tableName, array_keys($this->parameters))->where($this->idColumn . '=?');
				$this->parameters[$this->idColumn] = $this->uniqueKey;
				if ($this->queueClosure) {
					$queueClosure = $this->queueClosure->bindTo($this);
					call_user_func($queueClosure, $statement);
				}

				if ($closure) {
					$closure = $closure->bindTo($this);
					$this->parameters = call_user_func($closure, $this->parameters);
				}

				$statement->query($this->parameters);
			} else {
				// Delete the record when no delete column is provided.
				$this->parameters[$this->idColumn] = $this->uniqueKey;
				if ($closure) {
					$closure = $closure->bindTo($this);
					$this->parameters = call_user_func($closure, $this->parameters);
				}

				$statement = $this->database->delete($this->tableName, $this->parameters);
				if ($this->queueClosure) {
					$queueClosure = $this->queueClosure->bindTo($this);
					call_user_func($queueClosure, $statement);
				}
				$statement->query();
			}
		}

		return true;
	}

	/**
	 * Get the unique key.
	 *
	 * @return string
	 */
	public function getUniqueKey(): string
	{
		return $this->uniqueKey;
	}

	/**
	 * @param string $uniqueKey
	 * @return $this
	 */
	public function setUniqueKey(string $uniqueKey): self
	{
		$this->uniqueKey = $uniqueKey;
		return $this;
	}

	/**
	 * @return $this
	 */
	public function onQueue(Closure $closure): self
	{
		$this->queueClosure = $closure;
		return $this;
	}

	/**
	 * @return $this
	 */
	public function onDelete(Closure $closure): self
	{
		$this->onDeleteClosure = $closure;
		return $this;
	}
	/**
	 * Get the id column
	 *
	 * @return string
	 */
	public function getIDColumn(): string
	{
		return $this->idColumn;
	}

	/**
	 * Get the marker column
	 *
	 * @return string
	 */
	public function getMarkerColumn(): string
	{
		return $this->markerColumn;
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
	 * Get the fetched record in edit or delete mode
	 *
	 * @return mixed
	 */
	public function getFetched(): mixed
	{
		return $this->fetched;
	}

	/**
	 * Get the parameters that processed after submission
	 *
	 * @return array
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}

	/**
	 * Set the parser for the error code
	 *
	 * @param Closure|array|null $closure
	 * @return $this
	 */
	public function setErrorCodeParser(null|Closure|array $closure): self
	{
		if (is_array($closure)) {
			if (!count($closure) == 2 || !is_object($closure[0]) || !is_string($closure[1])) {
				return $this;
			}
		}
		$this->errorCodeParser = $closure;
		return $this;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return $this
	 */
	public function setValue(string $name, mixed $value): self
	{
		$this->dataset[$name] = $value;
		return $this;
	}
}