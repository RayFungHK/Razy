<?php

/**
 * RZ-52: how a process boots Razy without the web front controller.
 *
 * Every case runs a real child `php` against a throw-away install in a temp
 * directory (sites.inc.php, one dist, one module with a `library/` class):
 *   - a plain PHP script boots a distributor through Razy\Embed::boot() and
 *     calls a module API, from the phar and from a composer install;
 *   - the `bridge` command (its closure and the CLI) finds a distributor;
 *   - a bare Distributor (what `runapp` builds) autoloads module libraries,
 *     once, and a Domain-built one does not add a second autoloader;
 *   - `build` from a folder without sites/ refuses and writes nothing.
 * The phar is built once from this checkout's src/ into the temp directory;
 * the tracked Razy.phar and the checkout are never written.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class EmbedTest extends TestCase
{
    private static string $base = '';

    private static string $phar = '';

    /**
     * @var list<string>
     */
    private array $dirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$base = self::tmp('razy-embed-');
        $project = self::$base . '/project';
        \mkdir($project . '/pharfolder', 0o777, true);
        \copy(\dirname(__DIR__) . '/build.php', $project . '/build.php');
        self::copyTree(\dirname(__DIR__) . '/src', $project . '/src');
        $out = self::exec([\PHP_BINARY, '-d', 'phar.readonly=0', $project . '/build.php'], $project);
        if ($out['code'] !== 0 || !\is_file($project . '/Razy.phar')) {
            throw new RuntimeException('could not build the test phar: ' . $out['out'] . $out['err']);
        }
        self::$phar = $project . '/Razy.phar';
    }

    public static function tearDownAfterClass(): void
    {
        self::remove(self::$base);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
        $this->dirs = [];
    }

    private static function tmp(string $prefix): string
    {
        $dir = \sys_get_temp_dir() . '/' . $prefix . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0o777, true);

        return $dir;
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, out: string, err: string}
     */
    private static function exec(array $cmd, string $cwd, string $stdin = ''): array
    {
        $proc = \proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!\is_resource($proc)) {
            throw new RuntimeException('could not start ' . $cmd[0]);
        }
        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['code' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    /**
     * @return array<string, string>
     */
    private static function snapshot(string $dir): array
    {
        $rows = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $rows[$file->getPathname()] = $file->isDir() ? 'dir' : \md5_file($file->getPathname()) . ':' . $file->getMTime();
        }
        \ksort($rows);

        return $rows;
    }

    private static function copyTree(string $from, string $to): void
    {
        \mkdir($to, 0o777, true);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $target = $to . '/' . $it->getSubPathname();
            $file->isDir() ? @\mkdir($target, 0o777, true) : \copy($file->getPathname(), $target);
        }
    }

    private static function remove(string $path): void
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return;
        }
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\array_diff(\scandir($path), ['.', '..']) as $item) {
                self::remove($path . '/' . $item);
            }
            \rmdir($path);

            return;
        }
        \unlink($path);
    }

    // ── Embed::boot ───────────────────────────────────────────────────────

    public function testPlainScriptBootsADistributorFromThePharAndCallsAModuleApi(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$dist = \Razy\Embed::boot($argv[1], "demo");
$module = $dist->getRegistry()->getLoadedAPIModule("acme/hello");
echo json_encode([
    "class" => get_class($dist),
    "code" => $dist->getCode(),
    "greet" => $module->executeInternalCommand("greet", ["Ada"]),
    "shout" => $module->executeInternalCommand("shout", ["ada"]),
    "root" => SYSTEM_ROOT,
]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame([
            'class' => 'Razy\Distributor',
            'code' => 'demo',
            'greet' => 'hello Ada',
            'shout' => 'ADA',
            'root' => $root,
        ], \json_decode($out['out'], true), $out['out']);
    }

    public function testPlainScriptBootsFromAComposerInstall(): void
    {
        $root = $this->install();
        $autoload = \dirname(__DIR__) . '/vendor/autoload.php';
        $script = $this->script('<?php
require "' . $autoload . '";
$dist = \Razy\Embed::boot($argv[1], "demo");
echo $dist->getRegistry()->getLoadedAPIModule("acme/hello")->executeInternalCommand("shout", ["ada"]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame('ADA', $out['out']);
    }

    public function testBootingTheSameRootAgainGivesAnotherDistributorWithItsOwnAutoloader(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "demo");
$one = count(spl_autoload_functions());
$again = \Razy\Embed::boot($argv[1], "demo");
echo json_encode([count(spl_autoload_functions()) - $one, $again->getRegistry()->getLoadedAPIModule("acme/hello")->executeInternalCommand("greet", ["Bo"])]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame([1, 'hello Bo'], \json_decode($out['out'], true), $out['out']);
    }

    public function testBootKeepsTheTagOfCodeAtTag(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
echo \Razy\Embed::boot($argv[1], "demo@beta")->getTag() . "|" . \Razy\Embed::boot($argv[1], "demo")->getTag();
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame('beta|*', $out['out']);
    }

    public function testADiscardedDistributorTakesItsAutoloaderWithIt(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$keep = \Razy\Embed::boot($argv[1], "demo");
$before = count(spl_autoload_functions());
$dist = \Razy\Embed::boot($argv[1], "demo");
$held = count(spl_autoload_functions());
$gone = WeakReference::create($dist);
unset($dist);
gc_collect_cycles();
echo json_encode([$gone->get() === null, $held - $before, count(spl_autoload_functions()) - $before]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame([true, 1, 0], \json_decode($out['out'], true), 'one autoloader while held, none left once it is gone: ' . $out['out']);
    }

    public function testGetDistributorUsesTheMountPathOfTheMatchedHostAndTheRequestedCode(): void
    {
        $root = $this->install();
        \mkdir($root . '/sites/other');
        \file_put_contents($root . '/sites/other/dist.php', "<?php\nreturn ['dist' => 'other', 'modules' => ['*' => []]];\n");
        \file_put_contents($root . '/sites.inc.php', "<?php\nreturn ['domains' => ['first.example' => ['/first/' => 'demo'], 'second.example' => ['/a/' => 'other', '/b/' => 'demo', '/c/' => 'demo']], 'alias' => []];\n");
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "demo");
$app = new \Razy\Application();
$app->host("second.example");
echo implode(",", [$app->getDistributor("demo")?->getCode() ?? "null", $app->getDistributor("other")?->getCode() ?? "null", $app->getDistributor("nope")?->getCode() ?? "null"]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame('demo,other,null', $out['out'], 'the code asked for, on the path this host mounts it, not the first path of the host');
    }

    public function testBootAfterAnAcceptedDistFileThatThrowsLeavesTheBootstrapAndTheRootFixed(): void
    {
        $root = $this->install();
        \file_put_contents($root . '/sites/demo/dist.php', "<?php\nthrow new RuntimeException('dist-body');\n");
        $other = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$row = [];
try {
    \Razy\Embed::boot($argv[1], "demo");
} catch (Throwable $e) {
    $row["threw"] = get_class($e) . ":" . $e->getMessage();
}
$row["SYSTEM_ROOT"] = defined("SYSTEM_ROOT");
$row["RAZY_VERSION"] = defined("RAZY_VERSION");
try {
    \Razy\Embed::boot($argv[2], "demo");
    $row["other"] = "booted";
} catch (\Razy\Exception\ConfigurationException $e) {
    $row["other"] = "refused";
}
echo json_encode($row);
');

        $out = self::exec([\PHP_BINARY, $script, $root, $other], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame(['threw' => 'RuntimeException:dist-body', 'SYSTEM_ROOT' => true, 'RAZY_VERSION' => true, 'other' => 'refused'], \json_decode($out['out'], true), $out['out']);
    }

    public function testBootingADifferentRootInTheSameProcessThrows(): void
    {
        $first = $this->install();
        $second = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "demo");
try {
    \Razy\Embed::boot($argv[2], "demo");
    echo "no exception";
} catch (\Razy\Exception\ConfigurationException $e) {
    echo "refused: " . $e->getMessage();
}
echo "|" . SYSTEM_ROOT;
');

        $out = self::exec([\PHP_BINARY, $script, $first, $second], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertStringStartsWith('refused: ', $out['out']);
        $this->assertStringContainsString($first, $out['out']);
        $this->assertStringContainsString($second, $out['out']);
        $this->assertStringEndsWith('|' . $first, $out['out'], 'the first root stays the process root');
    }

    public function testBootRefusesABadRootABadIdentifierAndAnUnknownDistBeforeTouchingTheProcess(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
foreach ([["", "demo"], ["a\0b", "demo"], [$argv[1] . "/missing", "demo"], [$argv[1], "../demo"], [$argv[1], "nope"], [$argv[1], ""], [$argv[1], "demo@"], [$argv[1], "demo@../x"], [$argv[1], "demo\n"]] as [$r, $d]) {
    try {
        \Razy\Embed::boot($r, $d);
        echo "no exception;";
    } catch (\Razy\Exception\ConfigurationException $e) {
        echo "refused;";
    }
}
echo defined("SYSTEM_ROOT") || defined("RAZY_VERSION") ? "booted" : "untouched";
');

        $out = self::exec([\PHP_BINARY, $script, $root], $root); // cwd is a valid install: an empty root must not fall back to it

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame(\str_repeat('refused;', 9) . 'untouched', $out['out'], 'a refused call defines no constant and loads no bootstrap');
    }

    // ── code@tag: one parse everywhere (M1) ───────────────────────────────

    public function testATaggedCodeLoadsTheTaggedModuleSetOnEveryEntry(): void
    {
        $root = $this->installTagged("'/' => 'demo@dev'");
        $embed = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$d = \Razy\Embed::boot($argv[1], "demo@dev");
echo $d->getTag() . "|" . $d->getRegistry()->getLoadedAPIModule("acme/hello")->executeInternalCommand("greet", ["Ada"]);
');
        $app = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "bare");
$a = new \Razy\Application();
$a->host("localhost");
$d = $a->getDistributor("demo@dev");
echo $d->getTag() . "|" . $d->getRegistry()->getLoadedAPIModule("acme/hello")->executeInternalCommand("greet", ["Ada"]);
');

        $viaEmbed = self::exec([\PHP_BINARY, $embed, $root], \sys_get_temp_dir());
        $viaApplication = self::exec([\PHP_BINARY, $app, $root], \sys_get_temp_dir());
        $viaRunapp = self::exec([\PHP_BINARY, self::$phar, 'runapp', 'demo@dev'], $root, "call acme/hello greet [\"Ada\"]\nexit\n");

        $this->assertSame('dev|from-dev Ada', $viaEmbed['out'], $viaEmbed['err']);
        $this->assertSame('dev|from-dev Ada', $viaApplication['out'], $viaApplication['err']);
        $this->assertStringContainsString('from-dev Ada', $viaRunapp['out'], $viaRunapp['out'] . $viaRunapp['err']);
        $this->assertStringNotContainsString('dev@', $viaRunapp['out'], 'the tag is dev, not dev@');
    }

    public function testAnUntaggedCodeAndADottedTagKeepTheirSets(): void
    {
        $root = $this->installTagged("'/' => 'demo'");
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$d = \Razy\Embed::boot($argv[1], $argv[2]);
echo $d->getTag() . "|" . $d->getRegistry()->getLoadedAPIModule("acme/hello")->executeInternalCommand("greet", ["Ada"]);
');

        $untagged = self::exec([\PHP_BINARY, $script, $root, 'demo'], \sys_get_temp_dir());
        $dotted = self::exec([\PHP_BINARY, $script, $root, 'demo@v1.2.0'], \sys_get_temp_dir());
        $runapp = self::exec([\PHP_BINARY, self::$phar, 'runapp', 'demo'], $root, "call acme/hello greet [\"Ada\"]\nexit\n");

        $this->assertSame('*|from-default Ada', $untagged['out'], $untagged['err']);
        $this->assertSame('v1.2.0|from-dev Ada', $dotted['out'], $dotted['err']);
        $this->assertStringContainsString('from-default Ada', $runapp['out']);
    }

    // ── bridge ────────────────────────────────────────────────────────────

    public function testBridgeCommandCallsAModuleApiThroughTheCli(): void
    {
        $root = $this->install();
        $payload = \json_encode(['dist' => 'demo', 'module' => 'acme/hello', 'command' => 'greet', 'args' => ['Cy']]);

        $out = self::exec([\PHP_BINARY, self::$phar, 'bridge', $payload], $root);

        $this->assertSame(['ok' => true, 'data' => 'hello Cy'], \json_decode($out['out'], true), $out['out'] . $out['err']);
    }

    public function testBridgeCommandAnswersUnknownDistributorAndModule(): void
    {
        $root = $this->install();
        $missingDist = \json_encode(['dist' => 'nope', 'module' => 'acme/hello', 'command' => 'greet']);
        $missingModule = \json_encode(['dist' => 'demo', 'module' => 'acme/none', 'command' => 'greet']);

        $one = self::exec([\PHP_BINARY, self::$phar, 'bridge', $missingDist], $root);
        $two = self::exec([\PHP_BINARY, self::$phar, 'bridge', $missingModule], $root);

        $this->assertSame(['ok' => false, 'error' => "Distributor 'nope' not found"], \json_decode($one['out'], true), $one['out']);
        $this->assertSame(['ok' => false, 'error' => "Module 'acme/none' not found"], \json_decode($two['out'], true), $two['out']);
    }

    public function testBridgeClosureRunsAfterAnEmbeddedBoot(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "demo");
$bridge = require "phar://' . self::$phar . '/system/terminal/bridge.inc.php";
ob_start();
$ok = $bridge(json_encode(["dist" => "demo", "module" => "acme/hello", "command" => "shout", "args" => ["di"]]));
echo json_encode([$ok, ob_get_clean()]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame([true, '{"ok":true,"data":"DI"}'], \json_decode($out['out'], true), $out['out']);
    }

    public function testBridgeSaysUnknownFailedAndOkApartThroughTheCli(): void
    {
        $root = $this->install();
        $run = fn (string $command): array => self::exec([\PHP_BINARY, self::$phar, 'bridge', \json_encode(['dist' => 'demo', 'module' => 'acme/hello', 'command' => $command])], $root);

        $unknown = $run('nope');
        $failed = $run('boom');
        $null = $run('nothing');

        $this->assertSame(['ok' => false, 'status' => 'unknown', 'error' => "Unknown command 'nope'"], \json_decode($unknown['out'], true), $unknown['out']);
        $failed['json'] = \json_decode($failed['out'], true);
        $this->assertSame(['ok' => false, 'status' => 'failed'], \array_intersect_key($failed['json'], ['ok' => 1, 'status' => 1]), $failed['out']);
        $this->assertStringNotContainsString('boom-secret', $failed['out'], 'a failing command\'s message is not on the wire');
        $this->assertStringContainsString('boom-secret', $failed['err'], 'the trace stays on stderr');
        $this->assertSame(['ok' => true, 'data' => null], \json_decode($null['out'], true), $null['out']);
        $this->assertSame([0, 0, 0], [$unknown['code'], $failed['code'], $null['code']], 'exit code unchanged');
    }

    public function testBridgeClosureSaysUnknownFailedAndOkApart(): void
    {
        $root = $this->install();
        // one closure call per process: it locks the Application, as the CLI does
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
\Razy\Embed::boot($argv[1], "demo");
$bridge = require "phar://' . self::$phar . '/system/terminal/bridge.inc.php";
ob_start();
$ret = $bridge(json_encode(["dist" => "demo", "module" => "acme/hello", "command" => $argv[2]]));
echo json_encode([$ret, json_decode(ob_get_clean(), true)]);
');
        $run = fn (string $command): array => \json_decode(self::exec([\PHP_BINARY, $script, $root, $command], \sys_get_temp_dir())['out'], true) ?? [];

        $unknown = $run('nope');
        $failed = $run('boom');
        $null = $run('nothing');

        $this->assertSame([false, ['ok' => false, 'status' => 'unknown', 'error' => "Unknown command 'nope'"]], $unknown);
        $this->assertSame([false, 'failed'], [$failed[0] ?? null, $failed[1]['status'] ?? null]);
        $this->assertStringNotContainsString('boom-secret', \json_encode($failed));
        $this->assertSame([true, ['ok' => true, 'data' => null]], $null);
    }

    // ── runapp boot path ──────────────────────────────────────────────────

    public function testRunappCallsAModuleCommandThatUsesALibraryClass(): void
    {
        $root = $this->install();

        $out = self::exec([\PHP_BINARY, self::$phar, 'runapp', 'demo'], $root, "call acme/hello shout [\"ada\"]\nexit\n");

        $this->assertStringContainsString('ADA', $out['out'], $out['out'] . $out['err']);
        $this->assertStringNotContainsString('not found', $out['out'] . $out['err']);
    }

    public function testBareDistributorRegistersItsAutoloaderOnceAndADomainOneRegistersNone(): void
    {
        $root = $this->install();
        $script = $this->script('<?php
require "phar://' . self::$phar . '/library/Razy/Embed.php";
$boot = \Razy\Embed::boot($argv[1], "demo");
$before = count(spl_autoload_functions());
$bare = new \Razy\Distributor("demo", "*", null, "", "/");
$bare->initialize();
$afterBare = count(spl_autoload_functions());
$bare->initialize();
$afterTwice = count(spl_autoload_functions());
$app = new \Razy\Application();
$app->host("localhost");
$afterHost = count(spl_autoload_functions());
$viaApp = $app->getDistributor("demo");
echo json_encode([
    "bare" => $afterBare - $before,
    "twice" => $afterTwice - $afterBare,
    "host" => $afterHost - $afterTwice,
    "domain" => count(spl_autoload_functions()) - $afterHost,
    "loaded" => class_exists("acme\\\hello\\\Shouter"),
    "viaApp" => $viaApp?->getCode(),
]);
');

        $out = self::exec([\PHP_BINARY, $script, $root], \sys_get_temp_dir());

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertSame(
            ['bare' => 1, 'twice' => 0, 'host' => 1, 'domain' => 0, 'loaded' => true, 'viaApp' => 'demo'],
            \json_decode($out['out'], true),
            $out['out'],
        );
    }

    // ── build ─────────────────────────────────────────────────────────────

    public function testBuildFromAFolderWithoutSitesRefusesAndWritesNothing(): void
    {
        $cwd = $this->dir();
        $pharFolder = $this->dir();
        \copy(self::$phar, $pharFolder . '/Razy.phar');
        $before = self::snapshot($pharFolder);

        $out = self::exec([\PHP_BINARY, $pharFolder . '/Razy.phar', 'build', '.'], $cwd);

        $this->assertNotSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertStringContainsString('sites/', $out['out'] . $out['err']);
        $this->assertStringContainsString('has no sites/ or standalone/ folder', $out['out'] . $out['err'], 'the text says standalone/ is accepted too');
        $this->assertSame([], \array_diff(\scandir($cwd), ['.', '..']), 'the working directory is untouched');
        $this->assertSame($before, self::snapshot($pharFolder), "the phar's folder is untouched");
    }

    public function testBuildInThePharsOwnFolderStillInstalls(): void
    {
        $folder = $this->dir();
        \copy(self::$phar, $folder . '/Razy.phar');

        $out = self::exec([\PHP_BINARY, $folder . '/Razy.phar', 'build', '.'], $folder);

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertDirectoryExists($folder . '/sites');
        $this->assertFileExists($folder . '/sites.inc.php');
    }

    public function testBuildFromAFolderWithSitesInstallsThere(): void
    {
        $cwd = $this->dir();
        \mkdir($cwd . '/sites');
        $pharFolder = $this->dir();
        \copy(self::$phar, $pharFolder . '/Razy.phar');

        $out = self::exec([\PHP_BINARY, $pharFolder . '/Razy.phar', 'build', '.'], $cwd);

        $this->assertSame(0, $out['code'], $out['out'] . $out['err']);
        $this->assertFileExists($cwd . '/sites.inc.php');
        $this->assertSame(['Razy.phar'], \array_values(\array_diff(\scandir($pharFolder), ['.', '..'])), 'nothing lands next to the phar');
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * A throw-away install: one domain, dist `demo`, module `acme/hello` with
     * two API commands, one of which uses a class from the module's library/.
     */
    private function install(): string
    {
        $root = $this->dir();
        $module = $root . '/sites/demo/acme/hello';
        \mkdir($module . '/default/controller/api', 0o777, true);
        \mkdir($module . '/default/library', 0o777, true);
        \file_put_contents($root . '/sites.inc.php', "<?php\nreturn ['domains' => ['localhost' => ['/' => 'demo']], 'alias' => []];\n");
        \file_put_contents($root . '/sites/demo/dist.php', "<?php\nreturn ['dist' => 'demo', 'modules' => ['*' => ['acme/hello' => 'default']]];\n");
        \file_put_contents($module . '/module.php', "<?php\nreturn ['module_code' => 'acme/hello', 'name' => 'Hello', 'author' => 'test', 'description' => 'test', 'version' => '1.0.0'];\n");
        \file_put_contents($module . '/default/package.php', "<?php\nreturn ['module_code' => 'acme/hello', 'api_name' => 'hello', 'require' => []];\n");
        \file_put_contents($module . '/default/controller/hello.php', <<<'PHP'
            <?php
            use Razy\Agent;
            use Razy\Controller;

            return new class() extends Controller {
                public function __onInit(Agent $agent): bool
                {
                    $agent->addAPICommand('greet', 'api/greet');
                    $agent->addAPICommand('shout', 'api/shout');
                    $agent->addAPICommand('boom', 'api/boom');
                    $agent->addAPICommand('nothing', 'api/nothing');

                    return true;
                }
            };
            PHP);
        \file_put_contents($module . '/default/controller/api/greet.php', "<?php\nreturn function (string \$name = 'world'): string {\n    return 'hello ' . \$name;\n};\n");
        \file_put_contents($module . '/default/controller/api/shout.php', "<?php\nreturn function (string \$name): string {\n    return (new \\acme\\hello\\Shouter())->shout(\$name);\n};\n");
        \file_put_contents($module . '/default/controller/api/boom.php', "<?php\nreturn function (): void {\n    throw new \\RuntimeException('boom-secret');\n};\n");
        \file_put_contents($module . '/default/controller/api/nothing.php', "<?php\nreturn function (): mixed {\n    return null;\n};\n");
        \file_put_contents($module . '/default/library/Shouter.php', "<?php\nnamespace acme\\hello;\n\nclass Shouter\n{\n    public function shout(string \$s): string\n    {\n        return \\strtoupper(\$s);\n    }\n}\n");

        return \realpath($root);
    }

    /**
     * install() with a second version folder and a tag: dist.php pins `*` to default and `dev`
     * (and `v1.2.0`) to the dev folder; greet answers `from-default` or `from-dev`.
     *
     * @param string $sites the sites.inc.php mapping, e.g. "'/' => 'demo@dev'"
     */
    private function installTagged(string $sites): string
    {
        $root = $this->install();
        $module = $root . '/sites/demo/acme/hello';
        \file_put_contents($root . '/sites.inc.php', "<?php\nreturn ['domains' => ['localhost' => [{$sites}]], 'alias' => []];\n");
        \file_put_contents($root . '/sites/demo/dist.php', "<?php\nreturn ['dist' => 'demo', 'modules' => ['*' => ['acme/hello' => 'default'], 'dev' => ['acme/hello' => 'dev'], 'v1.2.0' => ['acme/hello' => 'dev']]];\n");
        \file_put_contents($module . '/default/controller/api/greet.php', "<?php\nreturn fn (string \$name = 'world'): string => 'from-default ' . \$name;\n");
        \mkdir($root . '/sites/bare');
        \file_put_contents($root . '/sites/bare/dist.php', "<?php\nreturn ['dist' => 'bare', 'modules' => ['*' => []]];\n");
        \mkdir($module . '/dev/controller/api', 0o777, true);
        \copy($module . '/default/package.php', $module . '/dev/package.php');
        \copy($module . '/default/controller/hello.php', $module . '/dev/controller/hello.php');
        foreach (['shout', 'boom', 'nothing'] as $name) {
            \copy($module . '/default/controller/api/' . $name . '.php', $module . '/dev/controller/api/' . $name . '.php');
        }
        \mkdir($module . '/dev/library', 0o777, true);
        \copy($module . '/default/library/Shouter.php', $module . '/dev/library/Shouter.php');
        \file_put_contents($module . '/dev/controller/api/greet.php', "<?php\nreturn fn (string \$name = 'world'): string => 'from-dev ' . \$name;\n");

        return $root;
    }

    private function script(string $code): string
    {
        $file = $this->dir() . '/script.php';
        \file_put_contents($file, $code);

        return $file;
    }

    private function dir(): string
    {
        $dir = self::tmp('razy-embed-case-');
        $this->dirs[] = $dir;

        return \realpath($dir);
    }
}
