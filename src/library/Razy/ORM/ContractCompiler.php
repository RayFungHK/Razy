<?php

declare(strict_types=1);

namespace Razy\ORM;

use Razy\Database\SchemaBuilder;
use Razy\Database\Table;

/**
 * Compiles a Contract into the machinery that already exists (C1/C2 of
 * architecture/ORM-CONTRACT-PACKS.md §6.2):
 *
 *  - CREATE TABLE SQL   via Table + the Column grammar (no new DSL);
 *  - live application   via SchemaBuilder::create() (same path migrations use);
 *  - a migration SOURCE the module can write into its own migration/ dir —
 *    per-module generation keeps modules as legal entities (RZ-001/008);
 *  - a snapshot()/drift() pair built on Table::exportConfig() (Table.php:536):
 *    store the snapshot, and a later Contract either matches it or reports
 *    added/removed/changed columns.
 *
 * HONEST SCOPE: this is create-generate + snapshot-drift REPORTING. It does
 * NOT generate ALTER diffs against the live database — that needs driver
 * introspection (describeTable) which verifiably does not exist in this
 * framework (§3.2); promising it here would repeat exactly the kind of
 * doc-vs-code drift this repo's dossiers spend pages cataloguing.
 */
final class ContractCompiler
{
    /** Column syntax used for model timestamp columns (matches Model's string datetimes). */
    private const TIMESTAMP_SYNTAX = 'type(text),nullable';

    /**
     * @param array<string, array{column: string, json: array<string, string>}> $fields
     */
    private static function addColumns(Table $table, array $fields, bool $timestamps): void
    {
        foreach ($fields as $name => $field) {
            $table->addColumn($name . '=' . $field['column']);
        }

        if ($timestamps) {
            $table->addColumn('created_at=' . self::TIMESTAMP_SYNTAX);
            $table->addColumn('updated_at=' . self::TIMESTAMP_SYNTAX);
        }
    }

    /**
     * Parse the `[col:col:…]` column list of an exportConfig() string into
     * name => segment. Returns null when the string cannot be understood.
     *
     * @return array<string, string>|null
     */
    private static function columnSegments(string $config): ?array
    {
        $open = \strpos($config, '[');
        if ($open === false) {
            // Any compiled-contract snapshot ALWAYS has a column block — a
            // bracket-less string is an alien shape, not an empty table.
            return null;
        }

        $inner = \substr($config, $open + 1);
        $end = \strpos($inner, ']');
        if ($end === false) {
            return null;
        }
        $firstBlock = \substr($inner, 0, $end);
        $segments = [];

        foreach (\explode(':', $firstBlock) as $segment) {
            $eq = \strpos($segment, '=');
            if ($eq === false || $eq === 0) {
                return null;
            }
            $segments[\trim(\substr($segment, 0, $eq), '`')] = $segment;
        }

        return $segments;
    }

