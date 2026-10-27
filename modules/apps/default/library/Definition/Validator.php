<?php

/**
 * razymod/apps — validates a definition against schema v1 (manual/15-razy-apps.md §3).
 *
 * Order: size and encoding, then structure (required keys, no unknown keys,
 * types, closed enums), then codes (pattern, reserved words, duplicates),
 * element ids (ULID, unique, kind stable against the previous version),
 * references (entities, dependencies, fields, workflows, roles, states),
 * expressions (parse + type-check; filters and row rules must be SQL-able),
 * views, workflow graphs, i18n keys (`en` always, `zh-HK` for system
 * templates) and caps. Every finding carries a JSON Pointer; nothing is
 * trimmed or repaired here. A definition is accepted only with zero errors.
 *
 * Options:
 *   dependencies  package code => definition, for relations and lookups into
 *                 packages named in depends_on
 *   previous      the previous version's definition (element id stability)
 *   system        true for system templates (zh-HK labels required)
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

use InvalidArgumentException;
use razymod\apps\Caps;
use razymod\apps\Expression\ExpressionError;
use razymod\apps\Expression\MapScope;
use razymod\apps\Expression\Parser;
use razymod\apps\Expression\Scope;
use razymod\apps\Expression\TypeChecker;
use razymod\apps\InputGuard;

final class Validator
{
    private const TOP = ['apps_schema', 'code', 'version', 'label', 'description', 'requires_engine', 'depends_on',
        'lineage', 'roles', 'menu', 'entities', 'workflows', 'permissions', 'i18n'];

    /** @var list<Issue> */
    private array $issues = [];

    /** @var array<string, string> i18n key => pointer of first use */
    private array $keys = [];

    /** @var array<string, array{src: string, ast: array<string, mixed>, site: string}> */
    private array $expressions = [];

    private Catalog $catalog;

    private string $package = '';

    /** @var array<string, true> */
    private array $roles = [];

    public function __construct(private readonly Caps $caps = new Caps())
    {
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $options dependencies (package code => definition), previous (definition), system (bool); caller-supplied, checked here
     */
    public function validate(array $def, array $options = []): ValidationResult
    {
        $this->issues = [];
        $this->keys = [];
        $this->expressions = [];
        $this->roles = [];

        if (!InputGuard::within($def, $this->caps->get('definition_bytes'))) {
            $this->error('', 'cap.definition_bytes', 'Definition is over the definition_bytes cap (' . $this->caps->get('definition_bytes') . ' bytes) or nested too deep');

            return $this->result();
        }
        try {
            $bytes = \strlen(Canonical::json($def));
            if ($bytes > $this->caps->get('definition_bytes')) {
                $this->error('', 'cap.definition_bytes', "Definition is {$bytes} bytes; the cap is " . $this->caps->get('definition_bytes'));

                return $this->result();
            }
        } catch (InvalidArgumentException $e) {
            $this->error('', 'definition.encoding', $e->getMessage());

            return $this->result();
        }

        $this->keysOf($def, '', ['apps_schema', 'code', 'version', 'entities', 'i18n'], self::TOP);
        if (($def['apps_schema'] ?? null) !== Schema::VERSION) {
            $this->error('/apps_schema', 'schema.version', 'apps_schema must be ' . Schema::VERSION);
        }
        $this->package = \is_string($def['code'] ?? null) ? $def['code'] : '';
        if (isset($def['code']) && (!\is_string($def['code']) || \strlen($def['code']) > 40
            || \preg_match(Schema::PACKAGE_CODE, $def['code']) !== 1)) {
            $this->error('/code', 'code.pattern', 'Package code must be lower-case words joined by hyphens, at most 40 characters');
        }
        if (isset($def['version']) && (!\is_string($def['version']) || \preg_match(Schema::SEMVER, $def['version']) !== 1)) {
            $this->error('/version', 'schema.version_format', 'version must be semver (1.2.0)');
        }
        if (isset($def['requires_engine']) && !$this->isConstraint($def['requires_engine'])) {
            $this->error('/requires_engine', 'schema.constraint', 'requires_engine must be a version constraint (">=1.0 <2")');
        }
        $this->label($def, '', 'label', 'app.label');
        $this->optionalKey($def, '', 'description');

        $deps = $this->dependsOn($def['depends_on'] ?? null);
        $this->lineage($def['lineage'] ?? null);
        $this->rolesList($def['roles'] ?? null);

        // caller-supplied context is capped like the definition itself
        $dependencies = [];
        $supplied = \is_array($options['dependencies'] ?? null) ? $options['dependencies'] : [];
        if (\count($supplied) > $this->caps->get('dependencies_per_app')) {
            $this->error('/depends_on', 'cap.dependencies_per_app', \count($supplied) . ' dependency definitions supplied; the cap is ' . $this->caps->get('dependencies_per_app'));
            $supplied = [];
        }
        foreach ($supplied as $code => $depDef) {
            if (!isset($deps[$code]) || !\is_array($depDef)) {
                continue;
            }
            if (!InputGuard::within($depDef, $this->caps->get('definition_bytes'))) {
                $this->error('/depends_on/' . $this->escape((string) $code), 'cap.definition_bytes', "The supplied definition of '{$code}' is over the definition_bytes cap");
                continue;
            }
            $dependencies[$code] = $depDef;
        }
        $previous = $options['previous'] ?? null;
        if (\is_array($previous) && !InputGuard::within($previous, $this->caps->get('definition_bytes'))) {
            $this->error('/', 'cap.definition_bytes', 'The previous version is over the definition_bytes cap');
            $previous = null;
        }
        $this->catalog = new Catalog($this->package, $def, $dependencies, $this->caps);

        $entities = $this->listOf($def, '', 'entities', 'entities_per_app', true);
        $entityCodes = $this->uniqueCodes($entities, '/entities', 'entity', true);
        $workflows = $this->listOf($def, '', 'workflows', 'workflows_per_app');
        $this->uniqueCodes($workflows, '/workflows', 'workflow', false);

        $this->ids($def, $previous);

        foreach ($entities as $i => $entity) {
            $this->entity($entity, "/entities/{$i}", $deps);
        }
        foreach ($workflows as $i => $wf) {
            $this->workflow($wf, "/workflows/{$i}", $entityCodes);
        }
        $this->menu($def['menu'] ?? null, $entities);
        $this->permissions($def['permissions'] ?? null, $entityCodes);
        $this->i18n($def['i18n'] ?? null, ($options['system'] ?? false) === true);

        return $this->result();
    }

    private function result(): ValidationResult
    {
        return new ValidationResult($this->issues, $this->expressions, \array_keys($this->keys));
    }

    // ── top level ──────────────────────────────────────────────────

    /**
     * @return array<string, true> declared dependency package codes
     */
    private function dependsOn(mixed $deps): array
    {
        if ($deps === null) {
            return [];
        }
        if (!\is_array($deps) || ($deps !== [] && \array_is_list($deps))) {
            $this->error('/depends_on', 'schema.type', 'depends_on must be a map of package code to version constraint');

            return [];
        }
        $out = [];
        foreach ($deps as $code => $constraint) {
            $p = '/depends_on/' . $this->escape((string) $code);
            if (\preg_match(Schema::PACKAGE_CODE, (string) $code) !== 1 || (string) $code === $this->package) {
                $this->error($p, 'code.pattern', "'{$code}' is not a valid dependency package code");
                continue;
            }
            if (!$this->isConstraint($constraint)) {
                $this->error($p, 'schema.constraint', 'Dependency version must be a constraint (">=1.0 <2")');
            }
            $out[(string) $code] = true;
        }

        return $out;
    }

    private function lineage(mixed $lineage): void
    {
        if ($lineage === null) {
            return;
        }
        if (!\is_array($lineage)) {
            $this->error('/lineage', 'schema.type', 'lineage must be an object');

            return;
        }
        $this->keysOf($lineage, '/lineage', [], ['base']);
        $base = $lineage['base'] ?? null;
        if ($base === null) {
            return;
        }
        if (!\is_array($base)) {
            $this->error('/lineage/base', 'schema.type', 'lineage.base must be null or {package, version}');

            return;
        }
        $this->keysOf($base, '/lineage/base', ['package', 'version'], ['package', 'version']);
        if (isset($base['package']) && (!\is_string($base['package']) || \preg_match(Schema::PACKAGE_CODE, $base['package']) !== 1)) {
            $this->error('/lineage/base/package', 'code.pattern', 'lineage.base.package must be a package code');
        }
        if (isset($base['version']) && (!\is_string($base['version']) || \preg_match(Schema::SEMVER, $base['version']) !== 1)) {
            $this->error('/lineage/base/version', 'schema.version_format', 'lineage.base.version must be semver');
        }
    }

    private function rolesList(mixed $roles): void
    {
        if ($roles === null) {
            return;
        }
        if (!\is_array($roles) || !\array_is_list($roles)) {
            $this->error('/roles', 'schema.type', 'roles must be a list of role codes');

            return;
        }
        $this->cap(\count($roles), 'roles_per_app', '/roles');
        foreach ($roles as $i => $role) {
            $p = "/roles/{$i}";
            if (!\is_string($role) || \preg_match(Schema::CODE, $role) !== 1) {
                $this->error($p, 'code.pattern', 'Role codes match ' . Schema::CODE);
                continue;
            }
            if (isset($this->roles[$role])) {
                $this->error($p, 'code.duplicate', "Role '{$role}' is listed twice");
            }
            $this->roles[$role] = true;
            $this->useKey("role.{$role}", $p);
        }
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function menu(mixed $menu, array $entities): void
    {
        if ($menu === null) {
            return;
        }
        if (!\is_array($menu) || !\array_is_list($menu)) {
            $this->error('/menu', 'schema.type', 'menu must be a list');

            return;
        }
        $this->cap(\count($menu), 'menu_items', '/menu');
        $views = [];
        foreach ($entities as $e) {
            if (\is_string($e['code'] ?? null)) {
                $views[$e['code']] = \is_array($e['views'] ?? null) ? $e['views'] : [];
            }
        }
        foreach ($menu as $i => $item) {
            $p = "/menu/{$i}";
            if (!\is_array($item)) {
                $this->error($p, 'schema.type', 'A menu item is {entity, view}');
                continue;
            }
            $this->keysOf($item, $p, ['entity', 'view'], ['entity', 'view', 'label']);
            $entity = $item['entity'] ?? null;
            if (!\is_string($entity) || !isset($views[$entity])) {
                $this->error("{$p}/entity", 'ref.unknown_entity', 'Menu entity is not an entity of this package');
                continue;
            }
            $view = $item['view'] ?? null;
            if (!\is_string($view) || !isset($views[$entity][$view])) {
                $this->error("{$p}/view", 'ref.unknown_view', "Entity '{$entity}' has no such view");
            }
            $this->optionalKey($item, $p, 'label');
        }
    }

    // ── entities and fields ────────────────────────────────────────

    /**
     * @param array<string, mixed> $entity
     * @param array<string, true> $deps
     */
    private function entity(array $entity, string $p, array $deps): void
    {
        $this->keysOf($entity, $p, ['id', 'code', 'fields'], ['id', 'code', 'label', 'help', 'numbering', 'fields', 'indexes', 'views']);
        $code = \is_string($entity['code'] ?? null) ? $entity['code'] : '';
        $this->label($entity, $p, 'label', "{$code}.label");
        $this->optionalKey($entity, $p, 'help');
        if (isset($entity['numbering'])) {
            $this->numbering($entity['numbering'], "{$p}/numbering", $entity);
        }

        $fields = $this->listOf($entity, $p, 'fields', 'fields_per_entity', true);
        $this->uniqueCodes($fields, "{$p}/fields", 'field', true);
        $relations = 0;
        $system = [];
        foreach ($fields as $i => $field) {
            if (($field['type'] ?? null) === 'relation') {
                ++$relations;
            }
            // system types add no weight, so one of each per entity (no free fields)
            $type = $field['type'] ?? null;
            if (\is_string($type) && \in_array($type, Schema::SYSTEM_TYPES, true)) {
                if (isset($system[$type])) {
                    $this->error("{$p}/fields/{$i}/type", 'field.system_duplicate', "An entity has at most one {$type} field");
                }
                $system[$type] = true;
            }
            $this->field($field, "{$p}/fields/{$i}", $code, $entity, $deps);
        }
        $this->cap($relations, 'relations_per_entity', "{$p}/fields");

        $this->indexes($entity['indexes'] ?? null, "{$p}/indexes", $code);
        $this->views($entity['views'] ?? null, "{$p}/views", $code, $entity);
    }

    /**
     * @param array<string, mixed> $f
     * @param string $entity the entity code
     * @param array<string, mixed> $entityDef the entity
     * @param array<string, true> $deps
     */
    private function field(array $f, string $p, string $entity, array $entityDef, array $deps): void
    {
        $type = $f['type'] ?? null;
        if (!\is_string($type) || !isset(Schema::FIELD_TYPES[$type])) {
            $this->error("{$p}/type", 'schema.enum', 'Field type must be one of: ' . \implode(', ', \array_keys(Schema::FIELD_TYPES)));
            $this->keysOf($f, $p, ['id', 'code', 'type'], \array_merge(Schema::FIELD_COMMON, ...\array_values(Schema::FIELD_TYPES)));

            return;
        }
        $this->keysOf($f, $p, ['id', 'code', 'type'], \array_merge(Schema::FIELD_COMMON, Schema::FIELD_TYPES[$type]));
        $code = \is_string($f['code'] ?? null) ? $f['code'] : '';
        $this->label($f, $p, 'label', "{$entity}.{$code}.label");
        $this->optionalKey($f, $p, 'help');
        foreach (['required', 'search', 'pii', 'unique', 'stored'] as $flag) {
            if (isset($f[$flag]) && !\is_bool($f[$flag])) {
                $this->error("{$p}/{$flag}", 'schema.type', "{$flag} must be true or false");
            }
        }
        $derived = \in_array($type, Schema::DERIVED_TYPES, true);
        if ($derived && ($f['required'] ?? false) === true) {
            $this->error("{$p}/required", 'field.not_settable', "A {$type} field is filled by the engine and cannot be required");
        }
        if (($f['search'] ?? false) === true && !\in_array($type, Schema::SEARCHABLE, true)) {
            $this->error("{$p}/search", 'field.not_searchable', "A {$type} field cannot be searched");
        }
        if (($f['unique'] ?? false) === true && ($derived || \in_array($type, Schema::NOT_INDEXABLE, true)
            || $type === 'boolean' || ($type === 'relation' && ($f['cardinality'] ?? null) !== 'many_to_one'))) {
            $this->error("{$p}/unique", 'field.not_indexable', "A {$type} field cannot be unique");
        }

        switch ($type) {
            case 'text':
            case 'long_text':
                $max = $type === 'text' ? $this->caps->get('text_max') : 1_000_000;
                $this->intRange($f, $p, 'max', 1, $max);
                break;

            case 'integer':
            case 'duration':
                $this->numberKey($f, $p, 'min', true);
                $this->numberKey($f, $p, 'max', true);
                $this->minMax($f, $p);
                break;

            case 'decimal':
                $this->intRange($f, $p, 'precision', 1, 38);
                $this->intRange($f, $p, 'scale', 0, \is_int($f['precision'] ?? null) ? $f['precision'] : 38);
                $this->numberKey($f, $p, 'min');
                $this->numberKey($f, $p, 'max');
                $this->minMax($f, $p);
                break;

            case 'money':
                $this->money($f, $p, $entityDef);
                break;

            case 'percent':
                $this->intRange($f, $p, 'scale', 0, 6);
                $this->numberKey($f, $p, 'min');
                $this->numberKey($f, $p, 'max');
                $this->minMax($f, $p);
                break;

            case 'select':
            case 'multi_select':
                $this->options($f, $p, $entity, $code);
                if ($type === 'multi_select') {
                    $this->intRange($f, $p, 'max_selected', 1, $this->caps->get('options_per_select'));
                }
                break;

            case 'status':
                $wf = $f['workflow'] ?? null;
                $workflow = \is_string($wf) ? $this->catalog->workflow($this->package, $wf) : null;
                if ($workflow === null) {
                    $this->error("{$p}/workflow", 'ref.unknown_workflow', 'A status field names a workflow of this package');
                } elseif (($workflow['entity'] ?? null) !== $entity) {
                    $this->error("{$p}/workflow", 'ref.workflow_entity', "Workflow '{$wf}' belongs to another entity");
                } elseif ($this->catalog->states($workflow) === []) {
                    $this->error("{$p}/workflow", 'ref.workflow_states', "Workflow '{$wf}' has no states");
                }
                break;

            case 'relation':
                $this->relation($f, $p, $entity, $deps);
                break;

            case 'file':
            case 'files':
            case 'image':
                $this->intRange($f, $p, 'max_size_mb', 1, 1024);
                if ($type === 'files') {
                    $this->intRange($f, $p, 'max_files', 1, 100);
                }
                if (isset($f['accept'])) {
                    $accept = $f['accept'];
                    if (!\is_array($accept) || !\array_is_list($accept) || $accept === [] || \count($accept) > 50) {
                        $this->error("{$p}/accept", 'schema.type', 'accept is a list of 1-50 MIME types');
                    } else {
                        foreach ($accept as $i => $mime) {
                            if (!\is_string($mime) || \preg_match(Schema::MIME, $mime) !== 1) {
                                $this->error("{$p}/accept/{$i}", 'schema.format', 'Not a MIME type (image/png, image/*)');
                            }
                        }
                    }
                }
                break;

            case 'computed':
                if (!\is_string($f['expr'] ?? null)) {
                    $this->error("{$p}/expr", 'schema.required', 'A computed field needs expr');
                } else {
                    $this->expression($f['expr'], "{$p}/expr", 'computed', $this->catalog->scope($this->package, $entity), 'scalar', false);
                }
                break;

            case 'rollup':
                $this->rollup($f, $p, $entity);
                break;

            case 'sequence':
                $this->numbering(\array_intersect_key($f, ['pattern' => true, 'reset' => true]), $p, $entityDef);
                break;
        }

        if (\array_key_exists('default', $f)) {
            // defaults run before the record exists: no field references
            $this->fieldValue($f, "{$p}/default", $type, new MapScope([]));
        }
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, mixed> $entity
     */
    private function money(array $f, string $p, array $entity): void
    {
        $this->intRange($f, $p, 'scale', 0, 6);
        $this->numberKey($f, $p, 'min');
        $this->numberKey($f, $p, 'max');
        $this->minMax($f, $p);
        $hasField = isset($f['currency_field']);
        $hasFixed = isset($f['currency']);
        if ($hasField === $hasFixed) {
            $this->error($p, 'field.currency', 'A money field has exactly one of currency_field or currency');

            return;
        }
        if ($hasFixed && (!\is_string($f['currency']) || \preg_match('/^[A-Z]{3}$/', $f['currency']) !== 1)) {
            $this->error("{$p}/currency", 'schema.format', 'currency is an ISO 4217 code (HKD)');
        }
        if ($hasField) {
            $target = null;
            foreach ((\is_array($entity['fields'] ?? null) ? $entity['fields'] : []) as $other) {
                if (\is_array($other) && ($other['code'] ?? null) === $f['currency_field']) {
                    $target = $other;
                }
            }
            if ($target === null || ($target['type'] ?? null) !== 'select') {
                $this->error("{$p}/currency_field", 'ref.unknown_field', 'currency_field names a select field of this entity');
            }
        }
    }

    /**
     * @param array<string, mixed> $f
     */
    private function options(array $f, string $p, string $entity, string $code): void
    {
        $opts = $f['options'] ?? null;
        if (!\is_array($opts) || !\array_is_list($opts) || $opts === []) {
            $this->error("{$p}/options", 'schema.required', 'A select field needs a non-empty options list');

            return;
        }
        $this->cap(\count($opts), 'options_per_select', "{$p}/options");
        $seen = [];
        foreach ($opts as $i => $opt) {
            $op = "{$p}/options/{$i}";
            $key = $opt;
            if (\is_array($opt)) {
                $this->keysOf($opt, $op, ['key'], ['key', 'tone', 'label']);
                $key = $opt['key'] ?? null;
                if (isset($opt['tone']) && !\in_array($opt['tone'], Schema::TONES, true)) {
                    $this->error("{$op}/tone", 'schema.enum', 'tone must be one of: ' . \implode(', ', Schema::TONES));
                }
            }
            if (!\is_string($key) || \preg_match(Schema::OPTION, $key) !== 1) {
                $this->error($op, 'code.pattern', 'Option keys match ' . Schema::OPTION);
                continue;
            }
            if (isset($seen[$key])) {
                $this->error($op, 'code.duplicate', "Option '{$key}' is listed twice");
            }
            $seen[$key] = true;
            $this->label(\is_array($opt) ? $opt : [], $op, 'label', "{$entity}.{$code}.option.{$key}");
        }
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, true> $deps
     */
    private function relation(array $f, string $p, string $entity, array $deps): void
    {
        $card = $f['cardinality'] ?? null;
        if (!\in_array($card, Schema::CARDINALITY, true)) {
            $this->error("{$p}/cardinality", 'schema.enum', 'cardinality must be one of: ' . \implode(', ', Schema::CARDINALITY));
        }
        $to = $f['to'] ?? null;
        if (!\is_string($to) || $to === '') {
            $this->error("{$p}/to", 'schema.required', 'A relation names its target entity in to');

            return;
        }
        [$pkg, $target] = $this->catalog->split($to);
        if ($pkg !== $this->package && !isset($deps[$pkg])) {
            $this->error("{$p}/to", 'ref.undeclared_dependency', "'{$pkg}' is not listed in depends_on");

            return;
        }
        if ($pkg !== $this->package && !$this->catalog->hasPackage($pkg)) {
            $this->warning("{$p}/to", 'ref.unchecked', "Dependency '{$pkg}' was not supplied, so '{$to}' is not checked");
        } elseif ($this->catalog->entity($pkg, $target) === null) {
            $this->error("{$p}/to", 'ref.unknown_entity', "'{$to}' is not an entity");

            return;
        }

        if (isset($f['on_delete'])) {
            if ($card !== 'many_to_one') {
                $this->error("{$p}/on_delete", 'field.on_delete', 'on_delete applies to many_to_one relations only');
            } elseif (!\in_array($f['on_delete'], Schema::ON_DELETE, true)) {
                $this->error("{$p}/on_delete", 'schema.enum', 'on_delete must be one of: ' . \implode(', ', Schema::ON_DELETE));
            }
        }
        if ($card === 'one_to_many') {
            $via = $f['via'] ?? null;
            $back = \is_string($via) ? $this->catalog->field($pkg, $target, $via) : null;
            if ($back === null && $this->catalog->hasPackage($pkg)) {
                $this->error("{$p}/via", 'ref.unknown_field', "one_to_many needs via: the many_to_one field on '{$to}' that points back");
            } elseif ($back !== null) {
                [$bp, $be] = $this->catalog->split(Catalog::str($back['to'] ?? null), $pkg);
                if (($back['type'] ?? null) !== 'relation' || ($back['cardinality'] ?? null) !== 'many_to_one'
                    || $bp !== $this->package || $be !== $entity) {
                    $this->error("{$p}/via", 'ref.via', "'{$to}.{$via}' is not a many_to_one relation to '{$entity}'");
                }
            }
        } elseif (isset($f['via'])) {
            $this->error("{$p}/via", 'field.via', 'via applies to one_to_many relations only');
        }
    }

    /**
     * @param array<string, mixed> $f
     */
    private function rollup(array $f, string $p, string $entity): void
    {
        $op = $f['op'] ?? null;
        if (!\in_array($op, Schema::ROLLUP_OPS, true)) {
            $this->error("{$p}/op", 'schema.enum', 'op must be one of: ' . \implode(', ', Schema::ROLLUP_OPS));

            return;
        }
        $rel = \is_string($f['relation'] ?? null) ? $this->catalog->field($this->package, $entity, $f['relation']) : null;
        if ($rel === null || ($rel['type'] ?? null) !== 'relation' || ($rel['cardinality'] ?? null) === 'many_to_one') {
            $this->error("{$p}/relation", 'ref.unknown_field', 'A rollup names a one_to_many or many_to_many relation of this entity');

            return;
        }
        if ($op === 'count') {
            if (isset($f['field'])) {
                $this->error("{$p}/field", 'field.rollup', 'count takes no field');
            }

            return;
        }
        [$tp, $te] = $this->catalog->split(Catalog::str($rel['to'] ?? null));
        if (!$this->catalog->hasPackage($tp)) {
            // sum is a number whatever the target; min / max take the target's type
            if ($op !== 'sum') {
                $this->error("{$p}/field", 'ref.unchecked', "{$op} over '{$tp}' needs that dependency to be supplied");
            }

            return;
        }
        $target = \is_string($f['field'] ?? null) ? $this->catalog->field($tp, $te, $f['field']) : null;
        $allowed = $op === 'sum' ? ['integer', 'decimal', 'money', 'percent', 'duration']
            : ['integer', 'decimal', 'money', 'percent', 'duration', 'date', 'datetime'];
        if ($target === null || !\in_array($target['type'] ?? null, $allowed, true)) {
            $this->error("{$p}/field", 'ref.unknown_field', "{$op} needs a " . ($op === 'sum' ? 'number' : 'number or date') . " field of '{$te}'");
        }
    }

    /**
     * A value for a field (its default, or an update_record assignment):
     * a literal of the field's type, or an '=expression' giving that type.
     *
     * User, group and department values are ids that belong to one data
     * space, while a package is shared by many, so they are expressions only
     * (=CURRENT_USER(), =owner). A many-to-one relation can be assigned by
     * expression in update_record but has no default; derived fields, status
     * (it moves through set_status) and files take no value here.
     *
     * @param array<string, mixed> $f the field, with the value under 'default'
     * @param bool $assignment true for update_record, false for a default
     */
    private function fieldValue(array $f, string $dp, string $type, Scope $scope, bool $assignment = false): void
    {
        $v = $f['default'];
        $m2o = $type === 'relation' && ($f['cardinality'] ?? null) === 'many_to_one';
        if (\in_array($type, Schema::DERIVED_TYPES, true) || \in_array($type, ['status', 'file', 'files', 'image'], true)
            || ($type === 'relation' && !($assignment && $m2o))) {
            $this->error($dp, 'field.not_settable', $assignment ? "A {$type} field cannot be set here" : "A {$type} field takes no default");

            return;
        }
        if (\is_string($v) && \str_starts_with($v, '=')) {
            $expect = match ($type) {
                'integer', 'decimal', 'money', 'percent', 'duration' => 'decimal',
                'select', 'text', 'long_text', 'rich_text', 'email', 'phone', 'url' => 'text',
                'multi_select' => 'list:text',
                'relation' => 'ref:' . $this->qualified(Catalog::str($f['to'] ?? null)),
                default => $type,
            };
            $this->expression($v, $dp, 'value', $scope, $expect, false);

            return;
        }
        if (\in_array($type, ['user', 'group', 'department', 'relation'], true)) {
            $this->error($dp, 'field.default', "A {$type} value is an expression (=CURRENT_USER(), =owner): ids belong to one data space");

            return;
        }
        $ok = match ($type) {
            'text', 'long_text', 'rich_text', 'email', 'phone', 'url' => \is_string($v)
                && \mb_strlen($v) <= (\is_int($f['max'] ?? null) ? $f['max'] : $this->caps->get('text_max')),
            'integer' => \is_int($v),
            'decimal', 'money', 'percent', 'duration' => \is_int($v) || (\is_float($v) && \is_finite($v)),
            'boolean' => \is_bool($v),
            'date' => \is_string($v) && \preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $d) === 1 && \checkdate((int) $d[2], (int) $d[3], (int) $d[1]),
            'datetime' => $this->isDatetime($v),
            'select' => \is_string($v) && \in_array($v, $this->catalog->options($f), true),
            'multi_select' => \is_array($v) && \array_is_list($v)
                && \count(\array_filter($v, static fn (mixed $x): bool => \is_string($x))) === \count($v)
                && \array_diff($v, $this->catalog->options($f)) === [],
            default => false,
        };
        if (!$ok) {
            $this->error($dp, 'field.default', "Value does not fit a {$type} field (a literal of that type, or '=expression')");
        }
    }

    /**
     * An ISO datetime literal with an offset (2026-10-27T09:00:00+08:00) that
     * names a real moment: calendar date, 00-23 hours, 00-59 minutes and
     * seconds, offsets up to ±14:00.
     */
    private function isDatetime(mixed $v): bool
    {
        if (!\is_string($v) || \preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(Z|([+-])(\d{2}):(\d{2}))$/', $v, $m) !== 1) {
            return false;
        }
        if (!\checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ($m[6] ?: 0) > 59) {
            return false;
        }

        return $m[7] === 'Z' || ((int) $m[9] * 60 + (int) $m[10] <= 14 * 60 && (int) $m[10] <= 59);
    }

    /**
     * "<entity>" or "<package>.<entity>" as "<package>.<entity>".
     */
    private function qualified(string $ref): string
    {
        [$pkg, $entity] = $this->catalog->split($ref);

        return $pkg . '.' . $entity;
    }

    /**
     * Numbering pattern: literal text, {YYYY} {YY} {MM}, exactly one {seq:N}
     * (N 1-12) and {field:<code>} of a select / text field.
     *
     * @param mixed $n
     * @param array<string, mixed> $entity
     */
    private function numbering(mixed $n, string $p, array $entity): void
    {
        if (!\is_array($n)) {
            $this->error($p, 'schema.type', 'numbering is {pattern, reset}');

            return;
        }
        $this->keysOf($n, $p, ['pattern'], ['pattern', 'reset']);
        if (isset($n['reset']) && !\in_array($n['reset'], Schema::RESET, true)) {
            $this->error("{$p}/reset", 'schema.enum', 'reset must be one of: ' . \implode(', ', Schema::RESET));
        }
        $pattern = $n['pattern'] ?? null;
        if (!\is_string($pattern)) {
            if (\array_key_exists('pattern', $n)) {
                $this->error("{$p}/pattern", 'schema.type', 'pattern is text');
            }

            return;
        }
        if (\strlen($pattern) > 64 || \preg_match('/^(?:[A-Za-z0-9 _.\/#-]|\{(?:YYYY|YY|MM|seq:(?:[1-9]|1[0-2])|field:[a-z][a-z0-9_]{0,39})\})+$/', $pattern) !== 1) {
            $this->error("{$p}/pattern", 'numbering.pattern', 'Pattern is literal text with {YYYY}, {YY}, {MM}, {seq:N} and {field:code}, at most 64 characters');

            return;
        }
        if (\preg_match_all('/\{seq:/', $pattern) !== 1) {
            $this->error("{$p}/pattern", 'numbering.pattern', 'Pattern needs exactly one {seq:N}');
        }
        \preg_match_all('/\{field:([a-z][a-z0-9_]*)\}/', $pattern, $m);
        foreach ($m[1] as $code) {
            $type = null;
            foreach ((\is_array($entity['fields'] ?? null) ? $entity['fields'] : []) as $field) {
                if (\is_array($field) && ($field['code'] ?? null) === $code) {
                    $type = $field['type'] ?? null;
                }
            }
            if (!\in_array($type, ['select', 'text'], true)) {
                $this->error("{$p}/pattern", 'ref.unknown_field', "{field:{$code}} must name a select or text field of this entity");
            }
        }
    }

    private function indexes(mixed $indexes, string $p, string $entity): void
    {
        if ($indexes === null) {
            return;
        }
        if (!\is_array($indexes) || !\array_is_list($indexes)) {
            $this->error($p, 'schema.type', 'indexes is a list of field lists');

            return;
        }
        $this->cap(\count($indexes), 'indexes_per_entity', $p);
        $seen = [];
        foreach ($indexes as $i => $index) {
            $ip = "{$p}/{$i}";
            $fields = $index;
            if (\is_array($index) && !\array_is_list($index)) {
                $this->keysOf($index, $ip, ['fields'], ['fields', 'unique']);
                $fields = $index['fields'] ?? null;
                if (isset($index['unique']) && !\is_bool($index['unique'])) {
                    $this->error("{$ip}/unique", 'schema.type', 'unique must be true or false');
                }
            }
            if (!\is_array($fields) || !\array_is_list($fields) || $fields === []) {
                $this->error($ip, 'schema.type', 'An index is a non-empty list of field codes');
                continue;
            }
            $this->cap(\count($fields), 'fields_per_index', $ip);
            foreach ($fields as $j => $code) {
                $field = \is_string($code) ? $this->catalog->field($this->package, $entity, $code) : null;
                $ok = $field !== null
                    ? !\in_array($field['type'] ?? null, Schema::NOT_INDEXABLE, true)
                        && !(($field['type'] ?? null) === 'computed' && ($field['stored'] ?? false) !== true)
                        && !(($field['type'] ?? null) === 'relation' && ($field['cardinality'] ?? null) !== 'many_to_one')
                    : \is_string($code) && isset(Schema::SYSTEM_COLUMNS[$code]);
                if (!$ok) {
                    $this->error("{$ip}/" . (\is_array($index) && !\array_is_list($index) ? "fields/{$j}" : $j), 'ref.unknown_field', 'Index fields must be stored, indexable fields of this entity');
                }
            }
            $sig = \json_encode($fields);
            if (isset($seen[$sig])) {
                $this->error($ip, 'index.duplicate', 'The same index is listed twice');
            }
            $seen[$sig] = true;
        }
    }

    // ── views ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entity
     */
    private function views(mixed $views, string $p, string $code, array $entity): void
    {
        if ($views === null) {
            return;
        }
        if (!\is_array($views) || ($views !== [] && \array_is_list($views))) {
            $this->error($p, 'schema.type', 'views is a map of list / form / board / detail');

            return;
        }
        $this->keysOf($views, $p, [], Schema::VIEW_KINDS);
        $this->cap(\count($views), 'views_per_entity', $p);
        $scope = $this->catalog->scope($this->package, $code);
        foreach ($views as $kind => $view) {
            $vp = "{$p}/{$kind}";
            if (!\is_array($view) || !\in_array($kind, Schema::VIEW_KINDS, true)) {
                if (!\is_array($view)) {
                    $this->error($vp, 'schema.type', 'A view is an object');
                }
                continue;
            }
            match ($kind) {
                'list' => $this->listView($view, $vp, $code, $scope),
                'form' => $this->formView($view, $vp, $code, $scope),
                'board' => $this->boardView($view, $vp, $code),
                'detail' => $this->detailView($view, $vp, $code),
            };
        }
    }

    /**
     * @param array<string, mixed> $v
     */
    private function listView(array $v, string $p, string $entity, Scope $scope): void
    {
        $this->keysOf($v, $p, ['columns'], ['columns', 'default_sort', 'filters', 'saved', 'page_size', 'bulk']);
        $this->fieldList($v['columns'] ?? null, "{$p}/columns", $entity, true);
        $this->fieldList($v['filters'] ?? null, "{$p}/filters", $entity, false, true);
        $this->intRange($v, $p, 'page_size', 1, 500);
        if (isset($v['default_sort'])) {
            $this->sort($v['default_sort'], "{$p}/default_sort", $entity);
        }
        if (isset($v['bulk'])) {
            if (!$this->subsetOf($v['bulk'], Schema::BULK_ACTIONS, true)) {
                $this->error("{$p}/bulk", 'schema.enum', 'bulk actions are: ' . \implode(', ', Schema::BULK_ACTIONS));
            }
        }
        $saved = $this->listOf($v, $p, 'saved', 'saved_views_per_entity');
        $this->uniqueCodes($saved, "{$p}/saved", 'saved view', false);
        foreach ($saved as $i => $s) {
            $sp = "{$p}/saved/{$i}";
            $this->keysOf($s, $sp, ['id', 'code', 'filter'], ['id', 'code', 'label', 'filter', 'sort', 'columns']);
            $this->label($s, $sp, 'label', "{$entity}.view." . (\is_string($s['code'] ?? null) ? $s['code'] : ''));
            if (\is_string($s['filter'] ?? null)) {
                $this->expression($s['filter'], "{$sp}/filter", 'filter', $scope, 'boolean', true);
            } elseif (isset($s['filter'])) {
                $this->error("{$sp}/filter", 'schema.type', 'filter is an expression (=…)');
            }
            if (isset($s['sort'])) {
                $this->sort($s['sort'], "{$sp}/sort", $entity);
            }
            $this->fieldList($s['columns'] ?? null, "{$sp}/columns", $entity, false);
        }
    }

    /**
     * @param array<string, mixed> $v
     */
    private function formView(array $v, string $p, string $entity, Scope $scope): void
    {
        $this->keysOf($v, $p, ['sections'], ['sections']);
        $sections = $this->listOf($v, $p, 'sections', 'form_sections', true);
        $placed = [];
        foreach ($sections as $i => $s) {
            $sp = "{$p}/sections/{$i}";
            $this->keysOf($s, $sp, ['label', 'fields'], ['label', 'fields', 'visible', 'readonly', 'columns']);
            $this->label($s, $sp, 'label', null);
            $this->fieldList($s['fields'] ?? null, "{$sp}/fields", $entity, true);
            foreach (\is_array($s['fields'] ?? null) ? $s['fields'] : [] as $j => $f) {
                if (\is_string($f) && isset($placed[$f])) {
                    $this->error("{$sp}/fields/{$j}", 'view.duplicate_field', "'{$f}' is already in another section");
                }
                if (\is_string($f)) {
                    $placed[$f] = true;
                }
            }
            foreach (['visible', 'readonly'] as $rule) {
                if (\is_string($s[$rule] ?? null)) {
                    $this->expression($s[$rule], "{$sp}/{$rule}", $rule, $scope, 'boolean', false);
                } elseif (isset($s[$rule])) {
                    $this->error("{$sp}/{$rule}", 'schema.type', "{$rule} is an expression (=…)");
                }
            }
            $this->intRange($s, $sp, 'columns', 1, 3);
        }
    }

    /**
     * @param array<string, mixed> $v
     */
    private function boardView(array $v, string $p, string $entity): void
    {
        $this->keysOf($v, $p, ['group_by', 'card'], ['group_by', 'card', 'wip_limits']);
        $group = \is_string($v['group_by'] ?? null) ? $this->catalog->field($this->package, $entity, $v['group_by']) : null;
        $values = null;
        if ($group !== null && ($group['type'] ?? null) === 'select') {
            $values = $this->catalog->options($group);
        } elseif ($group !== null && ($group['type'] ?? null) === 'status') {
            $wf = $this->catalog->workflow($this->package, Catalog::str($group['workflow'] ?? null));
            $values = $wf !== null ? $this->catalog->states($wf) : [];
        }
        if ($values === null) {
            $this->error("{$p}/group_by", 'ref.unknown_field', 'A board groups by a select or status field of this entity');
        }
        $this->fieldList($v['card'] ?? null, "{$p}/card", $entity, true);
        if (isset($v['wip_limits'])) {
            $limits = $v['wip_limits'];
            if (!\is_array($limits) || ($limits !== [] && \array_is_list($limits))) {
                $this->error("{$p}/wip_limits", 'schema.type', 'wip_limits maps a column value to a positive number');

                return;
            }
            foreach ($limits as $key => $limit) {
                $lp = "{$p}/wip_limits/" . $this->escape((string) $key);
                if ($values !== null && !\in_array((string) $key, $values, true)) {
                    $this->error($lp, 'ref.unknown_option', "'{$key}' is not a column of this board");
                }
                if (!\is_int($limit) || $limit < 1 || $limit > 10_000) {
                    $this->error($lp, 'schema.range', 'A WIP limit is a whole number from 1 to 10000');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $v
     */
    private function detailView(array $v, string $p, string $entity): void
    {
        $this->keysOf($v, $p, [], ['header', 'tabs']);
        $this->fieldList($v['header'] ?? null, "{$p}/header", $entity, false);
        if (!isset($v['tabs'])) {
            return;
        }
        if (!\is_array($v['tabs']) || !\array_is_list($v['tabs']) || \count($v['tabs']) > 20) {
            $this->error("{$p}/tabs", 'schema.type', 'tabs is a list of at most 20 tabs');

            return;
        }
        foreach ($v['tabs'] as $i => $tab) {
            $tp = "{$p}/tabs/{$i}";
            if (!\is_array($tab) || \count($tab) !== 1) {
                $this->error($tp, 'schema.type', 'A tab is one of {related: …}, {history: true}, {files: true}, {workflow: true}');
                continue;
            }
            $kind = (string) \array_key_first($tab);
            $value = $tab[$kind];
            if (\in_array($kind, ['history', 'files', 'workflow'], true)) {
                if ($value !== true) {
                    $this->error("{$tp}/{$kind}", 'schema.type', "{$kind} must be true");
                }
                continue;
            }
            if ($kind !== 'related') {
                $this->error("{$tp}/{$kind}", 'schema.unknown_key', "Unknown tab '{$kind}'");
                continue;
            }
            $this->relatedTab($value, "{$tp}/related", $entity);
        }
    }

    private function relatedTab(mixed $ref, string $p, string $entity): void
    {
        if (!\is_string($ref) || \substr_count($ref, '.') < 1) {
            $this->error($p, 'schema.format', 'related is "<entity>.<field>" or "<package>.<entity>.<field>"');

            return;
        }
        $dot = (int) \strrpos($ref, '.');
        [$pkg, $other] = $this->catalog->split(\substr($ref, 0, $dot));
        $via = \substr($ref, $dot + 1);
        if (!$this->catalog->hasPackage($pkg)) {
            $this->warning($p, 'ref.unchecked', "Dependency '{$pkg}' was not supplied, so '{$ref}' is not checked");

            return;
        }
        $back = $this->catalog->field($pkg, $other, $via);
        [$bp, $be] = $back !== null ? $this->catalog->split(Catalog::str($back['to'] ?? null), $pkg) : ['', ''];
        if ($back === null || ($back['type'] ?? null) !== 'relation' || ($back['cardinality'] ?? null) !== 'many_to_one'
            || $bp !== $this->package || $be !== $entity) {
            $this->error($p, 'ref.via', "'{$ref}' is not a many_to_one relation to '{$entity}'");
        }
    }

    private function sort(mixed $sort, string $p, string $entity): void
    {
        $code = \is_string($sort) ? \ltrim($sort, '-') : null;
        if ($code === null || \strlen((string) $sort) - \strlen($code) > 1 || !$this->sortable($entity, $code)) {
            $this->error($p, 'ref.unknown_field', 'Sort is "<field>" or "-<field>" on a stored, sortable field');
        }
    }

    private function sortable(string $entity, string $code): bool
    {
        if (isset(Schema::SYSTEM_COLUMNS[$code])) {
            return $code !== 'number' || \is_array($this->catalog->entity($this->package, $entity)['numbering'] ?? null);
        }
        $f = $this->catalog->field($this->package, $entity, $code);
        if ($f === null) {
            return false;
        }
        $type = $f['type'] ?? null;
        if ($type === 'computed') {
            return ($f['stored'] ?? false) === true;
        }
        if ($type === 'relation') {
            return ($f['cardinality'] ?? null) === 'many_to_one';
        }

        return !\in_array($type, ['long_text', 'rich_text', 'multi_select', 'file', 'files', 'image'], true);
    }

    /**
     * A list of field codes of this entity (system columns allowed).
     */
    private function fieldList(mixed $list, string $p, string $entity, bool $required, bool $filterable = false): void
    {
        if ($list === null) {
            if ($required) {
                $this->error($p, 'schema.required', 'A non-empty list of field codes is required');
            }

            return;
        }
        if (!\is_array($list) || !\array_is_list($list) || ($required && $list === [])) {
            $this->error($p, 'schema.type', 'Expected a list of field codes');

            return;
        }
        $this->cap(\count($list), 'fields_per_view', $p);
        $seen = [];
        foreach ($list as $i => $code) {
            $known = \is_string($code)
                && ($this->catalog->field($this->package, $entity, $code) !== null
                    || (isset(Schema::SYSTEM_COLUMNS[$code])
                        && ($code !== 'number' || \is_array($this->catalog->entity($this->package, $entity)['numbering'] ?? null))));
            if (!$known) {
                $this->error("{$p}/{$i}", 'ref.unknown_field', "'" . (\is_string($code) ? $code : '?') . "' is not a field of '{$entity}'");
                continue;
            }
            if (isset($seen[$code])) {
                $this->error("{$p}/{$i}", 'view.duplicate_field', "'{$code}' is listed twice");
            }
            $seen[$code] = true;
            if ($filterable && !$this->sortable($entity, $code)) {
                $this->error("{$p}/{$i}", 'view.not_filterable', "'{$code}' cannot be filtered on");
            }
        }
    }

    // ── workflows ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $wf
     * @param array<string, true> $entityCodes
     */
    private function workflow(array $wf, string $p, array $entityCodes): void
    {
        $this->keysOf($wf, $p, ['id', 'code', 'entity'], ['id', 'code', 'label', 'entity', 'states', 'nodes', 'edges']);
        $code = \is_string($wf['code'] ?? null) ? $wf['code'] : '';
        $this->label($wf, $p, 'label', "workflow.{$code}.label");
        $entity = $wf['entity'] ?? null;
        if (!\is_string($entity) || !isset($entityCodes[$entity])) {
            $this->error("{$p}/entity", 'ref.unknown_entity', 'A workflow belongs to an entity of this package');

            return;
        }

        $states = $this->listOf($wf, $p, 'states', 'workflow_states');
        $this->uniqueCodes($states, "{$p}/states", 'state', false);
        $initial = 0;
        foreach ($states as $i => $s) {
            $sp = "{$p}/states/{$i}";
            $this->keysOf($s, $sp, ['id', 'code', 'category'], ['id', 'code', 'label', 'category', 'initial', 'props']);
            $this->label($s, $sp, 'label', "workflow.{$code}.state." . (\is_string($s['code'] ?? null) ? $s['code'] : ''));
            if (isset($s['category']) && !\in_array($s['category'], Schema::STATE_CATEGORIES, true)) {
                $this->error("{$sp}/category", 'schema.enum', 'category must be one of: ' . \implode(', ', Schema::STATE_CATEGORIES));
            }
            if (isset($s['initial']) && !\is_bool($s['initial'])) {
                $this->error("{$sp}/initial", 'schema.type', 'initial must be true or false');
            }
            if (($s['initial'] ?? false) === true) {
                ++$initial;
            }
            if (isset($s['props'])) {
                $this->props($s['props'], "{$sp}/props");
            }
        }
        if ($initial > 1) {
            $this->error("{$p}/states", 'workflow.initial', 'At most one state is initial');
        }

        $nodes = $this->listOf($wf, $p, 'nodes', 'workflow_nodes');
        if ($states === [] && $nodes === []) {
            $this->error($p, 'workflow.empty', 'A workflow has states, nodes or both');
        }
        if ($nodes !== []) {
            $this->graph($wf, $p, $code, $entity, $nodes, $this->catalog->states($wf));
        } elseif (isset($wf['edges'])) {
            $this->error("{$p}/edges", 'workflow.edges', 'edges need nodes');
        }
    }

    private function props(mixed $props, string $p): void
    {
        if (!\is_array($props) || ($props !== [] && \array_is_list($props)) || \count($props) > 20) {
            $this->error($p, 'schema.type', 'props maps up to 20 property codes to numbers, text or booleans');

            return;
        }
        foreach ($props as $key => $value) {
            $pp = "{$p}/" . $this->escape((string) $key);
            if (\preg_match(Schema::CODE, (string) $key) !== 1 || $key === 'category') {
                $this->error($pp, 'code.pattern', 'Property codes match ' . Schema::CODE . " and are not 'category'");
            }
            $ok = \is_int($value) || (\is_float($value) && \is_finite($value)) || \is_bool($value)
                || (\is_string($value) && \strlen($value) <= 200);
            if (!$ok) {
                $this->error($pp, 'schema.type', 'A property is a number, text (≤ 200 bytes) or boolean');
            }
        }
    }

    /**
     * Graph rules: one trigger, every node reachable from it,
     * every join reachable from a fork, no cycle except through a human
     * step or a wait, edges fit each node type.
     *
     * @param array<string, mixed> $wf
     * @param array<int, array<string, mixed>> $nodes
     * @param list<string> $states
     */
    private function graph(array $wf, string $p, string $code, string $entity, array $nodes, array $states): void
    {
        $this->uniqueCodes($nodes, "{$p}/nodes", 'node', false);
        $scope = $this->catalog->scope($this->package, $entity);
        $types = [];
        $pointer = [];
        foreach ($nodes as $i => $n) {
            $np = "{$p}/nodes/{$i}";
            $type = $n['type'] ?? null;
            if (!\is_string($type) || !isset(Schema::NODE_TYPES[$type])) {
                $this->error("{$np}/type", 'schema.enum', 'Node type must be one of: ' . \implode(', ', \array_keys(Schema::NODE_TYPES)));
                continue;
            }
            $this->keysOf($n, $np, ['id', 'code', 'type'], \array_merge(['id', 'code', 'type', 'label'], Schema::NODE_TYPES[$type]));
            $nc = \is_string($n['code'] ?? null) ? $n['code'] : '';
            $this->label($n, $np, 'label', "workflow.{$code}.node.{$nc}");
            if ($nc !== '' && !isset($types[$nc])) {
                $types[$nc] = $type;
                $pointer[$nc] = $np;
            }
            $this->node($n, $np, $type, $entity, $scope, $states);
        }

        $out = [];
        $in = [];
        foreach (\array_keys($types) as $nc) {
            $out[$nc] = [];
            $in[$nc] = 0;
        }
        $edges = $wf['edges'] ?? [];
        if (!\is_array($edges) || !\array_is_list($edges)) {
            $this->error("{$p}/edges", 'schema.type', 'edges is a list of {from, to}');
            $edges = [];
        }
        if (\count($edges) > 4 * $this->caps->get('workflow_nodes')) {
            $this->error("{$p}/edges", 'cap.workflow_nodes', 'Too many edges');

            return;
        }
        $branches = [];
        foreach ($edges as $i => $e) {
            $ep = "{$p}/edges/{$i}";
            if (!\is_array($e)) {
                $this->error($ep, 'schema.type', 'An edge is {from, to}');
                continue;
            }
            $this->keysOf($e, $ep, ['from', 'to'], ['from', 'to', 'branch']);
            $from = $e['from'] ?? null;
            $to = $e['to'] ?? null;
            if (!\is_string($from) || !isset($types[$from]) || !\is_string($to) || !isset($types[$to])) {
                $this->error($ep, 'ref.unknown_node', 'Edge ends must be node codes of this workflow');
                continue;
            }
            if (\in_array($to, $out[$from], true)) {
                $this->error($ep, 'workflow.edge_duplicate', "Edge {$from} → {$to} is listed twice");
                continue;
            }
            if ($types[$from] === 'condition') {
                if (!\is_bool($e['branch'] ?? null)) {
                    $this->error($ep, 'workflow.branch', 'An edge out of a condition has branch: true or false');
                } else {
                    $branches[$from][] = $e['branch'];
                }
            } elseif (isset($e['branch'])) {
                $this->error("{$ep}/branch", 'workflow.branch', 'Only edges out of a condition have a branch');
            }
            $out[$from][] = $to;
            ++$in[$to];
        }

        $triggers = \array_keys($types, 'trigger', true);
        if (\count($triggers) !== 1) {
            $this->error("{$p}/nodes", 'workflow.trigger', 'A workflow with nodes has exactly one trigger');
        }
        foreach ($types as $nc => $type) {
            $np = $pointer[$nc];
            $nOut = \count($out[$nc]);
            $nIn = $in[$nc];
            $rule = match ($type) {
                'trigger' => $nIn === 0 && $nOut === 1 ? null : 'a trigger has no incoming edge and one outgoing',
                'end' => $nOut === 0 && $nIn >= 1 ? null : 'an end has incoming edges and no outgoing',
                'condition' => $nIn >= 1 && $nOut === 2 && \count(\array_unique($branches[$nc] ?? [])) === 2
                    ? null : 'a condition has two outgoing edges, branch true and branch false',
                'fork' => $nIn >= 1 && $nOut >= 2 ? null : 'a fork has two or more outgoing edges',
                'join' => $nIn >= 2 && $nOut === 1 ? null : 'a join has two or more incoming edges and one outgoing',
                default => $nIn >= 1 && $nOut === 1 ? null : "a {$type} node has incoming edges and one outgoing",
            };
            if ($rule !== null) {
                $this->error($np, 'workflow.edges', "Node '{$nc}': {$rule}");
            }
        }

        if (\count($triggers) === 1) {
            $reached = $this->reach($out, [$triggers[0]]);
            foreach (\array_keys($types) as $nc) {
                if (!isset($reached[$nc])) {
                    $this->error($pointer[$nc], 'workflow.unreachable', "Node '{$nc}' cannot be reached from the trigger");
                }
            }
        }
        $forks = \array_keys($types, 'fork', true);
        $fromForks = $this->reach($out, \array_map(fn (string $f): string => $out[$f][0] ?? $f, $forks), $forks);
        foreach (\array_keys($types, 'join', true) as $join) {
            if (!isset($fromForks[$join])) {
                $this->error($pointer[$join], 'workflow.join', "Join '{$join}' is not reachable from a fork");
            }
        }

        // cycles are allowed only through a human step or a wait
        $plain = [];
        foreach ($out as $from => $tos) {
            if ($types[$from] === 'human' || $types[$from] === 'wait') {
                continue;
            }
            $plain[$from] = \array_values(\array_filter($tos, fn (string $t): bool => $types[$t] !== 'human' && $types[$t] !== 'wait'));
        }
        $cycle = $this->findCycle($plain);
        if ($cycle !== null) {
            $this->error($pointer[$cycle], 'workflow.cycle', "Node '{$cycle}' is on a loop with no human step or wait");
        }
    }

    /**
     * @param array<string, mixed> $n
     * @param list<string> $states
     */
    private function node(array $n, string $p, string $type, string $entity, Scope $scope, array $states): void
    {
        switch ($type) {
            case 'trigger':
                $on = $n['on'] ?? null;
                if (!\in_array($on, Schema::TRIGGERS, true)) {
                    $this->error("{$p}/on", 'schema.enum', 'on must be one of: ' . \implode(', ', Schema::TRIGGERS));
                    break;
                }
                if ($on === 'field_changed' && (!\is_string($n['field'] ?? null) || $this->catalog->field($this->package, $entity, $n['field']) === null)) {
                    $this->error("{$p}/field", 'ref.unknown_field', 'field_changed names a field of the entity');
                }
                if ($on === 'status_entered') {
                    // the states of the named status field, or this workflow's own
                    $watch = $states;
                    if (isset($n['field'])) {
                        $f = \is_string($n['field']) ? $this->catalog->field($this->package, $entity, $n['field']) : null;
                        $wf = ($f !== null && ($f['type'] ?? null) === 'status')
                            ? $this->catalog->workflow($this->package, Catalog::str($f['workflow'] ?? null)) : null;
                        if ($wf === null) {
                            $this->error("{$p}/field", 'ref.unknown_field', 'field names a status field of the entity');
                        }
                        $watch = $wf !== null ? $this->catalog->states($wf) : [];
                    }
                    if (!\in_array($n['state'] ?? null, $watch, true)) {
                        $this->error("{$p}/state", 'ref.unknown_state', 'status_entered names a state of the watched status field (field) or of this workflow');
                    }
                }
                if ($on === 'schedule' && (!\is_string($n['cron'] ?? null) || \preg_match('/^[0-9*\/,\- ]{9,64}$/', $n['cron']) !== 1
                    || \count(\preg_split('/ +/', \trim($n['cron'])) ?: []) !== 5)) {
                    $this->error("{$p}/cron", 'schema.format', 'schedule needs a five-part cron expression');
                }
                if ($on === 'external' && (!\is_string($n['source'] ?? null) || \preg_match(Schema::EXECUTOR, $n['source']) !== 1)) {
                    $this->error("{$p}/source", 'schema.format', 'external needs source: a dotted event name (inbound.message)');
                }
                break;

            case 'human':
                $assign = $n['assign'] ?? null;
                if (!\is_array($assign) || \count($assign) !== 1) {
                    $this->error("{$p}/assign", 'schema.type', 'assign is {role: <role>} or {field: <user field>}');
                } elseif (isset($assign['role'])) {
                    if (!\is_string($assign['role']) || !isset($this->roles[$assign['role']])) {
                        $this->error("{$p}/assign/role", 'ref.unknown_role', 'assign.role names a role of this package');
                    }
                } elseif (isset($assign['field'])) {
                    $f = \is_string($assign['field']) ? $this->catalog->field($this->package, $entity, $assign['field']) : null;
                    if ($f === null || !\in_array($f['type'] ?? null, ['user', 'created_by'], true)) {
                        $this->error("{$p}/assign/field", 'ref.unknown_field', 'assign.field names a user field of the entity');
                    }
                } else {
                    $this->error("{$p}/assign", 'schema.type', 'assign is {role: <role>} or {field: <user field>}');
                }
                foreach (['verify', 'bound'] as $flag) {
                    if (isset($n[$flag]) && !\is_bool($n[$flag])) {
                        $this->error("{$p}/{$flag}", 'schema.type', "{$flag} must be true or false");
                    }
                }
                $this->intRange($n, $p, 'due_days', 0, 3650);
                $this->fieldList($n['form'] ?? null, "{$p}/form", $entity, false);
                if (\is_string($n['applicable'] ?? null)) {
                    $this->expression($n['applicable'], "{$p}/applicable", 'applicable', $scope, 'boolean', false);
                } elseif (isset($n['applicable'])) {
                    $this->error("{$p}/applicable", 'schema.type', 'applicable is an expression (=…)');
                }
                break;

            case 'condition':
                if (!\is_string($n['expr'] ?? null)) {
                    $this->error("{$p}/expr", 'schema.required', 'A condition needs expr');
                } else {
                    $this->expression($n['expr'], "{$p}/expr", 'condition', $scope, 'boolean', false);
                }
                break;

            case 'wait':
                $hasDays = isset($n['days']);
                $hasUntil = isset($n['until']);
                if ($hasDays === $hasUntil) {
                    $this->error($p, 'workflow.wait', 'A wait has exactly one of days or until');
                } elseif ($hasDays) {
                    $this->intRange($n, $p, 'days', 1, 3650);
                } else {
                    $f = \is_string($n['until']) ? $this->catalog->field($this->package, $entity, $n['until']) : null;
                    if ($f === null || !\in_array($f['type'] ?? null, ['date', 'datetime'], true)) {
                        $this->error("{$p}/until", 'ref.unknown_field', 'until names a date field of the entity');
                    }
                }
                break;

            case 'set_status':
                if (!\in_array($n['state'] ?? null, $states, true)) {
                    $this->error("{$p}/state", 'ref.unknown_state', 'set_status names a state of this workflow');
                }
                break;

            case 'update_record':
                $set = $n['set'] ?? null;
                if (!\is_array($set) || $set === [] || \array_is_list($set) || \count($set) > 50) {
                    $this->error("{$p}/set", 'schema.type', 'set maps 1-50 field codes to values or expressions');
                    break;
                }
                foreach ($set as $fc => $value) {
                    $sp = "{$p}/set/" . $this->escape((string) $fc);
                    $f = $this->catalog->field($this->package, $entity, (string) $fc);
                    if ($f === null || \in_array($f['type'] ?? null, \array_merge(Schema::DERIVED_TYPES, ['status']), true)) {
                        $this->error($sp, 'ref.unknown_field', "'{$fc}' is not a writable field (status moves through set_status)");
                        continue;
                    }
                    $this->fieldValue(\array_merge($f, ['default' => $value]), $sp, Catalog::str($f['type'] ?? null), $scope, true);
                }
                break;

            case 'notify':
                $to = $n['to'] ?? null;
                if (!\is_array($to) || \count($to) !== 1 || !(isset($to['role']) || isset($to['field']))) {
                    $this->error("{$p}/to", 'schema.type', 'to is {role: <role>} or {field: <user field>}');
                } elseif (isset($to['role']) && (!\is_string($to['role']) || !isset($this->roles[$to['role']]))) {
                    $this->error("{$p}/to/role", 'ref.unknown_role', 'to.role names a role of this package');
                } elseif (isset($to['field'])) {
                    $f = \is_string($to['field']) ? $this->catalog->field($this->package, $entity, $to['field']) : null;
                    if ($f === null || !\in_array($f['type'] ?? null, ['user', 'created_by'], true)) {
                        $this->error("{$p}/to/field", 'ref.unknown_field', 'to.field names a user field of the entity');
                    }
                }
                $this->label($n, $p, 'message', null);
                break;

            case 'external':
                if (!\is_string($n['executor'] ?? null) || \preg_match(Schema::EXECUTOR, $n['executor']) !== 1) {
                    $this->error("{$p}/executor", 'schema.format', 'executor is a dotted name (namespace.action)');
                }
                if (isset($n['input'])) {
                    $input = $n['input'];
                    if (!\is_array($input) || \array_is_list($input) || \count($input) > 50) {
                        $this->error("{$p}/input", 'schema.type', 'input maps up to 50 names to expressions');
                        break;
                    }
                    foreach ($input as $key => $expr) {
                        $ip = "{$p}/input/" . $this->escape((string) $key);
                        if (\preg_match(Schema::CODE, (string) $key) !== 1 || !\is_string($expr)) {
                            $this->error($ip, 'schema.type', 'An input is <code>: "=expression"');
                            continue;
                        }
                        $this->expression($expr, $ip, 'input', $scope, 'scalar', false);
                    }
                }
                break;
        }
    }

    /**
     * @param array<string, list<string>> $out
     * @param list<string> $start
     * @param list<string> $through nodes that count as reached (the forks themselves)
     *
     * @return array<string, true>
     */
    private function reach(array $out, array $start, array $through = []): array
    {
        $seen = [];
        $stack = $start;
        foreach ($through as $t) {
            $seen[$t] = true;
            foreach ($out[$t] ?? [] as $next) {
                $stack[] = $next;
            }
        }
        while ($stack !== []) {
            $n = \array_pop($stack);
            if (isset($seen[$n]) && !\in_array($n, $through, true)) {
                continue;
            }
            $seen[$n] = true;
            foreach ($out[$n] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $stack[] = $next;
                }
            }
        }

        return $seen;
    }

    /**
     * Iterative DFS; returns a node on a cycle, or null.
     *
     * @param array<string, list<string>> $out
     */
    private function findCycle(array $out): ?string
    {
        $state = [];
        foreach (\array_keys($out) as $root) {
            if (isset($state[$root])) {
                continue;
            }
            $stack = [[$root, 0]];
            $state[$root] = 1;
            while ($stack !== []) {
                [$n, $i] = $stack[\count($stack) - 1];
                $next = $out[$n][$i] ?? null;
                if ($next === null) {
                    $state[$n] = 2;
                    \array_pop($stack);
                    continue;
                }
                $stack[\count($stack) - 1][1] = $i + 1;
                if (!isset($out[$next])) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    return $next;
                }
                if (!isset($state[$next])) {
                    $state[$next] = 1;
                    $stack[] = [$next, 0];
                }
            }
        }

        return null;
    }

    // ── permissions ────────────────────────────────────────────────

    /**
     * @param array<string, true> $entityCodes
     */
    private function permissions(mixed $perm, array $entityCodes): void
    {
        if ($perm === null) {
            return;
        }
        if (!\is_array($perm)) {
            $this->error('/permissions', 'schema.type', 'permissions is {rules: [...]}');

            return;
        }
        $this->keysOf($perm, '/permissions', [], ['rules']);
        $rules = $this->listOf($perm, '/permissions', 'rules', 'permission_rules');
        $pairs = [];
        foreach ($rules as $i => $r) {
            $p = "/permissions/rules/{$i}";
            $this->keysOf($r, $p, ['role', 'entity', 'ops'], ['role', 'entity', 'ops', 'row', 'fields', 'workflow']);
            $role = $r['role'] ?? null;
            $entity = $r['entity'] ?? null;
            if (!\is_string($role) || !isset($this->roles[$role])) {
                $this->error("{$p}/role", 'ref.unknown_role', 'role names a role of this package');
            }
            if (!\is_string($entity) || !isset($entityCodes[$entity])) {
                $this->error("{$p}/entity", 'ref.unknown_entity', 'entity names an entity of this package');
                continue;
            }
            $pair = Catalog::str($role) . '|' . $entity;
            if (isset($pairs[$pair])) {
                $this->error($p, 'permissions.duplicate', 'One rule per role and entity');
            }
            $pairs[$pair] = true;
            $ops = $r['ops'] ?? null;
            if (!\is_array($ops) || $ops === [] || !$this->subsetOf($ops, Schema::OPS, true)) {
                $this->error("{$p}/ops", 'schema.enum', 'ops is a list of: ' . \implode(', ', Schema::OPS));
            }
            $row = $r['row'] ?? 'all';
            if ($row !== 'all') {
                if (!\is_string($row)) {
                    $this->error("{$p}/row", 'schema.type', 'row is "all" or an expression (=…)');
                } else {
                    $this->expression($row, "{$p}/row", 'row', $this->catalog->scope($this->package, $entity), 'boolean', true);
                }
            }
            if (isset($r['fields'])) {
                $fields = $r['fields'];
                if (!\is_array($fields) || \array_is_list($fields)) {
                    $this->error("{$p}/fields", 'schema.type', 'fields maps field codes to read / write / none');
                } else {
                    foreach ($fields as $fc => $access) {
                        $fp = "{$p}/fields/" . $this->escape((string) $fc);
                        if ($this->catalog->field($this->package, $entity, (string) $fc) === null) {
                            $this->error($fp, 'ref.unknown_field', "'{$fc}' is not a field of '{$entity}'");
                        }
                        if (!\in_array($access, Schema::FIELD_ACCESS, true)) {
                            $this->error($fp, 'schema.enum', 'Field access is read, write or none');
                        }
                    }
                }
            }
            if (isset($r['workflow'])) {
                $wfa = $r['workflow'];
                if (!$this->subsetOf($wfa, Schema::WORKFLOW_ABILITIES, true)) {
                    $this->error("{$p}/workflow", 'schema.enum', 'workflow is a list of: ' . \implode(', ', Schema::WORKFLOW_ABILITIES));
                }
            }
        }
    }

    // ── i18n ───────────────────────────────────────────────────────

    private function i18n(mixed $i18n, bool $system): void
    {
        if (!\is_array($i18n) || ($i18n !== [] && \array_is_list($i18n))) {
            if ($i18n !== null) {
                $this->error('/i18n', 'schema.type', 'i18n maps a locale to {key: text}');
            }

            return;
        }
        $this->cap(\count($i18n), 'i18n_locales', '/i18n');
        $total = 0;
        foreach ($i18n as $locale => $pack) {
            $lp = '/i18n/' . $this->escape((string) $locale);
            if (\preg_match(Schema::LOCALE, (string) $locale) !== 1) {
                $this->error($lp, 'i18n.locale', "'{$locale}' is not a locale code (en, zh-HK)");
                continue;
            }
            if (!\is_array($pack) || ($pack !== [] && \array_is_list($pack))) {
                $this->error($lp, 'schema.type', 'A locale pack maps keys to text');
                continue;
            }
            $total += \count($pack);
            foreach ($pack as $key => $text) {
                $kp = "{$lp}/" . $this->escape((string) $key);
                if (\preg_match(Schema::I18N_KEY, (string) $key) !== 1) {
                    $this->error($kp, 'i18n.key', 'i18n keys match ' . Schema::I18N_KEY);
                } elseif (!\is_string($text) || $text === '' || \mb_strlen($text) > $this->caps->get('i18n_value_length')) {
                    $this->error($kp, 'i18n.value', 'A label is non-empty text up to ' . $this->caps->get('i18n_value_length') . ' characters');
                }
            }
        }
        if ($total > $this->caps->get('i18n_keys')) {
            $this->error('/i18n', 'cap.i18n_keys', "{$total} i18n entries; the cap is " . $this->caps->get('i18n_keys'));
        }
        $required = $system ? ['en', 'zh-HK'] : ['en'];
        foreach ($required as $locale) {
            $pack = $i18n[$locale] ?? null;
            if (!\is_array($pack)) {
                $this->error('/i18n', 'i18n.missing_locale', "Locale '{$locale}' is required");
                continue;
            }
            foreach ($this->keys as $key => $where) {
                if (!isset($pack[$key])) {
                    $this->error($where, 'i18n.missing_key', "Label key '{$key}' has no '{$locale}' text");
                }
            }
        }
    }

    // ── expressions ────────────────────────────────────────────────

    private function expression(string $src, string $p, string $site, Scope $scope, string $expect, bool $sql): void
    {
        try {
            $ast = (new Parser($this->caps))->parse($src);
            $typed = (new TypeChecker($this->caps))->check($ast, $scope, $expect, $sql);
            $this->expressions[$p] = ['src' => $src, 'ast' => $typed, 'site' => $site];
        } catch (ExpressionError $e) {
            $at = $e->getOffset() >= 0 ? " (at {$e->getOffset()})" : '';
            $this->error($p, $e->getErrorCode(), $e->getMessage() . $at);
        }
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * Required + allowed keys of an object.
     *
     * @param array<mixed> $obj
     * @param list<string> $required
     * @param list<string> $allowed
     */
    private function keysOf(array $obj, string $p, array $required, array $allowed): void
    {
        if ($obj !== [] && \array_is_list($obj)) {
            $this->error($p, 'schema.type', 'Expected an object');

            return;
        }
        foreach ($required as $key) {
            if (!\array_key_exists($key, $obj)) {
                $this->error("{$p}/{$key}", 'schema.required', "'{$key}' is required");
            }
        }
        foreach (\array_keys($obj) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                $this->error($p . '/' . $this->escape((string) $key), 'schema.unknown_key', "Unknown key '{$key}'");
            }
        }
    }

    /**
     * A list of objects under $key, capped. Non-object items are reported.
     *
     * @param array<mixed> $parent
     *
     * @return array<int, array<string, mixed>>
     */
    private function listOf(array $parent, string $p, string $key, string $cap, bool $nonEmpty = false): array
    {
        $list = $parent[$key] ?? null;
        $lp = "{$p}/{$key}";
        if ($list === null) {
            return [];
        }
        if (!\is_array($list) || !\array_is_list($list)) {
            $this->error($lp, 'schema.type', "'{$key}' must be a list");

            return [];
        }
        if ($nonEmpty && $list === []) {
            $this->error($lp, 'schema.required', "'{$key}' must not be empty");
        }
        if (\count($list) > $this->caps->get($cap)) {
            $this->error($lp, "cap.{$cap}", "'{$key}' has " . \count($list) . ' items; the cap is ' . $this->caps->get($cap));

            return [];
        }
        $out = [];
        foreach ($list as $i => $item) {
            if (!\is_array($item) || ($item !== [] && \array_is_list($item))) {
                $this->error("{$lp}/{$i}", 'schema.type', 'Expected an object');
                continue;
            }
            $out[$i] = $item;
        }

        return $out;
    }

    /**
     * Codes of a list: pattern, reserved words (entities and fields), duplicates.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, true>
     */
    private function uniqueCodes(array $items, string $p, string $what, bool $reserved): array
    {
        $seen = [];
        foreach ($items as $i => $item) {
            $code = $item['code'] ?? null;
            $cp = "{$p}/{$i}/code";
            if (!\is_string($code) || \preg_match(Schema::CODE, $code) !== 1) {
                $this->error($cp, 'code.pattern', "A {$what} code matches " . Schema::CODE);
                continue;
            }
            if ($reserved && \in_array($code, Schema::RESERVED, true)) {
                $this->error($cp, 'code.reserved', "'{$code}' is reserved");
            }
            if (isset($seen[$code])) {
                $this->error($cp, 'code.duplicate', "Two {$what}s have the code '{$code}'");
            }
            $seen[$code] = true;
        }

        return $seen;
    }

    /**
     * @param array<string, mixed> $def
     * @param mixed $previous
     */
    private function ids(array $def, mixed $previous): void
    {
        $seen = [];
        foreach (ElementIds::collect($def) as $el) {
            $ip = $el['pointer'] . '/id';
            if ($el['id'] === null) {
                continue; // reported as schema.required by keysOf()
            }
            if (!ElementIds::isValid($el['id'])) {
                $this->error($ip, 'id.format', 'Element ids are ULIDs (26 characters, Crockford base32)');
                continue;
            }
            if (isset($seen[$el['id']])) {
                $this->error($ip, 'id.duplicate', "Id {$el['id']} is already used at {$seen[$el['id']]}");
                continue;
            }
            $seen[$el['id']] = $el['pointer'];
        }
        if (\is_array($previous)) {
            foreach (ElementIds::compare($previous, $def) as $issue) {
                $this->issues[] = $issue;
            }
        }
    }

    /**
     * An i18n key field: explicit key, or the conventional default key.
     *
     * @param array<mixed> $obj
     */
    private function label(array $obj, string $p, string $key, ?string $default): void
    {
        if (!\array_key_exists($key, $obj)) {
            if ($default === null) {
                $this->error("{$p}/{$key}", 'schema.required', "'{$key}' (an i18n key) is required");
            } elseif ($default !== '' && \preg_match(Schema::I18N_KEY, $default) === 1) {
                $this->useKey($default, $p);
            }

            return;
        }
        $this->optionalKey($obj, $p, $key);
    }

    /**
     * @param array<mixed> $obj
     */
    private function optionalKey(array $obj, string $p, string $key): void
    {
        if (!\array_key_exists($key, $obj)) {
            return;
        }
        $v = $obj[$key];
        if (!\is_string($v) || \preg_match(Schema::I18N_KEY, $v) !== 1) {
            $this->error("{$p}/{$key}", 'i18n.key', "'{$key}' is an i18n key matching " . Schema::I18N_KEY);

            return;
        }
        $this->useKey($v, "{$p}/{$key}");
    }

    private function useKey(string $key, string $p): void
    {
        $this->keys[$key] ??= $p === '' ? '/' : $p;
    }

    /**
     * @param array<mixed> $obj
     */
    private function intRange(array $obj, string $p, string $key, int $min, int $max): void
    {
        if (!isset($obj[$key])) {
            return;
        }
        if (!\is_int($obj[$key]) || $obj[$key] < $min || $obj[$key] > $max) {
            $this->error("{$p}/{$key}", 'schema.range', "{$key} is a whole number from {$min} to {$max}");
        }
    }

    /**
     * @param array<mixed> $obj
     */
    private function numberKey(array $obj, string $p, string $key, bool $int = false): void
    {
        if (!isset($obj[$key])) {
            return;
        }
        $v = $obj[$key];
        $ok = $int ? \is_int($v) : (\is_int($v) || (\is_float($v) && \is_finite($v)));
        if (!$ok) {
            $this->error("{$p}/{$key}", 'schema.type', "{$key} must be a " . ($int ? 'whole number' : 'number'));
        }
    }

    /**
     * @param array<mixed> $obj
     */
    private function minMax(array $obj, string $p): void
    {
        $min = $obj['min'] ?? null;
        $max = $obj['max'] ?? null;
        if ((\is_int($min) || \is_float($min)) && (\is_int($max) || \is_float($max)) && $min > $max) {
            $this->error("{$p}/min", 'schema.range', 'min is greater than max');
        }
    }

    private function cap(int $count, string $cap, string $p): void
    {
        if ($count > $this->caps->get($cap)) {
            $this->error($p, "cap.{$cap}", "{$count} items; the cap is " . $this->caps->get($cap));
        }
    }

    /**
     * A list of strings, each one of $allowed (and each once when $unique).
     *
     * @param list<string> $allowed
     */
    private function subsetOf(mixed $list, array $allowed, bool $unique = false): bool
    {
        if (!\is_array($list) || !\array_is_list($list)) {
            return false;
        }
        $seen = [];
        foreach ($list as $item) {
            if (!\is_string($item) || !\in_array($item, $allowed, true) || ($unique && isset($seen[$item]))) {
                return false;
            }
            $seen[$item] = true;
        }

        return true;
    }

    private function isConstraint(mixed $v): bool
    {
        return \is_string($v) && \strlen($v) <= 60 && \preg_match(Schema::CONSTRAINT, \trim($v)) === 1;
    }

    /**
     * JSON Pointer escaping for a key segment (RFC 6901).
     */
    private function escape(string $key): string
    {
        return \str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private function error(string $p, string $code, string $message): void
    {
        $this->issues[] = Issue::error($p, $code, $message);
    }

    private function warning(string $p, string $code, string $message): void
    {
        $this->issues[] = Issue::warning($p, $code, $message);
    }
}
