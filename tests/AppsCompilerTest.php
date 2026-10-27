<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use razymod\apps\Compiler;
use razymod\apps\Definition\Canonical;
use razymod\apps\Definition\PackageLoader;
use razymod\apps\Expression\ArrayValues;
use razymod\apps\Expression\Context;
use razymod\apps\Expression\Evaluator;
use razymod\apps\Weight;

require_once __DIR__ . '/fixtures/apps/autoload.php';

/**
 * razymod/apps compiler and weight function (manual/15-razy-apps.md §6), on the
 * crm-core / crm-deals fixture packages.
 */
#[CoversNothing]
class AppsCompilerTest extends TestCase
{
    private const PACKAGES = __DIR__ . '/fixtures/apps/packages';

    public function testCompilesTheFixtureIntoADeterministicPlan(): void
    {
        $a = $this->compileDeals();
        $b = $this->compileDeals();
        $this->assertTrue($a['ok'], \json_encode($a['issues']) ?: '');
        $this->assertSame($a['plan'], $b['plan'], 'same definition, same plan (cacheable by hash)');
        $plan = $a['plan'];
        $this->assertSame(Canonical::hash($this->deals()), $plan['content_hash']);
        $this->assertSame(['plan_version', 'apps_schema', 'package', 'version', 'content_hash', 'label', 'requires_engine',
            'depends_on', 'lineage', 'roles', 'menu', 'entities', 'workflows', 'permissions', 'abilities', 'i18n_keys',
            'weight_counts'], \array_keys($plan));
        $this->assertNotFalse(\json_encode($plan, JSON_THROW_ON_ERROR), 'plan is plain JSON');
    }

    public function testEntityPlan(): void
    {
        $deal = $this->compileDeals()['plan']['entities']['deal'];
        $this->assertSame('crm-deals.deal', $deal['qualified']);
        $this->assertSame([['text' => 'D-'], ['date' => 'YYYY'], ['text' => '-'], ['seq' => 5]], $deal['numbering']['tokens']);
        $this->assertSame('yearly', $deal['numbering']['reset']);
        $this->assertArrayHasKey('number', $deal['system_fields']);

        $f = $deal['fields'];
        $this->assertSame('status', $f['stage']['value_type']);
        $this->assertSame('ref:crm-core.company', $f['company']['value_type']);
        $this->assertSame(['to' => 'crm-core.company', 'cardinality' => 'many_to_one', 'on_delete' => 'restrict', 'via' => null], $f['company']['relation']);
        $this->assertSame('ref:crm-deals.lost_reason', $f['lost_reason']['value_type'], 'local targets are qualified too');
        $this->assertSame(['expr' => ['src' => '=CURRENT_USER()', 'type' => 'user', 'ast' => $f['owner']['default']['expr']['ast']]], $f['owner']['default']);
        $this->assertSame(['value' => 'HKD'], $f['currency']['default']);
        $this->assertSame('text', $f['company_name']['value_type'], 'lookup through a dependency');
        $this->assertFalse($f['weighted']['stored']);
        $this->assertSame('number', $f['weighted']['expr']['type']);
        $this->assertSame([['key' => 'HKD', 'label' => 'deal.currency.option.HKD', 'tone' => 'neutral'],
            ['key' => 'USD', 'label' => 'deal.currency.option.USD', 'tone' => 'neutral'],
            ['key' => 'CNY', 'label' => 'deal.currency.option.CNY', 'tone' => 'neutral']], $f['currency']['options']);
        $this->assertSame(['title'], $deal['search']);
        $this->assertContains('close_date', $deal['sortable']);
        $this->assertNotContains('attachments', $deal['sortable']);
        $this->assertNotContains('weighted', $deal['sortable']);

        $indexes = \array_map(static fn (array $i): string => \implode(',', $i['fields']) . ($i['unique'] ? '!' : ''), $deal['indexes']);
        $this->assertSame(['company', 'contact', 'lost_reason', 'stage,close_date', 'owner'], $indexes);

        $lost = $this->compileDeals()['plan']['entities']['lost_reason'];
        $this->assertNull($lost['numbering']);
        $this->assertArrayNotHasKey('number', $lost['system_fields']);
        $this->assertContains('name!', \array_map(static fn (array $i): string => \implode(',', $i['fields']) . ($i['unique'] ? '!' : ''), $lost['indexes']));
    }

    public function testViewsWorkflowsAndPermissionsCarryTypedExpressions(): void
    {
        $plan = $this->compileDeals()['plan'];
        $list = $plan['entities']['deal']['views']['list'];
        $this->assertSame('boolean', $list['saved']['my_open']['filter']['type']);
        $this->assertSame('call', $list['saved']['my_open']['filter']['ast']['k']);
        $this->assertSame('boolean', $plan['entities']['deal']['views']['form']['sections'][1]['visible']['type']);
        $this->assertNull($plan['entities']['deal']['views']['form']['sections'][0]['visible']);

        $pipeline = $plan['workflows']['deal_pipeline'];
        $this->assertSame('lead', $pipeline['initial']);
        $this->assertSame(0.7, $pipeline['states']['negotiation']['props']['probability']);
        $approval = $plan['workflows']['deal_approval'];
        $this->assertSame('boolean', $approval['nodes']['big_deal']['expr']['type']);
        $this->assertSame('workflow.deal_approval.node.approve', $approval['nodes']['approve']['label']);

        $rules = $plan['permissions']['rules'];
        $this->assertSame('boolean', $rules[0]['row']['type']);
        $this->assertSame('all', $rules[1]['row']);
        $this->assertContains('apps.crm-deals.deal.workflow.verify', $rules[1]['abilities']);
        $this->assertContains('apps.crm-deals.deal.read', $plan['abilities']);
        $this->assertContains('apps.crm-deals.lost_reason.export', $plan['abilities']);
        $this->assertSame($plan['abilities'], \array_values(\array_unique($plan['abilities'])));
    }

