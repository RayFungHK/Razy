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

    /** The only file names load() includes: `<Name>.<hash16>.php` inside the cache directory. */
    private const ARTIFACT = '/^[A-Za-z0-9_]+\.[0-9a-f]{16}\.php$/';

    /** Index key prefix for files loaded by path. */
    private const FILE = 'file:';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    /** @var array<string, array<string, mixed>> entries checked in this instance */
    private array $checked = [];

    /** @var array<string, Compiled> */
    private array $loaded = [];

    /** @var list<string> components being compiled (cycle detection) */
    private array $stack = [];

    private readonly Compiler $compiler;

    private readonly string $directory;

    /** Fingerprint of everything outside a source that changes its compiled form. */
    private readonly string $environment;

    /** @var array<string, string|null> file path => profile, for files loaded by path */
    private array $fileProfiles = [];

    public function __construct(
        private readonly ComponentResolver $resolver,
        string $directory,
        FunctionRegistry $functions = new FunctionRegistry(),
        private readonly string $mode = 'dev',
        ?Vocabulary $vocabulary = null,
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('rzx mode must be dev or compiled, got ' . $mode);
        }
        // One separator on every OS, so paths in index entries and messages are stable.
        $this->directory = \rtrim(\str_replace('\\', '/', $directory), '/');
        $vocabulary ??= Vocabulary::none();
        $this->compiler = new Compiler($functions, $vocabulary);
        $this->environment = \hash('sha256', $functions->signature() . "\0" . $vocabulary->signature());
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function field(array $entry, string $key): string
    {
        $value = $entry[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * Load a `.rzx` file by path: a screen or a layout, which is rendered
     * directly rather than used by name. It may use any registered component.
     */
    public function loadFile(string $path, ?string $profile = null): Compiled
    {
        $path = \str_replace('\\', '/', $path);
        $this->fileProfiles[$path] = $profile;

        return $this->load(self::FILE . $path);
    }

    public function load(string $name): Compiled
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }
        $entry = $this->ensure($name);
        $base = self::field($entry, 'file');
        if (!\preg_match(self::ARTIFACT, $base)) {
            throw new RenderError('The .rzx index names an unexpected file for ' . $name . '; run php Razy.phar compile --rzx');
        }
        $file = $this->directory . '/' . $base;
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

    private function profileOf(string $name, string $path): ?string
    {
        return \str_starts_with($name, self::FILE) ? ($this->fileProfiles[$path] ?? null) : $this->resolver->profile($name);
    }

    /**
     * @return array<string, mixed> the fresh index entry
     */
    private function ensure(string $name): array
    {
        if (isset($this->checked[$name])) {
            return $this->checked[$name];
        }
        $isFile = \str_starts_with($name, self::FILE);
        if (!$isFile && !Names::isComponent($name)) {
            throw new RenderError('Invalid .rzx component name: ' . $name);
        }
        $path = $isFile ? (\is_file(\substr($name, \strlen(self::FILE))) ? \substr($name, \strlen(self::FILE)) : null) : $this->resolver->resolve($name);
        if ($path === null) {
            throw new RenderError('Unknown .rzx component ' . $name);
        }
        $entry = $this->index()[$name] ?? null;
        if ($this->mode === 'compiled') {
            if ($entry === null || !$this->artifactExists($entry) || !$this->sameContent($entry, $path) || !$this->depsMatch($entry)) {
                throw new RenderError('.rzx component ' . $name . ' is ' . ($entry === null ? 'not compiled' : 'stale') . '; run php Razy.phar compile --rzx at deploy');
            }

            return $this->checked[$name] = $entry;
        }
        if ($entry === null || !$this->isFresh($entry, $path) || ($entry['profile'] ?? null) !== $this->profileOf($name, $path)) {
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
            || ($entry['environment'] ?? null) !== $this->environment || !$this->artifactExists($entry)) {
            return false;
        }
        if (!$this->sameContent($entry, $path)) {
            return false;
        }

        try {
            return $this->depsMatch($entry);
        } catch (RenderError) {
            return false; // a dependency is gone: recompile to report it at the use site
        }
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
            $isFile = \str_starts_with($name, self::FILE);
            [$doc, $meta] = $this->compiler->analyze($source, $isFile ? \basename($path, '.rzx') : $name);
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
            $profile = $this->profileOf($name, $path);
            $php = $this->compiler->generate($doc, $meta, $metaFor, $profile);
        } finally {
            \array_pop($this->stack);
        }
        \ksort($deps);
        $sha = \hash('sha256', $source->text);
        $hash = \hash('sha256', \implode("\0", [Compiler::VERSION, $sha, $this->environment, $profile ?? '', \json_encode($deps)]));
        $stem = $isFile ? 'file_' . \substr(\sha1($path), 0, 12) . '_' . (\preg_replace('/[^A-Za-z0-9_]/', '_', \basename($path, '.rzx')) ?? 'x') : \str_replace('.', '_', $name);
        $file = $stem . '.' . \substr($hash, 0, 16) . '.php';
        $this->write($file, $php);

        $stat = @\stat($path) ?: ['mtime' => 0, 'size' => 0];
        $entry = [
            'source' => $path, 'mtime' => $stat['mtime'], 'size' => $stat['size'], 'sha' => $sha, 'hash' => $hash,
            'file' => $file, 'deps' => $deps, 'compiler' => Compiler::VERSION, 'environment' => $this->environment, 'profile' => $profile,
            'meta' => $meta->toArray(),
        ];
        $old = $this->updateIndex($name, $entry);
        if (\is_string($old) && $old !== $file && \preg_match(self::ARTIFACT, $old)) {
            @\unlink($this->directory . '/' . $old);
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
        $file = $this->directory . '/' . 'index.php';
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

    /**
     * Read-modify-write of index.php under an exclusive lock, so two builds
     * at once each keep the other's entries.
     *
     * @param array<string, mixed> $entry
     *
     * @return string|null the file the entry named before
     */
    private function updateIndex(string $name, array $entry): ?string
    {
        $this->ensureDirectory();
        $lock = @\fopen($this->directory . '/' . 'index.lock', 'c');
        if ($lock === false || !\flock($lock, \LOCK_EX)) {
            throw new RenderError('Cannot lock the .rzx cache index in ' . $this->directory);
        }

        try {
            $file = $this->directory . '/' . 'index.php';
            if (\function_exists('opcache_invalidate')) {
                \opcache_invalidate($file, true); // another process may have written it
            }
            $this->index = null;
            $index = $this->index();
            $old = $index[$name]['file'] ?? null;
            $index[$name] = $entry;
            $this->index = $index;
            $data = ['schema' => self::SCHEMA, 'compiler' => Compiler::VERSION, 'razy' => \defined('RAZY_VERSION') ? \constant('RAZY_VERSION') : null, 'components' => $index];
            $this->write('index.php', "<?php\n\n// Razy RZX compile index. Generated; do not edit.\n\nreturn " . \var_export($data, true) . ";\n");

            return \is_string($old) ? $old : null;
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!\is_dir($this->directory)) {
            if (!@\mkdir($this->directory, 0o755, true) && !\is_dir($this->directory)) {
                throw new RenderError('Cannot create the .rzx cache directory ' . $this->directory);
            }
            @\chmod($this->directory, 0o755);
        }
    }

    /**
     * Atomic write: a temp file in the same directory, then rename.
     */
    private function write(string $file, string $php): void
    {
        $this->ensureDirectory();
        $target = $this->directory . '/' . $file;
        $temp = $target . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (\file_put_contents($temp, $php, \LOCK_EX) === false || !@\rename($temp, $target)) {
            @\unlink($temp);
            throw new RenderError('Cannot write the .rzx cache file ' . $target);
        }
        @\chmod($target, 0o644);
        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($target, true);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function artifactExists(array $entry): bool
    {
        return \is_string($entry['file'] ?? null) && \is_file($this->directory . '/' . $entry['file']);
    }

    /**
     * The source still has the content the entry was compiled from. Content,
     * not mtime + size: a deploy can restore an old mtime on new content.
     *
     * @param array<string, mixed> $entry
     */
    private function sameContent(array $entry, string $path): bool
    {
        $sha = @\hash_file('sha256', $path);

        return $sha !== false && $sha === ($entry['sha'] ?? null);
    }

    /**
     * Every dependency is itself fresh (or, in compiled mode, verified) and
     * still has the hash this entry was compiled against.
     *
     * @param array<string, mixed> $entry
     */
    private function depsMatch(array $entry): bool
    {
        foreach ((array) ($entry['deps'] ?? []) as $dep => $hash) {
            if (self::field($this->ensure((string) $dep), 'hash') !== $hash) {
                return false;
            }
        }

        return true;
    }
}
