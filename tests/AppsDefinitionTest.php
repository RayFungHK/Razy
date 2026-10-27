<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use razymod\apps\Caps;
use razymod\apps\Definition\Canonical;
use razymod\apps\Definition\ElementIds;
use razymod\apps\Definition\Issue;
use razymod\apps\Definition\PackageLoader;
use razymod\apps\Definition\ValidationResult;
use razymod\apps\Definition\Validator;
use stdClass;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * razymod/apps definitions (manual/15-razy-apps.md §2-4): schema v1, the validator
 * and its caps, element ids, canonical JSON + content hash, and the package
 * loader, against the crm-core / crm-deals fixture packages and hostile
 * variations of them.
 */
#[CoversNothing]
class AppsDefinitionTest extends TestCase
{
    private const PACKAGES = __DIR__ . '/fixtures/apps/packages';

    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && \is_dir($this->tmp)) {
            $this->rmTree($this->tmp);
        }
        $this->tmp = '';
        parent::tearDown();
    }

    // ── structure and codes ────────────────────────────────────────

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function hostileFieldCodes(): iterable
    {
        yield 'sql injection' => ['x"; DROP TABLE deal; --', 'code.pattern'];
        yield 'quote' => ["o'neil", 'code.pattern'];
        yield 'upper case' => ['Amount', 'code.pattern'];
        yield 'leading digit' => ['1st', 'code.pattern'];
        yield 'leading underscore' => ['__proto__', 'code.pattern'];
        yield 'too long' => [\str_repeat('a', 41), 'code.pattern'];
        yield 'unicode' => ['金額', 'code.pattern'];
        yield 'empty' => ['', 'code.pattern'];
        yield 'not a string' => [42, 'code.pattern'];
        yield 'sql keyword' => ['select', 'code.reserved'];
        yield 'system column' => ['id', 'code.reserved'];
        yield 'system created_at' => ['created_at', 'code.reserved'];
        yield 'postgres system column' => ['ctid', 'code.reserved'];
        yield 'expression keyword' => ['true', 'code.reserved'];
    }

    // ── fixtures ───────────────────────────────────────────────────

    public function testFixturePackagesAreValidSystemTemplates(): void
    {
        $core = $this->core();
        $deals = $this->deals();
        $this->assertClean((new Validator())->validate($core, ['system' => true]));
        $this->assertClean((new Validator())->validate($deals, ['system' => true, 'dependencies' => ['crm-core' => $core]]));
    }

    public function testDependencyLookupsNeedTheDependency(): void
    {
        $result = (new Validator())->validate($this->deals());
        $this->assertFalse($result->ok());
        $this->assertHasIssue($result, 'ref.unchecked', '/entities/0/fields/4/to', Issue::WARNING);
        $this->assertHasIssue($result, 'expr.unknown_field', '/entities/0/fields/9/expr');
    }

    public function testUndeclaredDependencyIsRefused(): void
    {
        $deals = $this->deals();
        unset($deals['depends_on']);
        $result = (new Validator())->validate($deals, ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertHasIssue($result, 'ref.undeclared_dependency', '/entities/0/fields/4/to');
    }

    #[DataProvider('hostileFieldCodes')]
    public function testHostileCodesAreRefused(mixed $code, string $expected): void
    {
        $def = $this->minimal();
        $def['entities'][0]['fields'][0]['code'] = $code;
        $this->assertHasIssue((new Validator())->validate($def), $expected, '/entities/0/fields/0/code');
    }

    public function testHostileLabelsAreOnlyEverKeys(): void
    {
        $def = $this->minimal();
        $def['entities'][0]['fields'][0]['label'] = "x'); DROP TABLE t; --";
        $this->assertHasIssue((new Validator())->validate($def), 'i18n.key', '/entities/0/fields/0/label');
        $def = $this->minimal();
        $def['i18n']['en']['deal.amount.label'] = "<script>alert(1)</script>'; DROP TABLE t; --";
        $this->assertClean((new Validator())->validate($def), 'label text is data, escaped where it is shown');
    }

    public function testStructureIsStrict(): void
    {
        $def = $this->minimal();
        $def['entities'][0]['fields'][0]['onclick'] = 'x';
        $def['entities'][0]['extra'] = 1;
        $def['php'] = 'phpinfo()';
        unset($def['version']);
        $def['entities'][0]['fields'][0]['type'] = 'blob';
        $result = (new Validator())->validate($def);
        $this->assertHasIssue($result, 'schema.unknown_key', '/php');
        $this->assertHasIssue($result, 'schema.unknown_key', '/entities/0/extra');
        $this->assertHasIssue($result, 'schema.unknown_key', '/entities/0/fields/0/onclick');
        $this->assertHasIssue($result, 'schema.required', '/version');
        $this->assertHasIssue($result, 'schema.enum', '/entities/0/fields/0/type');
    }

    public function testDuplicateCodesAndSchemaVersion(): void
    {
        $def = $this->minimal();
        $def['apps_schema'] = 2;
        $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(1, \str_repeat('b', 10)), 'code' => 'amount', 'type' => 'integer'];
        $result = (new Validator())->validate($def);
        $this->assertHasIssue($result, 'schema.version', '/apps_schema');
        $this->assertHasIssue($result, 'code.duplicate', '/entities/0/fields/1/code');
    }

    public function testFieldRules(): void
    {
        $def = $this->minimal();
        $id = fn (int $n): string => ElementIds::generate($n, \str_repeat('c', 10));
        $def['entities'][0]['fields'] = [
            ['id' => $id(1), 'code' => 'a', 'type' => 'text', 'max' => 999999],
            ['id' => $id(2), 'code' => 'b', 'type' => 'computed', 'expr' => '=1', 'required' => true],
            ['id' => $id(3), 'code' => 'c', 'type' => 'boolean', 'search' => true],
            ['id' => $id(4), 'code' => 'd', 'type' => 'money'],
            ['id' => $id(5), 'code' => 'e', 'type' => 'select', 'options' => ['x', 'x', 'bad key!']],
            ['id' => $id(6), 'code' => 'f', 'type' => 'relation', 'to' => 'deal', 'cardinality' => 'many_to_one', 'on_delete' => 'cascade'],
            ['id' => $id(7), 'code' => 'g', 'type' => 'integer', 'default' => 'ten'],
            ['id' => $id(8), 'code' => 'h', 'type' => 'decimal', 'precision' => 4, 'scale' => 6],
            ['id' => $id(9), 'code' => 'i', 'type' => 'files', 'accept' => ['text/html', 'not a mime']],
            ['id' => $id(10), 'code' => 'j', 'type' => 'integer', 'min' => 5, 'max' => 1],
            ['id' => $id(11), 'code' => 'k', 'type' => 'user', 'default' => '=amount'],
            ['id' => $id(12), 'code' => 'l', 'type' => 'select', 'options' => ['x'], 'default' => 'y'],
            ['id' => $id(13), 'code' => 'm', 'type' => 'rollup', 'relation' => 'f', 'op' => 'sum', 'field' => 'a'],
        ];
        $r = (new Validator())->validate($def);
        $this->assertHasIssue($r, 'schema.range', '/entities/0/fields/0/max');
        $this->assertHasIssue($r, 'field.not_settable', '/entities/0/fields/1/required');
        $this->assertHasIssue($r, 'field.not_searchable', '/entities/0/fields/2/search');
        $this->assertHasIssue($r, 'field.currency', '/entities/0/fields/3');
        $this->assertHasIssue($r, 'code.duplicate', '/entities/0/fields/4/options/1');
        $this->assertHasIssue($r, 'code.pattern', '/entities/0/fields/4/options/2');
        $this->assertHasIssue($r, 'schema.enum', '/entities/0/fields/5/on_delete');
        $this->assertHasIssue($r, 'field.default', '/entities/0/fields/6/default');
        $this->assertHasIssue($r, 'schema.range', '/entities/0/fields/7/scale');
        $this->assertHasIssue($r, 'schema.format', '/entities/0/fields/8/accept/1');
        $this->assertHasIssue($r, 'schema.range', '/entities/0/fields/9/min');
        $this->assertHasIssue($r, 'expr.unknown_field', '/entities/0/fields/10/default');
        $this->assertHasIssue($r, 'field.default', '/entities/0/fields/11/default');
        $this->assertHasIssue($r, 'ref.unknown_field', '/entities/0/fields/12/relation');
    }

    public function testNumberingPatterns(): void
    {
        foreach (['D-{seq:5}' => true, '{YYYY}/{MM}-{seq:3}' => true, '{field:kind}-{seq:4}' => true, 'D-{YYYY}' => false,
            '{seq:1}{seq:2}' => false, '{seq:13}' => false, '{now}-{seq:2}' => false, 'D;{seq:3}' => false,
            '{field:amount}-{seq:2}' => false] as $pattern => $ok) {
            $def = $this->minimal();
            $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(9, \str_repeat('d', 10)), 'code' => 'kind', 'type' => 'select', 'options' => ['a']];
            $def['i18n']['en'] += ['deal.kind.label' => 'Kind', 'deal.kind.option.a' => 'A'];
            $def['entities'][0]['numbering'] = ['pattern' => $pattern, 'reset' => 'yearly'];
            $result = (new Validator())->validate($def);
            $this->assertSame($ok, $result->ok(), "{$pattern}: " . \json_encode($result->issuesArray()));
        }
    }

    // ── caps ───────────────────────────────────────────────────────

    public function testCapsRefuseWithThePathAndNeverTrim(): void
    {
        $caps = new Caps(['fields_per_entity' => 2, 'options_per_select' => 2, 'expression_length' => 10]);
        $def = $this->minimal();
        $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(5, \str_repeat('e', 10)), 'code' => 'b', 'type' => 'integer'];
        $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(6, \str_repeat('e', 10)), 'code' => 'c', 'type' => 'integer'];
        $result = (new Validator($caps))->validate($def);
        $this->assertHasIssue($result, 'cap.fields_per_entity', '/entities/0/fields');
        $this->assertCount(3, $def['entities'][0]['fields'], 'the definition is not trimmed');

        $def = $this->minimal();
        $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(5, \str_repeat('e', 10)), 'code' => 's', 'type' => 'select', 'options' => ['a', 'b', 'c']];
        $def['entities'][0]['fields'][] = ['id' => ElementIds::generate(6, \str_repeat('e', 10)), 'code' => 'x', 'type' => 'computed', 'expr' => '=1 + 2 + 3 + 4'];
        $result = (new Validator(new Caps(['options_per_select' => 2, 'expression_length' => 10])))->validate($def);
        $this->assertHasIssue($result, 'cap.options_per_select', '/entities/0/fields/1/options');
        $this->assertHasIssue($result, 'expr.length', '/entities/0/fields/2/expr');
    }

    public function testCapsOnlyNarrow(): void
    {
        $caps = new Caps(['fields_per_entity' => 100000, 'entities_per_app' => 0, 'roles_per_app' => '5', 'nope' => 1, 'menu_items' => 3]);
        $this->assertSame(Caps::DEFAULTS['fields_per_entity'], $caps->get('fields_per_entity'));
        $this->assertSame(Caps::DEFAULTS['entities_per_app'], $caps->get('entities_per_app'));
        $this->assertSame(Caps::DEFAULTS['roles_per_app'], $caps->get('roles_per_app'));
        $this->assertSame(3, $caps->get('menu_items'));
        $this->expectException(InvalidArgumentException::class);
        $caps->get('nope');
    }

    public function testDefinitionSizeAndEncoding(): void
    {
        $def = $this->minimal();
        $def['i18n']['en']['deal.label'] = \str_repeat('x', 2000);
        $this->assertHasIssue((new Validator(new Caps(['definition_bytes' => 1000])))->validate($def), 'cap.definition_bytes', '/');
        $def = $this->minimal();
        $def['i18n']['en']['deal.label'] = "bad \xff";
        $this->assertHasIssue((new Validator())->validate($def), 'definition.encoding', '/');
    }

    // ── element ids ────────────────────────────────────────────────

    public function testUlidGeneration(): void
    {
        $id = ElementIds::generate(1_793_000_000_000, \str_repeat("\x00", 10));
        $this->assertSame(26, \strlen($id));
        $this->assertTrue(ElementIds::isValid($id));
        $this->assertSame('0000000000000000', \substr($id, 10));
        $this->assertSame(ElementIds::generate(5, \str_repeat("\xff", 10)), ElementIds::generate(5, \str_repeat("\xff", 10)));
        $this->assertTrue(ElementIds::generate(1) < ElementIds::generate(2), 'ids sort by time');
        $this->assertFalse(ElementIds::isValid('01JBQ8…'));
        $this->assertFalse(ElementIds::isValid('81JBQ8AAAAAAAAAAAAAAAAAAAA'), 'first character must be 0-7');
        $this->assertFalse(ElementIds::isValid('01JBQ8AAAAAAAAAAAAAAAAAAAI'), 'no I in Crockford');
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $seen[ElementIds::generate()] = true;
        }
        $this->assertCount(200, $seen);
    }

    public function testIdsMustBeValidAndUnique(): void
    {
        $def = $this->minimal();
        $def['entities'][0]['fields'][0]['id'] = 'not-a-ulid';
        $this->assertHasIssue((new Validator())->validate($def), 'id.format', '/entities/0/fields/0/id');
        $def = $this->minimal();
        $def['entities'][0]['fields'][0]['id'] = $def['entities'][0]['id'];
        $this->assertHasIssue((new Validator())->validate($def), 'id.duplicate', '/entities/0/fields/0/id');
        $def = $this->minimal();
        unset($def['entities'][0]['fields'][0]['id']);
        $this->assertHasIssue((new Validator())->validate($def), 'schema.required', '/entities/0/fields/0/id');
    }

    public function testAssignFillsMissingIdsAndReusesThePreviousVersion(): void
    {
        $previous = $this->deals();
        $draft = $previous;
        // the AI's draft dropped every id and added a field
        unset($draft['entities'][0]['id']);
        foreach ($draft['entities'][0]['fields'] as $i => $_) {
            unset($draft['entities'][0]['fields'][$i]['id']);
        }
        $draft['entities'][0]['fields'][] = ['code' => 'region', 'type' => 'text'];
        $assigned = ElementIds::assign($draft, $previous);
        $this->assertSame($previous['entities'][0]['id'], $assigned['entities'][0]['id']);
        $this->assertSame($previous['entities'][0]['fields'][1]['id'], $assigned['entities'][0]['fields'][1]['id']);
        $new = \end($assigned['entities'][0]['fields'])['id'];
        $this->assertTrue(ElementIds::isValid($new));
        $all = \array_column(ElementIds::collect($assigned), 'id');
        $this->assertSame(\count($all), \count(\array_unique($all)));

        // an id already present is never changed, even when the previous version disagrees
        $draft = $previous;
        $draft['entities'][0]['fields'][0]['id'] = ElementIds::generate(7, \str_repeat('z', 10));
        $this->assertSame($draft, ElementIds::assign($draft, $previous));
    }

    public function testIdKindsAreStableAcrossVersions(): void
    {
        $previous = $this->minimal();
        $next = $previous;
        // the field's id is reused for a new entity
        $next['entities'][] = ['id' => $previous['entities'][0]['fields'][0]['id'], 'code' => 'other',
            'fields' => [['id' => ElementIds::generate(8, \str_repeat('f', 10)), 'code' => 'x', 'type' => 'integer']]];
        $next['entities'][0]['fields'][0]['id'] = ElementIds::generate(9, \str_repeat('f', 10));
        $result = (new Validator())->validate($next, ['previous' => $previous]);
        $this->assertHasIssue($result, 'id.kind_changed', '/entities/1/id');
        $this->assertHasIssue($result, 'id.changed', '/entities/0/fields/0/id', Issue::WARNING);
    }

    // ── references, views, expressions ─────────────────────────────

    public function testExpressionSitesAreTypedAndSqlChecked(): void
    {
        $def = $this->deals();
        $def['entities'][0]['views']['list']['saved'][0]['filter'] = '=WORKDAY(close_date, 2) > TODAY()';
        $def['entities'][0]['views']['form']['sections'][1]['visible'] = '=amount';
        $def['entities'][0]['fields'][8]['expr'] = '=weighted * 2';
        $r = (new Validator())->validate($def, ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertHasIssue($r, 'expr.not_sql', '/entities/0/views/list/saved/0/filter');
        $this->assertHasIssue($r, 'expr.type', '/entities/0/views/form/sections/1/visible');
        $this->assertHasIssue($r, 'expr.cycle', '/entities/0/fields/8/expr');
    }

    public function testViewsReferenceRealFields(): void
    {
        $def = $this->deals();
        $def['entities'][0]['views']['list']['columns'][] = 'nope';
        $def['entities'][0]['views']['list']['filters'][] = 'attachments';
        $def['entities'][0]['views']['list']['default_sort'] = '--title';
        $def['entities'][0]['views']['board']['group_by'] = 'title';
        $def['entities'][0]['views']['board']['wip_limits'] = ['nope' => 0];
        $def['entities'][0]['views']['form']['sections'][1]['fields'][] = 'title';
        $def['entities'][0]['views']['calendar'] = ['x' => 1];
        $def['entities'][1]['views']['list']['columns'][] = 'number';
        $r = (new Validator())->validate($def, ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertHasIssue($r, 'ref.unknown_field', '/entities/0/views/list/columns/7');
        $this->assertHasIssue($r, 'view.not_filterable', '/entities/0/views/list/filters/3');
        $this->assertHasIssue($r, 'ref.unknown_field', '/entities/0/views/list/default_sort');
        $this->assertHasIssue($r, 'ref.unknown_field', '/entities/0/views/board/group_by');
        $this->assertHasIssue($r, 'schema.range', '/entities/0/views/board/wip_limits/nope');
        $this->assertHasIssue($r, 'view.duplicate_field', '/entities/0/views/form/sections/1/fields/2');
        $this->assertHasIssue($r, 'schema.unknown_key', '/entities/0/views/calendar');
        $this->assertHasIssue($r, 'ref.unknown_field', '/entities/1/views/list/columns/2', Issue::ERROR, 'lost_reason has no numbering');
    }

    public function testRelationsAndRollups(): void
    {
        $core = $this->core();
        // entities load in file-name order: activity, company, contact
        $core['entities'][1]['fields'][6]['via'] = 'job_title';
        $core['entities'][2]['fields'][4]['to'] = 'nowhere';
        $r = (new Validator())->validate($core);
        $this->assertHasIssue($r, 'ref.via', '/entities/1/fields/6/via');
        $this->assertHasIssue($r, 'ref.unknown_entity', '/entities/2/fields/4/to');
    }

    // ── workflows ──────────────────────────────────────────────────

    public function testWorkflowGraphRules(): void
    {
        $node = fn (string $code, string $type, array $extra = []): array => ['id' => ElementIds::generate(\crc32($code), \str_pad($code, 10, '-')), 'code' => $code, 'type' => $type] + $extra;
        $cases = [
            'two triggers' => [[$node('a', 'trigger', ['on' => 'created']), $node('b', 'trigger', ['on' => 'manual']), $node('z', 'end')],
                [['from' => 'a', 'to' => 'z'], ['from' => 'b', 'to' => 'z']], 'workflow.trigger'],
            'unreachable' => [[$node('a', 'trigger', ['on' => 'created']), $node('z', 'end'), $node('lost', 'end')],
                [['from' => 'a', 'to' => 'z']], 'workflow.edges'],
            'loop without human' => [[$node('a', 'trigger', ['on' => 'created']), $node('c', 'condition', ['expr' => '=amount > 1']),
                $node('u', 'update_record', ['set' => ['amount' => '=amount - 1']]), $node('z', 'end')],
                [['from' => 'a', 'to' => 'c'], ['from' => 'c', 'to' => 'u', 'branch' => true], ['from' => 'c', 'to' => 'z', 'branch' => false],
                    ['from' => 'u', 'to' => 'c']], 'workflow.cycle'],
            'condition one branch' => [[$node('a', 'trigger', ['on' => 'created']), $node('c', 'condition', ['expr' => '=amount > 1']), $node('z', 'end')],
                [['from' => 'a', 'to' => 'c'], ['from' => 'c', 'to' => 'z', 'branch' => true]], 'workflow.edges'],
            'join without fork' => [[$node('a', 'trigger', ['on' => 'created']), $node('c', 'condition', ['expr' => '=amount > 1']),
                $node('j', 'join'), $node('z', 'end')],
                [['from' => 'a', 'to' => 'c'], ['from' => 'c', 'to' => 'j', 'branch' => true], ['from' => 'c', 'to' => 'j', 'branch' => false],
                    ['from' => 'j', 'to' => 'z']], 'workflow.edge_duplicate'],
            'unknown node type' => [[$node('a', 'trigger', ['on' => 'created']), $node('x', 'php'), $node('z', 'end')],
                [['from' => 'a', 'to' => 'x'], ['from' => 'x', 'to' => 'z']], 'schema.enum'],
            'bad executor' => [[$node('a', 'trigger', ['on' => 'created']), $node('x', 'external', ['executor' => 'system']), $node('z', 'end')],
                [['from' => 'a', 'to' => 'x'], ['from' => 'x', 'to' => 'z']], 'schema.format'],
            'bad cron' => [[$node('a', 'trigger', ['on' => 'schedule', 'cron' => '* * * * * *; rm']), $node('z', 'end')],
                [['from' => 'a', 'to' => 'z']], 'schema.format'],
        ];
        foreach ($cases as $name => [$nodes, $edges, $code]) {
            $def = $this->minimal();
            $def['workflows'] = [['id' => ElementIds::generate(77, \str_repeat('w', 10)), 'code' => 'flow', 'entity' => 'deal', 'nodes' => $nodes, 'edges' => $edges]];
            $codes = \array_column((new Validator())->validate($def)->issuesArray(), 'code');
            $this->assertContains($code, $codes, "{$name}: " . \implode(', ', $codes));
        }
    }

    public function testLoopsThroughAHumanStepAreAllowed(): void
    {
        $node = fn (string $code, string $type, array $extra = []): array => ['id' => ElementIds::generate(\crc32($code) + 1, \str_pad($code, 10, '+')), 'code' => $code, 'type' => $type] + $extra;
        $def = $this->minimal();
        $def['roles'] = ['rep'];
        $def['workflows'] = [[
            'id' => ElementIds::generate(78, \str_repeat('v', 10)), 'code' => 'review', 'entity' => 'deal',
            'nodes' => [$node('start', 'trigger', ['on' => 'manual']), $node('check', 'human', ['assign' => ['role' => 'rep']]),
                $node('ok', 'condition', ['expr' => '=amount > 0']), $node('fork1', 'fork'), $node('w1', 'wait', ['days' => 1]),
                $node('w2', 'notify', ['to' => ['role' => 'rep'], 'message' => 'deal.label']), $node('j', 'join'), $node('done', 'end')],
            'edges' => [['from' => 'start', 'to' => 'check'], ['from' => 'check', 'to' => 'ok'],
                ['from' => 'ok', 'to' => 'check', 'branch' => false], ['from' => 'ok', 'to' => 'fork1', 'branch' => true],
                ['from' => 'fork1', 'to' => 'w1'], ['from' => 'fork1', 'to' => 'w2'], ['from' => 'w1', 'to' => 'j'],
                ['from' => 'w2', 'to' => 'j'], ['from' => 'j', 'to' => 'done']],
        ]];
        $def['i18n']['en'] += ['role.rep' => 'Rep', 'workflow.review.label' => 'Review'];
        foreach (['start', 'check', 'ok', 'fork1', 'w1', 'w2', 'j', 'done'] as $n) {
            $def['i18n']['en']["workflow.review.node.{$n}"] = $n;
        }
        $this->assertClean((new Validator())->validate($def));
    }

    // ── permissions and i18n ───────────────────────────────────────

    public function testPermissionRules(): void
    {
        $def = $this->deals();
        $def['permissions']['rules'][] = ['role' => 'admin', 'entity' => 'deal', 'ops' => ['read']];
        $def['permissions']['rules'][] = ['role' => 'sales_rep', 'entity' => 'deal', 'ops' => ['read']];
        $def['permissions']['rules'][2]['ops'] = ['read', 'sudo'];
        $def['permissions']['rules'][0]['row'] = '=NETWORKDAYS(close_date, TODAY()) > 1';
        $def['permissions']['rules'][0]['fields']['nope'] = 'write';
        $r = (new Validator())->validate($def, ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertHasIssue($r, 'ref.unknown_role', '/permissions/rules/4/role');
        $this->assertHasIssue($r, 'permissions.duplicate', '/permissions/rules/5');
        $this->assertHasIssue($r, 'schema.enum', '/permissions/rules/2/ops');
        $this->assertHasIssue($r, 'expr.not_sql', '/permissions/rules/0/row');
        $this->assertHasIssue($r, 'ref.unknown_field', '/permissions/rules/0/fields/nope');
    }

    public function testEveryLabelNeedsEnglishAndSystemTemplatesNeedZhHk(): void
    {
        $def = $this->minimal();
        unset($def['i18n']['en']['deal.amount.label']);
        $this->assertHasIssue((new Validator())->validate($def), 'i18n.missing_key', '/entities/0/fields/0');
        $this->assertTrue((new Validator())->validate($this->minimal())->ok());
        $this->assertHasIssue((new Validator())->validate($this->minimal(), ['system' => true]), 'i18n.missing_locale', '/i18n');
    }

    // ── canonical JSON and hash ────────────────────────────────────

    public function testCanonicalJsonIsKeyOrderIndependent(): void
    {
        $a = ['b' => 1, 'a' => ['y' => true, 'x' => [3, 1, 2]], 'c' => 'é/ü'];
        $b = ['c' => 'é/ü', 'a' => ['x' => [3, 1, 2], 'y' => true], 'b' => 1];
        $this->assertSame('{"a":{"x":[3,1,2],"y":true},"b":1,"c":"é/ü"}', Canonical::json($a));
        $this->assertSame(Canonical::hash($a), Canonical::hash($b));
        $this->assertNotSame(Canonical::hash($a), Canonical::hash(['b' => 1, 'a' => ['y' => true, 'x' => [1, 2, 3]], 'c' => 'é/ü']), 'list order is content');
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', Canonical::hash($a));
    }

    public function testCanonicalJsonNormalisesEmptiesAndNumbers(): void
    {
        $this->assertSame(Canonical::json(['a' => 1]), Canonical::json(['a' => 1, 'b' => null, 'c' => [], 'd' => ['e' => null]]));
        $this->assertSame('{"a":1}', Canonical::json(['a' => 1.0]));
        $this->assertSame('{"a":0.1}', Canonical::json(['a' => 0.1]));
        $this->assertSame('{"a":0}', Canonical::json(['a' => -0.0]));
        $this->assertSame('{"m":{"0":"x","1":"y"}}', Canonical::json(['m' => [1 => 'y', 0 => 'x']]), 'a sorted map stays an object');
        $this->assertSame('[null,[]]', Canonical::json([null, []]), 'lists keep their items');
    }

    public function testCanonicalJsonRefusesWhatItCannotRepresent(): void
    {
        foreach ([['a' => \NAN], ['a' => \INF], ['a' => "\xff"], ['a' => new stdClass()]] as $bad) {
            try {
                Canonical::json($bad);
                $this->fail('accepted ' . \var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $deep = [];
        for ($i = 0; $i < 70; ++$i) {
            $deep = ['x' => $deep ?: 1];
        }
        $this->expectException(InvalidArgumentException::class);
        Canonical::json($deep);
    }

    public function testFixtureHashIsStableAcrossLoads(): void
    {
        $this->assertSame(Canonical::hash($this->deals()), Canonical::hash($this->deals()));
        $shuffled = $this->deals();
        $shuffled = \array_reverse($shuffled, true);
        $this->assertSame(Canonical::hash($this->deals()), Canonical::hash($shuffled));
    }

    // ── package loader ─────────────────────────────────────────────

    public function testLoaderAssemblesTheFolderLayout(): void
    {
        $out = (new PackageLoader())->load(self::PACKAGES . '/crm-deals');
        $this->assertSame([], $out['issues']);
        $def = $out['definition'];
        $this->assertSame(['deal', 'lost_reason'], \array_column($def['entities'], 'code'));
        $this->assertSame(['deal_approval', 'deal_pipeline'], \array_column($def['workflows'], 'code'), 'name order');
        $this->assertArrayHasKey('board', $def['entities'][0]['views']);
        $this->assertSame(['en', 'zh-HK'], \array_keys($def['i18n']));
        $this->assertArrayNotHasKey('fixtures', $def);
    }

    public function testLoaderRefusesYamlUnknownFilesAndMismatches(): void
    {
        $dir = $this->copyPackage('crm-core');
        \file_put_contents("{$dir}/app.yaml", "code: crm-core\n");
        \file_put_contents("{$dir}/entities/extra.yml", "code: extra\n");
        \file_put_contents("{$dir}/run.php", '<?php phpinfo();');
        \copy("{$dir}/entities/contact.json", "{$dir}/entities/person.json");
        \file_put_contents("{$dir}/views/ghost.json", '{"list":{"columns":["x"]}}');
        $out = (new PackageLoader())->load($dir);
        $this->assertNull($out['definition']);
        $codes = \array_map(static fn (Issue $i): string => $i->code . ' ' . $i->path, $out['issues']);
        $this->assertContains('package.yaml_unsupported /app.yaml', $codes);
        $this->assertContains('package.yaml_unsupported /entities/extra.yml', $codes);
        $this->assertContains('package.unknown_file /run.php', $codes);
        $this->assertContains('package.name_mismatch /entities/person.json', $codes);
        $this->assertContains('package.orphan_views /views/ghost.json', $codes);
    }

    public function testLoaderRefusesBadJsonAndSymlinks(): void
    {
        $dir = $this->copyPackage('crm-core');
        \file_put_contents("{$dir}/permissions.json", '{"rules": [');
        $out = (new PackageLoader())->load($dir);
        $this->assertSame('package.json', $out['issues'][0]->code);

        $dir = $this->copyPackage('crm-core');
        $outside = $this->tmp . '/outside.json';
        \file_put_contents($outside, '{"code":"stolen","fields":[]}');
        if (!@\symlink($outside, "{$dir}/entities/stolen.json")) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $out = (new PackageLoader())->load($dir);
        $this->assertNull($out['definition']);
        $this->assertContains('package.symlink', \array_map(static fn (Issue $i): string => $i->code, $out['issues']));
        $this->assertSame('package.missing', (new PackageLoader())->load($this->tmp . '/nope')['issues'][0]->code);
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function core(): array
    {
        return (new PackageLoader())->load(self::PACKAGES . '/crm-core')['definition'];
    }

    /**
     * @return array<string, mixed>
     */
    private function deals(): array
    {
        return (new PackageLoader())->load(self::PACKAGES . '/crm-deals')['definition'];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimal(): array
    {
        return [
            'apps_schema' => 1,
            'code' => 'mini',
            'version' => '0.1.0',
            'entities' => [[
                'id' => ElementIds::generate(1_793_000_000_001, \str_repeat('a', 10)),
                'code' => 'deal',
                'fields' => [['id' => ElementIds::generate(1_793_000_000_002, \str_repeat('a', 10)), 'code' => 'amount', 'type' => 'money', 'currency' => 'HKD']],
            ]],
            'i18n' => ['en' => ['app.label' => 'Mini', 'deal.label' => 'Deal', 'deal.amount.label' => 'Amount']],
        ];
    }

    private function assertClean(ValidationResult $result, string $message = ''): void
    {
        $this->assertSame([], $result->issuesArray(), $message);
        $this->assertTrue($result->ok());
    }

    private function assertHasIssue(ValidationResult $result, string $code, string $path, string $severity = Issue::ERROR, string $message = ''): void
    {
        foreach ($result->issues() as $issue) {
            if ($issue->code === $code && $issue->path === $path && $issue->severity === $severity) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("No {$severity} {$code} at {$path}. {$message}\n" . \json_encode($result->issuesArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function copyPackage(string $name): string
    {
        if ($this->tmp === '') {
            $this->tmp = \sys_get_temp_dir() . '/razy_apps_test_' . \uniqid();
            \mkdir($this->tmp, 0o777, true);
        }
        $dest = $this->tmp . '/' . $name . '_' . \uniqid();
        $this->copyTree(self::PACKAGES . '/' . $name, $dest);

        return $dest;
    }

    private function copyTree(string $from, string $to): void
    {
        \mkdir($to, 0o777, true);
        foreach (\scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            \is_dir("{$from}/{$name}") ? $this->copyTree("{$from}/{$name}", "{$to}/{$name}") : \copy("{$from}/{$name}", "{$to}/{$name}");
        }
    }

    private function rmTree(string $dir): void
    {
        foreach (\scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "{$dir}/{$name}";
            (\is_dir($path) && !\is_link($path)) ? $this->rmTree($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
