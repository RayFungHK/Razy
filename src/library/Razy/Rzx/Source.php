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

/**
 * One `.rzx` source text plus its file name.
 *
 * Every token and AST node stores a byte offset into this text; `Source`
 * turns an offset into a 1-based line and column (columns count UTF-8
 * characters, not bytes) and renders the code frame used by CompileError.
 */
final class Source
{
    private const BOM = "\xEF\xBB\xBF";

    /** @var list<int> byte offset of the first character of each line */
    private array $lineStarts = [0];

    public function __construct(
        public readonly string $text,
        public readonly string $file = '<rzx>',
    ) {
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            if ($text[$i] === "\n") {
                $this->lineStarts[] = $i + 1;
            }
        }
    }

    public static function fromFile(string $path): self
    {
        $text = @\file_get_contents($path);
        if ($text === false) {
            throw new CompileError('RZX000', 'Cannot read the .rzx file', new self('', $path), 0);
        }

        return new self($text, $path);
    }

    /**
     * @return array{int, int} [line, column], both 1-based
     */
    public function position(int $offset): array
    {
        $offset = \max(0, \min($offset, \strlen($this->text)));
        $low = 0;
        $high = \count($this->lineStarts) - 1;
        while ($low < $high) {
            $mid = ($low + $high + 1) >> 1;
            if ($this->lineStarts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        $lineStart = $this->lineStarts[$low];
        if ($low === 0 && \str_starts_with($this->text, self::BOM)) {
            $lineStart = \min(3, $offset); // a UTF-8 BOM is not a column
        }
        $prefix = \substr($this->text, $lineStart, $offset - $lineStart);
        $column = \preg_match_all('/./su', $prefix);
        if ($column === false) {
            $column = \strlen($prefix); // not valid UTF-8: fall back to bytes
        }

        return [$low + 1, $column + 1];
    }

    public function line(int $line): string
    {
        if ($line < 1 || $line > \count($this->lineStarts)) {
            return '';
        }
        $start = $this->lineStarts[$line - 1];
        $end = $this->lineStarts[$line] ?? \strlen($this->text) + 1;

        $text = \rtrim(\substr($this->text, $start, $end - $start - 1), "\r");

        return $line === 1 && \str_starts_with($text, self::BOM) ? \substr($text, 3) : $text;
    }

    /**
     * Three-line code frame (previous line, the line, next line) with a caret
     * under the column.
     */
    public function frame(int $offset): string
    {
        [$line, $column] = $this->position($offset);
        $last = \min(\count($this->lineStarts), $line + 1);
        $width = \strlen((string) $last);
        $out = [];
        for ($n = \max(1, $line - 1); $n <= $last; ++$n) {
            $marker = $n === $line ? '>' : ' ';
            $out[] = \sprintf('%s %' . $width . 'd | %s', $marker, $n, $this->line($n));
            if ($n === $line) {
                $out[] = \str_repeat(' ', $width + 3) . '| ' . \str_repeat(' ', $column - 1) . '^';
            }
        }

        return \implode("\n", $out);
    }
}
