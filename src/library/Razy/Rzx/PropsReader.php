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

use ArgumentCountError;
use InvalidArgumentException;
use Razy\Rzx\Node\PropsNode;
use TypeError;

/**
 * Reads a `<props>` block into `array<string, Prop>` without running PHP.
 *
 * The block must be `return [ 'name' => Prop::type(…)->modifier(…), … ];`.
 * This class interprets exactly that subset (string, number, bool, null and
 * array literals, `Prop::` chains, comments), so nothing in a `.rzx` file is
 * ever evaluated as PHP: no `eval`, no `include`, no ext-tokenizer.
 */
final class PropsReader
{
    private const STATIC = ['string', 'int', 'float', 'bool', 'html', 'any', 'list', 'map', 'shape', 'enum'];

    private const MODIFIERS = ['required', 'optional', 'default', 'min', 'max'];

    private int $pos = 0;

    private int $end = 0;

    private int $depth = 0;

    /** @var array<string|int, int> offset of each top-level key */
    private array $keys = [];

    private function __construct(private readonly Source $source)
    {
    }

    /**
     * @return array<string, Prop>
     */
    public static function read(Source $source, PropsNode $props): array
    {
        $reader = new self($source);
        $reader->pos = $props->bodyOffset;
        $reader->end = $props->bodyOffset + \strlen($props->php);

        return $reader->file();
    }

    /**
     * @return array<string, Prop>
     */
    private function file(): array
    {
        $this->expectWord('return', 'A <props> block is `return [ … ];`');
        $start = $this->pos;
        $schema = $this->value();
        if (!\is_array($schema)) {
            throw $this->error('RZX060', '<props> must return an array literal', $start);
        }
        $this->skip();
        if ($this->peek() === ';') {
            ++$this->pos;
        }
        $this->skip();
        if ($this->pos < $this->end) {
            throw $this->error('RZX060', 'Unexpected code after the <props> array', $this->pos);
        }
        $props = [];
        foreach ($schema as $name => $prop) {
            $at = $this->keys[$name] ?? $start;
            if (!\is_string($name) || !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw $this->error('RZX061', 'Prop names must be identifiers (letters, digits, `_`), got ' . \var_export($name, true), $at);
            }
            if (!$prop instanceof Prop) {
                throw $this->error('RZX061', 'Prop "' . $name . '" must be a Prop:: chain', $at);
            }
            $props[$name] = $prop;
        }

        return $props;
    }

    private function value(): mixed
    {
        $this->skip();
        $at = $this->pos;
        $char = $this->peek();
        if ($char === '[') {
            return $this->array();
        }
        if ($char === "'" || $char === '"') {
            return $this->string();
        }
        if (\preg_match('/\G-?[0-9]+(?:\.[0-9]+)?/', $this->source->text, $m, 0, $at)) {
            $this->pos += \strlen($m[0]);

            return \str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }
        $word = $this->word();
        switch (\strtolower($word)) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
        }
        if ($word === 'Prop' || $word === '\Razy\Rzx\Prop' || $word === 'Razy\Rzx\Prop') {
            return $this->chain($at);
        }

        throw $this->error('RZX062', $word === '' ? 'Expected a value' : 'Only literals and Prop:: chains are allowed in <props>, found `' . $word . '`', $at);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function array(): array
    {
        ++$this->pos; // [
        ++$this->depth;
        $items = [];
        while (true) {
            $this->skip();
            if ($this->peek() === ']') {
                ++$this->pos;
                --$this->depth;

                return $items;
            }
            $keyAt = $this->pos;
            $value = $this->value();
            $this->skip();
            if (\substr($this->source->text, $this->pos, 2) === '=>') {
                $this->pos += 2;
                if (!\is_string($value) && !\is_int($value)) {
                    throw $this->error('RZX062', 'An array key must be a string or an integer', $keyAt);
                }
                if (\array_key_exists($value, $items)) {
                    throw $this->error('RZX061', 'Duplicate key ' . \var_export($value, true), $keyAt);
                }
                if ($this->depth === 1) {
                    $this->keys[$value] = $keyAt;
                }
                $items[$value] = $this->value();
            } else {
                if ($this->depth === 1) {
                    $this->keys[\count($items)] = $keyAt;
                }
                $items[] = $value;
            }
            $this->skip();
            if ($this->peek() === ',') {
                ++$this->pos;
            } elseif ($this->peek() !== ']') {
                throw $this->error('RZX062', 'Expected `,` or `]`', $this->pos);
            }
        }
    }

