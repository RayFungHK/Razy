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

use Razy\Database;
use Razy\Database\Statement\Builder;
use Razy\Database\Statement\DeleteSyntaxBuilder;
use Razy\Database\Statement\InsertSyntaxBuilder;
use Razy\Database\Statement\SelectSyntaxBuilder;
use Razy\Database\Statement\StatementType;
use Razy\Database\Statement\UpdateSyntaxBuilder;
use Razy\Exception\QueryException;
use Razy\PluginTrait;
use Razy\SimpleSyntax;
use Throwable;

/**
 * Class Statement.
 *
 * Builds and executes SQL statements (SELECT, INSERT, UPDATE, DELETE) using
 * a fluent interface. Supports Where Simple Syntax, TableJoin Simple Syntax,
 * Update Simple Syntax, parameterized queries, sub-queries, grouping, ordering,
 * pagination, and result parsing/collection.
 *
 * @package Razy
 *
 * @license MIT
 */
class Statement
{
    use PluginTrait;

    /** @var string Regex pattern for validating column names (backtick-quoted or alpha-start identifiers) */
    public const REGEX_COLUMN = '/^(?:`(?:(?:\\\.(*SKIP)(*FAIL)|.)+|\\\[\\\`])+`|[a-z]\w*)$/';

    /** @var array Column names used in INSERT/DELETE statements */
    private array $columns = [];

    /** @var int Number of rows to fetch (LIMIT length), 0 for unlimited */
    private int $fetchLength = 0;

    /** @var array GROUP BY column expressions */
    private array $groupby = [];

    /** @var WhereSyntax|null HAVING clause syntax parser */
    private ?WhereSyntax $havingSyntax = null;

    /** @var array ORDER BY column expressions with direction */
    private array $orderby = [];

    /** @var array Parameter values bound to the statement for value substitution */
    private array $parameters = [];

    /** @var int Starting position for LIMIT/OFFSET */
    private int $position = 0;

    /** @var array SELECT column expressions (defaults to ['*']) */
    private array $selectColumns;

    /** @var TableJoinSyntax|null FROM clause with table join definitions */
    private ?TableJoinSyntax $tableJoinSyntax = null;

    /** @var string The resolved table name (with prefix) for INSERT/UPDATE/DELETE */
    private string $tableName;

    /** @var StatementType|null Statement type, null when not yet configured */
    private ?StatementType $type = null;

    /** @var array Parsed update expressions keyed by column name */
    private array $updateSyntax = [];

    /** @var WhereSyntax|null WHERE clause syntax parser */
    private ?WhereSyntax $whereSyntax = null;

    /** @var array Columns to check for duplicate key in upsert operations */
    private array $onDuplicateKey = [];

    /** @var Builder|null Active statement builder plugin instance */
    private ?Builder $builderPlugin = null;

    /** @var StatementExecutor The executor for running queries */
    private StatementExecutor $executor;

    /** @var LazyResultSet The result set handler for lazy-loading and result processing */
    private LazyResultSet $resultSet;

    /**
     * Statement constructor.
     *
     * @param Database $database The Database instance
     * @param string $sql A string of the SQL statement
     */
    public function __construct(private readonly Database $database, private string $sql = '')
    {
        $this->sql = \trim($this->sql);
        if ($this->sql) {
            $this->type = StatementType::Raw;
        }
        $this->selectColumns = ['*'];
        $this->executor = new StatementExecutor($this->database, $this);
        $this->resultSet = new LazyResultSet($this->executor);
    }

    /**
     * Convert a list of column into where syntax with wildcard.
     *
     * @param string $parameter
     * @param array $columns
     *
     * @return string
     *
     * @throws Error
     */
    public static function getSearchTextSyntax(string $parameter, array $columns): string
    {
        $syntax = [];
        $parameter = \trim($parameter);
        if (!$parameter) {
            throw new QueryException('The parameter is required');
        }
        foreach ($columns as $column) {
            $column = self::standardizeColumn($column);
            if ($column) {
                $syntax[] = $column . '*=:' . $parameter;
            }
        }

        return (\count($syntax)) ? \implode('|', $syntax) : '';
    }

