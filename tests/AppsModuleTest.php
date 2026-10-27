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
        foreach (\array_keys(AppsController::API_ALLOW) as $cmd) {
            yield "allow {$cmd}" => [$cmd, true];
        }
        yield 'deny unknown' => ['drop-everything', false];
        yield 'deny empty' => ['', false];
        yield 'deny case variant' => ['Validate', false];
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

    #[DataProvider('gateMatrix')]
    public function testApiGate(string $method, bool $allowed): void
    {
        $caller = $this->createMock(ModuleInfo::class);
        $caller->method('getCode')->willReturn('acme/registry');
        $this->assertSame($allowed, $this->controller()->__onAPICall($caller, $method));
    }

    public function testValidateCommand(): void
    {
        $out = $this->command('validate')($this->deals(), ['dependencies' => ['crm-core' => $this->core()], 'system' => true, 'junk' => 1]);
        $this->assertSame(['ok' => true, 'issues' => []], $out);
        $bad = $this->deals();
        $bad['entities'][0]['code'] = 'Deal; DROP';
        $out = $this->command('validate')($bad, ['dependencies' => 'not-an-array']);
        $this->assertFalse($out['ok']);
        $this->assertSame('code.pattern', $out['issues'][0]['code']);
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
        $out = $this->command('assign-ids')($draft, $previous);
        $this->assertSame($previous['entities'][0]['fields'][2]['id'], $out['entities'][0]['fields'][2]['id']);
        $fresh = $this->command('assign-ids')(['entities' => [['code' => 'x', 'fields' => [['code' => 'y']]]]]);
        $this->assertTrue(ElementIds::isValid($fresh['entities'][0]['id']));
        $this->assertTrue(ElementIds::isValid($fresh['entities'][0]['fields'][0]['id']));
    }

    public function testWeightCommandUsesTheRateCardFromConfig(): void
    {
        $out = $this->command('weight')($this->deals());
        $this->assertSame('reference', $out['coefficients_source']);
        $this->assertSame(Weight::of($this->deals(), Weight::REFERENCE)['weight'], $out['weight']);
        $this->assertSame(3, $out['wf_nodes']);

        $card = ['entity' => 1, 'field' => 0, 'relation' => 0, 'view' => 0, 'saved_view' => 0, 'row_rule_role' => 0];
        $out = $this->command('weight', ['weight' => $card])($this->deals());
        $this->assertSame('config', $out['coefficients_source']);
        $this->assertSame(2, $out['weight']);

        $this->expectException(InvalidArgumentException::class);
        $this->command('weight', ['weight' => ['entity' => -1]])($this->deals());
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
        $file = \str_replace('-', '_', $name);
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
