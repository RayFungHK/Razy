<?php

/**
 * This file is part of Razy.
 *
 * RZ-08: the framework loads `.env` before it evaluates config.inc.php, so
 * config can read environment values (from the process or the .env file).
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Config\RuntimeConfig;
use Razy\Env;
use Razy\Exception\ConfigurationException;

#[CoversClass(RuntimeConfig::class)]
class RuntimeConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        Env::fullReset();
        foreach (['RAZY_ENV_FILE', 'RZ08_FROM_FILE', 'RZ08_REAL'] as $key) {
            \putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->root = \sys_get_temp_dir() . '/razy_rz08_' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        Env::fullReset();
        foreach (['RAZY_ENV_FILE', 'RZ08_FROM_FILE', 'RZ08_REAL'] as $key) {
            \putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach (\glob($this->root . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
        \rmdir($this->root);
    }

    public function testLoadsDotEnvFromSystemRoot(): void
    {
        \file_put_contents($this->root . '/.env', "RZ08_FROM_FILE=from-file\n");
        $this->assertSamePath($this->root . '/.env', RuntimeConfig::loadEnv($this->root));
        $this->assertSame('from-file', Env::get('RZ08_FROM_FILE'));
        $this->assertSame('from-file', \getenv('RZ08_FROM_FILE'));
    }

    public function testMissingDotEnvIsNotAnError(): void
    {
        $this->assertNull(RuntimeConfig::loadEnv($this->root));
    }

    public function testRealEnvironmentWinsOverDotEnv(): void
    {
        \putenv('RZ08_REAL=from-process');
        \file_put_contents($this->root . '/.env', "RZ08_REAL=from-file\n");
        RuntimeConfig::loadEnv($this->root);
        $this->assertSame('from-process', Env::get('RZ08_REAL'));
    }

    public function testEnvFileOverrideRelativeToRoot(): void
    {
        \mkdir($this->root . '/conf');
        \file_put_contents($this->root . '/conf/app.env', "RZ08_FROM_FILE=override\n");
        \file_put_contents($this->root . '/.env', "RZ08_FROM_FILE=default\n");
        \putenv('RAZY_ENV_FILE=conf/app.env');
        $this->assertSamePath($this->root . '/conf/app.env', RuntimeConfig::loadEnv($this->root));
        $this->assertSame('override', Env::get('RZ08_FROM_FILE'));
        \unlink($this->root . '/conf/app.env');
        \rmdir($this->root . '/conf');
    }

    public function testEnvFileOverrideMustExist(): void
    {
        \putenv('RAZY_ENV_FILE=' . $this->root . '/nope.env');
        $this->expectException(ConfigurationException::class);
        RuntimeConfig::loadEnv($this->root);
    }

    public function testEnvFileNoneDisablesLoading(): void
    {
        \file_put_contents($this->root . '/.env', "RZ08_FROM_FILE=from-file\n");
        \putenv('RAZY_ENV_FILE=none');
        $this->assertNull(RuntimeConfig::loadEnv($this->root));
        $this->assertNull(Env::get('RZ08_FROM_FILE'));
    }

    public function testConfigIsEvaluatedAfterDotEnv(): void
    {
        \file_put_contents($this->root . '/.env', "RZ08_FROM_FILE=tz-from-env\n");
        \file_put_contents(
            $this->root . '/config.inc.php',
            "<?php return ['timezone' => \\Razy\\Env::string('RZ08_FROM_FILE', 'UTC'), 'debug' => false];",
        );
        RuntimeConfig::loadEnv($this->root);
        $config = RuntimeConfig::loadConfig($this->root, null);
        $this->assertSame('tz-from-env', $config['timezone']);
    }

    public function testPreloadedConfigIsNeverEvaluatedTwice(): void
    {
        // A pre-RZ-08 index.php already required this file; requiring it again would
        // fatal on the function declaration.
        \file_put_contents(
            $this->root . '/config.inc.php',
            "<?php function rz08_config_helper(): string { return 'x'; } return ['debug' => false];",
        );
        $preloaded = ['debug' => true];
        $this->assertSame($preloaded, RuntimeConfig::loadConfig($this->root, $preloaded));
        $this->assertFalse(\function_exists('rz08_config_helper'));
    }

    public function testNoConfigFileGivesEmptyConfig(): void
    {
        $this->assertSame([], RuntimeConfig::loadConfig($this->root, null));
    }

    public function testConfigMustReturnArray(): void
    {
        \file_put_contents($this->root . '/config.inc.php', '<?php return "nope";');
        $this->expectException(ConfigurationException::class);
        RuntimeConfig::loadConfig($this->root, null);
    }

    public function testBootstrapLoadsEnvThenConfigBeforeAnythingReadsThem(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/bootstrap.inc.php');
        $env = \strpos($src, 'Config\RuntimeConfig::loadEnv(SYSTEM_ROOT);');
        $config = \strpos($src, 'Config\RuntimeConfig::loadConfig(SYSTEM_ROOT,');
        $proxies = \strpos($src, 'TrustedProxies::configure(');
        $this->assertNotFalse($env);
        $this->assertNotFalse($config);
        $this->assertNotFalse($proxies);
        $this->assertLessThan($config, $env);
        $this->assertLessThan($proxies, $config);
        $this->assertStringContainsString('catch (\Razy\Exception\ConfigurationException $e)', $src, 'bad .env/config fails closed before the mode constants exist');
    }

    public function testEntryPointsLeaveConfigToTheFramework(): void
    {
        foreach (['/src/asset/setup/index.php', '/src/system/terminal/serve.inc.php'] as $file) {
            $src = (string) \file_get_contents(SYSTEM_ROOT . $file);
            $this->assertStringContainsString("getenv('RAZY_PHAR')", $src, $file);
            // config.inc.php is only read when the phar is not found directly.
            $this->assertMatchesRegularExpression('/if \(!\\\?is_file\(\$pharPath\) && \\\?is_file\(__DIR__ \. \'\/config\.inc\.php\'\)\)/', $src, $file);
        }
    }

    /**
     * PathUtil joins with DIRECTORY_SEPARATOR (backslash on Windows).
     */
    private function assertSamePath(string $expected, ?string $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertSame(\realpath($expected), \realpath((string) $actual));
    }
}