    private function chain(int $at): Prop
    {
        $this->skip();
        if (\substr($this->source->text, $this->pos, 2) !== '::') {
            throw $this->error('RZX062', 'Expected `::` after Prop', $this->pos);
        }
        $this->pos += 2;
        $nameAt = $this->pos;
        $name = $this->word();
        if (!\in_array($name, self::STATIC, true)) {
            throw $this->error('RZX063', 'Unknown Prop::' . $name . '(); use one of ' . \implode(', ', self::STATIC), $nameAt);
        }
        $prop = $this->invoke($nameAt, static fn (array $args): mixed => \call_user_func_array([Prop::class, $name], $args));
        while (true) {
            $this->skip();
            if (\substr($this->source->text, $this->pos, 2) !== '->') {
                return $prop;
            }
            $this->pos += 2;
            $nameAt = $this->pos;
            $modifier = $this->word();
            if (!\in_array($modifier, self::MODIFIERS, true)) {
                throw $this->error('RZX063', 'Unknown modifier ->' . $modifier . '(); use one of ' . \implode(', ', self::MODIFIERS), $nameAt);
            }
            $current = $prop;
            $prop = $this->invoke($nameAt, static fn (array $args): mixed => \call_user_func_array([$current, $modifier], $args));
        }
    }

    /**
     * Read `( args )` and call $call with them, mapping a bad argument to a compile error.
     *
     * @param callable(list<mixed>): mixed $call
     */
    private function invoke(int $at, callable $call): Prop
    {
        $this->skip();
        if ($this->peek() !== '(') {
            throw $this->error('RZX062', 'Expected `(`', $this->pos);
        }
        ++$this->pos;
        $args = [];
        while (true) {
            $this->skip();
            if ($this->peek() === ')') {
                ++$this->pos;
                break;
            }
            $args[] = $this->value();
            $this->skip();
            if ($this->peek() === ',') {
                ++$this->pos;
            } elseif ($this->peek() !== ')') {
                throw $this->error('RZX062', 'Expected `,` or `)`', $this->pos);
            }
        }

        try {
            $prop = $call($args);
        } catch (InvalidArgumentException|TypeError|ArgumentCountError $e) {
            throw $this->error('RZX064', 'Invalid Prop arguments: ' . $e->getMessage(), $at);
        }
        if (!$prop instanceof Prop) {
            throw $this->error('RZX064', 'Invalid Prop chain', $at);
        }

        return $prop;
    }

    private function string(): string
    {
        $text = $this->source->text;
        $quote = $text[$this->pos];
        $start = $this->pos;
        $value = '';
        for ($i = $start + 1; $i < $this->end; ++$i) {
            $char = $text[$i];
            if ($char === $quote) {
                $this->pos = $i + 1;

                return $value;
            }
            if ($quote === '"' && $char === '$') {
                throw $this->error('RZX062', 'Variables are not allowed in <props> strings; use single quotes', $i);
            }
            if ($char === '\\' && $i + 1 < $this->end) {
                $next = $text[$i + 1];
                $map = $quote === "'" ? ["'" => "'", '\\' => '\\'] : ['"' => '"', '\\' => '\\', 'n' => "\n", 't' => "\t", '$' => '$'];
                if (isset($map[$next])) {
                    $value .= $map[$next];
                    ++$i;
                    continue;
                }
            }
            $value .= $char;
        }

        throw $this->error('RZX062', 'Unterminated string', $start);
    }

    private function word(): string
    {
        if (\preg_match('/\G\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\[A-Za-z_][A-Za-z0-9_]*)*/', $this->source->text, $m, 0, $this->pos) && $this->pos + \strlen($m[0]) <= $this->end) {
            $this->pos += \strlen($m[0]);

            return $m[0];
        }

        return '';
    }

    private function expectWord(string $word, string $reason): void
    {
        $this->skip();
        $at = $this->pos;
        if (\strtolower($this->word()) !== $word) {
            throw $this->error('RZX060', $reason, $at);
        }
    }

    /** Skip whitespace and `//`, `#`, `/* *\/` comments. */
    private function skip(): void
    {
        $text = $this->source->text;
        while ($this->pos < $this->end) {
            $this->pos += \strspn($text, " \t\r\n", $this->pos, $this->end - $this->pos);
            $two = \substr($text, $this->pos, 2);
            if ($two === '//' || ($this->peek() === '#' && $two !== '#[')) {
                $newline = \strpos($text, "\n", $this->pos);
                $this->pos = $newline === false || $newline > $this->end ? $this->end : $newline;
            } elseif ($two === '/*') {
                $close = \strpos($text, '*/', $this->pos + 2);
                if ($close === false || $close >= $this->end) {
                    throw $this->error('RZX062', 'Unterminated comment', $this->pos);
                }
                $this->pos = $close + 2;
            } else {
                return;
            }
        }
    }

    private function peek(): string
    {
        return $this->pos < $this->end ? $this->source->text[$this->pos] : '';
    }

    private function error(string $code, string $reason, int $offset): CompileError
    {
        return new CompileError($code, $reason, $this->source, $offset);
    }
}