    private static function escape(string $value): string
    {
        return \str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Pure CREATE TABLE string — no database needed (tests pin this).
     */
    public function createTableSql(Contract $contract, string $prefix = ''): string
    {
        return $this->buildTable($contract, $prefix)->getSyntax();
    }

    /**
     * Execute the CREATE via the schema pipeline (SchemaBuilder::create
     * path, SchemaBuilder.php:64-70 — prefix re-applied there, so compile
     * here WITHOUT prefix and let the builder own it).
     */
    public function apply(Contract $contract, SchemaBuilder $schema): void
    {
        $definition = $contract->getFields();
        $timestamps = $contract->hasTimestamps();
        $indexes = $contract->getIndexes();

        $schema->create($contract->getTable(), static function (Table $table) use ($definition, $timestamps, $indexes): void {
            self::addColumns($table, $definition, $timestamps);

            foreach ($indexes as $index) {
                $table->groupIndexing($index['columns'], $index['name']);
            }
        });
    }

    /**
     * Current-shape snapshot for the drift discipline: store this string
     * (e.g. beside the Contract file) and compare after edits.
     */
    public function snapshot(Contract $contract, string $prefix = ''): string
    {
        return $this->buildTable($contract, $prefix)->exportConfig();
    }

    /**
     * Compare a previously stored snapshot against the Contract as it is NOW.
     *
     * Column segments come from exportConfig()'s `[col:col:…]` list; anything
     * unparsable degrades honestly to a whole-string `shape_changed` verdict
     * rather than guessing.
     *
     * @return array{clean: bool, added: list<string>, removed: list<string>, changed: list<string>, shape_changed: bool}
     */
    public function drift(Contract $contract, string $previousSnapshot, string $prefix = ''): array
    {
        $current = $this->snapshot($contract, $prefix);

        if ($current === $previousSnapshot) {
            return ['clean' => true, 'added' => [], 'removed' => [], 'changed' => [], 'shape_changed' => false];
        }

        $before = self::columnSegments($previousSnapshot);
        $after = self::columnSegments($current);

        if ($before === null || $after === null) {
            return ['clean' => false, 'added' => [], 'removed' => [], 'changed' => [], 'shape_changed' => true];
        }

        $added = \array_keys(\array_diff_key($after, $before));
        $removed = \array_keys(\array_diff_key($before, $after));
        $changed = [];

        foreach ($after as $name => $segment) {
            if (isset($before[$name]) && $before[$name] !== $segment) {
                $changed[] = $name;
            }
        }

        return [
            'clean' => $added === [] && $removed === [] && $changed === [],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'shape_changed' => false,
        ];
    }

    /**
     * Generate the SOURCE of a module migration file whose up() creates the
     * contract's table (C1: output is a normal, editable migration shipped
     * INSIDE the module — the normal MigrationManager pipeline owns rollout).
     */
    public function toMigrationSource(Contract $contract, string $description = ''): string
    {
        $addColumns = [];
        foreach ($this->orderedColumns($contract) as $column => $syntax) {
            $addColumns[] = "                \$table->addColumn('" . self::escape($column . '=' . $syntax) . "');";
        }
        $indexLines = [];
        foreach ($contract->getIndexes() as $index) {
            $quoted = \array_map(static fn (string $c): string => "'" . self::escape($c) . "'", $index['columns']);
            $name = $index['name'] !== '' ? ", '" . self::escape($index['name']) . "'" : '';
            $indexLines[] = '            $table->groupIndexing([' . \implode(', ', $quoted) . "]{$name});";
        }

        $indexBlock = $indexLines === [] ? '' : "\n" . \implode("\n", $indexLines);
        $columnBlock = \implode("\n", $addColumns);
        $description = self::escape($description !== '' ? $description : 'Create ' . $contract->getTable() . ' table from contract');

        return <<<PHP
            <?php

            declare(strict_types=1);

            // Generated from Contract(table: {$contract->getTable()}) — review before shipping;
            // this is a normal editable migration (contract create-generate C1).

            use Razy\\Database\\Migration;
            use Razy\\Database\\SchemaBuilder;
            use Razy\\Database\\Table;

            return new class extends Migration {
                public function up(SchemaBuilder \$schema): void
                {
                    \$schema->create('{$contract->getTable()}', static function (Table \$table): void {
                    {$columnBlock}{$indexBlock}
                    });
                }

                public function down(SchemaBuilder \$schema): void
                {
                    \$schema->dropIfExists('{$contract->getTable()}');
                }

                public function getDescription(): string
                {
                    return '{$description}';
                }
            };
            PHP;
    }

    /**
     * Columns in declaration order, plus timestamp pair when enabled.
     *
     * @return array<string, string> column => Column-syntax
     */
    private function orderedColumns(Contract $contract): array
    {
        $columns = [];
        foreach ($contract->getFields() as $name => $field) {
            $columns[$name] = $field['column'];
        }

        if ($contract->hasTimestamps()) {
            $columns['created_at'] = self::TIMESTAMP_SYNTAX;
            $columns['updated_at'] = self::TIMESTAMP_SYNTAX;
        }

        return $columns;
    }

    private function buildTable(Contract $contract, string $prefix): Table
    {
        $table = new Table($prefix . $contract->getTable());

        self::addColumns($table, $contract->getFields(), $contract->hasTimestamps());

        foreach ($contract->getIndexes() as $index) {
            $table->groupIndexing($index['columns'], $index['name']);
        }

        return $table;
    }
}
