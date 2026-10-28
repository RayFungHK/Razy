#!/usr/bin/env php
<?php

/**
 * lint-style.php — the framework half of docs/STYLE.md, checked.
 *
 * Rules (docs/STYLE.md section in brackets):
 *   STY-NAME     method / function names are camelCase; magic `__x` names pass [§1]
 *   STY-FILE     a file is at most 400 lines [§7]
 *   STY-CLASS    a class / trait / interface / enum body is at most 400 lines [§7]
 *   STY-FUNC     a named function or method is at most 60 lines [§7]
 *   STY-STRICT   a file declares strict_types=1 [§8]
 *   RZ-019       no value glued into a query-builder syntax string [§3]
 *                (patterns shared with lint-module-discipline: tools/lint/rz019.php)
 *
 * Code that broke a STY rule before the rule existed is listed in the
 * baseline (tools/style-baseline.json): it may stay, a sized entry may not
 * grow, and every new violation fails. Fixing one leaves a stale entry,
 * which is reported so the baseline only shrinks. RZ-019 has no baseline:
 * each legitimate splice carries `lint-allow: RZ-019 <reason>` on the same
 * or the previous line.
 *
 * Usage:
 *   php tools/lint-style.php [paths...] [--format=table|json] [--baseline=FILE]
 *   php tools/lint-style.php --generate-baseline      # rewrite the baseline from today's code
 *
 * Paths default to src/library/Razy. Exit codes: 0 clean, 1 violations, 2 usage error.
 */
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const MAX_FILE = 400;
const MAX_CLASS = 400;
const MAX_FUNC = 60;
const SIZED = ['STY-FILE' => MAX_FILE, 'STY-CLASS' => MAX_CLASS, 'STY-FUNC' => MAX_FUNC];
const STALE_NOTE = 'baseline entry no longer needed — run --generate-baseline to shrink it';

exit(main(array_slice($argv, 1)));

/**
 * @param list<string> $args
 */
