<?php

/**
 * razymod/apps — entities and workflows of a package and its dependencies,
 * and what a field path means in an entity (the expression Scope).
 *
 * Entity references are "<entity>" (this package) or "<package>.<entity>"
 * (a package listed in depends_on). A lookup follows many-to-one relations
 * only, at most lookup_depth hops. A computed field's type is its
 * expression's type, typed on demand with a guard against loops.
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

use razymod\apps\Caps;
use razymod\apps\Expression\ExpressionError;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\Scope;
use razymod\apps\Expression\TypeChecker;

final class Catalog
{
    /** @var array<string, array<string, array<string, mixed>>> package => entity code => entity */
    private array $entities = [];

    /** @var array<string, array<string, array<string, mixed>>> package => workflow code => workflow */
    private array $workflows = [];

    /** @var array<string, array{type: string, options: ?list<string>, sql: bool}> */
    private array $computed = [];

    /** @var array<string, true> */
    private array $typing = [];

    /**
     * @param array<string, mixed> $def the package being checked
     * @param array<mixed> $dependencies package code => definition (caller-supplied, checked here)
     */
    public function __construct(private readonly string $package, array $def, array $dependencies, private readonly Caps $caps)
    {
        $this->index($package, $def);
        foreach ($dependencies as $code => $dep) {
            if (\is_string($code) && $code !== $package && \is_array($dep)) {
                $this->index($code, $dep);
            }
        }
    }

    /**
     * A definition value as a string, '' when it is anything else. Definitions
     * come from authors and AI: an array where a code belongs must not reach
     * a (string) cast.
     */
    public static function str(mixed $v): string
    {
        return \is_string($v) ? $v : '';
    }

    public function package(): string
    {
        return $this->package;
    }

    public function hasPackage(string $code): bool
    {
        return isset($this->entities[$code]);
    }

    /**
     * Split "<entity>" or "<package>.<entity>" into [package, entity]. An
     * unqualified ref belongs to the package that declares it ($context,
     * default: the package being checked), so a relation inside a
     * dependency resolves inside that dependency.
     *
     * @return array{0: string, 1: string}
     */
    public function split(string $ref, ?string $context = null): array
    {
        $dot = \strrpos($ref, '.');
        if ($dot === false) {
            return [$context ?? $this->package, $ref];
        }

        return [\substr($ref, 0, $dot), \substr($ref, $dot + 1)];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function entity(string $package, string $code): ?array
    {
        return $this->entities[$package][$code] ?? null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function workflow(string $package, string $code): ?array
    {
        return $this->workflows[$package][$code] ?? null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function field(string $package, string $entity, string $code): ?array
    {
        $fields = $this->entity($package, $entity)['fields'] ?? null;
        foreach (\is_array($fields) ? $fields : [] as $field) {
            if (\is_array($field) && ($field['code'] ?? null) === $code) {
                return $field;
            }
        }

        return null;
    }

    public function qualified(string $package, string $entity): string
    {
        return $package . '.' . $entity;
    }

    /**
     * The expression Scope of one entity.
     */
    public function scope(string $package, string $entity): Scope
    {
        return new class($this, $package, $entity) implements Scope {
            public function __construct(private readonly Catalog $catalog, private readonly string $package, private readonly string $entity)
            {
            }

            public function resolve(array $path, int $at): array
            {
                return $this->catalog->resolve($this->package, $this->entity, $path, $at);
            }
        };
    }

    /**
     * @param list<string> $path
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     */
    public function resolve(string $package, string $entity, array $path, int $at, int $hops = 0): array
    {
        $head = $path[0];
        $rest = \array_slice($path, 1);
        $dotted = \implode('.', $path);

        if (isset(Schema::SYSTEM_COLUMNS[$head]) && $this->field($package, $entity, $head) === null) {
            if ($head === 'number' && !\is_array($this->entity($package, $entity)['numbering'] ?? null)) {
                throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "'{$entity}' has no numbering, so no 'number'", $at);
            }
            if ($rest !== []) {
                throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Unknown field '{$dotted}'", $at);
            }

            return ['type' => Schema::SYSTEM_COLUMNS[$head], 'options' => null, 'sql' => true];
        }

        $field = $this->field($package, $entity, $head);
        if ($field === null) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Unknown field '{$head}' on '{$entity}'", $at);
        }
        $type = self::str($field['type'] ?? null);

        if ($type === 'status') {
            return $this->status($package, $field, $rest, $dotted, $at);
        }
        if ($type === 'relation') {
            $card = $field['cardinality'] ?? null;
            if ($card !== 'many_to_one') {
                throw new ExpressionError(
                    ExpressionError::TYPE,
                    "'{$head}' is a to-many relation; use a rollup field to read it",
                    $at,
                );
            }
            [$tp, $te] = $this->split(self::str($field['to'] ?? null), $package);
            if ($rest === []) {
                // the relation value itself needs no target definition
                return ['type' => 'ref:' . $this->qualified($tp, $te), 'options' => null, 'sql' => true];
            }
            if ($this->entity($tp, $te) === null) {
                throw new ExpressionError(
                    ExpressionError::UNKNOWN_FIELD,
                    "'{$head}' points to '{$tp}.{$te}', which is not available to check",
                    $at,
                );
            }
            if ($hops + 1 > $this->caps->get('lookup_depth')) {
                throw new ExpressionError(
                    ExpressionError::UNKNOWN_FIELD,
                    "'{$dotted}' follows more than " . $this->caps->get('lookup_depth') . ' relations',
                    $at,
                );
            }

            return $this->resolve($tp, $te, $rest, $at, $hops + 1);
        }
        if ($rest !== []) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Unknown field '{$dotted}'", $at);
        }

        return match ($type) {
            'text', 'long_text', 'rich_text', 'email', 'phone', 'url', 'sequence' => $this->info('text'),
            'select' => $this->info('text', $this->options($field)),
            'multi_select' => $this->info('list:text', $this->options($field)),
            'integer', 'decimal', 'money', 'percent', 'duration' => $this->info('number'),
            'boolean' => $this->info('boolean'),
            'date' => $this->info('date'),
            'datetime', 'created_at', 'updated_at' => $this->info('datetime'),
            'user', 'created_by' => $this->info('user'),
            'group' => $this->info('group'),
            'department' => $this->info('department'),
            'file', 'files', 'image' => $this->info('opaque', null, false),
            'rollup' => $this->rollup($package, $entity, $field, $at),
            'computed' => $this->computedType($package, $entity, $field, $at),
            default => throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Field '{$head}' has no usable type", $at),
        };
    }

    /**
     * Option keys of a select / multi_select field.
     *
     * @param array<string, mixed> $field
     *
     * @return list<string>
     */
    public function options(array $field): array
    {
        $out = [];
        foreach (\is_array($field['options'] ?? null) ? $field['options'] : [] as $opt) {
            $key = \is_array($opt) ? ($opt['key'] ?? null) : $opt;
            if (\is_string($key)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * State codes of a workflow.
     *
     * @param array<string, mixed> $wf
     *
     * @return list<string>
     */
    public function states(array $wf): array
    {
        $out = [];
        foreach (\is_array($wf['states'] ?? null) ? $wf['states'] : [] as $state) {
            if (\is_array($state) && \is_string($state['code'] ?? null)) {
                $out[] = $state['code'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function index(string $package, array $def): void
    {
        $this->entities[$package] = [];
        $this->workflows[$package] = [];
        foreach (\is_array($def['entities'] ?? null) ? $def['entities'] : [] as $entity) {
            if (\is_array($entity) && \is_string($entity['code'] ?? null) && !isset($this->entities[$package][$entity['code']])) {
                $this->entities[$package][$entity['code']] = $entity;
            }
        }
        foreach (\is_array($def['workflows'] ?? null) ? $def['workflows'] : [] as $wf) {
            if (\is_array($wf) && \is_string($wf['code'] ?? null) && !isset($this->workflows[$package][$wf['code']])) {
                $this->workflows[$package][$wf['code']] = $wf;
            }
        }
    }

    /**
     * @param ?list<string> $options
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     */
    private function info(string $type, ?array $options = null, bool $sql = true): array
    {
        return ['type' => $type, 'options' => $options, 'sql' => $sql];
    }

    /**
     * A status field: the state code itself, its category, or a state prop.
     *
     * @param array<string, mixed> $field
     * @param list<string> $rest
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     */
    private function status(string $package, array $field, array $rest, string $dotted, int $at): array
    {
        $wf = $this->workflow($package, self::str($field['workflow'] ?? null));
        if ($wf === null) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "'{$dotted}': the status workflow is missing", $at);
        }
        if ($rest === []) {
            return $this->info('status', $this->states($wf));
        }
        if (\count($rest) > 1) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Unknown field '{$dotted}'", $at);
        }
        if ($rest[0] === 'category') {
            return $this->info('text', Schema::STATE_CATEGORIES);
        }
        $type = null;
        foreach (\is_array($wf['states'] ?? null) ? $wf['states'] : [] as $state) {
            if (!\is_array($state) || !\is_array($state['props'] ?? null) || !\array_key_exists($rest[0], $state['props'])) {
                continue;
            }
            $v = $state['props'][$rest[0]];
            $t = (\is_int($v) || \is_float($v)) ? 'number' : (\is_bool($v) ? 'boolean' : (\is_string($v) ? 'text' : null));
            if ($t === null || ($type !== null && $type !== $t)) {
                throw new ExpressionError(ExpressionError::TYPE, "State property '{$dotted}' mixes types", $at);
            }
            $type = $t;
        }
        if ($type === null) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "No state of this workflow has '{$rest[0]}'", $at);
        }

        return $this->info($type);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     */
    private function rollup(string $package, string $entity, array $field, int $at): array
    {
        $op = $field['op'] ?? null;
        if ($op === 'count' || $op === 'sum') {
            return $this->info('number');
        }
        $rel = $this->field($package, $entity, self::str($field['relation'] ?? null));
        if ($rel === null) {
            throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, 'Rollup relation is missing', $at);
        }
        [$tp, $te] = $this->split(self::str($rel['to'] ?? null), $package);
        $target = $this->resolve($tp, $te, [self::str($field['field'] ?? null)], $at, $this->caps->get('lookup_depth'));

        return $this->info($target['type']);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array{type: string, options: ?list<string>, sql: bool}
     */
    private function computedType(string $package, string $entity, array $field, int $at): array
    {
        $key = $package . '.' . $entity . '.' . self::str($field['code'] ?? null);
        if (isset($this->computed[$key])) {
            return $this->computed[$key];
        }
        if (isset($this->typing[$key])) {
            throw new ExpressionError(ExpressionError::CYCLE, 'Computed field ' . self::str($field['code'] ?? null) . ' refers to itself', $at);
        }
        $src = $field['expr'] ?? null;
        if (!\is_string($src)) {
            throw new ExpressionError(ExpressionError::TYPE, 'Computed field ' . self::str($field['code'] ?? null) . ' has no expression', $at);
        }
        $this->typing[$key] = true;
        try {
            $ast = (new Parser($this->caps))->parse($src);
            $typed = (new TypeChecker($this->caps))->check($ast, $this->scope($package, $entity), 'scalar');
        } catch (ExpressionError $e) {
            if ($e->getErrorCode() === ExpressionError::CYCLE) {
                throw $e;
            }

            throw new ExpressionError(
                ExpressionError::TYPE,
                'Computed field ' . self::str($field['code'] ?? null) . ' does not type-check',
                $at,
            );
        } finally {
            unset($this->typing[$key]);
        }
        $info = $this->info((string) $typed['t'], null, ($field['stored'] ?? false) === true);
        $this->computed[$key] = $info;

        return $info;
    }
}