    public function testPlanExpressionsRunInTheEvaluator(): void
    {
        $plan = $this->compileDeals()['plan'];
        $ev = new Evaluator();
        $ctx = new Context('u1', [], [], new DateTimeImmutable('2026-10-27T00:00:00Z'));
        $f = $plan['entities']['deal']['fields'];
        $this->assertSame(84000.0, $ev->evaluate($f['weighted']['expr']['ast'], new ArrayValues(['amount' => 120000, 'stage.probability' => 0.7]), $ctx));
        $this->assertTrue($ev->evaluate($f['overdue']['expr']['ast'], new ArrayValues(['stage.category' => 'active', 'close_date' => '2026-10-01']), $ctx));
        $this->assertFalse($ev->evaluate($f['overdue']['expr']['ast'], new ArrayValues(['stage.category' => 'done', 'close_date' => '2026-10-01']), $ctx));
        $this->assertSame('u1', $ev->evaluate($f['owner']['default']['expr']['ast'], new ArrayValues([]), $ctx));
        $filter = $plan['entities']['deal']['views']['list']['saved']['closing_this_month']['filter']['ast'];
        $this->assertTrue($ev->evaluate($filter, new ArrayValues(['close_date' => '2026-10-31']), $ctx));
        $this->assertFalse($ev->evaluate($filter, new ArrayValues(['close_date' => '2026-11-01']), $ctx));
    }

    public function testInvalidDefinitionsDoNotCompile(): void
    {
        $deals = $this->deals();
        $deals['entities'][0]['fields'][0]['code'] = 'select';
        $out = (new Compiler())->compile($deals, ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertFalse($out['ok']);
        $this->assertNull($out['plan']);
        $this->assertSame('code.reserved', $out['issues'][0]['code']);
    }

    public function testNoSqlTextInThePlan(): void
    {
        $json = (string) \json_encode($this->compileDeals()['plan']);
        foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'CREATE ', 'DROP '] as $sql) {
            $this->assertStringNotContainsString($sql, $json);
        }
    }

    // ── weight ─────────────────────────────────────────────────────

    public function testFixtureWeights(): void
    {
        $core = Weight::of($this->core(), Weight::REFERENCE);
        // 3 entities, 24 fields (activity.created is a system type and adds
        // nothing), 4 relations, 9 views, 2 saved views, 1 role with a row rule
        $this->assertSame(
            ['entities' => 3, 'fields' => 24, 'relations' => 4, 'views' => 9, 'saved_views' => 2, 'row_rule_roles' => 1, 'wf_nodes' => 0],
            \array_diff_key($core['counts'], ['by_entity' => true])
        );
        $this->assertSame(30 + 24 + 8 + 18 + 2 + 3, $core['weight']);

        $deals = Weight::of($this->deals(), Weight::REFERENCE);
        $this->assertSame(
            ['entities' => 2, 'fields' => 15, 'relations' => 3, 'views' => 5, 'saved_views' => 2, 'row_rule_roles' => 1, 'wf_nodes' => 3],
            \array_diff_key($deals['counts'], ['by_entity' => true])
        );
        $this->assertSame(20 + 15 + 6 + 10 + 2 + 3, $deals['weight']);
        $this->assertSame(3, $deals['wf_nodes'], 'condition, human, notify; not trigger or the two ends');
        $this->assertSame(['fields' => 13, 'relations' => 3, 'views' => 4, 'saved_views' => 2], $deals['counts']['by_entity']['deal']);
    }

    public function testThePlanExampleFormula(): void
    {
        // the published worked example: 4 entities, 60 fields, 6 relations, 10 views, 4 saved views, 1 row-rule role = 139
        $counts = ['entities' => 4, 'fields' => 60, 'relations' => 6, 'views' => 10, 'saved_views' => 4, 'row_rule_roles' => 1, 'wf_nodes' => 7];
        $this->assertSame(139, Weight::score($counts, Weight::REFERENCE));
        $card = ['entity' => 5, 'field' => 0.5, 'relation' => 1, 'view' => 1, 'saved_view' => 0, 'row_rule_role' => 2];
        $this->assertSame(20 + 30.0 + 6 + 10 + 0 + 2, Weight::score($counts, $card));
    }

    public function testCoefficientsAreChecked(): void
    {
        foreach ([[], ['entity' => -1] + Weight::REFERENCE, ['bogus' => 1] + Weight::REFERENCE, ['field' => '1'] + Weight::REFERENCE, ['view' => \NAN] + Weight::REFERENCE] as $bad) {
            try {
                Weight::checkCoefficients($bad);
                $this->fail('accepted ' . \json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, plan: ?array<string, mixed>, issues: list<array<string, string>>}
     */
    private function compileDeals(): array
    {
        return (new Compiler())->compile($this->deals(), ['system' => true, 'dependencies' => ['crm-core' => $this->core()]]);
    }

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
}