function main(array $args): int
{
    $paths = [];
    $format = 'table';
    $baselineFile = ROOT . '/tools/style-baseline.json';
    $generate = false;
    foreach ($args as $arg) {
        if ($arg === '--generate-baseline') {
            $generate = true;
        } elseif (str_starts_with($arg, '--format=')) {
            $format = substr($arg, 9);
        } elseif (str_starts_with($arg, '--baseline=')) {
            $baselineFile = substr($arg, 11);
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Usage: php tools/lint-style.php [paths...] [--format=table|json] [--baseline=FILE] [--generate-baseline]\n");

            return 2;
        } else {
            $paths[] = rtrim($arg, '/');
        }
    }
    $paths = $paths ?: [ROOT . '/src/library/Razy'];

    $findings = [];
    foreach (phpFiles($paths) as $file) {
        array_push($findings, ...scanFile($file));
    }

    if ($generate) {
        file_put_contents($baselineFile, json_encode(buildBaseline($findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        printf("Baseline written: %s (%d entries)\n", relativePath($baselineFile), count(array_filter($findings, static fn ($f) => $f['rule'] !== 'RZ-019')));

        return 0;
    }

    $baseline = is_file($baselineFile) ? (array) json_decode((string) file_get_contents($baselineFile), true) : [];
    [$violations, $stale] = compareBaseline($findings, $baseline, $paths);

    return report($violations, $stale, $format);
}

/**
 * @param list<string> $paths
 *
 * @return list<string>
 */
function phpFiles(array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $spl) {
            if ($spl->isFile() && $spl->getExtension() === 'php') {
                $files[] = $spl->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * Repository-relative path, so baseline keys do not depend on the checkout.
 */
function relativePath(string $file): string
{
    $root = (string) realpath(ROOT);
    $real = (string) realpath($file);

    return str_starts_with($real, $root . '/') ? substr($real, strlen($root) + 1) : $file;
}

/**
 * Every finding in one file: ['rule', 'key', 'file', 'line', 'size' (sized rules), 'message'].
 *
 * @return list<array<string, mixed>>
 */
function scanFile(string $file): array
{
    $source = (string) file_get_contents($file);
    $path = relativePath($file);
    $lines = explode("\n", $source);
    $findings = [];

    $fileLines = substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1);
    if ($fileLines > MAX_FILE) {
        $findings[] = finding('STY-FILE', $path, $path, 1, $fileLines, "file has {$fileLines} lines (cap " . MAX_FILE . ')');
    }
    if (!preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $source)) {
        $findings[] = finding('STY-STRICT', $path, $path, 1, null, 'no declare(strict_types=1)');
    }
    foreach (declarations($source) as [$kind, $name, $line, $size]) {
        $key = $path . '::' . $name;
        if ($kind === 'function') {
            $short = (string) substr((string) strrchr('::' . $name, ':'), 1);
            if (!preg_match('/^(?:__[a-z][A-Za-z0-9]*|[a-z][A-Za-z0-9]*)$/', $short)) {
                $findings[] = finding('STY-NAME', $key, $path, $line, null, "`{$short}` is not camelCase");
            }
            if ($size > MAX_FUNC) {
                $findings[] = finding('STY-FUNC', $key, $path, $line, $size, "`{$name}` has {$size} lines (cap " . MAX_FUNC . ')');
            }
        } elseif ($size > MAX_CLASS) {
            $findings[] = finding('STY-CLASS', $key, $path, $line, $size, "`{$name}` has {$size} lines (cap " . MAX_CLASS . ')');
        }
    }
    $patterns = require __DIR__ . '/lint/rz019.php';
    foreach ($lines as $i => $text) {
        foreach ($patterns as $re) {
            if (preg_match($re, $text) && !lineAllows($lines, $i, 'RZ-019')) {
                $findings[] = finding('RZ-019', $path . ':' . ($i + 1), $path, $i + 1, null, 'value glued into a query-builder syntax string — use :name + assign(), or justify a spliced identifier with `lint-allow: RZ-019 <reason>`');
                break;
            }
        }
    }

    return $findings;
}

/**
 * @return array<string, mixed>
 */
function finding(string $rule, string $key, string $file, int $line, ?int $size, string $message): array
{
    return ['rule' => $rule, 'key' => $key, 'file' => $file, 'line' => $line, 'size' => $size, 'message' => $message];
}

/**
 * `lint-allow: <rule> <reason>` on the line or the one before it; a bare allow does not count.
 *
 * @param list<string> $lines
 */
function lineAllows(array $lines, int $i, string $rule): bool
{
    foreach ([$lines[$i] ?? '', $lines[$i - 1] ?? ''] as $text) {
        if (preg_match('/lint-allow:\s*' . preg_quote($rule, '/') . '\s+\S/', $text)) {
            return true;
        }
    }

    return false;
}

/**
 * Named classes / traits / interfaces / enums and named functions / methods,
 * with their line spans: [kind 'type'|'function', name, start line, lines].
 *
 * @return list<array{0: string, 1: string, 2: int, 3: int}>
 */
function declarations(string $source): array
{
    $tokens = token_get_all($source);
    $found = [];
    $stack = []; // open type bodies: [name, depth at which it closes]
    $depth = 0;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($stack !== [] && end($stack)[1] === $depth) {
                    array_pop($stack);
                }
            }
            continue;
        }
        if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            continue;
        }
        $isType = in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true);
        if (!$isType && $token[0] !== T_FUNCTION) {
            continue;
        }
        $name = nextName($tokens, $i);
        if ($name === null || ($isType && previousSignificant($tokens, $i) === T_DOUBLE_COLON)) {
            continue; // anonymous class / closure / `Foo::class`
        }
        $span = bodySpan($tokens, $i);
        if ($span === null) {
            if (!$isType) {
                // abstract or interface method: no body, so no size, but its name still counts
                $owner = $stack !== [] ? end($stack)[0] . '::' : '';
                $found[] = ['function', $owner . $name, $token[2], 0];
            }
            continue;
        }
        if ($isType) {
            $found[] = ['type', $name, $token[2], $span[1] - $token[2] + 1];
            $stack[] = [$name, $depth];
        } else {
            $owner = $stack !== [] ? end($stack)[0] . '::' : '';
            $found[] = ['function', $owner . $name, $token[2], $span[1] - $token[2] + 1];
        }
    }

    return $found;
}

/**
 * The identifier that names the declaration at $i, or null when it is anonymous.
 *
 * @param array<int, mixed> $tokens
 */
