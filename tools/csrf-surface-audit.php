<?php

/**
 * csrf-surface-audit — READ-ONLY classification of a dist's mutating route surface.
 *
 * Companion tool to architecture/ERP-SECURITY-EXPOSURE.md step 1: before any dist
 * arms `'csrf' => 'on'`, someone must NAME every mutating route and decide its
 * fate. This script produces that list — it changes nothing, writes nothing
 * outside its own output file, and runs with ANY Razy phar the site already
 * ships (no adoption implied; the boot it performs is the same read-mostly
 * initialize every web request already does on that box).
 *
 * Usage:
 *   php csrf-surface-audit.php <site-root> <dist-code> [domain='*'] [--out=file.md] [--format=md|csv]
 *
 * Output: one row per route whose method is POST/PUT/PATCH/DELETE or '*'
 * (method-unconstrained = reachable by mutating verbs — flagged MIXED), with a
 * HEOURISTIC class (the script never decides, it sorts candidates):
 *   needs-token        a human-facing route; gets csrfField()/X-CSRF-TOKEN in the client pass
 *   unauth-candidate   login/register/token-shaped — arming covers these too; verify on purpose
 *   exempt-candidate   webhook/callback/signature-shaped — the only legitimate csrfExempt() material
 *   MIXED              method '*' — decide, then constrain or exempt
 * Every class still needs an owner decision; the exemption decision lands as a
 * ->csrfExempt('<reason>') line whose review IS the security review.
 *
 * Honesty bounds: heuristics read names, not semantics; module __onInit runs
 * (same as any request); a DB the dist needs is required (readiness gates);
 * a dist that cannot boot here simply cannot be armed here — the script says so.
 */

declare(strict_types=1);

if ('cli' !== \PHP_SAPI) {
    fwrite(STDERR, "cli only.\n");
    exit(1);
}

[$siteRoot, $distCode, $domain, $outFile, $format] = [null, null, '*', null, 'md'];
$pharOverride = null;
foreach ($argv as $i => $arg) {
    if (0 === $i) {
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $outFile = substr($arg, 6);

        continue;
    }
    if (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);

        continue;
    }
    if (str_starts_with($arg, '--phar=')) {
        // explicit override for non-standard layouts (a site whose config
        // points phar_location at a container path, run from the host);
        // NEVER a silent fallback — a wrong phar_location in production must
        // stay as loud here as at every real boot.
        $pharOverride = substr($arg, 7);

        continue;
    }
    if (null === $siteRoot) {
        $siteRoot = $arg;
    } elseif (null === $distCode) {
        $distCode = $arg;
    } else {
        $domain = $arg;
    }
}

if (null === $siteRoot || null === $distCode) {
    fwrite(STDERR, "usage: php csrf-surface-audit.php <site-root> <dist-code> [domain] [--out=file] [--format=md|csv]\n");
    exit(1);
}

if (!is_dir($siteRoot = (string) realpath($siteRoot))) {
    fwrite(STDERR, "site root not found: {$siteRoot}\n");
    exit(1);
}

// ── boot exactly the way the phar CLI boots (migrate's shape): site cwd, config, phar ──
if (!chdir($siteRoot)) {
    fwrite(STDERR, "cannot cd into site root\n");
    exit(1);
}
define('SYSTEM_ROOT', getcwd());

$configFile = SYSTEM_ROOT . '/config.inc.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "no config.inc.php under {$siteRoot} — that is not a Razy site root\n");
    exit(1);
}
$siteConfig = require $configFile;

$pharPath = $pharOverride ?? (rtrim(($siteConfig['phar_location'] ?? SYSTEM_ROOT), '/\\') . '/Razy.phar');
if (!is_file($pharPath)) {
    $tried = (null === $pharOverride) ? " (set with --phar= if this site's phar lives elsewhere)" : '';
    fwrite(STDERR, "no Razy.phar at {$pharPath}{$tried}\n");
    exit(1);
}
if (!\Phar::loadPhar($pharPath, 'Razy.phar')) {
    fwrite(STDERR, "phar refused to load: {$pharPath}\n");
    exit(1);
}
define('PHAR_PATH', 'phar://Razy.phar');
define('CORE_FOLDER', PHAR_PATH . '/system/');

require CORE_FOLDER . 'bootstrap.inc.php';

// ── the audit itself ───────────────────────────────────────────────────────────
use Razy\Distributor;

