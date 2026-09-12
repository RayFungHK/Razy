<?php

declare(strict_types=1);

namespace Razy\ORM;

use InvalidArgumentException;

/**
 * Declarative data contract for a Model — the skeleton the Model reads and
 * the DDL compiler writes from (architecture/ORM-CONTRACT-PACKS.md §6.1).
 *
 * A Contract is a PURE VALUE OBJECT: it performs no IO and touches no
 * database. Field definitions reuse the EXISTING Column simple-syntax
 * (`type(int),nullable,reference(teams,id)` — parsed by Database\Column.php:92,
 * FK DDL emitted via :585-600), so there is no new schema DSL: the same
 * grammar powers runtime casts, validation, and CREATE TABLE.
 *
 * JSON sub-schemas are ANNOTATIONS, not extra columns: `profile.city` style
 * addresses exist for visibility/verification; the stored column stays one
 * `json` column (DDL unchanged — stated honestly).
 *
 * Relations are declared (not inferred) so cross-model reads stop being
 * hand-typed joins; DDL foreign keys still come from the FIELD-level
 * `reference(...)` flags — a relation documents intent, the field emits
 * constraint SQL. 1:1 contract↔DB mapping per the user's design brief.
 *
 * A Model without a Contract behaves exactly as before (BC, §6.5).
 */
final class Contract
{
    /** Relation kinds recognised by the ORM (through-type closes the §5.2 gap). */
    public const RELATION_KINDS = ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasManyThrough'];

    /** @var array<string, array{column: string, json: array<string, string>}> */
    private readonly array $fields;

    /** @var list<array{kind: string, target: string, fk?: string, pivot?: string, through?: string, as: string}> */
    private readonly array $relations;

    /** @var list<array{columns: list<string>, name: string}> */
    private readonly array $indexes;

    /**
     * @param array{
     *     table: string,
     *     primary_key?: string,
     *     timestamps?: bool,
     *     fields: array<string, string|array{column?: string, type?: string, json?: array<string, string>}>,
     *     relations?: list<array{kind: string, target: string, as: string, fk?: string, pivot?: string, through?: string}>,
     *     indexes?: list<array{columns: list<string>, name?: string}>
     * } $definition
     */
    private function __construct(
        private readonly array $definition,
        private readonly string $table,
        private readonly string $primaryKey,
        private readonly bool $timestamps,
    ) {
        $this->fields = $this->normaliseFields($definition['fields']);
        $this->relations = $this->normaliseRelations($definition['relations'] ?? []);
        $this->indexes = $this->normaliseIndexes($definition['indexes'] ?? []);
    }

