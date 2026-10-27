<?php

/**
 * razymod/apps — turns a valid definition into a runtime plan (manual/15-razy-apps.md §6).
 *
 * The plan is plain JSON-safe arrays, deterministic for a given definition
 * (no clock, no randomness), so the runtime can cache it by content hash
 * (data space + version id + content hash). It holds:
 *
 * - entities keyed by code: fields with their expression type, options,
 *   relation targets (qualified "<package>.<entity>"), parsed defaults and
 *   computed expressions as typed ASTs, the system columns, the index list
 *   (declared indexes, unique fields, one index per many-to-one relation),
 *   search and sortable columns, and views with every expression typed
 * - workflows keyed by code: states, the initial state, nodes with typed
 *   expressions, edges
 * - permissions: rules with typed row expressions and the Gate abilities
 *   (apps.<package>.<entity>.<op>, …workflow.<ability>)
 * - the content hash, the i18n keys used and the weight counts
 *
 * Nothing here is SQL. Physical table / column names come from the
 * physical name map (RZA-B) and filters / row rules compile to bound SQL in
 * the record runtime (RZA-C), both from this plan.
 */

declare(strict_types=1);

namespace razymod\apps;

use LogicException;
use razymod\apps\Definition\Canonical;
use razymod\apps\Definition\Catalog;
use razymod\apps\Definition\Issue;
use razymod\apps\Definition\Schema;
use razymod\apps\Definition\ValidationResult;
use razymod\apps\Definition\Validator;
use razymod\apps\Expression\ExpressionError;

final class Compiler
{
    public const PLAN_VERSION = 1;

    /** @var array<string, array{src: string, ast: array<string, mixed>, site: string}> */
    private array $expr = [];

    public function __construct(private readonly Caps $caps = new Caps())
    {
    }

