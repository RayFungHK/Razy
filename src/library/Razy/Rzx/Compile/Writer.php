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

namespace Razy\Rzx\Compile;

use Razy\Rzx\Source;

/**
 * Accumulates generated PHP: merges static HTML into single `echo` lines,
 * indents blocks, writes a `// rzx: File.rzx:42` comment whenever the source
 * line changes, and records the line map (PHP line => `.rzx` line).
 */
final class Writer
{
    /** @var list<string> */
    private array $lines = [];

    /** @var array<int, int> */
    private array $map = [];

    private string $pending = '';

    private int $indent = 0;

    private int $lastLine = 0;

    /** Physical lines written so far (an echoed literal may span several). */
    private int $count = 0;

    public function __construct(private readonly Source $source)
    {
    }

    /** Append static HTML; consecutive literals become one echo. */
    public function html(string $html): void
    {
        $this->pending .= $html;
    }

    /** A raw line, outside the source mapping (file header and footer). */
    public function raw(string $line): void
    {
        $this->flush();
        $this->push($line === '' ? '' : \str_repeat('    ', $this->indent) . $line);
    }

    /** One statement generated from the node at $offset. */
    public function statement(string $code, int $offset): void
    {
        $this->flush();
        $this->mark($offset);
        $this->push(\str_repeat('    ', $this->indent) . $code);
    }

    /** Open a block (`if (…) {`), mapped to $offset. */
    public function open(string $code, int $offset): void
    {
        $this->statement($code, $offset);
        ++$this->indent;
    }

    /** Close a block, optionally continuing it (`} else {`). */
    public function close(string $code = '}'): void
    {
        $this->flush();
        --$this->indent;
        $this->push(\str_repeat('    ', $this->indent) . $code);
        if (\str_ends_with($code, '{')) {
            ++$this->indent;
        }
    }

    public function indent(int $levels): void
    {
        $this->flush();
        $this->indent += $levels;
    }

    public function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        $this->push(\str_repeat('    ', $this->indent) . 'echo ' . \var_export($this->pending, true) . ';');
        $this->pending = '';
    }

    public function code(): string
    {
        $this->flush();

        return \implode("\n", $this->lines) . "\n";
    }

    /**
     * @return array<int, int> 1-based PHP line => `.rzx` line
     */
    public function lineMap(): array
    {
        return $this->map;
    }

    private function mark(int $offset): void
    {
        [$line] = $this->source->position($offset);
        if ($line !== $this->lastLine) {
            $this->push(\str_repeat('    ', $this->indent) . '// rzx: ' . (\preg_replace('/[^A-Za-z0-9._-]/', '_', \basename($this->source->file)) ?? 'source') . ':' . $line);
            $this->lastLine = $line;
        }
        $this->map[$this->count + 1] = $line;
    }

    private function push(string $line): void
    {
        $this->lines[] = $line;
        $this->count += 1 + \substr_count($line, "\n");
    }
}
