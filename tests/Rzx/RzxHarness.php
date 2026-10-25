<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use FilesystemIterator;
use Razy\Rzx\CompileCache;
use Razy\Rzx\DirectoryResolver;
use Razy\Rzx\FunctionRegistry;
use Razy\Rzx\Runtime\Runtime;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Temp component directories, a cache, and a Runtime with a recording anchor
 * sink, for the RZ-28b compiler tests.
 */
trait RzxHarness
{
    private string $root;

    /** @var list<string> */
    private array $warnings = [];

    private RecordingSink $sink;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/razy-rzx-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root . '/app', 0o775, true);
        \mkdir($this->root . '/kit', 0o775, true);
        $this->sink = new RecordingSink();
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? \rmdir((string) $file) : \unlink((string) $file);
        }
        \rmdir($this->root);
    }

    /**
     * Write a component; a name with a dot goes to the `Kit` namespace.
     */
    private function component(string $name, string $source): string
    {
        $path = \str_starts_with($name, 'Kit.') ? $this->root . '/kit/' . \substr($name, 4) . '.rzx' : $this->root . '/app/' . $name . '.rzx';
        \file_put_contents($path, $source);

        return $path;
    }

    private function cache(?FunctionRegistry $functions = null, string $mode = 'dev'): CompileCache
    {
        return new CompileCache(
            new DirectoryResolver(['' => $this->root . '/app', 'Kit' => $this->root . '/kit']),
            $this->root . '/cache',
            $functions ?? $this->functions(),
            $mode,
        );
    }

    private function functions(): FunctionRegistry
    {
        return (new FunctionRegistry())
            ->register('upper', static fn ($s): string => \strtoupper((string) $s))
            ->register('join', static fn (array $l, string $sep = ','): string => \implode($sep, $l))
            ->register('asset', static fn (string $path): string => '/assets/' . $path);
    }

    private function runtime(?CompileCache $cache = null, bool $check = true): Runtime
    {
        return new Runtime($cache ?? $this->cache(), $this->functions(), $this->sink, $check, function (string $m): void {
            $this->warnings[] = $m;
        });
    }

    /**
     * Compile and render a one-off component named T.
     *
     * @param array<string, mixed> $props
     */
    private function render(string $source, array $props = []): string
    {
        $this->component('T', $source);

        return $this->runtime()->render('T', $props);
    }
}
