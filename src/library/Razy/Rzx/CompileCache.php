<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

use InvalidArgumentException;
use Razy\Rzx\Runtime\Compiled;
use Razy\Rzx\Runtime\ComponentLoader;
use Razy\Rzx\Runtime\RenderError;
use Throwable;

/**
 * The build-time cache of compiled components, after the BootCompiler pattern.
 *
 * Layout of $directory:
 *
 *     index.php                       schema, compiler version, one entry per component
 *     Kit_Dialog.<hash16>.php         one compiled file per component and hash
 *
 * Each entry holds the source path, mtime, size and sha256, the dependency
 * hashes, the function-registry signature, and the component's contract.
 * Its hash is sha256(compiler version, source sha, function signature,
 * dependency hashes), so editing a component recompiles every component
 * that uses it.
 *
 * Modes:
 * - `dev`: the first load in a request checks mtime + size (then sha256)
 *   of the source and its dependencies, and recompiles what changed.
 * - `compiled`: never compiles. A missing or stale entry is an error that
 *   names `php Razy.phar compile --rzx`, never a silent recompile.
 */
final class CompileCache implements ComponentLoader
{
    public const SCHEMA = 'rzx-1';

    public const MODES = ['dev', 'compiled'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    /** @var array<string, array<string, mixed>> entries checked in this instance */
    private array $checked = [];

    /** @var array<string, Compiled> */
    private array $loaded = [];

    /** @var list<string> components being compiled (cycle detection) */
    private array $stack = [];

    private readonly Compiler $compiler;

    public function __construct(
        private readonly ComponentResolver $resolver,
        private readonly string $directory,
        private readonly FunctionRegistry $functions = new FunctionRegistry(),
        private readonly string $mode = 'dev',
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('rzx mode must be dev or compiled, got ' . $mode);
        }
        $this->compiler = new Compiler($functions);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function field(array $entry, string $key): string
    {
        $value = $entry[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    public function load(string $name): Compiled
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }
        $entry = $this->ensure($name);
        $file = $this->directory . \DIRECTORY_SEPARATOR . self::field($entry, 'file');
        $compiled = (static fn (string $__file): mixed => include $__file)($file);
        if (!$compiled instanceof Compiled) {
            throw new RenderError('Compiled .rzx file is corrupt: ' . $file . '; run php Razy.phar compile --rzx');
        }
        $compiled->compiledFile = $file;

        return $this->loaded[$name] = $compiled;
    }

    /**
     * Compile (or confirm fresh) every named component; for deploy. A compile
     * error throws, so a broken component fails the build.
     *
     * @param list<string> $names
     *
     * @return array<string, string> name => hash
     */
    public function build(array $names): array
    {
        $hashes = [];
        foreach ($names as $name) {
            $hashes[$name] = self::field($this->ensure($name), 'hash');
        }

        return $hashes;
    }

    /**
     * The contract of a component (compiling it when needed).
     */
    public function meta(string $name): ComponentMeta
    {
        return ComponentMeta::fromArray((array) ($this->ensure($name)['meta'] ?? []));
    }

    /**
     * @return array<string, mixed> the fresh index entry
     */
    private function ensure(string $name): array
    {
        if (isset($this->checked[$name])) {
            return $this->checked[$name];
        }
        if (!Names::isComponent($name)) {
            throw new RenderError('Invalid .rzx component name: ' . $name);
        }
        $path = $this->resolver->resolve($name);
        if ($path === null) {
            throw new RenderError('Unknown .rzx component ' . $name);
        }
        $entry = $this->index()[$name] ?? null;
        if ($this->mode === 'compiled') {
            if ($entry === null || !$this->artifactExists($entry) || !$this->sameStat($entry, $path)) {
                throw new RenderError('.rzx component ' . $name . ' is ' . ($entry === null ? 'not compiled' : 'stale') . '; run php Razy.phar compile --rzx at deploy');
            }

            return $this->checked[$name] = $entry;
        }
        if ($entry === null || !$this->isFresh($entry, $path)) {
            $entry = $this->compile($name, $path);
        }

        return $this->checked[$name] = $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isFresh(array $entry, string $path): bool
    {
        if (($entry['source'] ?? null) !== $path || ($entry['compiler'] ?? null) !== Compiler::VERSION
            || ($entry['functions'] ?? null) !== $this->functions->signature() || !$this->artifactExists($entry)) {
            return false;
        }
        if (!$this->sameStat($entry, $path) && \hash_file('sha256', $path) !== ($entry['sha'] ?? null)) {
            return false;
        }
        foreach ((array) ($entry['deps'] ?? []) as $dep => $hash) {
            try {
                if (self::field($this->ensure((string) $dep), 'hash') !== $hash) {
                    return false;
                }
            } catch (RenderError) {
                return false; // the dependency is gone: recompile to report it at the use site
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(string $name, string $path): array
    {
        if (\in_array($name, $this->stack, true)) {
            throw new RenderError('Components use each other in a cycle: ' . \implode(' → ', [...$this->stack, $name]));
        }
        $this->stack[] = $name;

        try {
            $source = Source::fromFile($path);
            [$doc, $meta] = $this->compiler->analyze($source, $name);
            $deps = [];
            $metaFor = function (string $child, int $offset) use ($name, $meta, $source, &$deps): ComponentMeta {
                if ($child === $name) {
                    return $meta; // a component may render itself (trees)
                }
                if (\in_array($child, $this->stack, true)) {
                    throw new CompileError('RZX114', 'Components use each other in a cycle: ' . \implode(' → ', [...$this->stack, $child]), $source, $offset);
                }
                if ($this->resolver->resolve($child) === null) {
                    throw new CompileError('RZX110', 'Unknown component <' . $child . '>', $source, $offset);
                }
                $entry = $this->ensure($child);
                $deps[$child] = self::field($entry, 'hash');

                return ComponentMeta::fromArray((array) ($entry['meta'] ?? []));
            };
            $php = $this->compiler->generate($doc, $meta, $metaFor);
        } finally {
            \array_pop($this->stack);
        }
        \ksort($deps);
        $sha = \hash('sha256', $source->text);
        $hash = \hash('sha256', \implode("\0", [Compiler::VERSION, $sha, $this->functions->signature(), \json_encode($deps)]));
        $file = \str_replace('.', '_', $name) . '.' . \substr($hash, 0, 16) . '.php';
        $this->write($file, $php);

        $old = $this->index()[$name]['file'] ?? null;
        $stat = @\stat($path) ?: ['mtime' => 0, 'size' => 0];
        $entry = [
            'source' => $path, 'mtime' => $stat['mtime'], 'size' => $stat['size'], 'sha' => $sha, 'hash' => $hash,
            'file' => $file, 'deps' => $deps, 'compiler' => Compiler::VERSION, 'functions' => $this->functions->signature(),
            'meta' => $meta->toArray(),
        ];
        $this->index[$name] = $entry;
        $this->writeIndex();
        if (\is_string($old) && $old !== $file) {
            @\unlink($this->directory . \DIRECTORY_SEPARATOR . $old);
        }
        unset($this->loaded[$name]);

        return $entry;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $index = [];
        $file = $this->directory . \DIRECTORY_SEPARATOR . 'index.php';
        if (\is_file($file)) {
            try {
                $data = (static fn (string $__file): mixed => include $__file)($file);
            } catch (Throwable) {
                $data = null;
            }
            if (\is_array($data) && ($data['schema'] ?? null) === self::SCHEMA && \is_array($data['components'] ?? null)) {
                foreach ($data['components'] as $name => $entry) {
                    if (\is_string($name) && \is_array($entry)) {
                        /** @var array<string, mixed> $entry */
                        $index[$name] = $entry;
                    }
                }
            }
        }

        return $this->index = $index;
    }

    private function writeIndex(): void
    {
        $data = ['schema' => self::SCHEMA, 'compiler' => Compiler::VERSION, 'razy' => \defined('RAZY_VERSION') ? \constant('RAZY_VERSION') : null, 'components' => $this->index];
        $this->write('index.php', "<?php\n\n// Razy RZX compile index. Generated; do not edit.\n\nreturn " . \var_export($data, true) . ";\n");
    }

    /**
     * Atomic write: a temp file in the same directory, then rename.
     */
    private function write(string $file, string $php): void
    {
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o775, true) && !\is_dir($this->directory)) {
            throw new RenderError('Cannot create the .rzx cache directory ' . $this->directory);
        }
        $target = $this->directory . \DIRECTORY_SEPARATOR . $file;
        $temp = $target . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (\file_put_contents($temp, $php, \LOCK_EX) === false || !@\rename($temp, $target)) {
            @\unlink($temp);
            throw new RenderError('Cannot write the .rzx cache file ' . $target);
        }
        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($target, true);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function artifactExists(array $entry): bool
    {
        return \is_string($entry['file'] ?? null) && \is_file($this->directory . \DIRECTORY_SEPARATOR . $entry['file']);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function sameStat(array $entry, string $path): bool
    {
        \clearstatcache(true, $path);
        $stat = @\stat($path);

        return $stat !== false && $stat['mtime'] === ($entry['mtime'] ?? null) && $stat['size'] === ($entry['size'] ?? null);
    }
}
