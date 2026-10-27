<?php

/**
 * razymod/apps — what a field path means where an expression is written.
 *
 * The definition validator implements this per entity (fields, system
 * fields, status attributes, lookups along many-to-one relations). Tests and
 * hosts can use MapScope.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

interface Scope
{
    /**
     * Resolve a field path.
     *
     * @param list<string> $path
     * @param int $at source offset, for errors
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     *                                                                type: a Types name; options: allowed text values (select options,
     *                                                                status states) or null; sql: whether the path can be read in a SQL
     *                                                                predicate (false for computed fields that are not stored)
     *
     * @throws ExpressionError UNKNOWN_FIELD, TYPE or CYCLE
     */
    public function resolve(array $path, int $at): array;
}
