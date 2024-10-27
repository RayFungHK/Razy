<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Closure;
use Razy\Controller;
use Razy\Database;
use Razy\Database\Statement\Plugin;
use Razy\Error;
use Razy\SimpleSyntax;
use ReflectionClass;
use Throwable;
use function preg_match;
use function Razy\append;
use function Razy\tidy;

class Statement
{
	private static mixed $pluginFolder = [];

	const REGEX_COLUMN = '/^(?:`(?:(?:\\\\.(*SKIP)(*FAIL)|.)+|\\\\[\\\\`])+`|[a-z]\w*)$/';
    
	private array $columns = [];
    private array $collects = [];
    private array $collections = [];
	private int $fetchLength = 0;
	private array $groupby = [];
	private ?WhereSyntax $havingSyntax = null;
	private bool $once = false;
	private array $orderby = [];
	private array $parameters = [];
	private ?Closure $parser = null;
	private int $position = 0;
	private array $selectColumns;
	private ?TableJoinSyntax $tableJoinSyntax = null;
	private string $tableName;
	private string $type = '';
	private array $updateSyntax = [];
	private ?WhereSyntax $whereSyntax = null;
	private array $onDuplicateKey = [];
	private ?Plugin $builderPlugin = null;

	/**
	 * Statement constructor.
	 *
	 * @param Database $database The Database instance
	 * @param string $sql A string of the SQL statement
	 */
	public function __construct(private readonly Database $database, private string $sql = '')
	{
		$this->sql = trim($this->sql);
		if ($this->sql) {
			$this->type = 'sql';
		}
		$this->selectColumns = ['*'];
	}

	/**
	 * Convert a list of column into where syntax with wildcard
	 *
	 * @param string $parameter
	 * @param array $columns
	 *
	 * @return string
	 * @throws Error
	 */
	public static function GetSearchTextSyntax(string $parameter, array $columns): string
	{
		$syntax = [];
		$parameter = trim($parameter);
		if (!$parameter) {
			throw new Error('The parameter is required');
		}
		foreach ($columns as $column) {
			$column = self::StandardizeColumn($column);
			if ($column) {
				$syntax[] = $column . '*=:' . $parameter;
			}
		}

		return (count($syntax)) ? implode('|', $syntax) : '';
	}

	/**
	 * Standardize the column string.
	 *
	 * @param string $column
	 *
	 * @return string
	 */
	public static function StandardizeColumn(string $column): string
	{
		$column = trim($column);
		if (preg_match('/^((`(?:(?:\\\\.(*SKIP)(*FAIL)|.)++|\\\\[\\\\`])+`|[a-z]\w*)(?:\.((?2)))?)(?:(->>?)([\'"])\$(.+)\5)?$/', $column, $matches)) {
			if (isset($matches[3]) && preg_match('/^[a-z]\w*$/', $matches[3])) {
				return $matches[2] . '.`' . trim($matches[3]) . '`';
			}
			if (preg_match('/^[a-z]\w*$/', $matches[2])) {
				return '`' . trim($matches[2]) . '`';
			}
		}

		return '';
	}

	/**
	 * Get or create the TableJoinSyntax instance by the name, it will replace the table name as a sub query in the
	 * Table Join Syntax.
	 *
	 * @param string $tableName the table will be replaced
	 *
	 * @return null|Statement
	 */
	public function alias(string $tableName): ?Statement
	{
		return ($this->tableJoinSyntax) ? $this->tableJoinSyntax->getAlias($tableName) : null;
	}

	/**
	 * @param string $plugin
	 * @return Plugin|null
	 * @throws Error
	 */
	public function builder(string $plugin): ?Plugin
	{
		return ($this->builderPlugin = $this->loadPlugin($plugin));
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

		foreach (self::$pluginFolder as $folder => $controller) {
			$pluginFile = append($folder, $name . '.php');
			if (is_file($pluginFile)) {
				try {
					$plugin = require $pluginFile;
					$reflection = new ReflectionClass($plugin);
					if ($reflection->isAnonymous()) {
						$parent = $reflection->getParentClass();
						if ($parent->getName() === 'Razy\Database\Statement\Plugin') {
							return ($this->builderPlugin = new $plugin($this));
						}
					}
					throw new Error('Missing or invalid plugin entity');
				} catch (Throwable $e) {
					throw new Error('Failed to load the plugin');
				}
			}
		}

		return $this->builderPlugin;
	}

	/**
	 * Assign parameters for generating SQL statement.
	 *
	 * @param array $parameters
	 *
	 * @return $this
	 */
	public function assign(array $parameters = []): Statement
	{
		$this->parameters = $parameters;

		return $this;
	}

