<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use PDO;
use PDOStatement;

/**
 * Collects bound values while a Statement compiles (RZ-02).
 *
 * Statement::compile() opens a binder; while it is active, every value the
 * syntax builders emit becomes a named placeholder (`:rzp0`, `:rzp1`, ...)
 * instead of a quoted literal. Sub-statements compiled inside the same pass
 * share the active binder, so one SQL template carries all values.
 * Placeholder names depend only on the order of values, so the same query
 * shape always yields the same template and the statement pool can reuse it.
 *
 * @license MIT
 */
final class ParameterBinder
{
    /** @var string Prefix of generated placeholder names */
    public const PREFIX = 'rzp';

    /** @var array<int, self> Binders of the compile passes in progress (innermost last) */
    private static array $stack = [];

    /** @var array<string, mixed> Bound values keyed by placeholder name (without colon) */
    private array $params = [];

    /**
     * Open a binder for a compile pass.
     *
     * @return self
     */
    public static function begin(): self
    {
        $binder = new self();
        self::$stack[] = $binder;

        return $binder;
    }

    /**
     * Close the given binder (and any binder opened after it).
     *
     * @param self $binder
     */
    public static function end(self $binder): void
    {
        $index = \array_search($binder, self::$stack, true);
        if (false !== $index) {
            self::$stack = \array_slice(self::$stack, 0, (int) $index);
        }
    }

    /**
     * The binder of the innermost compile pass, or null outside compile().
     *
     * @return self|null
     */
    public static function active(): ?self
    {
        return self::$stack[\count(self::$stack) - 1] ?? null;
    }

    /**
     * Bind compiled parameters on a prepared PDO statement with driver-aware types.
     * Booleans use PDO::PARAM_BOOL on Postgres and integer 0/1 elsewhere (MySQL and
     * SQLite store booleans as integers, and PDO MySQL mangles PARAM_BOOL false).
     *
     * @param PDOStatement $statement
     * @param array<string, mixed> $params
     * @param string|null $driverType 'mysql', 'pgsql', 'sqlite' or null
     */
    public static function bindAll(PDOStatement $statement, array $params, ?string $driverType): void
    {
        foreach ($params as $name => $value) {
            if (\is_bool($value)) {
                if ('pgsql' === $driverType) {
                    $statement->bindValue(':' . $name, $value, PDO::PARAM_BOOL);
                } else {
                    $statement->bindValue(':' . $name, (int) $value, PDO::PARAM_INT);
                }
            } elseif (null === $value) {
                $statement->bindValue(':' . $name, null, PDO::PARAM_NULL);
            } elseif (\is_int($value)) {
                $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue(':' . $name, (string) $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * Bind a value and return its placeholder.
     *
     * @param mixed $value
     *
     * @return string The placeholder, e.g. ":rzp0"
     */
    public function add(mixed $value): string
    {
        $name = self::PREFIX . \count($this->params);
        $this->params[$name] = $value;

        return ':' . $name;
    }

    /**
     * The bound values whose placeholder appears in the SQL. Values bound by a
     * builder branch that was later discarded are dropped, because PDO rejects
     * values without a matching placeholder.
     *
     * @param string $sql The compiled SQL template
     *
     * @return array<string, mixed>
     */
    public function paramsUsedIn(string $sql): array
    {
        if (!$this->params) {
            return [];
        }
        \preg_match_all('/:(' . self::PREFIX . '\d+)(?!\w)/', $sql, $matches);

        return \array_intersect_key($this->params, \array_flip($matches[1]));
    }
}
