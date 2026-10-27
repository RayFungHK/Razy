<?php

/**
 * razymod/apps — stable element ids.
 *
 * Entities, fields, saved views, workflows, workflow states and workflow
 * nodes carry an immutable ULID. Merges and migrations follow the id, not
 * the code: renaming a field keeps its id (and its column); a new id for an
 * old code means "retire the old element, add a new one".
 *
 * - generate()  a ULID (48-bit ms time + 80 random bits, Crockford base32)
 * - assign()    fills missing ids; an element whose kind, parent and code
 *               match one in the previous version gets that version's id,
 *               so an AI draft that drops ids cannot silently re-create
 *               columns
 * - collect()   every id with its kind, JSON pointer and code
 * - compare()   an id used for a different kind than in the previous
 *               version is an error; the same code under a new id is a
 *               warning (the migration plan will retire + add)
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

use Generator;
use InvalidArgumentException;

final class ElementIds
{
    public const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function isValid(mixed $id): bool
    {
        return \is_string($id) && \preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * @param ?int $ms unix time in milliseconds (default: now)
     * @param ?string $random 10 random bytes (default: random_bytes)
     */
    public static function generate(?int $ms = null, ?string $random = null): string
    {
        $ms ??= (int) (\microtime(true) * 1000);
        $random ??= \random_bytes(10);
        if ($ms < 0 || $ms > 0xFFFFFFFFFFFF || \strlen($random) !== 10) {
            throw new InvalidArgumentException('ULID needs a 48-bit time and 10 random bytes');
        }
        $time = '';
        for ($i = 9; $i >= 0; --$i) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = \intdiv($ms, 32);
        }
        // 80 bits → 16 base32 characters, 5 bits at a time
        $bits = '';
        foreach (\str_split($random) as $byte) {
            $bits .= \str_pad(\decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $rand = '';
        foreach (\str_split($bits, 5) as $chunk) {
            $rand .= self::ALPHABET[\bindec($chunk)];
        }

        return $time . $rand;
    }

    /**
     * Every element id in a definition.
     *
     * @param array<string, mixed> $def
     *
     * @return list<array{id: mixed, kind: string, pointer: string, code: string, key: string}>
     *                                                                                          key = kind-qualified path of codes, e.g. "entity:deal/field:amount"
     */
    public static function collect(array $def): array
    {
        $out = [];
        foreach (self::walk($def) as $el) {
            $out[] = $el;
        }

        return $out;
    }

    /**
     * Fill missing ids, reusing the previous version's id for the same element.
     *
     * @param array<string, mixed> $def
     * @param ?array<string, mixed> $previous
     * @param ?callable(): string $generator
     *
     * @return array<string, mixed>
     */
    public static function assign(array $def, ?array $previous = null, ?callable $generator = null): array
    {
        $generator ??= static fn (): string => self::generate();
        $known = [];
        if ($previous !== null) {
            foreach (self::walk($previous) as $el) {
                if (self::isValid($el['id'])) {
                    $known[$el['key']] = $el['id'];
                }
            }
        }
        $used = [];
        foreach (self::walk($def) as $el) {
            if (self::isValid($el['id'])) {
                $used[$el['id']] = true;
            }
        }
        foreach (self::walk($def) as $el) {
            if ($el['id'] !== null) {
                continue;
            }
            $id = $known[$el['key']] ?? null;
            if ($id === null || isset($used[$id])) {
                do {
                    $id = $generator();
                } while (isset($used[$id]));
            }
            $used[$id] = true;
            self::set($def, $el['pointer'], $id);
        }

        return $def;
    }

    /**
     * Id stability between two versions.
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     *
     * @return list<Issue>
     */
    public static function compare(array $previous, array $next): array
    {
        $kindById = [];
        $idByKey = [];
        foreach (self::walk($previous) as $el) {
            if (self::isValid($el['id'])) {
                $kindById[$el['id']] = $el['kind'];
                $idByKey[$el['key']] = $el['id'];
            }
        }
        $issues = [];
        foreach (self::walk($next) as $el) {
            if (!self::isValid($el['id'])) {
                continue;
            }
            $was = $kindById[$el['id']] ?? null;
            if ($was !== null && $was !== $el['kind']) {
                $issues[] = Issue::error(
                    $el['pointer'] . '/id',
                    'id.kind_changed',
                    "Element id {$el['id']} was a {$was} in the previous version, now a {$el['kind']}",
                );
            }
            $old = $idByKey[$el['key']] ?? null;
            if ($old !== null && $old !== $el['id']) {
                $issues[] = Issue::warning(
                    $el['pointer'] . '/id',
                    'id.changed',
                    "'{$el['code']}' has a new id ({$old} before): the old element is retired and a new one added",
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return Generator<array{id: mixed, kind: string, pointer: string, code: string, key: string}>
     */
    private static function walk(array $def): Generator
    {
        foreach (self::listAt($def, 'entities') as $ei => $entity) {
            $ep = "/entities/{$ei}";
            $ekey = 'entity:' . self::code($entity);
            yield self::el($entity, 'entity', $ep, $ekey);
            foreach (self::listAt($entity, 'fields') as $fi => $field) {
                yield self::el($field, 'field', "{$ep}/fields/{$fi}", $ekey . '/field:' . self::code($field));
            }
            $list = \is_array($entity['views'] ?? null) && \is_array($entity['views']['list'] ?? null)
                ? $entity['views']['list'] : [];
            foreach (self::listAt($list, 'saved') as $si => $saved) {
                yield self::el($saved, 'saved_view', "{$ep}/views/list/saved/{$si}", $ekey . '/saved:' . self::code($saved));
            }
        }
        foreach (self::listAt($def, 'workflows') as $wi => $wf) {
            $wp = "/workflows/{$wi}";
            $wkey = 'workflow:' . self::code($wf);
            yield self::el($wf, 'workflow', $wp, $wkey);
            foreach (self::listAt($wf, 'states') as $si => $state) {
                yield self::el($state, 'wf_state', "{$wp}/states/{$si}", $wkey . '/state:' . self::code($state));
            }
            foreach (self::listAt($wf, 'nodes') as $ni => $node) {
                yield self::el($node, 'wf_node', "{$wp}/nodes/{$ni}", $wkey . '/node:' . self::code($node));
            }
        }
    }

    /**
     * @param array<string, mixed> $parent
     *
     * @return array<int, array<string, mixed>>
     */
    private static function listAt(array $parent, string $key): array
    {
        $list = $parent[$key] ?? null;
        if (!\is_array($list) || !\array_is_list($list)) {
            return [];
        }

        return \array_filter($list, static fn (mixed $x): bool => \is_array($x));
    }

    /**
     * @param array<string, mixed> $el
     *
     * @return array{id: mixed, kind: string, pointer: string, code: string, key: string}
     */
    private static function el(array $el, string $kind, string $pointer, string $key): array
    {
        return ['id' => $el['id'] ?? null, 'kind' => $kind, 'pointer' => $pointer, 'code' => self::code($el), 'key' => $key];
    }

    /**
     * @param array<string, mixed> $el
     */
    private static function code(array $el): string
    {
        return \is_string($el['code'] ?? null) ? $el['code'] : '';
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function set(array &$def, string $pointer, string $id): void
    {
        $ref = &$def;
        foreach (\explode('/', \ltrim($pointer, '/')) as $seg) {
            $ref = &$ref[\ctype_digit($seg) ? (int) $seg : $seg];
        }
        $ref['id'] = $id;
    }
}