	/**
	 * Functional FROM syntax with TableJoin Simple Syntax.
	 *
	 * @param string|Closure $syntax A well formatted TableJoin Simple Syntax
	 *
	 * @return $this
	 * @throws Error
	 */
	public function from(string|Closure $syntax): Statement
	{
		if (!$this->tableJoinSyntax) {
			$this->tableJoinSyntax = new TableJoinSyntax($this);
		}
		$this->tableJoinSyntax->parseSyntax($syntax);
		$this->type = 'select';

		return $this;
	}

	/**
	 * Parse the update syntax.
	 *
	 * @param string $syntax
	 *
	 * @return array
	 */
	private function parseSyntax(string $syntax): array
	{
		$syntax = trim($syntax);

		return SimpleSyntax::ParseSyntax($syntax, '+-*/&');
	}

	/**
	 * Get the Database entity
	 *
	 * @return Database
	 */
	public function getDatabase(): Database
	{
		return $this->database;
	}

	/**
	 * @return Plugin|null
	 */
	public function getBuilder(): ?Plugin
	{
		return $this->builderPlugin;
	}

	/**
	 * Generate the SQL statement.
	 *
	 * @return string
	 * @throws Throwable
	 */
	public function getSyntax(): string
	{
		if ('select' === $this->type) {
			if (!$this->tableJoinSyntax) {
				throw new Error('No Table Join Syntax is provided.');
			}
			$tableJoinSyntax = $this->tableJoinSyntax->getSyntax();

			$sql = 'SELECT ' . implode(', ', $this->selectColumns) . ' FROM ' . $tableJoinSyntax;
			if ($this->whereSyntax) {
				$syntax = $this->whereSyntax->getSyntax();
				if ($syntax) {
					$sql .= ' WHERE ' . $syntax;
				}
			}

			if (count($this->groupby)) {
				$sql .= ' GROUP BY ' . implode(', ', $this->groupby);
			}

			if ($this->havingSyntax) {
				$syntax = $this->havingSyntax->getSyntax();
				if ($syntax) {
					$sql .= ' HAVING ' . $syntax;
				}
			}

			if (count($this->orderby)) {
				foreach ($this->orderby as &$orderby) {
					if (is_array($orderby)) {
						$orderby = $orderby['syntax']->getSyntax() . ' ' . $orderby['ordering'];
					}
				}
				$sql .= ' ORDER BY ' . implode(', ', $this->orderby);
			}

			if ($this->fetchLength == 0 && $this->position > 0) {
				$sql .= ' LIMIT ' . $this->position;
			} elseif ($this->fetchLength > 0) {
				$sql .= ' LIMIT ' . $this->position . ', ' . $this->fetchLength;
			}

			return $sql;
		}

		if ('update' == $this->type) {
			if ($this->tableName && !empty($this->updateSyntax)) {
				$updateSyntax = [];
				foreach ($this->updateSyntax as $column => $syntax) {
					$updateSyntax[] = '`' . $column . '` = ' . $this->getUpdateSyntax($syntax, $column);
				}

				$sql = 'UPDATE ' . $this->tableName . ' SET ' . implode(', ', $updateSyntax);
				if ($this->whereSyntax) {
					$syntax = $this->whereSyntax->getSyntax();
					if ($syntax) {
						$sql .= ' WHERE ' . $syntax;
					}
				}

				return $sql;
			}

			throw new Error('Missing update syntax.');
		} elseif ('insert' == $this->type || 'replace' == $this->type) {
			if ($this->tableName && !empty($this->columns)) {
				$sql = strtoupper($this->type) . ' INTO ' . $this->tableName . ' (`' . implode('`, `', $this->columns) . '`) VALUES (';
				$values = [];
				foreach ($this->columns as $column) {
					$values[] = $this->getValueAsStatement($column);
				}
				$sql .= implode(', ', $values) . ')';

				if (count($this->onDuplicateKey)) {
					$duplicatedKeys = [];
					foreach ($this->onDuplicateKey as $column) {
						if (is_string($column)) {
							$duplicatedKeys[] = '`' . $column . '`=' . $this->getValueAsStatement($column);
						}
					}

					if (count($duplicatedKeys)) {
						$sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $duplicatedKeys);
					}
				}
				return $sql;
			}

			throw new Error('Invalid insert statement or no columns has provided.');
		} elseif ('delete' == $this->type) {
			if ($this->tableName && !empty($this->columns)) {
				$sql = 'DELETE FROM ' . $this->tableName;
				if ($this->whereSyntax) {
					$syntax = $this->whereSyntax->getSyntax();
					if ($syntax) {
						$sql .= ' WHERE ' . $syntax;
					}
				}
				return $sql;
			}

			throw new Error('Invalid delete statement.');
		}