    /**
     * Literal text and {YYYY} {YY} {MM} {seq:N} {field:code} tokens.
     *
     * @return list<array<string, string|int>>
     */
    public static function numberingTokens(string $pattern): array
    {
        $tokens = [];
        \preg_match_all('/\{(YYYY|YY|MM|seq:(\d+)|field:([a-z][a-z0-9_]*))\}|[^{]+/', $pattern, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (\count($t) === 1) {
                $tokens[] = ['text' => $t[0]];
            } elseif (\str_starts_with($t[1], 'seq:')) {
                $tokens[] = ['seq' => (int) $t[2]];
            } elseif (\str_starts_with($t[1], 'field:')) {
                $tokens[] = ['field' => $t[3]];
            } else {
                $tokens[] = ['date' => $t[1]];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $options dependencies (package code => definition), previous (definition), system (bool); caller-supplied, checked here
     *
     * @return array{ok: bool, plan: ?array<string, mixed>, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function compile(array $def, array $options = []): array
    {
        $result = (new Validator($this->caps))->validate($def, $options);
        if (!$result->ok()) {
            return ['ok' => false, 'plan' => null, 'issues' => $result->issuesArray()];
        }

        try {
            $plan = $this->plan($def, $result, $options);
        } catch (ExpressionError $e) {
            // validation should have caught this; never let it escape the API
            $issues = $result->issuesArray();
            $issues[] = Issue::error('/', 'compile.' . $e->getErrorCode(), $e->getMessage())->toArray();

            return ['ok' => false, 'plan' => null, 'issues' => $issues];
        }

        return ['ok' => true, 'plan' => $plan, 'issues' => $result->issuesArray()];
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $options caller-supplied; dependencies are re-checked here
     *
     * @return array<string, mixed>
     */
    private function plan(array $def, ValidationResult $result, array $options): array
    {
        $this->expr = $result->expressions();
        $package = (string) $def['code'];
        $deps = [];
        foreach (\is_array($options['dependencies'] ?? null) ? $options['dependencies'] : [] as $code => $d) {
            if (isset($def['depends_on'][$code]) && \is_array($d)) {
                $deps[$code] = $d;
            }
        }
        $catalog = new Catalog($package, $def, $deps, $this->caps);

        $entities = [];
        foreach ($def['entities'] as $i => $entity) {
            $entities[$entity['code']] = $this->entity($entity, "/entities/{$i}", $package, $catalog);
        }

        $workflows = [];
        foreach ($def['workflows'] ?? [] as $i => $wf) {
            $workflows[$wf['code']] = $this->workflow($wf, "/workflows/{$i}");
        }

        $rules = [];
        $abilities = [];
        foreach ($entities as $code => $e) {
            foreach ($e['abilities'] as $a) {
                $abilities[$a] = true;
            }
        }
        foreach ($def['permissions']['rules'] ?? [] as $i => $r) {
            $base = "apps.{$package}.{$r['entity']}";
            $ruleAbilities = \array_map(static fn (string $op): string => "{$base}.{$op}", $r['ops']);
            foreach ($r['workflow'] ?? [] as $w) {
                $ruleAbilities[] = "{$base}.workflow.{$w}";
            }
            $rules[] = [
                'role' => $r['role'],
                'entity' => $r['entity'],
                'ops' => \array_values($r['ops']),
                'row' => ($r['row'] ?? 'all') === 'all' ? 'all' : $this->typed("/permissions/rules/{$i}/row"),
                'fields' => $r['fields'] ?? [],
                'workflow' => $r['workflow'] ?? [],
                'abilities' => $ruleAbilities,
            ];
        }
        $abilities = \array_keys($abilities);
        \sort($abilities);

        return [
            'plan_version' => self::PLAN_VERSION,
            'apps_schema' => Schema::VERSION,
            'package' => $package,
            'version' => $def['version'],
            'content_hash' => Canonical::hash($def),
            'label' => $def['label'] ?? 'app.label',
            'requires_engine' => $def['requires_engine'] ?? '*',
            'depends_on' => $def['depends_on'] ?? [],
            'lineage' => $def['lineage'] ?? ['base' => null],
            'roles' => $def['roles'] ?? [],
            'menu' => $def['menu'] ?? [],
            'entities' => $entities,
            'workflows' => $workflows,
            'permissions' => ['rules' => $rules],
            'abilities' => $abilities,
            'i18n_keys' => $result->i18nKeys(),
            'weight_counts' => Weight::counts($def),
        ];
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array<string, mixed>
     */
    private function entity(array $entity, string $p, string $package, Catalog $catalog): array
    {
        $code = $entity['code'];
        $fields = [];
        $indexes = [];
        $search = [];
        $sortable = [];
        foreach ($entity['fields'] as $i => $f) {
            $fp = "{$p}/fields/{$i}";
            $type = $f['type'];
            if ($type === 'relation') {
                // computed directly: the target may be in a dependency that was not supplied
                $target = $this->qualify($package, (string) $f['to']);
                $valueType = ($f['cardinality'] === 'many_to_one' ? 'ref:' : 'list:ref:') . $target;
            } else {
                $valueType = $catalog->resolve($package, $code, [$f['code']], 0)['type'];
            }
            $out = [
                'id' => $f['id'],
                'code' => $f['code'],
                'type' => $type,
                'label' => $f['label'] ?? "{$code}.{$f['code']}.label",
                'value_type' => $valueType,
                'required' => ($f['required'] ?? false) === true,
                'unique' => ($f['unique'] ?? false) === true,
                'search' => ($f['search'] ?? false) === true,
                'pii' => ($f['pii'] ?? false) === true,
                'stored' => $this->stored($f),
            ];
            if (isset($f['help'])) {
                $out['help'] = $f['help'];
            }
            $params = \array_intersect_key($f, \array_flip(Schema::FIELD_TYPES[$type]));
            unset($params['expr'], $params['options'], $params['to'], $params['cardinality'], $params['on_delete'], $params['via']);
            if ($params !== []) {
                $out['params'] = $params;
            }
            if ($type === 'select' || $type === 'multi_select') {
                $out['options'] = $this->options($f, $code);
            }
            if ($type === 'relation') {
                $out['relation'] = [
                    'to' => $this->qualify($package, (string) $f['to']),
                    'cardinality' => $f['cardinality'],
                    'on_delete' => $f['cardinality'] === 'many_to_one' ? ($f['on_delete'] ?? 'restrict') : null,
                    'via' => $f['via'] ?? null,
                ];
                if ($f['cardinality'] === 'many_to_one') {
                    $indexes[] = ['fields' => [$f['code']], 'unique' => false, 'source' => 'relation'];
                }
            }
            if ($type === 'computed') {
                $out['expr'] = $this->typed("{$fp}/expr");
            }
            if (\array_key_exists('default', $f)) {
                $out['default'] = isset($this->expr["{$fp}/default"])
                    ? ['expr' => $this->typed("{$fp}/default")]
                    : ['value' => $f['default']];
            }
            if ($out['unique']) {
                $indexes[] = ['fields' => [$f['code']], 'unique' => true, 'source' => 'unique'];
            }
            if ($out['search']) {
                $search[] = $f['code'];
            }
            if ($out['stored'] && !\in_array($type, ['long_text', 'rich_text', 'multi_select', 'file', 'files', 'image'], true)
                && !($type === 'relation' && $f['cardinality'] !== 'many_to_one')) {
                $sortable[] = $f['code'];
            }
            $fields[$f['code']] = $out;
        }
        foreach ($entity['indexes'] ?? [] as $index) {
            $list = \array_is_list($index) ? $index : $index['fields'];
            $indexes[] = ['fields' => \array_values($list), 'unique' => !\array_is_list($index) && ($index['unique'] ?? false) === true, 'source' => 'declared'];
        }
        $indexes = $this->dedupeIndexes($indexes);

        $system = Schema::SYSTEM_COLUMNS;
        $numbering = null;
        if (isset($entity['numbering'])) {
            $numbering = [
                'pattern' => $entity['numbering']['pattern'],
                'tokens' => self::numberingTokens($entity['numbering']['pattern']),
                'reset' => $entity['numbering']['reset'] ?? 'never',
            ];
        } else {
            unset($system['number']);
        }

        $abilities = [];
        foreach (Schema::OPS as $op) {
            $abilities[] = "apps.{$package}.{$code}.{$op}";
        }
        foreach (Schema::WORKFLOW_ABILITIES as $w) {
            $abilities[] = "apps.{$package}.{$code}.workflow.{$w}";
        }

        return [
            'id' => $entity['id'],
            'code' => $code,
            'qualified' => "{$package}.{$code}",
            'label' => $entity['label'] ?? "{$code}.label",
            'numbering' => $numbering,
            'system_fields' => $system,
            'fields' => $fields,
            'indexes' => $indexes,
            'search' => $search,
            'sortable' => \array_merge(\array_keys($system), $sortable),
            'views' => $this->views($entity['views'] ?? [], "{$p}/views", $code),
            'abilities' => $abilities,
        ];
    }

    /**
     * @param array<string, mixed> $views
     *
     * @return array<string, mixed>
     */
    private function views(array $views, string $p, string $entity): array
    {
        $out = [];
        if (isset($views['list'])) {
            $list = $views['list'];
            $saved = [];
            foreach ($list['saved'] ?? [] as $i => $s) {
                $saved[$s['code']] = [
                    'id' => $s['id'],
                    'code' => $s['code'],
                    'label' => $s['label'] ?? "{$entity}.view.{$s['code']}",
                    'filter' => $this->typed("{$p}/list/saved/{$i}/filter"),
                    'sort' => $s['sort'] ?? null,
                    'columns' => $s['columns'] ?? null,
                ];
            }
            $out['list'] = [
                'columns' => $list['columns'],
                'default_sort' => $list['default_sort'] ?? '-created_at',
                'filters' => $list['filters'] ?? [],
                'page_size' => $list['page_size'] ?? 50,
                'bulk' => $list['bulk'] ?? [],
                'saved' => $saved,
            ];
        }
        if (isset($views['form'])) {
            $sections = [];
            foreach ($views['form']['sections'] as $i => $s) {
                $sp = "{$p}/form/sections/{$i}";
                $sections[] = [
                    'label' => $s['label'],
                    'fields' => $s['fields'],
                    'columns' => $s['columns'] ?? 1,
                    'visible' => isset($s['visible']) ? $this->typed("{$sp}/visible") : null,
                    'readonly' => isset($s['readonly']) ? $this->typed("{$sp}/readonly") : null,
                ];
            }
            $out['form'] = ['sections' => $sections];
        }
        if (isset($views['board'])) {
            $out['board'] = [
                'group_by' => $views['board']['group_by'],
                'card' => $views['board']['card'],
                'wip_limits' => $views['board']['wip_limits'] ?? [],
            ];
        }
        if (isset($views['detail'])) {
            $out['detail'] = ['header' => $views['detail']['header'] ?? [], 'tabs' => $views['detail']['tabs'] ?? []];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $wf
     *
     * @return array<string, mixed>
     */
    private function workflow(array $wf, string $p): array
    {
        $states = [];
        $initial = null;
        foreach ($wf['states'] ?? [] as $s) {
            $states[$s['code']] = [
                'id' => $s['id'],
                'code' => $s['code'],
                'label' => $s['label'] ?? "workflow.{$wf['code']}.state.{$s['code']}",
                'category' => $s['category'],
                'props' => $s['props'] ?? [],
            ];
            if (($s['initial'] ?? false) === true) {
                $initial = $s['code'];
            }
        }
        $initial ??= \array_key_first($states);

        $nodes = [];
        foreach ($wf['nodes'] ?? [] as $i => $n) {
            $np = "{$p}/nodes/{$i}";
            $node = $n;
            unset($node['label']);
            $node['label'] = $n['label'] ?? "workflow.{$wf['code']}.node.{$n['code']}";
            foreach (['expr', 'applicable'] as $key) {
                if (isset($n[$key])) {
                    $node[$key] = $this->typed("{$np}/{$key}");
                }
            }
            if ($n['type'] === 'update_record') {
                foreach ($n['set'] as $fc => $value) {
                    $sp = "{$np}/set/" . \str_replace(['~', '/'], ['~0', '~1'], (string) $fc);
                    $node['set'][$fc] = isset($this->expr[$sp]) ? ['expr' => $this->typed($sp)] : ['value' => $value];
                }
            }
            if ($n['type'] === 'external' && isset($n['input'])) {
                foreach ($n['input'] as $key => $_) {
                    $node['input'][$key] = $this->typed("{$np}/input/{$key}");
                }
            }
            $nodes[$n['code']] = $node;
        }

        return [
            'id' => $wf['id'],
            'code' => $wf['code'],
            'entity' => $wf['entity'],
            'label' => $wf['label'] ?? "workflow.{$wf['code']}.label",
            'states' => $states,
            'initial' => $initial,
            'nodes' => $nodes,
            'edges' => $wf['edges'] ?? [],
        ];
    }

    /**
     * @return array{src: string, type: string, ast: array<string, mixed>}
     */
    private function typed(string $pointer): array
    {
        $e = $this->expr[$pointer] ?? throw new LogicException("No typed expression at {$pointer}");

        return ['src' => $e['src'], 'type' => (string) $e['ast']['t'], 'ast' => $e['ast']];
    }

    /**
     * @param array<string, mixed> $f
     */
    private function stored(array $f): bool
    {
        return match ($f['type']) {
            'computed' => ($f['stored'] ?? false) === true,
            'rollup' => false,
            'relation' => $f['cardinality'] === 'many_to_one',
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return list<array{key: string, label: string, tone: string}>
     */
    private function options(array $f, string $entity): array
    {
        $out = [];
        foreach ($f['options'] as $opt) {
            $key = \is_array($opt) ? $opt['key'] : $opt;
            $out[] = [
                'key' => $key,
                'label' => \is_array($opt) && isset($opt['label']) ? $opt['label'] : "{$entity}.{$f['code']}.option.{$key}",
                'tone' => \is_array($opt) ? ($opt['tone'] ?? 'neutral') : 'neutral',
            ];
        }

        return $out;
    }

    private function qualify(string $package, string $ref): string
    {
        return \str_contains($ref, '.') ? $ref : "{$package}.{$ref}";
    }

    /**
     * @param list<array{fields: list<string>, unique: bool, source: string}> $indexes
     *
     * @return list<array{fields: list<string>, unique: bool, source: string}>
     */
    private function dedupeIndexes(array $indexes): array
    {
        $out = [];
        foreach ($indexes as $index) {
            $key = \implode(',', $index['fields']);
            if (isset($out[$key])) {
                $out[$key]['unique'] = $out[$key]['unique'] || $index['unique'];
                continue;
            }
            $out[$key] = $index;
        }

        return \array_values($out);
    }
}