try {
    $distributor = new Distributor($distCode, $domain);
    $distributor->initialize();
} catch (Throwable $e) {
    fwrite(STDERR, "dist '{$distCode}' cannot boot on this box: {$e->getMessage()}\n"
        . "(a dist that cannot boot here cannot be armed here — fix boot first; nothing else was touched)\n");
    exit(1);
}

$mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];

// keyword classes — deliberately grell: over-calling exempt-CANDIDATES is safe
// (the owner still decides); under-calling them is what silently breaks a webhook.
$unauthKeys = ['login', 'signin', 'register', 'signup', 'password', 'forgot', 'reset', 'oauth', 'sso', 'token', 'session'];
$machineKeys = ['webhook', 'callback', 'hook', 'notify', 'signature', 'hmac', 'payout', 'payment', 'charge'];

$rows = [];
foreach ($distributor->getRouter()->getRoutes() as $key => $info) {
    $method = strtoupper((string) ($info['method'] ?? '*'));
    // 'route_path' is written on every registration row; never cast 'path'
    // itself — for Route-entity routes it holds an OBJECT.
    $path = (string) ($info['route_path'] ?? $key);
    $moduleCode = is_object($info['module'] ?? null) ? $info['module']->getModuleInfo()->getCode() : (string) ($info['module_code'] ?? ($info['module'] ?? '?'));

    if ('*' === $method) {
        $class = 'MIXED';
    } elseif (!in_array($method, $mutating, true)) {
        continue; // safe methods are not the arming question
    } else {
        $hay = strtolower($path); // name-based heuristics; the owner decides, grell on purpose
        $class = 'needs-token';
        foreach ($machineKeys as $k) {
            if (str_contains($hay, $k)) {
                $class = 'exempt-candidate';

                break;
            }
        }
        if ('needs-token' === $class) {
            foreach ($unauthKeys as $k) {
                if (str_contains($hay, $k)) {
                    $class = 'unauth-candidate';

                    break;
                }
            }
        }
    }

    $rows[] = ['method' => $method, 'path' => $path, 'module' => $moduleCode, 'class' => $class];
}

// hand-roll sweep: any existing csrf-shaped code under THIS dist (plus the
// shared/ tree platform modules live in) is a migration candidate to the door
// (and proof someone already worried about this).
$handRolls = [];
foreach ([SITES_FOLDER . '/' . $distCode, SYSTEM_ROOT . '/shared'] as $root) {
    if (!is_dir($root)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || 'php' !== strtolower($f->getExtension())) {
            continue;
        }
        $body = (string) @file_get_contents($f->getPathname());
        if ('' !== $body && preg_match('/csrf/i', $body)) {
            $handRolls[] = str_replace(SYSTEM_ROOT . DIRECTORY_SEPARATOR, '', $f->getPathname());
        }
    }
}

// ── report ────────────────────────────────────────────────────────────────────
$counts = [];
foreach ($rows as $r) {
    $counts[$r['class']] = ($counts[$r['class']] ?? 0) + 1;
}

$header = "csrf-surface-audit — dist '{$distCode}'@'{$domain}'  (Razy " . RAZY_VERSION . ' phar at ' . $pharPath . ")\n"
    . 'mutating surface: ' . count($rows) . ' routes  |  ' . implode('  |  ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts)) . "\n"
    . 'readout only — every row is a candidate class, the decision is the owner\'s; exemptions land as ->csrfExempt(\'<reason>\') whose PR review IS the security review';

if ('csv' === $format) {
    $lines = [$header, 'method,path,module,class'];
    foreach ($rows as $r) {
        $lines[] = implode(',', [$r['method'], $r['path'], $r['module'], $r['class']]);
    }
} else {
    $lines = [$header, '', '| method | path | module | class |', '|---|---|---|---|'];
    foreach ($rows as $r) {
        $lines[] = "| {$r['method']} | `{$r['path']}` | {$r['module']} | {$r['class']} |";
    }
    if ($handRolls) {
        $lines[] = '';
        $lines[] = 'existing csrf-shaped code found (hand-rolls to migrate to the door, or false friends):';
        foreach ($handRolls as $h) {
            $lines[] = "- {$h}";
        }
    }
}

$report = implode("\n", $lines) . "\n";

if (null !== $outFile) {
    file_put_contents($outFile, $report);
    echo "written: {$outFile} (" . count($rows) . " mutating routes)\n";
} else {
    echo $report;
}
exit(0); // a readout never fails the caller; emptiness is a result too
