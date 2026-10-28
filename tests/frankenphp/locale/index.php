<?php

/**
 * RZ-39 fixture for tools/frankenphp-locale-race.sh (FrankenPHP, threads).
 *
 *   ?mode=watch&s=N  one thread reads LC_CTYPE in a loop for N seconds and
 *                    reports every read that was not UTF-8
 *   ?mode=legacy     the pre-RZ-39 bootstrap line: setlocale() every request
 *   ?mode=fixed      the RZ-39 bootstrap call (Razy\Util\Locale)
 *
 * A request that called setlocale() makes PHP reset the process-wide locale
 * at request end, so with `legacy` the watcher sees LC_CTYPE=C; with `fixed`
 * it must not.
 *
 * @license MIT
 */

declare(strict_types=1);

$mode = (string) ($_GET['mode'] ?? '');
if ('watch' === $mode) {
    $until = \microtime(true) + (float) ($_GET['s'] ?? 3);
    $reads = 0;
    $bad = 0;
    $seen = [];
    while (\microtime(true) < $until) {
        $now = \setlocale(\LC_CTYPE, '0');
        ++$reads;
        if (false === $now || 1 !== \preg_match('/utf-?8/i', $now)) {
            ++$bad;
            $seen[(string) $now] = true;
        }
    }
    \header('Content-Type: application/json');
    // glibc can hand back a half-freed name during the reset: keep it printable.
    echo \json_encode(
        ['reads' => $reads, 'bad' => $bad, 'seen' => \array_map('strval', \array_keys($seen))],
        \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
    );

    return;
}
if ('legacy' === $mode) {
    \setlocale(\LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'UTF-8', '');
    echo 'legacy';

    return;
}
if ('fixed' === $mode) {
    require_once __DIR__ . '/../../../src/library/Razy/Util/Locale.php';
    Razy\Util\Locale::ensureUtf8Ctype(\getenv(Razy\Util\Locale::ENV));
    echo 'fixed';

    return;
}
\http_response_code(400);
echo 'mode?';