    /**
     * Build + validate a Contract from its declarative array.
     *
     * Validation is fail-loud at DEFINE time (config error is a programmer
     * error, not a runtime surprise). Column-syntax CONTENTS are validated by
     * the Column grammar itself at compile time — only the shape is checked
     * here, so the single grammar stays the single validator.
     *
     * @param array<string, mixed> $definition
     */
    public static function define(array $definition): self
    {
        foreach (['table', 'fields'] as $required) {
            if (!isset($definition[$required])) {
                throw new InvalidArgumentException("Contract definition requires '{$required}'.");
            }
        }

        $table = (string) $definition['table'];
        if (\preg_match('/^[a-z]\w*$/', $table) !== 1) {
            throw new InvalidArgumentException("Contract table name is invalid: '{$table}'.");
        }

        if (!\is_array($definition['fields']) || $definition['fields'] === []) {
            throw new InvalidArgumentException('Contract fields must be a non-empty map.');
        }

        return new self(
            $definition,
            $table,
            (string) ($definition['primary_key'] ?? 'id'),
            (bool) ($definition['timestamps'] ?? false),
        );
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function hasTimestamps(): bool
    {
        return $this->timestamps;
    }

    /**
     * Field name => ['column' => Column-syntax string, 'json' => sub-field map].
     *
     * @return array<string, array{column: string, json: array<string, string>}>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<array{kind: string, target: string, fk?: string, pivot?: string, through?: string, as: string}>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * @return list<array{columns: list<string>, name: string}>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Names of declared JSON sub-field addresses (e.g. 'profile.city').
     *
     * @return list<string>
     */
    public function getJsonAddresses(): array
    {
        $addresses = [];

        foreach ($this->fields as $name => $field) {
            foreach (\array_keys($field['json']) as $sub) {
                $addresses[] = $name . '.' . $sub;
            }
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, array{column: string, json: array<string, string>}>
     */
    private function normaliseFields(array $raw): array
    {
        $fields = [];

        foreach ($raw as $name => $spec) {
            $name = (string) $name;
            if (\preg_match('/^[a-z]\w*$/', $name) !== 1) {
                throw new InvalidArgumentException("Contract field name is invalid: '{$name}'.");
            }

            $json = [];

            if (\is_string($spec)) {
                $column = $spec;
            } elseif (\is_array($spec)) {
                $column = (string) ($spec['column'] ?? $spec['type'] ?? '');
                $json = (array) ($spec['json'] ?? []);

                foreach ($json as $sub => $subSyntax) {
                    if (\preg_match('/^[a-z]\w*$/', (string) $sub) !== 1) {
                        throw new InvalidArgumentException("Contract JSON sub-field name is invalid: '{$name}.{$sub}'.");
                    }
                    if (!\is_string($subSyntax)) {
                        throw new InvalidArgumentException("Contract JSON sub-field '{$name}.{$sub}' needs a Column-syntax string.");
                    }
                }
            } else {
                throw new InvalidArgumentException("Contract field '{$name}' must be a Column-syntax string or a definition array.");
            }

            if ($column === '') {
                throw new InvalidArgumentException("Contract field '{$name}' has empty Column syntax.");
            }

            $fields[$name] = ['column' => $column, 'json' => $json];
        }

        if (!isset($fields[$this->primaryKey]) && $fields !== []) {
            // The primary key must be a declared field; auto-increment keys
            // are conventionally declared as 'type(int),auto'.
            throw new InvalidArgumentException("Contract primary key '{$this->primaryKey}' is not a declared field.");
        }

        return $fields;
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<array{kind: string, target: string, fk?: string, pivot?: string, through?: string, as: string}>
     */
    private function normaliseRelations(array $raw): array
    {
        $relations = [];

        foreach ($raw as $index => $relation) {
            if (!\is_array($relation)) {
                throw new InvalidArgumentException("Contract relation #{$index} must be an array.");
            }

            $kind = (string) ($relation['kind'] ?? '');
            if (!\in_array($kind, self::RELATION_KINDS, true)) {
                throw new InvalidArgumentException("Contract relation #{$index} has unknown kind '{$kind}'.");
            }

            $target = (string) ($relation['target'] ?? '');
            $as = (string) ($relation['as'] ?? '');
            if ($target === '' || $as === '') {
                throw new InvalidArgumentException("Contract relation #{$index} needs both 'target' and 'as'.");
            }

            if ($kind === 'belongsToMany' && (string) ($relation['pivot'] ?? '') === '') {
                throw new InvalidArgumentException("belongsToMany relation '{$as}' requires a pivot table name.");
            }
            if ($kind === 'hasManyThrough' && (string) ($relation['through'] ?? '') === '') {
                throw new InvalidArgumentException("hasManyThrough relation '{$as}' requires a 'through' model.");
            }

            $normalised = ['kind' => $kind, 'target' => $target, 'as' => $as];
            foreach (['fk', 'pivot', 'through'] as $optional) {
                if (isset($relation[$optional]) && (string) $relation[$optional] !== '') {
                    $normalised[$optional] = (string) $relation[$optional];
                }
            }

            $relations[] = $normalised;
        }

        return $relations;
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<array{columns: list<string>, name: string}>
     */
    private function normaliseIndexes(array $raw): array
    {
        $indexes = [];

        foreach ($raw as $index) {
            $columns = array_values(array_map('strval', (array) ($index['columns'] ?? [])));
            if ($columns === []) {
                throw new InvalidArgumentException('Contract index needs at least one column.');
            }
            foreach ($columns as $column) {
                if (!isset($this->fields[$column])) {
                    throw new InvalidArgumentException("Contract index references undeclared field '{$column}'.");
                }
            }

            $indexes[] = ['columns' => $columns, 'name' => (string) ($index['name'] ?? '')];
        }

        return $indexes;
    }
}
