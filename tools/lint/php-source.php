<?php

/**
 * Token-level readers shared by tools/lint-module-discipline.php and
 * tools/lint-style.php, so a comment, a string literal or a line break never
 * changes what counts as live code.
 */
declare(strict_types=1);

/**
 * The source with every comment blanked to the same number of newlines, so
 * byte offsets map to the same line numbers as the original.
 */
function lintStripComments(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * 1-based lines where an RZ-019 pattern matches, scanning the comment-free
 * source as a whole so a call split over several lines is still seen; the
 * line is where the match starts.
 *
 * @param list<string> $patterns tools/lint/rz019.php
 *
 * @return list<int>
 */
function lintRz019Lines(string $source, array $patterns): array
{
    $code = lintStripComments($source);
    $lines = [];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $offset]) {
                $lines[substr_count($code, "\n", 0, $offset) + 1] = true;
            }
        }
    }
    $lines = array_keys($lines);
    sort($lines);

    return $lines;
}

/**
 * Next token index that is not whitespace or a comment, or null.
 *
 * @param array<int, mixed> $tokens
 */
function lintNext(array $tokens, int $i, int $step = 1): ?int
{
    for ($j = $i + $step; $j >= 0 && $j < count($tokens); $j += $step) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $j;
    }

    return null;
}

/**
 * The value of a quoted literal token, or null.
 */
function lintLiteral(mixed $token): ?string
{
    if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    return stripcslashes(substr($token[1], 1, -1));
}

/**
 * Published names in live code: `->addAPICommand('name', ...)`,
 * `->addAPICommand(['name' => ..., ...])`, the same for addBridgeCommand, and
 * `->trigger('event')`. A leading '#' (also bound internally) is dropped.
 * Names inside comments or string literals are not calls and do not count.
 *
 * @return list<array{0: int, 1: string, 2: string, 3: string}> [line, kind, name, literal as written]
 */
function lintRegistrations(string $source): array
{
    $kinds = ['addAPICommand' => 'API command', 'addBridgeCommand' => 'bridge command', 'trigger' => 'event'];
    $tokens = token_get_all($source);
    $found = [];
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || !isset($kinds[$t[1]])) {
            continue;
        }
        $prev = lintNext($tokens, $i, -1);
        $open = lintNext($tokens, $i);
        if ($prev === null || !is_array($tokens[$prev]) || !in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            || $open === null || $tokens[$open] !== '(') {
            continue;
        }
        $arg = lintNext($tokens, $open);
        $name = $arg === null ? null : lintLiteral($tokens[$arg]);
        if ($name !== null) {
            $found[] = [$tokens[$arg][2], $kinds[$t[1]], ltrim($name, '#'), $name];
        } elseif ($arg !== null && $tokens[$arg] === '[' && $t[1] !== 'trigger') {
            array_push($found, ...lintBatchKeys($tokens, $arg, $kinds[$t[1]]));
        }
    }

    return $found;
}

/**
 * Keys of the batch array that starts at $open: `'name' => ...` at depth 1.
 *
 * @param array<int, mixed> $tokens
 *
 * @return list<array{0: int, 1: string, 2: string, 3: string}>
 */
function lintBatchKeys(array $tokens, int $open, string $kind): array
{
    $found = [];
    $depth = 0;
    for ($j = $open; $j < count($tokens); $j++) {
        $t = $tokens[$j];
        if ($t === '[' || $t === '(') {
            $depth++;
        } elseif ($t === ']' || $t === ')') {
            if (--$depth === 0) {
                break;
            }
        } elseif ($depth === 1 && ($key = lintLiteral($t)) !== null) {
            $arrow = lintNext($tokens, $j);
            if ($arrow !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
                $found[] = [$t[2], $kind, ltrim($key, '#'), $key];
            }
        }
    }

    return $found;
}

/**
 * The live `'owner' => '...'` value of a manifest (trimmed), or null when
 * there is none outside comments.
 */
function lintOwner(string $source): ?string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $i => $t) {
        if (lintLiteral($t) !== 'owner') {
            continue;
        }
        $arrow = lintNext($tokens, $i);
        $value = $arrow === null ? null : lintNext($tokens, $arrow);
        if ($value !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
            $owner = lintLiteral($tokens[$value]);

            return $owner === null ? null : trim($owner);
        }
    }

    return null;
}

/**
 * True when the file has a live `declare(strict_types=1)`.
 */
function lintHasStrictTypes(string $source): bool
{
    $tokens = token_get_all($source);
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || $t[0] !== T_DECLARE) {
            continue;
        }
        $seq = [];
        for ($j = lintNext($tokens, $i); $j !== null && count($seq) < 4; $j = lintNext($tokens, $j)) {
            $seq[] = is_array($tokens[$j]) ? strtolower($tokens[$j][1]) : $tokens[$j];
        }
        if ($seq === ['(', 'strict_types', '=', '1']) {
            return true;
        }
    }

    return false;
}
