<?php

/**
 * razymod/apps — the weight of a definition (manual/15-razy-apps.md §6).
 *
 *   weight = c.entity × entities + c.field × fields + c.relation × relations
 *          + c.view × views + c.saved_view × saved views
 *          + c.row_rule_role × roles with row rules
 *
 * One function for the registry, the approval card and billing. Counting:
 * - fields: every declared field except the system types (created_at,
 *   updated_at, created_by); computed and rollup fields count; a relation
 *   field counts as a field and as a relation
 * - views: each of list / form / board / detail an entity defines
 * - saved views: list.saved entries
 * - roles with row rules: roles with at least one rule whose row is an
 *   expression (not "all")
 * - i18n, numbering, options and labels add nothing
 * - workflow nodes are reported separately (wf_nodes): every node except
 *   trigger (start) and end
 *
 * The coefficients are rate-card settings and are always passed in.
 * REFERENCE holds the published reference values, the starting rate
 * card. The module's `weight` command reads the live values from config
 * and uses REFERENCE only when none are set, saying so in its answer.
 */

declare(strict_types=1);

namespace razymod\apps;

use InvalidArgumentException;
use razymod\apps\Definition\Schema;

final class Weight
{
    public const REFERENCE = [
        'entity' => 10,
        'field' => 1,
        'relation' => 2,
        'view' => 2,
        'saved_view' => 1,
        'row_rule_role' => 3,
    ];

    /**
     * @param array<string, mixed> $def a valid definition
     *
     * @return array{entities: int, fields: int, relations: int, views: int, saved_views: int, row_rule_roles: int, wf_nodes: int, by_entity: array<string, array{fields: int, relations: int, views: int, saved_views: int}>}
     */
    public static function counts(array $def): array
    {
        $counts = ['entities' => 0, 'fields' => 0, 'relations' => 0, 'views' => 0, 'saved_views' => 0,
            'row_rule_roles' => 0, 'wf_nodes' => 0, 'by_entity' => []];

        foreach (self::items($def['entities'] ?? null) as $entity) {
            $code = \is_string($entity['code'] ?? null) ? $entity['code'] : '';
            $e = ['fields' => 0, 'relations' => 0, 'views' => 0, 'saved_views' => 0];
            foreach (self::items($entity['fields'] ?? null) as $field) {
                $type = $field['type'] ?? null;
                if (\in_array($type, Schema::SYSTEM_TYPES, true)) {
                    continue;
                }
                ++$e['fields'];
                if ($type === 'relation') {
                    ++$e['relations'];
                }
            }
            $views = \is_array($entity['views'] ?? null) ? $entity['views'] : [];
            foreach (Schema::VIEW_KINDS as $kind) {
                if (\is_array($views[$kind] ?? null)) {
                    ++$e['views'];
                }
            }
            $e['saved_views'] = \count(self::items($views['list']['saved'] ?? null));

            ++$counts['entities'];
            $counts['fields'] += $e['fields'];
            $counts['relations'] += $e['relations'];
            $counts['views'] += $e['views'];
            $counts['saved_views'] += $e['saved_views'];
            $counts['by_entity'][$code] = $e;
        }

        $rowRoles = [];
        $perm = \is_array($def['permissions'] ?? null) ? $def['permissions'] : [];
        foreach (self::items($perm['rules'] ?? null) as $rule) {
            $row = $rule['row'] ?? 'all';
            if ($row !== 'all' && \is_string($rule['role'] ?? null)) {
                $rowRoles[$rule['role']] = true;
            }
        }
        $counts['row_rule_roles'] = \count($rowRoles);

        foreach (self::items($def['workflows'] ?? null) as $wf) {
            $counts['wf_nodes'] += self::nodes($wf['nodes'] ?? null, 0);
        }

        return $counts;
    }

    /**
     * @param array<string, int|float> $coefficients every key of REFERENCE, each ≥ 0
     */
    public static function score(array $counts, array $coefficients): int|float
    {
        self::checkCoefficients($coefficients);
        $w = $coefficients['entity'] * $counts['entities']
            + $coefficients['field'] * $counts['fields']
            + $coefficients['relation'] * $counts['relations']
            + $coefficients['view'] * $counts['views']
            + $coefficients['saved_view'] * $counts['saved_views']
            + $coefficients['row_rule_role'] * $counts['row_rule_roles'];

        return \is_float($w) ? \round($w, 4) : $w;
    }

    /**
     * Weight, workflow nodes and the breakdown in one call.
     *
     * @param array<string, mixed> $def
     * @param array<string, int|float> $coefficients
     *
     * @return array{weight: int|float, wf_nodes: int, counts: array<string, mixed>, coefficients: array<string, int|float>}
     */
    public static function of(array $def, array $coefficients): array
    {
        $counts = self::counts($def);

        return [
            'weight' => self::score($counts, $coefficients),
            'wf_nodes' => $counts['wf_nodes'],
            'counts' => $counts,
            'coefficients' => $coefficients,
        ];
    }

    /**
     * @param array<mixed> $coefficients
     */
    public static function checkCoefficients(array $coefficients): void
    {
        foreach (self::REFERENCE as $key => $_) {
            $c = $coefficients[$key] ?? null;
            if (!(\is_int($c) || (\is_float($c) && \is_finite($c))) || $c < 0) {
                throw new InvalidArgumentException("Weight coefficient '{$key}' must be a number ≥ 0");
            }
        }
        foreach (\array_keys($coefficients) as $key) {
            if (!isset(self::REFERENCE[$key])) {
                throw new InvalidArgumentException("Unknown weight coefficient '{$key}'");
            }
        }
    }

    /**
     * Workflow nodes except trigger and end, walking any nested `nodes`
     * (schema v1 has none, and the validator refuses unknown keys; the walk
     * keeps a shape it never saw from weighing nothing).
     */
    private static function nodes(mixed $list, int $depth): int
    {
        $n = 0;
        foreach ($depth < 16 ? self::items($list) : [] as $node) {
            $type = $node['type'] ?? null;
            if ($type !== 'trigger' && $type !== 'end') {
                ++$n;
            }
            $n += self::nodes($node['nodes'] ?? null, $depth + 1);
        }

        return $n;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        return \array_values(\array_filter($list, static fn (mixed $x): bool => \is_array($x)));
    }
}
