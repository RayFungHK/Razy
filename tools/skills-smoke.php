<?php

/**
 * generate-skills smoke — the receipts-not-✓ guard.
 *
 * generate-skills shipped broken for a whole product cycle with green ✓s
 * (five silent deaths, changelog v1.2.0 §"Pre-tag corrections") because
 * NOTHING ever ran it. This tool runs it end-to-end against a throwaway
 * fixture site and asserts REAL files with REAL content:
 *
 *   1. `build` a scratch site in the temp dir (positional path: never prompts)
 *   2. copy two fixture demo modules in (api_provider: registered commands;
 *      event_receiver: inline-closure listeners)
 *   3. run `php Razy.phar generate-skills` from the site root
 *   4. assert: exit 0, zero per-module ✗ lines, and the generated files
 *      actually carry greet / inline-closure listener lines — the exact
 *      sections the silent deaths hollowed out
 *
 * Exit 0 = every assertion held. Any failure prints the offending receipt.
 *
 * Usage: php tools/skills-smoke.php   (from anywhere; paths are self-locating)
 *
 * @license MIT
 */

// migrate-shape boot not needed: this tool shells out to the phar CLI and
// only touches the filesystem itself. Zero framework adoption.

$repoRoot = \dirname(__DIR__);
$phar = $repoRoot . DIRECTORY_SEPARATOR . 'Razy.phar';

if (!\is_file($phar)) {
    fwrite(STDERR, "FATAL: Razy.phar not found at {$phar}\n");

    exit(1);
}

$scratchBase = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy-skills-smoke';
$scratch = $scratchBase . '-' . \getmypid();

/** last exec output buffer */
function sh(string $cmd, ?string $cwd = null): array
{
    if ($cwd !== null) {
        $prev = \getcwd();
        \chdir($cwd);
    }
    \exec($cmd . ' 2>&1', $out, $exit);
    if (isset($prev)) {
        \chdir($prev);
    }

    return [$exit, \implode("\n", $out)];
}

// Fresh scratch: previous runs (or their corpses) must never fake a pass.
if (\is_dir($scratch)) {
    rrmdir($scratch);
}
\mkdir($scratch . DIRECTORY_SEPARATOR . 'smoke', 0o777, true);

function stripAnsi(string $s): string
{
    return \preg_replace('/\e\[[0-9;]*m/', '', $s);
}

function rrcopy(string $from, string $to): void
{
    if (!\is_dir($to)) {
        \mkdir($to, 0o777, true);
    }
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $src = $from . DIRECTORY_SEPARATOR . $entry;
        $dst = $to . DIRECTORY_SEPARATOR . $entry;
        if (\is_dir($src)) {
            rrcopy($src, $dst);
        } else {
            \copy($src, $dst);
        }
    }
}

function rrmdir(string $dir): void
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $entry;
        if (\is_dir($p) && !\is_link($p)) {
            rrmdir($p);
        } else {
            @\unlink($p);
        }
    }
    @\rmdir($dir);
}

$failures = [];
$receipts = [];

// 1) build the scratch site — the USER-shaped way: phar lives inside the
//    project root (main.php:36-55 resolves SYSTEM_ROOT to the phar's own
//    directory when cwd is not yet a Razy installation; a foreign phar
//    pointed at an empty dir would resolve SYSTEM_ROOT to the phar's home,
//    not the target — by design, not by bug)
$site = $scratch . DIRECTORY_SEPARATOR . 'smoke';
\copy($phar, $site . DIRECTORY_SEPARATOR . 'Razy.phar');
[$exit, $out] = sh('php Razy.phar build .', $site);
if ($exit !== 0) {
    $failures[] = "build exited {$exit}:\n" . substr($out, -800);
}
if (!\is_file($site . DIRECTORY_SEPARATOR . 'sites.inc.php')) {
    $failures[] = 'build produced no sites.inc.php (fixture site unusable)';
}

// 2) fixture modules: one API provider, one closure-listener listener
$fixtures = [
    'io/api_provider',
    'core/event_receiver',
];
foreach ($fixtures as $mod) {
    $src = $repoRoot . DIRECTORY_SEPARATOR . 'demo_modules' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mod);
    $dst = $site . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mod);
    if (!\is_dir($src)) {
        $failures[] = "fixture module missing: {$mod}";

        continue;
    }
    rrcopy($src, $dst);
}

// A distributor IS its dist.php (build only lays the directory skeleton —
// the comment inside the generated sites.inc.php says so). Declare the
// fixtures; without this file scanDistributions skips the dist entirely.
\file_put_contents(
    $site . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'main' . DIRECTORY_SEPARATOR . 'dist.php',
    "<?php return ['dist' => 'main', 'modules' => ['*' => ["
    . "'io/api_provider' => '*', 'core/event_receiver' => '*']]];"
);

// 3) run the command from the site root, exactly like a developer
[$exit, $out] = sh('php Razy.phar generate-skills', $site);
$out = stripAnsi($out);
if ($exit !== 0) {
    $failures[] = "generate-skills exited {$exit}:\n" . substr($out, -1200);
}
if (str_contains($out, 'error:')) {
    $bad = array_filter(explode("\n", $out), fn ($l) => str_contains($l, 'error:'));
    $failures[] = "per-module error lines present in output:\n" . implode("\n", $bad);
}

// 4) receipts on disk — content assertions, not file-count vibes
$checks = [
    'skills/main.md' => null,
    'skills/main/io/api_provider-default.md' => 'greet',
    'skills/main/core/event_receiver-default.md' => 'listens core/event_demo',
];
foreach ($checks as $file => $needle) {
    $path = $site . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!\is_file($path)) {
        $failures[] = "missing generated file: {$file}";

        continue;
    }
    if ($needle !== null && !str_contains((string) file_get_contents($path), $needle)) {
        $failures[] = "{$file} does not carry '{$needle}' — a hollowed section is back";

        continue;
    }
    $receipts[] = sprintf('OK  %-52s %6d bytes%s', $file, filesize($path), $needle !== null ? " (has '{$needle}')" : '');
}

// report
foreach ($receipts as $r) {
    echo $r, "\n";
}
if ($failures !== []) {
    foreach ($failures as $f) {
        fwrite(STDERR, 'FAIL ' . $f . "\n");
    }
    rrmdir($scratch);
    exit(1);
}

echo 'generate-skills smoke: ' . count($receipts) . " receipts, 0 failures\n";
rrmdir($scratch);
exit(0);
