<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\RouteDispatcher;
use Razy\Routing\RouteAudit;

/**
 * Phase 3 (dossier option (f)): FM-2 build-time audit.
 *
 * Compiled regexes come from the REAL engine (RouteDispatcher::compileRouteRegex)
 * — the audit is judged against engine truth, not a reimplementation.
 */
#[CoversClass(RouteAudit::class)]
class RouteAuditTest extends TestCase
{
    /**
     * @param array<string, array> $extra
     *
     * @return array<string, array>
     */
    private static function standard(string $pattern, array $extra = []): array
    {
        return [
            'ANY:' . $pattern => array_merge([
                'type' => 'standard',
                'route' => $pattern,
                'compiled_regex' => RouteDispatcher::compileRouteRegex($pattern),
                'module_code' => 'vendor/test',
                'path' => 'some.closure',
            ], $extra),
        ];
    }

    /**
     * @return array<string, array>
     */
    private static function lazy(string $routePath): array
    {
        return [
            'ANY:' . $routePath => [
                'type' => 'lazy',
                'route_path' => $routePath,
                'route' => '',
                'module_code' => 'vendor/test',
                'path' => 'some.closure',
            ],
        ];
    }

    private function audit(array $routes, array $foreign = [], array $allow = []): array
    {
        return RouteAudit::run($routes, $foreign, $allow);
    }

    public function testRootCatchAllIsFlagged(): void
    {
        $result = $this->audit(self::standard('/'));

        $this->assertCount(1, $result['findings']);
        $this->assertSame(RouteAudit::RULE_ROOT_CLAIM, $result['findings'][0]['rule']);
        $this->assertSame('vendor/test', $result['findings'][0]['module_code']);
    }

    public function testDeepLiteralRouteIsClean(): void
    {
        $this->assertSame([], $this->audit(self::standard('/user/profile'))['findings']);
    }

    public function testTwoSegmentWildcardIsRootClaim(): void
    {
        // '/:a/:a' matches ANY two-segment prefix plus the unanchored tail —
        // FM-2 bullet 2 ("broad patterns likewise cross the foreign prefix").
        $result = $this->audit(self::standard('/:a/:a'));

        $this->assertCount(1, $result['findings']);
        $this->assertSame(RouteAudit::RULE_ROOT_CLAIM, $result['findings'][0]['rule']);
    }

    public function testUnanchoredTailAbsorbingForeignPrefixIsFlagged(): void
    {
        // '/v1' also serves '/v1.5/anything' (dossier §1.6 unanchored tail);
        // a sibling mounted at /v1.5 gets absorbed if it ever reaches PHP.
        $result = $this->audit(
            self::standard('/v1'),
            ['/v1.5' => 'sibling mount /v1.5 (otherdist) on example.com'],
        );

        $this->assertCount(1, $result['findings']);
        $this->assertSame(RouteAudit::RULE_ABSORBS_FOREIGN, $result['findings'][0]['rule']);
        $this->assertSame('/v1.5', $result['findings'][0]['prefix']);
    }

    public function testSegmentBoundaryIsNotFalsePositive(): void
    {
        // '/user/:a' vs sibling '/users': the engine's literal '/' requirement
        // means NO match — the audit must not invent a finding (FM prefix
        // discipline: /shop/ != /shopping).
        $result = $this->audit(
            self::standard('/user/:a'),
            ['/users' => 'sibling mount /users (otherdist) on example.com'],
        );

        $this->assertSame([], $result['findings']);
    }

    public function testRootClaimReportsOnceNotPerPrefix(): void
    {
        $result = $this->audit(self::standard('/'), [
            '/api-py' => 'declared exclusion /api-py',
            '/shop' => 'sibling mount /shop (otherdist) on example.com',
        ]);

        $this->assertCount(1, $result['findings'], 'root_claim subsumes the per-prefix noise');
        $this->assertSame(RouteAudit::RULE_ROOT_CLAIM, $result['findings'][0]['rule']);
    }

    public function testLazyAliasPrefixShadowing(): void
    {
        // dossier FM-2 CONFIRMED case: lazy at alias 'shop' swallows the
        // sibling mount /shop/reports once it reaches PHP.
        $result = $this->audit(self::lazy('/shop'), [
            '/shop/reports' => 'sibling mount /shop/reports (pydist) on example.com',
        ]);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(RouteAudit::RULE_LAZY_SHADOWS, $result['findings'][0]['rule']);
    }

    public function testLazyShadowingCoversExactEqualityAndExclusions(): void
    {
        $result = $this->audit(self::lazy('/shop'), [
            '/shop' => 'declared exclusion /shop',      // exact equality
            '/shop/x' => 'declared exclusion /shop/x',  // below
        ]);

        $this->assertCount(2, $result['findings']);
    }

    public function testLazySisterPrefixIsClean(): void
    {
        // '/shopping' is NOT under '/shop/' — the '/'+appended comparison holds.
        $result = $this->audit(self::lazy('/shop'), [
            '/shopping' => 'sibling mount /shopping (otherdist) on example.com',
        ]);

        $this->assertSame([], $result['findings']);
    }

    public function testAllowListSuppressesStandardAndLazy(): void
    {
        $routes = self::standard('/');

        $result = $this->audit($routes, [], ['vendor/test:/']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['suppressed']);

        $result = $this->audit(self::lazy('/shop'), ['/shop/x' => 'declared exclusion /shop/x'], ['vendor/test:/shop']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['suppressed']);
    }

    public function testEntryWithoutCompiledRegexIsSkippedNotFabricated(): void
    {
        $routes = ['ANY:/weird' => ['type' => 'standard', 'route' => '/weird', 'module_code' => 'vendor/test']];

        $this->assertSame([], $this->audit($routes, ['/weird' => 'x'])['findings']);
    }

    public function testLazyBoundWithWildcardCharsIsSkipped(): void
    {
        // A stored route_path containing '*' would make a str_starts_with
        // shadow claim fictitious — the guard skips, never guesses.
        $result = $this->audit(self::lazy('/sh*'), ['/shop/x' => 'sibling mount /shop/x on example.com']);

        $this->assertSame([], $result['findings']);
    }

    public function testModuleCodeFallsBackToQuestionMarkWithoutModuleEntry(): void
    {
        $routes = ['ANY:/x' => ['type' => 'lazy', 'route_path' => '/x', 'path' => 'y']];
        $result = $this->audit($routes, ['/x/deep' => 'sibling mount /x/deep on example.com']);

        $this->assertCount(1, $result['findings']);
        $this->assertSame('?', $result['findings'][0]['module_code']);
    }

    public function testProbePathShape(): void
    {
        $this->assertSame('/' . RouteAudit::PROBE_SEGMENT . '/deep-path', RouteAudit::probePath(''));
        $this->assertSame('/api-py/' . RouteAudit::PROBE_SEGMENT . '/deep-path', RouteAudit::probePath('/api-py/'));
    }
}
