<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Configuration;
use Razy\Module;
use Razy\Module\apps\AppsController;
use Razy\ModuleInfo;
use razymod\apps\Definition\Canonical;
use razymod\apps\Definition\ElementIds;
use razymod\apps\Definition\PackageLoader;
use razymod\apps\Weight;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/fixtures/apps/autoload.php';
// the data provider reads AppsController::API_ALLOW before any test runs
if (!\class_exists(AppsController::class, false)) {
    require_once __DIR__ . '/../modules/apps/default/controller/support/controller.php';
}

/**
 * razymod/apps module surface: metadata, the five published API commands
 * (RZ-014: each one exercised through its real handler file, bound to the
 * controller the way ClosureLoader binds it), the __onAPICall allow-list,
 * and the per-distributor config (caps narrowing, weight coefficients).
 */
#[CoversNothing]
class AppsModuleTest extends TestCase
{
    private const MODULE = __DIR__ . '/../modules/apps';

    private const PACKAGES = __DIR__ . '/fixtures/apps/packages';

    private string $tempDir = '';

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && \is_dir($this->tempDir)) {
            foreach (\glob($this->tempDir . '/*') ?: [] as $f) {
                @\unlink((string) $f);
            }
            @\rmdir($this->tempDir);
        }
        $this->tempDir = '';
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function gateMatrix(): iterable
    {
        $listed = ['callers' => ['acme/registry']];
        foreach (\array_keys(AppsController::API_ALLOW) as $cmd) {
            yield "listed caller: {$cmd}" => [$cmd, 'acme/registry', $listed, true];
            yield "unlisted caller: {$cmd}" => [$cmd, 'acme/other', $listed, false];
            yield "no callers configured: {$cmd}" => [$cmd, 'acme/registry', [], false];
        }
        yield 'listed caller, unknown command' => ['drop-everything', 'acme/registry', $listed, false];
        yield 'listed caller, empty command' => ['', 'acme/registry', $listed, false];
        yield 'listed caller, case variant' => ['Validate', 'acme/registry', $listed, false];
        yield 'callers not a list' => ['validate', 'acme/registry', ['callers' => 'acme/registry'], false];
        yield 'empty caller code' => ['validate', '', ['callers' => ['']], false];
    }

    public function testMetadataVersionsAgreeAndTheModuleIsGeneric(): void
    {
        $module = require self::MODULE . '/module.php';
        $package = require self::MODULE . '/default/package.php';
        $this->assertSame('razymod/apps', $module['module_code']);
        $this->assertSame($module['version'], $package['version']);
        $this->assertSame('apps', $package['api_name']);
        $this->assertArrayNotHasKey('migration', $package, 'RZA-A owns no tables');

        // layer rule: the generic engine knows data spaces, never a consumer's
        // tenants (the consumer's name itself is LayerBoundaryTest's job)
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::MODULE, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $text = (string) \file_get_contents((string) $file);
            $this->assertDoesNotMatchRegularExpression('/tenant/i', $text, (string) $file);
            $this->assertDoesNotMatchRegularExpression('/\bAI\b/', $text, (string) $file . ': neutral wording (automated draft, external author)');
        }
    }

    public function testInitRegistersExactlyTheAllowListedCommands(): void
    {
        $registered = [];
        $agent = $this->createMock(Agent::class);
        $agent->method('addAPICommand')->willReturnCallback(function (string $cmd, string $path) use (&$registered, $agent) {
            $registered[$cmd] = $path;

            return $agent;
        });
        $this->assertTrue($this->controller()->__onInit($agent));
        $this->assertSame(\array_keys(AppsController::API_ALLOW), \array_keys($registered));
        foreach ($registered as $path) {
            $this->assertFileExists(self::MODULE . '/default/controller/' . $path . '.php');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('gateMatrix')]
    public function testApiGate(string $method, string $callerCode, array $config, bool $allowed): void
    {
        // review r3: callers come from config 'callers', default none
        $caller = $this->createMock(ModuleInfo::class);
        $caller->method('getCode')->willReturn($callerCode);
        $this->assertSame($allowed, $this->controller($config)->__onAPICall($caller, $method));
    }

    public function testEveryCommandRefusesOversizedInputBeforeWork(): void
    {
        $config = ['caps' => ['definition_bytes' => 2000]];
        $big = $this->deals();
        foreach (['validate', 'compile', 'weight'] as $cmd) {
            $out = $this->command($cmd, $config)($big);
            $this->assertFalse($out['ok'], $cmd);
            $this->assertSame('cap.definition_bytes', $out['issues'][0]['code'], $cmd);
            $this->assertStringNotContainsString('/', \str_replace(['/depends_on'], '', $out['issues'][0]['message']), 'no paths in messages');
        }
        $this->assertFalse($this->command('assignIds', $config)($big)['ok']);
        $this->assertFalse($this->command('assignIds', $config)(['entities' => []], $big)['ok'], 'previous is capped too');
        $this->assertFalse($this->command('hash', $config)($big)['ok']);

        $deps = \array_fill_keys(\array_map(static fn (int $i): string => "dep-{$i}", \range(1, 21)), ['code' => 'x']);
        $out = $this->command('validate')($this->deals(), ['dependencies' => $deps]);
        $this->assertSame('cap.dependencies_per_app', $out['issues'][0]['code']);
        $out = $this->command('validate', $config)(['apps_schema' => 1], ['dependencies' => ['crm-core' => $big]]);
        $this->assertSame('cap.definition_bytes', $out['issues'][0]['code']);

        $deep = [];
        for ($i = 0; $i < 80; ++$i) {
            $deep = ['x' => $deep ?: 1];
        }
        $this->assertFalse($this->command('validate')($deep)['ok'], 'nesting deeper than 64 is refused');
    }

    public function testValidateCommand(): void
    {
        $out = $this->command('validate')($this->deals(), ['dependencies' => ['crm-core' => $this->core()], 'system' => true, 'junk' => 1]);
        $this->assertSame(['ok' => true, 'issues' => []], $out);
        $bad = $this->deals();
        $bad['entities'][0]['code'] = 'Deal; DROP';
        $out = $this->command('validate')($bad);
        $this->assertFalse($out['ok']);
        $this->assertSame('code.pattern', $out['issues'][0]['code']);
        $out = $this->command('validate')($this->deals(), ['dependencies' => 'not-an-array']);
        $this->assertSame('cap.dependencies_per_app', $out['issues'][0]['code'], 'malformed options are refused at the door');
    }

    public function testCompileCommand(): void
    {
        $out = $this->command('compile')($this->deals(), ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertTrue($out['ok']);
        $this->assertSame(Canonical::hash($this->deals()), $out['plan']['content_hash']);
        $out = $this->command('compile')(['apps_schema' => 1]);
        $this->assertFalse($out['ok']);
        $this->assertNull($out['plan']);
    }

    public function testHashCommand(): void
    {
        $out = $this->command('hash')($this->deals());
        $this->assertTrue($out['ok']);
        $this->assertSame(Canonical::hash($this->deals()), $out['hash']);
        $this->assertSame(\strlen(Canonical::json($this->deals())), $out['bytes']);
        $this->assertFalse($this->command('hash')(['x' => \NAN])['ok']);
        $small = $this->command('hash', ['caps' => ['definition_bytes' => 100]])($this->deals());
        $this->assertFalse($small['ok']);
        $this->assertNull($small['hash']);
    }

    public function testAssignIdsCommand(): void
    {
        $previous = $this->deals();
        $draft = $previous;
        unset($draft['entities'][0]['fields'][2]['id']);
        $out = $this->command('assignIds')($draft, $previous);
        $this->assertTrue($out['ok']);
        $this->assertSame($previous['entities'][0]['fields'][2]['id'], $out['definition']['entities'][0]['fields'][2]['id']);
        $fresh = $this->command('assignIds')(['entities' => [['code' => 'x', 'fields' => [['code' => 'y']]]]])['definition'];
        $this->assertTrue(ElementIds::isValid($fresh['entities'][0]['id']));
        $this->assertTrue(ElementIds::isValid($fresh['entities'][0]['fields'][0]['id']));
    }

    public function testWeightCommandUsesTheRateCardFromConfig(): void
    {
        $deps = ['dependencies' => ['crm-core' => $this->core()]];
        $out = $this->command('weight')($this->deals(), $deps);
        $this->assertTrue($out['ok']);
        $this->assertSame('reference', $out['coefficients_source']);
        $this->assertSame(Weight::of($this->deals(), Weight::REFERENCE)['weight'], $out['weight']);
        $this->assertSame(3, $out['wf_nodes']);

        $card = ['entity' => 1, 'field' => 0, 'relation' => 0, 'view' => 0, 'saved_view' => 0, 'row_rule_role' => 0];
        $out = $this->command('weight', ['weight' => $card])($this->deals(), $deps);
        $this->assertSame('config', $out['coefficients_source']);
        $this->assertSame(2, $out['weight']);

        // review r3: weight validates first; the 3 × created_at evasion is refused, not weighed
        $evasive = $this->deals();
        foreach ([1, 2, 3] as $n) {
            $evasive['entities'][1]['fields'][] = ['id' => ElementIds::generate($n, \str_repeat('w', 10)), 'code' => "made{$n}", 'type' => 'created_at'];
            $evasive['i18n']['en']["lost_reason.made{$n}.label"] = 'Made';
        }
        $out = $this->command('weight')($evasive, $deps);
        $this->assertFalse($out['ok']);
        $this->assertArrayNotHasKey('weight', $out);
        $this->assertContains('field.system_duplicate', \array_column($out['issues'], 'code'));
        $this->assertFalse($this->command('weight')($this->deals())['ok'], 'without its dependency crm-deals does not validate');

        $this->expectException(InvalidArgumentException::class);
        $this->command('weight', ['weight' => ['entity' => -1]])($this->deals(), $deps);
    }

    public function testConfigCapsOnlyNarrow(): void
    {
        $this->assertSame(2, $this->controller(['caps' => ['fields_per_entity' => 2]])->caps()->get('fields_per_entity'));
        $this->assertSame(200, $this->controller(['caps' => ['fields_per_entity' => 9999]])->caps()->get('fields_per_entity'));
        $this->assertSame(200, $this->controller(['caps' => 'nonsense'])->caps()->get('fields_per_entity'));
        $out = $this->command('validate', ['caps' => ['fields_per_entity' => 2]])($this->deals(), ['dependencies' => ['crm-core' => $this->core()]]);
        $this->assertSame('cap.fields_per_entity', $out['issues'][0]['code']);
    }

    // ── helpers ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function controller(array $config = []): AppsController
    {
        $prototype = require self::MODULE . '/default/controller/apps.php';
        $class = \get_class($prototype);
        if ($this->tempDir === '') {
            $this->tempDir = \sys_get_temp_dir() . '/razy_apps_module_' . \uniqid();
            \mkdir($this->tempDir, 0o777, true);
        }
        $file = $this->tempDir . '/apps_' . \md5(\serialize($config)) . '.php';
        \file_put_contents($file, '<?php return ' . \var_export($config, true) . ';');
        $module = $this->createMock(Module::class);
        $module->method('loadConfig')->willReturn(new Configuration($file));

        return new $class($module);
    }

    /**
     * The handler closure of a command, bound to a controller like ClosureLoader does.
     *
     * @param array<string, mixed> $config
     */
    private function command(string $name, array $config = []): Closure
    {
        $file = \strtolower((string) \preg_replace('/(?<=[a-z])[A-Z]/', '_$0', $name)); // assignIds -> assign_ids.php
        $closure = require self::MODULE . "/default/controller/api/{$file}.php";
        $this->assertInstanceOf(Closure::class, $closure);
        $controller = $this->controller($config);

        return Closure::bind($closure, $controller, \get_class($controller));
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
