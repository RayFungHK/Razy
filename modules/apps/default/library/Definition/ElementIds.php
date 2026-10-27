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
 *               so a draft that drops ids cannot silently re-create
 *               columns; an id that moved (to another kind, parent or
 *               element) is never kept
 * - collect()   every id with its kind, JSON pointer, code and parent
 * - compare()   with a previous version, a changed, moved or re-kinded id
 *               is an error (merges and migrations follow ids)
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
     * @return list<array{id: mixed, kind: string, pointer: string, code: string, key: string, parent: ?string}>
     *                                                                                                           key = kind-qualified path of codes, e.g. "entity:deal/field:amount"
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
     * An id the draft carries is kept only when it is still the same element:
     * same kind and same parent (by the parent's id), and either the same
     * code or a rename (the old code is gone from the draft). An id that
     * moved to another kind, another parent, or onto another element whose
     * old element is still there (a swap) is dropped and re-assigned, and so
     * is a second use of one id in the draft.
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
        $prev = self::index($previous ?? []);
        $draft = self::collect($def);
        $draftKeys = \array_count_values(\array_column($draft, 'key'));

        // ids that stay where they are (same element as before, or new and
        // unique): never handed out to another element below
        $idAt = [];
        foreach ($draft as $el) {
            $idAt[$el['pointer']] = self::isValid($el['id']) ? $el['id'] : null;
        }
        $reserved = [];
        foreach ($draft as $el) {
            $id = $idAt[$el['pointer']];
            if ($id === null || isset($reserved[$id])) {
                continue;
            }
            $parentId = $el['parent'] !== null ? ($idAt[$el['parent']] ?? null) : null;
            if (!isset($prev['byId'][$id]) || !self::moved($prev['byId'][$id], $el['kind'], $el['key'], $parentId, $draftKeys)) {
                $reserved[$id] = $el['pointer'];
            }
        }

        $final = [];
        $used = [];
        foreach ($draft as $el) {
            $parentId = $el['parent'] !== null ? ($final[$el['parent']] ?? null) : null;
            $id = self::isValid($el['id']) ? $el['id'] : null;
            if ($id !== null && ($reserved[$id] ?? null) !== $el['pointer']) {
                $id = null; // moved, re-kinded, or a second use of one id
            }
            if ($id === null) {
                $known = $prev['byKey'][$el['key']] ?? null;
                if ($known !== null && !isset($used[$known]) && !isset($reserved[$known])
                    && ($prev['parentOf'][$known] ?? null) === $parentId) {
                    $id = $known;
                }
            }
            while ($id === null || isset($used[$id]) || (isset($reserved[$id]) && $reserved[$id] !== $el['pointer'])) {
                $id = $generator();
            }
            $used[$id] = true;
            $final[$el['pointer']] = $id;
            if ($id !== $el['id']) {
                self::set($def, $el['pointer'], $id);
            }
        }

        return $def;
    }

    /**
     * Id stability between two versions; every finding is an error.
     *
     * - id.kind_changed: an id is now a different kind of element
     * - id.moved: an id is now under another parent, or on another element
     *   while its old element is still there (a swap)
     * - id.changed: an element keeps its kind, parent and code but has a new id
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     *
     * @return list<Issue>
     */
    public static function compare(array $previous, array $next): array
    {
        $prev = self::index($previous);
        $els = self::collect($next);
        $idAt = [];
        foreach ($els as $el) {
            $idAt[$el['pointer']] = self::isValid($el['id']) ? $el['id'] : null;
        }
        $nextKeys = \array_count_values(\array_column($els, 'key'));
        $issues = [];
        foreach ($els as $el) {
            if (!self::isValid($el['id'])) {
                continue;
            }
            $parentId = $el['parent'] !== null ? ($idAt[$el['parent']] ?? null) : null;
            $was = $prev['byId'][$el['id']] ?? null;
            if ($was !== null && $was['kind'] !== $el['kind']) {
                $issues[] = Issue::error(
                    $el['pointer'] . '/id',
                    'id.kind_changed',
                    "Element id {$el['id']} was a {$was['kind']} in the previous version, now a {$el['kind']}",
                );
            } elseif ($was !== null && self::moved($was, $el['kind'], $el['key'], $parentId, $nextKeys)) {
                $issues[] = Issue::error(
                    $el['pointer'] . '/id',
                    'id.moved',
                    "Element id {$el['id']} belonged to another element in the previous version",
                );
            }
            $old = $prev['byKey'][$el['key']] ?? null;
            if ($old !== null && $old !== $el['id'] && ($prev['parentOf'][$old] ?? null) === $parentId) {
                $issues[] = Issue::error(
                    $el['pointer'] . '/id',
                    'id.changed',
                    "'{$el['code']}' had the id {$old}; keep it (or retire the element and add a new code)",
                );
            }
        }

        return $issues;
    }

    /**
     * Whether an id from the previous version now sits on a different element.
     *
     * @param array{kind: string, key: string, parentId: ?string} $was
     * @param array<string, int> $nextKeys keys present in the new definition
     */
    private static function moved(array $was, string $kind, string $key, ?string $parentId, array $nextKeys): bool
    {
        if ($was['kind'] !== $kind || $was['parentId'] !== $parentId) {
            return true;
        }

        // a new code is a rename only when the old code is gone
        return $was['key'] !== $key && isset($nextKeys[$was['key']]);
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return array{byId: array<string, array{kind: string, key: string, parentId: ?string}>, byKey: array<string, string>, parentOf: array<string, ?string>}
     */
    private static function index(array $def): array
    {
        $byId = [];
        $byKey = [];
        $parentOf = [];
        $idAt = [];
        foreach (self::collect($def) as $el) {
            $id = self::isValid($el['id']) ? $el['id'] : null;
            $idAt[$el['pointer']] = $id;
            if ($id === null || isset($byId[$id])) {
                continue;
            }
            $parentId = $el['parent'] !== null ? ($idAt[$el['parent']] ?? null) : null;
            $byId[$id] = ['kind' => $el['kind'], 'key' => $el['key'], 'parentId' => $parentId];
            $byKey[$el['key']] ??= $id;
            $parentOf[$id] = $parentId;
        }

        return ['byId' => $byId, 'byKey' => $byKey, 'parentOf' => $parentOf];
    }

    /**
     * Parents come before their children.
     *
     * @param array<string, mixed> $def
     *
     * @return Generator<array{id: mixed, kind: string, pointer: string, code: string, key: string, parent: ?string}>
     */
    private static function walk(array $def): Generator
    {
        foreach (self::listAt($def, 'entities') as $ei => $entity) {
            $ep = "/entities/{$ei}";
            $ekey = 'entity:' . self::code($entity);
            yield self::el($entity, 'entity', $ep, $ekey, null);
            foreach (self::listAt($entity, 'fields') as $fi => $field) {
                yield self::el($field, 'field', "{$ep}/fields/{$fi}", $ekey . '/field:' . self::code($field), $ep);
            }
            $list = \is_array($entity['views'] ?? null) && \is_array($entity['views']['list'] ?? null)
                ? $entity['views']['list'] : [];
            foreach (self::listAt($list, 'saved') as $si => $saved) {
                yield self::el($saved, 'saved_view', "{$ep}/views/list/saved/{$si}", $ekey . '/saved:' . self::code($saved), $ep);
            }
        }
        foreach (self::listAt($def, 'workflows') as $wi => $wf) {
            $wp = "/workflows/{$wi}";
            $wkey = 'workflow:' . self::code($wf);
            yield self::el($wf, 'workflow', $wp, $wkey, null);
            foreach (self::listAt($wf, 'states') as $si => $state) {
                yield self::el($state, 'wf_state', "{$wp}/states/{$si}", $wkey . '/state:' . self::code($state), $wp);
            }
            foreach (self::listAt($wf, 'nodes') as $ni => $node) {
                yield self::el($node, 'wf_node', "{$wp}/nodes/{$ni}", $wkey . '/node:' . self::code($node), $wp);
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
     * @return array{id: mixed, kind: string, pointer: string, code: string, key: string, parent: ?string}
     */
    private static function el(array $el, string $kind, string $pointer, string $key, ?string $parent): array
    {
        return ['id' => $el['id'] ?? null, 'kind' => $kind, 'pointer' => $pointer, 'code' => self::code($el), 'key' => $key, 'parent' => $parent];
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