function nextName(array $tokens, int $i): ?string
{
    for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($t === '&') {
            continue; // function &name()
        }

        return is_array($t) && $t[0] === T_STRING ? $t[1] : null;
    }

    return null;
}

/**
 * Token id of the previous non-whitespace token (or a char token as its own value).
 *
 * @param array<int, mixed> $tokens
 */
function previousSignificant(array $tokens, int $i): int|string|null
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_WHITESPACE) {
            continue;
        }

        return is_array($t) ? $t[0] : $t;
    }

    return null;
}

/**
 * [open line, close line] of the declaration's `{ ... }` body, or null when a
 * `;` comes first (no body).
 *
 * @param array<int, mixed> $tokens
 *
 * @return array{0: int, 1: int}|null
 */
function bodySpan(array $tokens, int $i): ?array
{
    $n = count($tokens);
    $line = is_array($tokens[$i]) ? $tokens[$i][2] : 0;
    for ($j = $i + 1; $j < $n && $tokens[$j] !== '{'; $j++) {
        if ($tokens[$j] === ';') {
            return null;
        }
        $line = is_array($tokens[$j]) ? $tokens[$j][2] + substr_count($tokens[$j][1], "\n") : $line;
    }
    $open = $line;
    $depth = 0;
    for (; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t)) {
            $line = $t[2] + substr_count($t[1], "\n");
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
            continue;
        }
        if ($t === '{') {
            $depth++;
        } elseif ($t === '}' && --$depth === 0) {
            return [$open, $line];
        }
    }

    return null;
}

/**
 * Baseline: sized rules keep their current size, the others are listed.
 *
 * @param list<array<string, mixed>> $findings
 *
 * @return array<string, array<string, int|true>>
 */
function buildBaseline(array $findings): array
{
    $baseline = [];
    foreach ($findings as $f) {
        if ($f['rule'] === 'RZ-019') {
            continue; // justified per line, never baselined
        }
        $baseline[$f['rule']][$f['key']] = $f['size'] ?? true;
    }
    ksort($baseline);
    foreach ($baseline as &$entries) {
        ksort($entries);
    }

    return $baseline;
}

/**
 * [violations, stale baseline keys]. A sized finding passes while it is no
 * bigger than its baseline size; stale keys are only checked under the
 * scanned paths.
 *
 * @param list<array<string, mixed>> $findings
 * @param array<string, mixed> $baseline
 * @param list<string> $paths
 *
 * @return array{0: list<array<string, mixed>>, 1: list<string>}
 */
function compareBaseline(array $findings, array $baseline, array $paths): array
{
    $violations = [];
    $seen = [];
    foreach ($findings as $f) {
        $known = $baseline[$f['rule']][$f['key']] ?? null;
        $seen[$f['rule']][$f['key']] = true;
        if ($known === null || (isset(SIZED[$f['rule']]) && $f['size'] > $known)) {
            if ($known !== null) {
                $f['message'] .= " — grew past its baseline of {$known}";
            }
            $violations[] = $f;
        }
    }
    $prefixes = array_map('relativePath', $paths);
    $stale = [];
    foreach ($baseline as $rule => $entries) {
        foreach (array_keys((array) $entries) as $key) {
            $inScope = (bool) array_filter($prefixes, static fn ($p) => str_starts_with((string) $key, $p));
            if ($inScope && !isset($seen[$rule][$key])) {
                $stale[] = $rule . ' ' . $key;
            }
        }
    }

    return [$violations, $stale];
}

/**
 * @param list<array<string, mixed>> $violations
 * @param list<string> $stale
 */
function report(array $violations, array $stale, string $format): int
{
    if ($format === 'json') {
        echo json_encode(['violations' => $violations, 'stale_baseline' => $stale], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    } else {
        foreach ($violations as $v) {
            printf("%s:%d  %s  %s\n", $v['file'], $v['line'], $v['rule'], $v['message']);
        }
        foreach ($stale as $key) {
            printf("note  %s  %s\n", $key, STALE_NOTE);
        }
        printf("\n%s: %d violation(s), %d stale baseline entr%s\n", $violations === [] ? 'PASSED' : 'FAILED', count($violations), count($stale), count($stale) === 1 ? 'y' : 'ies');
    }

    return $violations === [] ? 0 : 1;
}
