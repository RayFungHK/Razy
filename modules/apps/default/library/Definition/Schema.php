<?php

/**
 * razymod/apps — the closed lists of definition schema v1 (`apps_schema: 1`).
 *
 * Shared by the validator, the compiler and the weight function so they
 * cannot drift. The shape itself is documented in manual/15-razy-apps.md.
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

final class Schema
{
    public const VERSION = 1;

    /** Element codes: entities, fields, roles, views, workflows, nodes, states. */
    public const CODE = '/^[a-z][a-z0-9_]{0,39}$/';

    /** Package codes: lower-case words joined by single hyphens. */
    public const PACKAGE_CODE = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** Select option keys (HKD, net_30, …). */
    public const OPTION = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/';

    /** i18n keys. */
    public const I18N_KEY = '/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/';

    public const LOCALE = '/^[a-z]{2,3}(-[A-Z]{2}|-[A-Z][a-z]{3})?$/';

    public const SEMVER = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(-[0-9A-Za-z.-]{1,40})?$/';

    /** A version constraint: space-separated comparisons, or "*". */
    public const CONSTRAINT = '/^(\*|(>=|<=|>|<|=|\^|~)?\d{1,6}(\.\d{1,6}){0,2}( +(>=|<=|>|<|=|\^|~)?\d{1,6}(\.\d{1,6}){0,2}){0,3})$/';

    public const EXECUTOR = '/^[a-z][a-z0-9_]{0,39}(\.[a-z][a-z0-9_]{0,39}){1,3}$/';

    public const MIME = '/^[a-z]{1,20}\/([a-z0-9][a-z0-9.+-]{0,60}|\*)$/';

    /**
     * Columns every entity table has (RZA-B); readable in views and
     * expressions, never declared as fields.
     *
     * @var array<string, string> code => expression type
     */
    public const SYSTEM_COLUMNS = [
        'number' => 'text',
        'created_at' => 'datetime',
        'created_by' => 'user',
        'updated_at' => 'datetime',
        'updated_by' => 'user',
    ];

    /** Codes no entity or field may take: system columns and SQL / expression keywords. */
    public const RESERVED = [
        'id', 'uid', 'number', 'created_at', 'created_by', 'updated_at', 'updated_by', 'version', 'retired_at',
        'search', 'all', 'and', 'any', 'array', 'as', 'asc', 'between', 'both', 'by', 'case', 'cast', 'check',
        'collate', 'column', 'constraint', 'create', 'cross', 'current_date', 'current_time',
        'current_timestamp', 'current_user', 'default', 'delete', 'desc', 'distinct', 'do', 'drop', 'else',
        'end', 'except', 'exists', 'false', 'fetch', 'for', 'foreign', 'from', 'full', 'grant', 'group',
        'having', 'in', 'index', 'inner', 'insert', 'intersect', 'into', 'is', 'join', 'key', 'lateral',
        'leading', 'left', 'like', 'limit', 'natural', 'not', 'null', 'offset', 'on', 'only', 'or', 'order',
        'outer', 'primary', 'references', 'returning', 'right', 'select', 'session_user', 'set', 'some',
        'table', 'then', 'to', 'trailing', 'true', 'union', 'unique', 'update', 'user', 'using', 'values',
        'when', 'where', 'window', 'with', 'oid', 'tableoid', 'xmin', 'xmax', 'cmin', 'cmax', 'ctid',
    ];

    /**
     * Field types (v1, closed) and their type-specific keys.
     *
     * @var array<string, list<string>>
     */
    public const FIELD_TYPES = [
        'text' => ['max'],
        'long_text' => ['max'],
        'rich_text' => [],
        'integer' => ['min', 'max'],
        'decimal' => ['precision', 'scale', 'min', 'max'],
        'money' => ['currency_field', 'currency', 'scale', 'min', 'max'],
        'percent' => ['scale', 'min', 'max'],
        'boolean' => [],
        'date' => [],
        'datetime' => [],
        'duration' => ['min', 'max'],
        'email' => [],
        'phone' => [],
        'url' => [],
        'select' => ['options'],
        'multi_select' => ['options', 'max_selected'],
        'status' => ['workflow'],
        'user' => [],
        'group' => [],
        'department' => [],
        'relation' => ['to', 'cardinality', 'on_delete', 'via'],
        'file' => ['accept', 'max_size_mb'],
        'files' => ['accept', 'max_size_mb', 'max_files'],
        'image' => ['max_size_mb'],
        'computed' => ['expr', 'stored'],
        'rollup' => ['relation', 'op', 'field'],
        'sequence' => ['pattern', 'reset'],
        'created_at' => [],
        'updated_at' => [],
        'created_by' => [],
    ];

    public const FIELD_COMMON = ['id', 'code', 'type', 'label', 'help', 'required', 'default', 'search', 'pii', 'unique'];

    /** Types the engine fills in; no required / default / unique. */
    public const DERIVED_TYPES = ['computed', 'rollup', 'sequence', 'created_at', 'updated_at', 'created_by'];

    /** Field types that add nothing to the weight (system fields). */
    public const SYSTEM_TYPES = ['created_at', 'updated_at', 'created_by'];

    public const SEARCHABLE = ['text', 'long_text', 'rich_text', 'email', 'phone', 'url', 'select', 'sequence'];

    public const NOT_INDEXABLE = ['long_text', 'rich_text', 'multi_select', 'files', 'file', 'image', 'rollup'];

    public const CARDINALITY = ['many_to_one', 'one_to_many', 'many_to_many'];

    public const ON_DELETE = ['restrict', 'set_null', 'cascade_soft'];

    public const ROLLUP_OPS = ['count', 'sum', 'min', 'max'];

    public const RESET = ['yearly', 'monthly', 'never'];

    public const TONES = ['neutral', 'info', 'success', 'warning', 'danger'];

    public const VIEW_KINDS = ['list', 'form', 'board', 'detail'];

    public const BULK_ACTIONS = ['export', 'retire'];

    public const OPS = ['create', 'read', 'update', 'delete', 'export'];

    public const FIELD_ACCESS = ['read', 'write', 'none'];

    public const WORKFLOW_ABILITIES = ['start', 'complete', 'verify', 'recall', 'reassign'];

    public const STATE_CATEGORIES = ['open', 'active', 'done', 'cancelled'];

    /**
     * Workflow node types (v1, closed) and their type-specific keys.
     *
     * @var array<string, list<string>>
     */
    public const NODE_TYPES = [
        'trigger' => ['on', 'field', 'state', 'cron', 'source'],
        'human' => ['assign', 'verify', 'form', 'due_days', 'applicable', 'bound'],
        'condition' => ['expr'],
        'fork' => [],
        'join' => [],
        'wait' => ['days', 'until'],
        'set_status' => ['state'],
        'update_record' => ['set'],
        'notify' => ['to', 'message'],
        'external' => ['executor', 'input'],
        'end' => [],
    ];

    public const TRIGGERS = ['created', 'updated', 'field_changed', 'status_entered', 'schedule', 'manual', 'external'];
}
