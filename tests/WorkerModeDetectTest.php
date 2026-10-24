<?php

/**
 * This file is part of Razy.
 *
 * RZ-23: WORKER_MODE is true only for a real FrankenPHP worker (the
 * FRANKENPHP_WORKER marker) or the CADDY_WORKER_MODE=true opt-in. Classic
 * FrankenPHP (php_server, no worker directive) also has
 * frankenphp_handle_request() and must take the per-request path. The mode
 * constants are guarded, so an entry script may define them first.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\RuntimeMode;

#[CoversClass(RuntimeMode::class)]
class WorkerModeDetectTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function offMarkers(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'false string' => ['false'],
            'bool false' => [false],
            'null' => [null],
            'array' => [['1']],
        ];
    }

    public function testClassicFrankenPhpIsNotWorker(): void
    {
        $this->assertFalse(RuntimeMode::detectWorker(['SERVER_SOFTWARE' => 'FrankenPHP'], true, false, false));
    }

    public function testWorkerMarkerInServerIsWorker(): void
    {
        $this->assertTrue(RuntimeMode::detectWorker(['FRANKENPHP_WORKER' => '1'], true, false, false));
    }

    public function testWorkerMarkerInEnvIsWorker(): void
    {
        $this->assertTrue(RuntimeMode::detectWorker([], true, false, '1'));
    }

    public function testMarkerWithoutHandleRequestIsNotWorker(): void
    {
        $this->assertFalse(RuntimeMode::detectWorker(['FRANKENPHP_WORKER' => '1'], false, false, false));
    }

    public function testCaddyWorkerModeOptInIsWorker(): void
    {
        $this->assertTrue(RuntimeMode::detectWorker([], true, 'true', false));
        $this->assertTrue(RuntimeMode::detectWorker([], false, 'true', false));
    }

    public function testCaddyWorkerModeOtherValuesDoNotOptIn(): void
    {
        $this->assertFalse(RuntimeMode::detectWorker([], true, 'false', false));
        $this->assertFalse(RuntimeMode::detectWorker([], true, '1', false));
    }

    #[DataProvider('offMarkers')]
    public function testOffMarkerIsNotWorker(mixed $marker): void
    {
        $this->assertFalse(RuntimeMode::detectWorker(['FRANKENPHP_WORKER' => $marker], true, false, false));
    }

    public function testCliBootIsNotWorker(): void
    {
        $this->assertSame([true, false, false], $this->bootModes(''));
    }

    public function testCliBootIgnoresCaddyWorkerMode(): void
    {
        $this->assertSame([true, false, false], $this->bootModes('', ['CADDY_WORKER_MODE' => 'true']));
    }

    public function testPredefinedWorkerModeIsKeptWithoutWarning(): void
    {
        $this->assertSame([true, false, true], $this->bootModes("define('WORKER_MODE', true);"));
    }

    public function testPredefinedModesAreKeptWithoutWarning(): void
    {
        $this->assertSame(
            [false, true, false],
            $this->bootModes("define('CLI_MODE', false); define('WEB_MODE', true); define('WORKER_MODE', false);"),
        );
    }

    public function testBootstrapNoLongerTrustsHandleRequestAlone(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/bootstrap.inc.php');
        $this->assertStringNotContainsString("\\define('WORKER_MODE', \\function_exists('frankenphp_handle_request')", $src);
        $this->assertStringContainsString('RuntimeMode::detectWorker(', $src);
        $this->assertDoesNotMatchRegularExpression('/^\s*\\\define\(\'(CLI|WEB|WORKER)_MODE\'/m', $src, 'every mode define is guarded by \defined()');
    }

    /**
     * Boot bootstrap.inc.php in a fresh CLI process and return [CLI_MODE, WEB_MODE, WORKER_MODE].
     * Any PHP warning or notice (e.g. "Constant already defined") fails the test.
     *
     * @param array<string, string> $env
     *
     * @return array{bool, bool, bool}
     */
    private function bootModes(string $predefine, array $env = []): array
    {
        $root = \sys_get_temp_dir() . '/razy_rz23_' . \bin2hex(\random_bytes(4));
        \mkdir($root, 0o700, true);
        $script = $root . '/boot.php';
        \file_put_contents($script, '<?php' . "\n"
            . 'set_error_handler(function (int $no, string $msg) { fwrite(STDERR, "PHP error: $msg\n"); exit(3); });' . "\n"
            . 'define(\'SYSTEM_ROOT\', ' . \var_export($root, true) . ');' . "\n"
            . 'define(\'PHAR_PATH\', ' . \var_export(SYSTEM_ROOT . '/src', true) . ');' . "\n"
            . $predefine . "\n"
            . 'require PHAR_PATH . \'/system/bootstrap.inc.php\';' . "\n"
            . 'echo json_encode([CLI_MODE, WEB_MODE, WORKER_MODE]);' . "\n");

        try {
            $proc = \proc_open(
                [\PHP_BINARY, '-d', 'error_reporting=-1', $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $env + (\getenv() ?: []),
            );
            $this->assertIsResource($proc);
            $out = (string) \stream_get_contents($pipes[1]);
            $err = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $code = \proc_close($proc);
        } finally {
            $this->removeDir($root);
        }

        $this->assertSame(0, $code, "boot failed: {$err}{$out}");
        $this->assertSame('', $err);
        $modes = \json_decode($out, true);
        $this->assertIsArray($modes, $out);

        /** @var array{bool, bool, bool} $modes */
        return $modes;
    }

    private function removeDir(string $dir): void
    {
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) && !\is_link($path) ? $this->removeDir($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