		return $this->sql;
	}

	/**
	 * Generate the UPDATE statement.
	 *
	 * @param array $updateSyntax
	 * @param string $column
	 *
	 * @return string
	 * @throws Error
	 */
	private function getUpdateSyntax(array $updateSyntax, string $column): string
	{
		$parser = function (array &$extracted) use (&$parser, $column) {
			$parsed = [];
			$operand = '';
			while ($clip = array_shift($extracted)) {
				if (is_array($clip)) {
					if ('&' === $operand) {
						$merged = implode(', ', $parsed) . ', ';
						$parsed = ['CONCAT(' . $merged . $parser($clip) . ')'];
					} else {
						$parsed[] = '(' . $parser($clip) . ')';
					}
				} else {
					if (!preg_match('/^[+\-*\/&]$/', $clip)) {
						$matches = null;
						if ('?' === $clip || preg_match('/^:(\w+)$/', $clip, $matches)) {
							$clip = $this->getValueAsStatement(('?' === $clip) ? $column : $matches[1]);
						} else {
							$clip = preg_replace_callback('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|:(\w+)/', function ($matches) {
								return $this->getValueAsStatement($matches[3]);
							}, $clip);
						}

						if ('&' === $operand) {
							$merged = implode(', ', $parsed) . ', ';
							$parsed = ['CONCAT(' . $merged . $clip . ')'];
						} else {
							$parsed[] = $clip;
						}
					} else {
						throw new Error('Cannot generate the update statement because the Update Simple Syntax is not correct.');
					}
				}

				$operand = array_shift($extracted);
				if ($operand) {
					if (!is_string($operand) || !preg_match('/^[+\-*\/&]$/', $operand)) {
						throw new Error('Cannot generate the update statement because the Update Simple Syntax is not correct.');
					}

					if ('&' !== $operand) {
						$parsed[] = $operand;
					}
				}
			}

			return implode(' ', $parsed);
		};

		$extracted = $updateSyntax;

		return $parser($extracted);
	}

	/**
	 * Get the value as a string for statement.
	 *
	 * @param string $column
	 *
	 * @return string
	 */
	private function getValueAsStatement(string $column): string
	{
		$value = $this->getValue($column);
		if ($value === null) {
			return 'NULL';
		}

		if (is_scalar($value)) {
			return (is_string($value)) ? '\'' . addslashes($value) . '\'' : $value;
		}

		return '\'\'';
	}

	/**
	 * Get the assigned parameter value by given name.
	 *
	 * @param string $name
	 *
	 * @return null|mixed
	 */
	public function getValue(string $name): mixed
	{
		return $this->parameters[$name] ?? null;
	}

	/**
	 * Set the group by syntax.
	 *
	 * @param string $syntax
	 *
	 * @return $this
	 */
	public function group(string $syntax): Statement
	{
		$clips = preg_split('/\s*,\s*/', $syntax);

		$this->groupby = [];
		foreach ($clips as &$column) {
			$column = self::StandardizeColumn($column);
			$this->groupby[] = trim($column);
		}

		return $this;
	}

	/**
	 * Set the Statement as an insert statement.
	 *
	 * @param string $tableName The table name will be inserted a new record
	 * @param array $columns A set of columns to insert
	 * @param array $duplicateKeys A set of columns to check the duplicate key
	 *
	 * @return $this
	 * @throws Error
	 */
	public function insert(string $tableName, array $columns, array $duplicateKeys = []): Statement
	{
		$this->type = 'insert';
		$tableName = trim($tableName);
		if (!$tableName) {
			throw new Error('The table name cannot be empty.');
		}
		$this->tableName = $this->getPrefix() . $tableName;

		foreach ($columns as $index => &$column) {
			$column = trim($column);
			if (preg_match('/^(?:`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)$/', $column)) {
				$column = trim($column, '`');
			} else {
				unset($columns[$index]);
			}
		}

		$this->columns = $columns;
		$this->onDuplicateKey = array_values($duplicateKeys);

		return $this;
	}

	/**
	 * Get the table prefix.
	 *
	 * @return string
	 */
	public function getPrefix(): string
	{
		return $this->database->getPrefix();
	}

	/**
	 * Execute the statement and fetch the first result.
	 *
	 * @param array $parameters
	 *
	 * @return mixed
	 * @throws Throwable
	 */
	public function lazy(array $parameters = []): mixed
	{
		$result = $this->query($parameters)->fetch();
		if ($result) {
			$parser = $this->parser;
			if (is_callable($parser)) {
				$parser($result);
			}
		}

		// If the parser set only execute once, reset the parser.
		if ($this->once) {
			$this->once = false;
			$this->parser = null;
		}

		return $result;
	}


	/**
	 * Execute the statement and return the Query instance.
	 *
	 * @param array $parameters
	 *
	 * @return Query
	 * @throws Throwable
	 */
	public function query(array $parameters = []): Query
	{
		if (count($parameters)) {
			$this->parameters = array_merge($this->parameters, $parameters);
		}

		return $this->database->execute($this);
	}

    /**
     * Get the collection after fetch query
     *
     * @param string $name
     * @return array|null
     */
    public function getCollection(string $name): ?array
    {
        return $this->collections[$name] ?? null;
    }

	/**
	 * Execute the statement and return the result by group if the column is given.
	 *
	 * @param array $parameters An array contains the parameter
	 * @param string $column The key of the group result
	 * @param bool $stackable Group all result with same column into an array
	 * @param string $stackColumn The key of the stacked result
	 *
	 * @return array
	 * @throws Throwable
	 */
	public function &lazyGroup(array $parameters = [], string $column = '', bool $stackable = false, string $stackColumn = ''): array
	{
		$result = [];
		$query = $this->query($parameters);
		while ($row = $query->fetch()) {
			$parser = $this->parser;
			if (is_callable($parser)) {
				$parser($row);
			}

            if (count($this->collects)) {
                foreach ($this->collects as $name => $isUnique) {
                    if (array_key_exists($name, $row)) {
                        $this->collections[$name] = $this->collections[$name] ?? [];
                        if ($isUnique) {
                            $this->collections[$name][$row[$name]] = true;
                        } else {
                            $this->collections[$name][] = $row[$name];
                        }
                    }
                }
            }

			if (!$column || !array_key_exists($column, $row)) {
				$result[] = $row;
			} else {
				if ($stackable) {
					if (!isset($result[$row[$column]])) {
						$result[$row[$column]] = [];
					}

					if (!$stackColumn || !array_key_exists($stackColumn, $row)) {
						$result[$row[$column]][] = $row;
					} else {
						$result[$row[$column]][$row[$stackColumn]] = $row;
					}
				} else {
					$result[$row[$column]] = $row;
				}
			}
		}

		// If the parser set only execute once, reset the parser.
		if ($this->once) {
			$this->once = false;
			$this->parser = null;
		}

		return $result;
	}

	/**
	 * Execute the statement and group the result as the key value pair.
	 *
	 * @param string $keyColumn
	 * @param string $valueColumn
	 * @param array $parameters
	 *
	 * @return array
	 * @throws Error
	 * @throws Throwable
	 */
	public function lazyKeyValuePair(string $keyColumn, string $valueColumn, array $parameters = []): array
	{
		$keyColumn = trim($keyColumn);
		$valueColumn = trim($valueColumn);
		if (!$valueColumn || !$keyColumn) {
			throw new Error('The key or value column name cannot be empty.');
		}
		$result = [];
		$query = $this->query($parameters);
		while ($row = $query->fetch()) {
			$parser = $this->parser;
			if (is_callable($parser)) {
				$parser($row);
			}

			if (!isset($row[$keyColumn])) {
				throw new Error('The key column `' . $keyColumn . '` cannot found in fetched result.');
			}
			if (!isset($row[$valueColumn])) {
				throw new Error('The key column `' . $keyColumn . '` cannot found in fetched result.');
			}
			$result[$row[$keyColumn]] = $row[$valueColumn];
		}

		return $result;
	}

	/**
	 * Set the fetch count and position.
	 *
	 * @param int $position
	 * @param int $fetchLength
	 *
	 * @return Statement
	 */
	public function limit(int $position, int $fetchLength = 0): Statement
	{
		$this->fetchLength = $fetchLength;
		$this->position = $position;

		return $this;
	}

    /**
     * Set the order by syntax.
     *
     * @param string $syntax
     *
     * @return $this
     * @throws Error
     */
	public function order(string $syntax): Statement
	{
		$clips = preg_split('/\s*,\s*/', $syntax);

		$this->orderby = [];
		foreach ($clips as $column) {
			$ordering = '';
			if (preg_match('/^([<>])?(.+)/', $column, $matches)) {
				$ordering = $matches[1];
				$column = $matches[2];
			}

			$standardizedColumn = self::StandardizeColumn($column);
			if ($standardizedColumn) {
				$standardizedColumn .= ('>' === $ordering) ? ' DESC' : ' ASC';
				$this->orderby[] = $standardizedColumn;
			} else {
				$orderSyntax = new WhereSyntax($this);
				$orderSyntax->parseSyntax($column);
				$this->orderby[] = [
					'syntax' => $orderSyntax,
					'ordering' => ('>' === $ordering) ? 'DESC' : 'ASC'
				];
			}
		}

		return $this;
	}

    /**
     * Collect the data from the query result by column name
     *
     * @param string|array $columns
     * @param bool|null $isUnique
     * @return $this
     */
    public function collect(string|array $columns, ?bool $isUnique = false): static
    {
        if (is_array($columns))  {
            foreach ($columns as $column => $isUnique) {
                if (is_string($column) && ($column = trim($column))) {
                    $this->collect($column, $isUnique);
                }
            }
        } else {
            $this->collects[$columns] = $isUnique;
        }

        return $this;
    }

	/**
	 * Functional SELECT syntax.
	 *
	 * @param string $columns a set of columns to be generated as SELECT expression
	 *
	 * @return $this
	 */
	public function select(string $columns): Statement
	{
		$this->selectColumns = preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $columns);

		foreach ($this->selectColumns as &$column) {
			$column = (preg_match('/^\w+$/', $column)) ? '`' . $column . '`' : $column;
		}

		return $this;
	}

	/**
	 * Set the parser used to parse the fetching data.
	 *
	 * @param Closure $closure
	 * @param bool $once
	 *
	 * @return Statement
	 */
	public function setParser(Closure $closure, bool $once = false): Statement
	{
		$this->parser = $closure;
		$this->once = $once;

		return $this;
	}

	/**
	 * @param string $tableName
	 * @param array $parameters
	 * @param string $whereSyntax
	 * @return $this
	 * @throws Error
	 */
	public function delete(string $tableName, array $parameters = [], string $whereSyntax = ''): static
	{
		$this->type = 'delete';
		$tableName = trim($tableName);
		if (!$tableName) {
			throw new Error('The table name cannot be empty.');
		}

		if (count($parameters)) {
			$this->columns = array_keys($parameters);
			$this->whereSyntax = new WhereSyntax($this);
			if (!$whereSyntax) {
				foreach ($parameters as $key => $value) {
					if (is_array($value)) {
						$whereSyntax .= ',' . $key . '|=?';
					} else {
						$whereSyntax .= ',' . $key . '=?';
					}
				}
			}
			$this->whereSyntax->parseSyntax($whereSyntax);
			$this->assign($parameters);
		}

		return $this;
	}

	/**
	 * Set the Statement as an update statement.
	 *
	 * @param string $tableName The table name will be updated record
	 * @param array $updateSyntax A set of Update Simple Syntax
	 *
	 * @return $this
	 * @throws Error
	 */
	public function update(string $tableName, array $updateSyntax): Statement
	{
		$this->type = 'update';
		$tableName = trim($tableName);
		if (!$tableName) {
			throw new Error('The table name cannot be empty.');
		}
		$this->tableName = $this->getPrefix() . $tableName;

		$this->updateSyntax = [];
		foreach ($updateSyntax as &$syntax) {
			$syntax = trim($syntax);
			if (preg_match('/(?:(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|^(`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)(?:(\+\+|--)|\s*([+\-*\/&]?=)\s*(.+))?$/', $syntax, $matches)) {
				$matches[3] = trim($matches[3], '`');

				if (isset($matches[4]) && $matches[4]) {
					$this->updateSyntax[$matches[3]] = ['`' . $matches[3] . '`', ('++' === $matches[4]) ? '+' : '-', 1];
				} elseif (isset($matches[6])) {
					$shortenSyntax = [];
					if (2 === strlen($matches[5])) {
						$operator = $matches[5][0];
						$shortenSyntax = ['`' . $matches[3] . '`', $operator];
					}
					$this->updateSyntax[$matches[3]] = array_merge($shortenSyntax, $this->parseSyntax($matches[6]));
				} else {
					$this->updateSyntax[$matches[3]] = [':' . $matches[3]];
				}
			}
		}

		if (empty($this->updateSyntax)) {
			throw new Error('There is no update syntax will be executed.');
		}

		return $this;
	}

	/**
	 * Functional WHERE syntax with Where Simple Syntax.
	 *
	 * @param string|Closure $syntax The well formatted Where Simple Syntax
	 *
	 * @return Statement
	 * @throws Error
	 */
	public function where(string|Closure $syntax): Statement
	{
		if (!$this->whereSyntax) {
			$this->whereSyntax = new WhereSyntax($this);
		}
		$this->whereSyntax->parseSyntax($syntax);

		return $this;
	}
}
