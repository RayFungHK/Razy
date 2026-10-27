<?php

/**
 * razymod/apps — size caps for definitions and expressions.
 *
 * Defaults are the engine's ceiling. Config can only narrow a cap: a config
 * value above the default, zero, negative or not an integer is ignored and
 * the default stays. A definition over a cap is refused with the element
 * path, never trimmed (manual/15-razy-apps.md §3.7).
 */

declare(strict_types=1);

namespace razymod\apps;

use InvalidArgumentException;

final class Caps
{
    public const DEFAULTS = [
        // definition
        'definition_bytes' => 1_000_000,
        'entities_per_app' => 50,
        'fields_per_entity' => 200,
        'relations_per_entity' => 30,
        'views_per_entity' => 4,
        'saved_views_per_entity' => 30,
        'indexes_per_entity' => 20,
        'fields_per_index' => 4,
        'options_per_select' => 200,
        'roles_per_app' => 30,
        'permission_rules' => 500,
        'menu_items' => 50,
        'form_sections' => 30,
        'fields_per_view' => 60,
        'text_max' => 10_000,
        'workflows_per_app' => 20,
        'workflow_nodes' => 100,
        'workflow_states' => 50,
        'i18n_locales' => 20,
        'i18n_keys' => 5_000,
        'i18n_value_length' => 2_000,
        'lookup_depth' => 3,
        'dependencies_per_app' => 20,
        // expressions
        'expression_length' => 1_000,
        'expression_depth' => 24,
        'expression_nodes' => 400,
        'expression_args' => 32,
        'literal_length' => 500,
        // evaluator
        'eval_steps' => 10_000,
        'eval_string_bytes' => 65_536,
        'eval_list_items' => 1_000,
    ];

    /** @var array<string, int> */
    private array $values;

    /**
     * @param array<string, mixed> $config narrowing overrides (unknown keys ignored)
     */
    public function __construct(array $config = [])
    {
        $values = self::DEFAULTS;
        foreach ($config as $name => $value) {
            if (!isset($values[$name]) || !\is_int($value) || $value < 1) {
                continue;
            }
            if ($value < $values[$name]) {
                $values[$name] = $value;
            }
        }
        $this->values = $values;
    }

    public function get(string $name): int
    {
        return $this->values[$name] ?? throw new InvalidArgumentException("Unknown cap '{$name}'");
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->values;
    }
}