    /**
     * Standardize the column string.
     *
     * @param string $column
     *
     * @return string
     */
    public static function standardizeColumn(string $column): string
    {
        $column = \trim($column);
        // Match column syntax: optional table alias prefix, column name (backtick-quoted or identifier),
        // with optional JSON path operator (-> or ->>)
        if (\preg_match('/^((`(?:(?:\\\.(*SKIP)(*FAIL)|.)++|\\\[\\\`])+`|[a-z]\w*)(?:\.((?2)))?)(?:(->>?)([\'"])\$(.+)\5)?$/', $column, $matches)) {
            if (isset($matches[3]) && \preg_match('/^[a-z]\w*$/', $matches[3])) {
                // Column with table alias: quote the column part
                return $matches[2] . '.`' . \trim($matches[3]) . '`';
            }
            if (\preg_match('/^[a-z]\w*$/', $matches[2])) {
                // Standalone column name: wrap in backticks
                return '`' . \trim($matches[2]) . '`';
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
     * @return Statement|null
     */
    public function alias(string $tableName): ?self
    {
        return ($this->tableJoinSyntax) ? $this->tableJoinSyntax->getAlias($tableName) : null;
    }

    /**
     * Instantiate and initialize a Builder plugin by name.
     * Builders extend statement construction with custom SQL generation logic.
     *
     * @param string $builderName The registered builder plugin name
     * @param mixed ...$arguments Arguments passed to the builder factory
     *
     * @return Builder|null The initialized builder instance
     *
     * @throws Error If the builder cannot be created
     */
    public function builder(string $builderName, ...$arguments): ?Builder
    {
        $plugin = self::GetPlugin($builderName);
        if ($plugin) {
            try {
                return $this->builderPlugin = $plugin['entity'](...$arguments)->init($this);
            } catch (Throwable) {
                throw new QueryException('Failed to create builder: ' . $builderName);
            }
        }
        throw new QueryException('Failed to create builder: ' . $builderName);
    }

    /**
     * Assign parameter values used for SQL generation and value substitution.
     * These parameters are referenced by :name in Where, Update, and Insert syntax.
     *
     * @param array $parameters Associative array of parameter name => value pairs
     *
     * @return $this
     */
    public function assign(array $parameters = []): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Define the FROM clause using TableJoin Simple Syntax.
     * Sets the statement type to 'select' and initializes the TableJoinSyntax parser.
     * Accepts either a syntax string or a callable that returns/modifies the syntax.
     *
     * @param string|callable $syntax A well formatted TableJoin Simple Syntax string or callable
     *
     * @return $this
     *
     * @throws Error If the syntax is invalid
     */
    public function from(string|callable $syntax): self
    {
        if (!$this->tableJoinSyntax) {
            $this->tableJoinSyntax = new TableJoinSyntax($this);
        }

        if (\is_callable($syntax)) {
            $syntax = $syntax(...);
        }

        $this->tableJoinSyntax->parseSyntax($syntax);
        $this->type = StatementType::Select;

        return $this;
    }

    /**
     * Get the Database entity.
     *
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Get the currently active Builder plugin, if any.
     *
     * @return Builder|null The active builder or null
     */
    public function getBuilder(): ?Builder
    {
        return $this->builderPlugin;
    }

    /**
     * Get the TableJoinSyntax (FROM clause) as a string.
     * Returns the parsed FROM clause without the SELECT part.
     *
     * @return string|null The FROM clause syntax, or null if not set
     *
     * @throws Throwable
     */
    public function getFromSyntax(): ?string
    {
        return $this->tableJoinSyntax?->getSyntax();
    }

    /**
     * Get the WhereSyntax (WHERE clause) as a string.
     * Returns the parsed WHERE clause without the WHERE keyword.
     *
     * @return string|null The WHERE clause syntax, or null if not set
     *
     * @throws Throwable
     */
    public function getWhereSyntax(): ?string
    {
        return $this->whereSyntax?->getSyntax();
    }

    /**
     * Get the HavingSyntax (HAVING clause) as a string.
     * Returns the parsed HAVING clause without the HAVING keyword.
     *
     * @return string|null The HAVING clause syntax, or null if not set
     *
     * @throws Throwable
     */
    public function getHavingSyntax(): ?string
    {
        return $this->havingSyntax?->getSyntax();
    }

    /**
     * Generate the SQL statement.
     *
     * @return string
     *
     * @throws Throwable
     */
    public function getSyntax(): string
    {
        return match ($this->type) {
            StatementType::Select => (new SelectSyntaxBuilder())->buildSyntax($this),
            StatementType::Update => (new UpdateSyntaxBuilder())->buildSyntax($this),
            StatementType::Insert, StatementType::Replace => (new InsertSyntaxBuilder())->buildSyntax($this),
            StatementType::Delete => (new DeleteSyntaxBuilder())->buildSyntax($this),
            default => $this->sql,
        };
    }

    /**
     * Get the SELECT column expressions array.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getSelectColumnsArray(): array
    {
        return $this->selectColumns;
    }

    /**
     * Get the GROUP BY column expressions array.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getGroupByArray(): array
    {
        return $this->groupby;
    }

    /**
     * Get the ORDER BY expressions array.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getOrderByArray(): array
    {
        return $this->orderby;
    }

    /**
     * Get the LIMIT fetch length.
     *
     * @return int
     *
     * @internal Used by syntax builders
     */
    public function getFetchLength(): int
    {
        return $this->fetchLength;
    }

    /**
     * Get the LIMIT offset position.
     *
     * @return int
     *
     * @internal Used by syntax builders
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Get the resolved table name (with prefix).
     *
     * @return string
     *
     * @internal Used by syntax builders
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the column names for INSERT/DELETE.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getColumnsArray(): array
    {
        return $this->columns;
    }

    /**
     * Get the parsed update expressions.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getUpdateExpressions(): array
    {
        return $this->updateSyntax;
    }

    /**
     * Get the ON DUPLICATE KEY columns for upsert.
     *
     * @return array
     *
     * @internal Used by syntax builders
     */
    public function getOnDuplicateKey(): array
    {
        return $this->onDuplicateKey;
    }

    /**
     * Get the raw type string for INSERT vs REPLACE distinction.
     *
     * @return string
     *
     * @internal Used by InsertSyntaxBuilder
     */
    public function getRawType(): string
    {
        return $this->type->value ?? '';
    }

    /**
     * Convert a column's assigned parameter value into its SQL literal representation.
     * Handles NULL, boolean, array (JSON), string, and numeric values.
     *
     * @param string $column The parameter/column name to look up
     *
     * @return string The SQL literal value
     */
    public function getValueAsStatement(string $column): string
    {
        $value = $this->getValue($column);
        if ($value === null) {
            return 'NULL';
        }

        if (\is_bool($value)) {
            return (string) (int) $value;
        }

        if (\is_array($value)) {
            $value = \json_encode($value);
        }

        if (\is_scalar($value)) {
            if (\is_string($value)) {
                // Use PDO::quote() for safe SQL escaping instead of addslashes()
                $adapter = $this->database->getDBAdapter();
                return $adapter->quote($value);
            }
            return (string) $value;
        }

        throw new QueryException('Unsupported value type ' . \get_debug_type($value) . ' for column `' . $column . '` in SQL statement.');
    }

    /**
     * Get the assigned parameter value by given name.
     * Returns null if the parameter doesn't exist or is explicitly null.
     *
     * @param string $name The parameter name
     *
     * @return mixed|null The parameter value or null
     */
    public function getValue(string $name): mixed
    {
        return (!isset($this->parameters[$name])) ? null : $this->parameters[$name];
    }

    /**
     * Merge additional parameters into the existing parameter set.
     *
     * @param array $parameters Parameters to merge
     *
     * @internal Used by StatementExecutor
     */
    public function mergeParameters(array $parameters): void
    {
        $this->parameters = \array_merge($this->parameters, $parameters);
    }

    /**
     * Get the StatementExecutor instance for direct execution access.
     *
     * @return StatementExecutor
     */
    public function getExecutor(): StatementExecutor
    {
        return $this->executor;
    }

    /**
     * Get the LazyResultSet instance for direct result processing access.
     *
     * @return LazyResultSet
     */
    public function getResultSet(): LazyResultSet
    {
        return $this->resultSet;
    }

    /**
     * Set the GROUP BY clause from a comma-separated list of column expressions.
     * Each column name is standardized (backtick-quoted if bare identifier).
     *
     * @param string $syntax Comma-separated column names for grouping
     *
     * @return $this
     */
    public function group(string $syntax): self
    {
        $clips = \preg_split('/\s*,\s*/', $syntax);

        // Standardize each column name and add to GROUP BY list
        $this->groupby = [];
        foreach ($clips as &$column) {
            $column = self::standardizeColumn($column);
            $this->groupby[] = \trim($column);
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
     *
     * @throws Error
     */
    public function insert(string $tableName, array $columns, array $duplicateKeys = []): self
    {
        $this->type = StatementType::Insert;
        $tableName = \trim($tableName);
        if (!$tableName) {
            throw new QueryException('The table name cannot be empty.');
        }
        $this->tableName = $this->getPrefix() . $tableName;

        // Validate and normalize column names, stripping backticks from quoted names
        foreach ($columns as $index => &$column) {
            $column = \trim($column);
            if (\preg_match('/^(?:`(?:\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)$/', $column)) {
                $column = \trim($column, '`');
            } else {
                unset($columns[$index]);
            }
        }

        $this->columns = $columns;
        $this->onDuplicateKey = \array_values($duplicateKeys);

        return $this;
    }

    /**
     * Get the database table name prefix from the parent Database instance.
     *
     * @return string The configured table prefix, or empty string
     */
    public function getPrefix(): string
    {
        return $this->database->getPrefix();
    }

    /**
     * Execute the statement, fetch the first row, and optionally apply the parser callback.
     * If the parser is set to 'once' mode, it will be cleared after this call.
     *
     * @param array $parameters Optional parameters to merge into the statement
     *
     * @return mixed The first result row (possibly transformed by parser), or false/null
     *
     * @throws Throwable
     */
    public function lazy(array $parameters = []): mixed
    {
        return $this->resultSet->lazy($parameters);
    }

    /**
     * Execute the statement and return the Query instance for row-by-row fetching.
     * Merges any provided parameters with existing ones before execution.
     *
     * @param array $parameters Optional parameters to merge before execution
     *
     * @return Query The query result wrapper for fetching rows
     *
     * @throws Throwable If execution fails
     */
    public function query(array $parameters = []): Query
    {
        return $this->executor->query($parameters);
    }

    /**
     * Create a database view from this SELECT statement.
     *
     * @param string $viewTableName The name for the new view
     * @param array $parameters Optional parameters to merge into the statement
     *
     * @return bool True if the view was created successfully
     *
     * @throws Error
     * @throws Throwable
     */
    public function createViewTable(string $viewTableName, array $parameters = []): bool
    {
        return $this->executor->createViewTable($viewTableName, $parameters);
    }

    /**
     * Get the collected values for a specific column after executing a query with collect().
     * Returns the accumulated values gathered during lazyGroup() execution.
     *
     * @param string $name The column name that was collected
     *
     * @return array|null The collected values, or null if the column was not collected
     */
    public function getCollection(string $name): ?array
    {
        return $this->resultSet->getCollection($name);
    }

    /**
     * Execute the statement and return all results, optionally grouped by a column.
     * Supports stacking (multiple rows per group key) and collecting column values.
     *
     * @param array $parameters An array of parameter values to merge
     * @param string $column Column name to use as array key for grouping results
     * @param bool $stackable When true, groups with the same key collect into arrays
     * @param string $stackColumn When stacking, use this column as the sub-key within each group
     *
     * @return array The result set, optionally grouped and keyed
     *
     * @throws Throwable
     */
    public function &lazyGroup(array $parameters = [], string $column = '', bool $stackable = false, string $stackColumn = ''): array
    {
        return $this->resultSet->lazyGroup($parameters, $column, $stackable, $stackColumn);
    }

    /**
     * Execute the statement and build a key-value pair array from two columns.
     *
     * @param string $keyColumn The column to use as array keys
     * @param string $valueColumn The column to use as array values
     * @param array $parameters Optional parameters to merge
     *
     * @return array Associative array of key => value pairs
     *
     * @throws Error If column names are empty or not found in results
     * @throws Throwable
     */
    public function lazyKeyValuePair(string $keyColumn, string $valueColumn, array $parameters = []): array
    {
        return $this->resultSet->lazyKeyValuePair($keyColumn, $valueColumn, $parameters);
    }

    /**
     * Set the LIMIT/OFFSET for query result pagination.
     * When fetchLength is 0, position is treated as the total row limit.
     * When fetchLength > 0, position is the offset and fetchLength is the count.
     *
     * @param int $position The starting offset, or total limit when fetchLength is 0
     * @param int $fetchLength The number of rows to fetch (0 = use position as limit)
     *
     * @return Statement
     */
    public function limit(int $position, int $fetchLength = 0): self
    {
        $this->fetchLength = $fetchLength;
        $this->position = $position;

        return $this;
    }

    /**
     * Set the ORDER BY clause using Simple Syntax.
     * Supports optional direction prefixes: '<' for ASC, '>' for DESC.
     *
     * @param string $syntax Comma-separated column expressions with optional direction prefix
     *
     * @return $this
     *
     * @throws Error|Throwable
     */
    public function order(string $syntax): self
    {
        $clips = SimpleSyntax::parseSyntax($syntax, ',', '', null, true);

        $this->orderby = [];
        foreach ($clips as $column) {
            $ordering = '';
            if (\preg_match('/^([<>])?(.+)/', $column, $matches)) {
                $ordering = $matches[1];
                $column = $matches[2];
            }

            $standardizedColumn = self::standardizeColumn($column);
            if ($standardizedColumn) {
                $standardizedColumn .= ('>' === $ordering) ? ' DESC' : ' ASC';
                $this->orderby[] = $standardizedColumn;
            } else {
                $orderSyntax = new WhereSyntax($this);
                $this->orderby[] = [
                    'syntax' => $orderSyntax,
                    'column' => $column,
                    'ordering' => ('>' === $ordering) ? 'DESC' : 'ASC',
                ];
            }
        }

        return $this;
    }

    /**
     * Register columns to collect values from during query execution.
     * During lazyGroup() execution, values from these columns are accumulated
     * into the collections array, optionally ensuring uniqueness.
     *
     * @param string|array $columns Column name or array of column => isUnique pairs
     * @param bool|null $isUnique When true, duplicate values are eliminated (uses array keys)
     *
     * @return $this
     */
    public function collect(string|array $columns, ?bool $isUnique = false): static
    {
        $this->resultSet->collect($columns, $isUnique);

        return $this;
    }

    /**
     * Set the SELECT column expressions for the query.
     * Columns are split by commas while preserving quoted strings.
     * Bare identifiers are automatically wrapped in backticks.
     *
     * @param string $columns Comma-separated column expressions for the SELECT clause
     *
     * @return $this
     */
    public function select(string $columns): self
    {
        // Split by commas while preserving quoted strings (single, double, or backtick-quoted)
        $this->selectColumns = \preg_split('/(?:(?<q>[\'"`])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $columns);

        foreach ($this->selectColumns as &$column) {
            $column = (\preg_match('/^\w+$/', $column)) ? '`' . $column . '`' : $column;
        }

        return $this;
    }

    /**
     * Set a callback to transform each fetched row during lazy() and lazyGroup() execution.
     * When $once is true, the parser is automatically cleared after the first query execution.
     *
     * @param callable $closure The row transformation callback, receives row array by reference
     * @param bool $once When true, the parser is removed after a single use
     *
     * @return Statement
     */
    public function setParser(callable $closure, bool $once = false): self
    {
        $this->resultSet->setParser($closure, $once);

        return $this;
    }

    /**
     * Set the Statement as a DELETE statement. Automatically builds a WHERE clause
     * from the parameters if no explicit WHERE syntax is provided.
     *
     * @param string $tableName The table to delete from
     * @param array $parameters Column => value pairs for condition building
     * @param string $whereSyntax Optional explicit Where Simple Syntax
     *
     * @return $this
     *
     * @throws Error
     */
    public function delete(string $tableName, array $parameters = [], string $whereSyntax = ''): static
    {
        $this->type = StatementType::Delete;
        $tableName = \trim($tableName);
        if (!$tableName) {
            throw new QueryException('The table name cannot be empty.');
        }
        $this->tableName = $this->getPrefix() . $tableName;

        if (\count($parameters)) {
            $this->columns = \array_keys($parameters);
            $this->whereSyntax = new WhereSyntax($this);

            // Auto-build WHERE conditions: use |= (IN) for arrays, = for scalars
            if (!$whereSyntax) {
                $conditions = [];
                foreach ($parameters as $key => $value) {
                    if (\is_array($value)) {
                        $conditions[] = $key . '|=?';
                    } else {
                        $conditions[] = $key . '=?';
                    }
                }
                $whereSyntax = \implode(',', $conditions);
            }
            $this->whereSyntax->parseSyntax($whereSyntax);
            $this->assign($parameters);
        }

        return $this;
    }

    /**
     * Set the Statement as an UPDATE statement with Update Simple Syntax.
     * Parses expressions like "column=value", "column+=value", "column++", etc.
     *
     * @param string $tableName The table to update
     * @param array $updateSyntax Array of Update Simple Syntax strings
     *
     * @return $this
     *
     * @throws Error If no valid update syntax provided
     */
    public function update(string $tableName, array $updateSyntax): self
    {
        $this->type = StatementType::Update;
        $tableName = \trim($tableName);
        if (!$tableName) {
            throw new QueryException('The table name cannot be empty.');
        }
        $this->tableName = $this->getPrefix() . $tableName;

        $this->updateSyntax = [];
        foreach ($updateSyntax as &$syntax) {
            $syntax = \trim($syntax);
            // Match update syntax: column(++|--), column(op)=value, or bare column name
            // Handles backtick-quoted columns, shorthand increment/decrement, and compound operators (+= -= *= /= &=)
            if (\preg_match('/(?:(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(?<w>\[)(?:\\\.(*SKIP)|[^\[\]])*]|\\\.)(*SKIP)(*FAIL)|^(`(?:\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)(?:(\+\+|--)|\s*([+\-*\/&]?=)\s*(.+))?$/', $syntax, $matches)) {
                $matches[3] = \trim($matches[3], '`');

                if (isset($matches[4]) && $matches[4]) {
                    // Shorthand increment/decrement: column++ or column--
                    $this->updateSyntax[$matches[3]] = ['`' . $matches[3] . '`', ('++' === $matches[4]) ? '+' : '-', 1];
                } elseif (isset($matches[6])) {
                    // Assignment with optional operator: column=value or column+=value
                    $shortenSyntax = [];
                    if (2 === \strlen($matches[5])) {
                        $operator = $matches[5][0];
                        $shortenSyntax = ['`' . $matches[3] . '`', $operator];
                    }
                    $this->updateSyntax[$matches[3]] = \array_merge($shortenSyntax, $this->parseSyntax($matches[6]));
                } else {
                    // Simple assignment: column (no operator) defaults to parameter reference :column
                    $this->updateSyntax[$matches[3]] = [':' . $matches[3]];
                }
            }
        }

        if (empty($this->updateSyntax)) {
            throw new QueryException('There is no update syntax will be executed.');
        }

        return $this;
    }

    /**
     * Define the WHERE clause using Where Simple Syntax.
     * Creates or reuses a WhereSyntax instance and parses the provided syntax.
     * Accepts either a syntax string or a callable that returns/modifies the syntax.
     *
     * @param string|callable $syntax The Where Simple Syntax string or callable
     *
     * @return Statement
     *
     * @throws Error If the syntax is invalid
     */
    public function where(string|callable $syntax): self
    {
        if (!$this->whereSyntax) {
            $this->whereSyntax = new WhereSyntax($this);
        }

        if (\is_callable($syntax)) {
            $syntax = $syntax(...);
        }

        $this->whereSyntax->parseSyntax($syntax);

        return $this;
    }

    /**
     * Get the statement syntax type.
     *
     * @return string One of: 'sql', 'select', 'insert', 'replace', 'update', 'delete', or empty string
     */
    public function getType(): string
    {
        return $this->type->value ?? '';
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
        $syntax = \trim($syntax);

        // Delegate to SimpleSyntax parser with arithmetic and concatenation operators
        return SimpleSyntax::parseSyntax($syntax, '+-*/&');
    }
}
