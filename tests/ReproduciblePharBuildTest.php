<?php

/**
 * This file is part of Razy.
 *
 * RZ-34: two builds of the same tree are byte-identical. Creation order and
 * mtime must not change the sha256. The shared timestamp is SOURCE_DATE_EPOCH,
 * else the git commit time of HEAD, else 0.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ReproduciblePharBuildTest extends TestCase
{
    private const EPOCH = 1700000000;

    private const GIT_EPOCH = 1577836800;

    /**
     * @var array<string, string>
     */
    private const FILES = [
        'src/main.php' => "<?php\necho \"ok\";\n",
        'src/readme.txt' => "hello\n",
        'src/.keep' => "hidden\n",
        'src/library/Demo/A.php' => "<?php\nnamespace Demo;\nclass A {}\n",
        'src/library/Demo/B.php' => "<?php\nnamespace Demo;\nclass B {}\n",
    ];

    /**
     * @var list<string>
     */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];
    }

    public function testSameEpochIgnoresCreationOrderMtimeAndMode(): void
    {
        $first = $this->project(\array_keys(self::FILES), 1000000000, 0644);
        $second = $this->project(\array_reverse(\array_keys(self::FILES)), 1500000000, 0755);
        $env = ['SOURCE_DATE_EPOCH' => (string) self::EPOCH];

        $a = $this->build($first, $env);
        $b = $this->build($second, $env);

        self::assertSame($a['sha256'], $b['sha256']);
        $info = $this->inspect($a['phar']);
        self::assertSame('Razy.phar', $info['alias']);
        self::assertSame('SHA-256', $info['hash_type']);
        self::assertStringStartsWith("#!/usr/bin/env php \n", $info['stub']);
        self::assertSame(\array_fill(0, \count($info['times']), self::EPOCH), \array_values($info['times']));
        foreach (['main.php', 'readme.txt', '.keep', 'library/Demo/A.php', 'library/Demo/B.php', 'system/classmap.php'] as $name) {
            self::assertArrayHasKey($name, $info['times']);
        }
        $joined = \implode("\n", \array_keys($info['times']));
        self::assertStringNotContainsString('link-to-readme', $joined);
        self::assertStringNotContainsString('vacant', $joined);
        self::assertStringContainsString('Count: 2 classes', $info['classmap']);
        self::assertStringContainsString('Demo/A.php', $info['classmap']);
        self::assertStringContainsString('Demo/B.php', $info['classmap']);
    }

    public function testDifferentEpochChangesTheHashAndTheStoredTimestamp(): void
    {
        $tree = \array_keys(self::FILES);
        $early = $this->build($this->project($tree, 1000000000, 0644), ['SOURCE_DATE_EPOCH' => '1700000000']);
        $later = $this->build($this->project($tree, 1000000000, 0644), ['SOURCE_DATE_EPOCH' => '1700000001']);

        self::assertNotSame($early['sha256'], $later['sha256']);
        self::assertSame([1700000000], \array_values(\array_unique(\array_values($this->inspect($early['phar'])['times']))));
        self::assertSame([1700000001], \array_values(\array_unique(\array_values($this->inspect($later['phar'])['times']))));
    }

    public function testUnsetEpochUsesTheGitCommitTime(): void
    {
        $first = $this->gitProject(\array_keys(self::FILES), 1100000000, 0644);
        $second = $this->gitProject(\array_reverse(\array_keys(self::FILES)), 1600000000, 0755);

        $a = $this->build($first, ['SOURCE_DATE_EPOCH' => null]);
        $b = $this->build($second, ['SOURCE_DATE_EPOCH' => null]);

        self::assertSame($a['sha256'], $b['sha256']);
        self::assertSame(
            [self::GIT_EPOCH],
            \array_values(\array_unique(\array_values($this->inspect($a['phar'])['times'])))
        );
    }

    public function testSourceDateEpochOverridesTheGitCommitTime(): void
    {
        $root = $this->gitProject(\array_keys(self::FILES), 1100000000, 0644);
        $built = $this->build($root, ['SOURCE_DATE_EPOCH' => (string) self::EPOCH]);
        self::assertSame(
            [self::EPOCH],
            \array_values(\array_unique(\array_values($this->inspect($built['phar'])['times'])))
        );
    }

    /**
     * @param list<string> $order
     */
    private function project(array $order, int $mtime, int $mode): string
    {
        $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rzy34-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0777, true);
        $this->dirs[] = $root;
        \copy(\dirname(__DIR__) . DIRECTORY_SEPARATOR . 'build.php', $root . DIRECTORY_SEPARATOR . 'build.php');
        \touch($root . DIRECTORY_SEPARATOR . 'build.php', $mtime);
        foreach ($order as $rel) {
            $path = $root . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $parent = \dirname($path);
            if (!\is_dir($parent)) {
                \mkdir($parent, 0777, true);
            }
            \file_put_contents($path, self::FILES[$rel]);
            \chmod($path, $mode);
            \touch($path, $mtime);
        }
        $vacant = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'vacant';
        \mkdir($vacant, 0777, true);
        \touch($vacant, $mtime);
        @\symlink('readme.txt', $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'link-to-readme.txt');

        return $root;
    }

    /**
     * @param list<string> $order
     */
    private function gitProject(array $order, int $mtime, int $mode): string
    {
        $root = $this->project($order, $mtime, $mode);
        $this->spawn(['git', 'init', '-q', '-b', 'master'], $root, []);
        $this->spawn(['git', 'config', 'user.email', 't@example.test'], $root, []);
        $this->spawn(['git', 'config', 'user.name', 't'], $root, []);
        $this->spawn(['git', 'config', 'commit.gpgsign', 'false'], $root, []);
        $this->spawn(['git', 'add', '-A'], $root, []);
        $this->spawn(['git', 'commit', '-qm', 'fixture'], $root, [
            'GIT_AUTHOR_DATE' => '2020-01-01T00:00:00Z',
            'GIT_COMMITTER_DATE' => '2020-01-01T00:00:00Z',
        ]);

        return $root;
    }

    /**
     * @param array<string, string|null> $env
     *
     * @return array{phar: string, sha256: string}
     */
    private function build(string $root, array $env): array
    {
        $result = $this->spawn(
            [\PHP_BINARY, '-d', 'phar.readonly=0', $root . DIRECTORY_SEPARATOR . 'build.php'],
            $root,
            $env
        );
        $phar = $root . DIRECTORY_SEPARATOR . 'Razy.phar';
        self::assertFileExists($phar, $result['stderr'] . $result['stdout']);

        return ['phar' => $phar, 'sha256' => \hash_file('sha256', $phar)];
    }

    /**
     * @return array{alias: string, hash_type: string, stub: string, times: array<string, int>, classmap: string}
     */
    private function inspect(string $phar): array
    {
        $code = <<<'PHP'
$p = new Phar(getenv('PHAR_PATH'));
$times = [];
foreach (new RecursiveIteratorIterator($p) as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    $marker = '.phar/';
    $pos = strpos($path, $marker);
    $rel = $pos === false ? $path : substr($path, $pos + strlen($marker));
    $times[$rel] = $file->getMTime();
}
ksort($times);
$sig = $p->getSignature();
echo json_encode([
    'alias' => $p->getAlias(),
    'hash_type' => $sig['hash_type'] ?? '',
    'stub' => $p->getStub(),
    'times' => $times,
    'classmap' => $p['system/classmap.php']->getContent(),
], JSON_THROW_ON_ERROR);
PHP;
        $result = $this->spawn([\PHP_BINARY, '-r', $code], \dirname($phar), [
            'SOURCE_DATE_EPOCH' => null,
            'PHAR_PATH' => $phar,
        ]);
        self::assertSame(0, $result['code'], $result['stderr'] . $result['stdout']);
        $decoded = \json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $command
     * @param array<string, string|null> $overrides null unsets a variable
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function spawn(array $command, string $cwd, array $overrides): array
    {
        $env = \getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($env[$key]);
            } else {
                $env[$key] = $value;
            }
        }
        $proc = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        self::assertIsResource($proc);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        if ($code !== 0 && !isset($overrides['PHAR_PATH'])) {
            self::fail($stderr . $stdout);
        }

        return ['code' => $code, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                \unlink($item->getPathname());
            } else {
                \rmdir($item->getPathname());
            }
        }
        \rmdir($dir);
    }
}
