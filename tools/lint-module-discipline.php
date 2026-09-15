#!/usr/bin/env php
<?php

/**
 * lint-module-discipline.php — Razy Golden Rules static checker.
 *
 * Machine-enforces the architecture rules defined in skills/RAZY-AI-RULES.md
 * (rule IDs RZ-xxx are shared between the two). Designed for CI and for AI-agent
 * self-verification: exits non-zero on violations and can emit machine-readable JSON.
 *
 * Usage:
 *   php tools/lint-module-discipline.php <path> [...paths] [options]
 *
 * Options:
 *   --format=table|json   Output format (default: table)
 *   --strict              Treat warnings as errors (exit 1)
 *   --exclude=a,b,c       Additional path-substring excludes (merged with defaults)
 *   --self-test           Run built-in fixtures through the matchers and exit
 *   --help                Show usage
 *
 * Suppression:
 *   // lint-allow: RZ-003        on the same or the immediately-preceding line
 *   // lint-disable-file         anywhere in the first 3 lines (needs justification)
 *
 * Scope notes:
 *   - Intended targets: project module dirs (sites/<dist>/vendor/module,
 *     shared/module, demos). Framework-internal layers (src/system, src/library)
 *     have legitimate exceptions (CLI process management etc.) — scan them with
 *     --exclude=src/system or keep this tool pointed at module trees.
 *   - Generated trees (autoload/, vendor/) are excluded by default.
 *
 * Exit codes: 0 = clean, 1 = violations (errors, or any issue with --strict),
 *             2 = usage/IO error.
 */
declare(strict_types=1);

const DEFAULT_EXCLUDES = [
    'vendor/', 'autoload/', '.git/', 'node_modules/', '.venv/', 'storage/',
    'Razy.wiki', 'memory/', 'docs/', 'documentation/', 'coverage/',
    '.phpunit.cache/', 'demo_backup', 'test-razy-cli/', 'sites/nonexistent',
];

/**
 * Rule definitions.
 * fields: id, level (error|warn), scope (php|tpl|any|php-special), desc, regex (array)
 * 'any' = applies to php AND tpl files (catches PHP teaching snippets embedded in .tpl).
 */
const RULES = [
    ['id' => 'RZ-001', 'level' => 'error', 'scope' => 'php', 'desc' => 'Cross-module file access via require/include',
     'regex' => ['#\b(?:require|include|require_once|include_once)\s*\(?\s*[\'"][^\'"]*(?:\.\.[/\\\\]|sites[/\\\\]|shared[/\\\\]module|vendor[/\\\\]module)#i',
                 '#\b(?:file_get_contents|file_put_contents)\s*\([^;]*[\'"][^\'"]*(?:sites[/\\\\]|shared[/\\\\]module)#']],
    ['id' => 'RZ-002', 'level' => 'error', 'scope' => 'php-special', 'desc' => 'addBridgeCommand without __onBridgeCall gate in module'],
    ['id' => 'RZ-003', 'level' => 'error', 'scope' => 'php', 'desc' => 'SQL discipline violation (raw SQL / PDO bypass / superglobals)',
     'regex' => ['#\bnew\s+[\\\\]?PDO\s*\(#i',
                 '#->prepare\s*\(\s*[\'"]#',
                 '#getSearchTextSyntax\s*\([^)]*\$#',
                 '#->(?:query|execute)\s*\(\s*[\'"]\s*(?:SELECT|INSERT|UPDATE|DELETE)#i',
                 '#\b(?:->getDB\(\))->prepare\s*\(\s*[\'"]#i']],
    ['id' => 'RZ-003', 'level' => 'warn', 'scope' => 'php', 'desc' => 'Input superglobal read — cast/validate immediately; route args preferred via getRoutedInfo(); never interpolate into SQL',
     'regex' => ['#\$_(?:GET|POST|REQUEST|COOKIE)\b#']],
    ['id' => 'RZ-004', 'level' => 'warn', 'scope' => 'tpl', 'desc' => 'Template variable output without modifier — engine does NOT auto-escape (review data provenance)',
     'regex' => ['#{\$[A-Za-z_][\w.]*\}#']],
    ['id' => 'RZ-004', 'level' => 'warn', 'scope' => 'tpl', 'desc' => 'Legacy modifier pipe — the engine treats | as a FALLBACK chain, so the piped modifier is a silent no-op (rewrite as ->modifier)',
     'regex' => ['#{\$[A-Za-z_][\w.]*\|(?!\$|\x27|")[a-z][\w.]*\}#']],
    ['id' => 'RZ-003', 'level' => 'error', 'scope' => 'any', 'desc' => 'SQL discipline violation shown in template/comment context (teaching bad code)',
     'regex' => ['#\bnew\s+[\\\\]?PDO\s*\(#i',
                 '#->prepare\s*\(\s*[\'"](?:SELECT|INSERT|UPDATE|DELETE)#i']],
    ['id' => 'RZ-011', 'level' => 'error', 'scope' => 'any', 'desc' => 'Dynamic-code execution shown in template context (teaching bad code)',
     'regex' => ['#\beval\s*\(\s*[^)]*\$#',
                 '#\bspawnPHPCode\s*\([^)]*\$#']],
    ['id' => 'RZ-005', 'level' => 'error', 'scope' => 'php', 'desc' => 'DI fence climb — resolving blocked framework internals',
     'regex' => ['#[\w:]*>\s*(?:make|resolve|get|has)\s*\(\s*[\'"]/?[\\\\/]*Razy[\\\\/]+(?:Application|Container|Standalone|Domain|Distributor|Module|Controller|PluginManager|ModuleRegistry|ModuleScanner|RouteDispatcher)\b#']],
    ['id' => 'RZ-006', 'level' => 'error', 'scope' => 'php', 'desc' => 'File op outside module sandbox (absolute/relative-escape path)',
     'regex' => ['#\b(?:file_put_contents|fopen|fwrite|unlink|rename|mkdir|rmdir|touch|copy)\s*\(\s*[^,)]*[\'"](?:[A-Za-z]:[/\\\\]|/[^\'"]{2,}|[^\'"]*\.\.[/\\\\])#']],
    ['id' => 'RZ-007', 'level' => 'error', 'scope' => 'php', 'desc' => 'Hand-mutating generated dependency trees',
     'regex' => ['#\b(?:file_put_contents|fwrite|unlink|rename|copy)\s*\([^;]*(?:lock\.json|autoload[/\\\\])#']],
    ['id' => 'RZ-008', 'level' => 'error', 'scope' => 'php', 'desc' => 'Cross-module shared state (global / static writes into framework classes)',
     'regex' => ['#\bglobal\s+\$#',
                 '#[\\\\A-Za-z][A-Za-z0-9_\\\\]*::\$\w+\s*=[^=]#']],
    ['id' => 'RZ-011', 'level' => 'error', 'scope' => 'php', 'desc' => 'Dynamic code / shell execution',
     'regex' => ['#\beval\s*\(#',
                 '#(?<![:\w>$])(?<!->)(?<!::)\b(?:exec|shell_exec|passthru|proc_open|popen|create_function)\s*\(#',
                 '#`[^`\n]*`\s*;$#',
                 '#\bspawnPHPCode\s*\([^)]*\$#']],
    ['id' => 'RZ-013', 'level' => 'error', 'scope' => 'php', 'desc' => 'Hand-writing generated rewrite/server config',
     'regex' => ['#\b(?:file_put_contents|fwrite)\s*\([^;]*(?:\.htaccess|Caddyfile)#']],
    ['id' => 'RZ-016', 'level' => 'error', 'scope' => 'php', 'desc' => 'Migration execution in module code — migrations run at the deploy door (`php Razy.phar migrate`) only, never from web-triggered module paths (dossier MODULE-LIFECYCLE.md; needs a written lint-allow justification for the CLI-command exemption)',
     'regex' => ['#\bgetMigrationManager\s*\(\s*\)#']],
    // RZ-009/010/012/014 are semantic (lifecycle usage, contracts, tests) — not
    // statically detectable with confidence; enforced by review + composer quality.
    // RZ-017 (cross-module namespace import) is structural, not per-line regex —
    // implemented as the two-pass scan below (module manifests, then import check).
];

function usage(int $code = 2): never
{
    fwrite(STDERR, "Usage: php lint-module-discipline.php <path> [...] [--format=table|json] [--strict] [--exclude=a,b] [--self-test]\n");
    exit($code);
}

// ── CLI parse ────────────────────────────────────────────────────────────
$paths = [];
$format = 'table';
$strict = false;
$selfTest = false;
$excludes = DEFAULT_EXCLUDES;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') usage(0);
    elseif ($arg === '--self-test') $selfTest = true;
    elseif ($arg === '--strict') $strict = true;
    elseif (str_starts_with($arg, '--format=')) $format = substr($arg, 9);
    elseif (str_starts_with($arg, '--exclude=')) $excludes = array_merge($excludes, explode(',', substr($arg, 10)));
    elseif (str_starts_with($arg, '--')) usage(2);
    else $paths[] = rtrim($arg, '/\\');
}

if ($selfTest) exit(selfTest());
if ($paths === []) usage(2);
if (!in_array($format, ['table', 'json'], true)) usage(2);

// ── Scan ─────────────────────────────────────────────────────────────────
$violations = [];
$lineSuppressions = 0;
$filesDisabled = 0;
$scanned = 0;

// RZ-017 pre-pass: module manifests → namespace map (vendor\module → root).
// Namespaces are not declared in package.php; the convention (and the shape
// ERP proved dangerous) is namespace == module_code with '\' separators.
$nsMap = [];
foreach ($paths as $root) {
    foreach (discoverModuleManifests($root, $excludes) as [$code, $rootDir]) {
        $ns = strtolower(str_replace('/', '\\', $code));
        if (!isset($nsMap[$ns])) {
            $nsMap[$ns] = $rootDir;
        }
    }
}

foreach ($paths as $root) {
    if (!file_exists($root)) {
        fwrite(STDERR, "error: path not found: {$root}\n");
        exit(2);
    }
    $it = is_file($root)
        ? new ArrayIterator([new SplFileInfo($root)])
        : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $spl) {
        /** @var SplFileInfo $spl */
        if (!$spl->isFile()) continue;
        $file = str_replace('\\', '/', $spl->getPathname());
        foreach ($excludes as $ex) {
            if ($ex !== '' && str_contains($file, $ex)) continue 2;
        }
        $ext = strtolower($spl->getExtension());
        $scope = match ($ext) {
            'php' => 'php',
            'tpl' => 'tpl',
            'html', 'htm' => (bool) preg_match('#/(templates?|views?)/#', $file) ? 'tpl' : null,
            default => null,
        };
        if ($scope === null) continue;
        if ($spl->getSize() > 4_000_000) continue;

        $lines = @file($file);
        if ($lines === false) continue;
        $scanned++;

        // File-level disable
        $head = implode('', array_slice($lines, 0, 3));
        if (str_contains($head, 'lint-disable-file')) { $filesDisabled++; continue; }

        // RZ-002 special heuristic: bridge registered without gate anywhere in the module
        $body = implode('', $lines);
        if ($scope === 'php' && str_contains($body, 'addBridgeCommand') && !str_contains($body, '__onBridgeCall')) {
            $moduleRoot = findModuleRoot($file);
            if ($moduleRoot !== null && !moduleDefinesBridgeGate($moduleRoot)) {
                foreach ($lines as $i => $line) {
                    if (str_contains($line, 'addBridgeCommand')) {
                        if (isSuppressed($lines, $i, 'RZ-002')) { $lineSuppressions++; continue; }
                        $violations[] = violation($file, $i + 1, 'RZ-002', 'error', RULES[1]['desc']);
                    }
                }
            }
        }

        // RZ-017 (structural): cross-module namespace import — `use`/FQCN
        // references resolving to a SIBLING module's namespace are the
        // RZ-001 blind spot the ERP audit measured at 74 live hits. Sanctioned
        // surfaces are addAPICommand + events, never class-level coupling.
        if ($scope === 'php' && $nsMap !== []) {
            $owner = findModuleRoot($file);
            foreach (rz017Scan($lines, $nsMap, $owner) as [$lineNo, $hitNs, $targetDir, $ownerLabel]) {
                if (isSuppressed($lines, $lineNo - 1, 'RZ-017')) { $lineSuppressions++; continue; }
                $violations[] = violation($file, $lineNo, 'RZ-017', 'error',
                    'Cross-module namespace import (\'' . $hitNs . '\' belongs to ' . $targetDir . ', file is in ' . $ownerLabel . ') — use addAPICommand or events (RZ-001 remedy)');
            }
        }

        // Regex rules ('any' rules apply to both php and tpl scopes)
        foreach (RULES as $rule) {
            if ($rule['scope'] !== $scope && $rule['scope'] !== 'any') continue;
            foreach ($rule['regex'] as $re) {
                foreach ($lines as $i => $line) {
                    if (!@preg_match($re, $line)) continue;
                    if (isSuppressed($lines, $i, $rule['id'])) { $lineSuppressions++; continue; }
                    $violations[] = violation($file, $i + 1, $rule['id'], $rule['level'], $rule['desc'] . ' — ' . trim(mb_substr(strip_tags($line), 0, 120)));
                }
            }
        }
    }
}

// ── Output ───────────────────────────────────────────────────────────────
$errors = count(array_filter($violations, fn($v) => $v['level'] === 'error'));
$warns = count($violations) - $errors;
usort($violations, fn($a, $b) => [$a['file'], $a['line'], $a['rule']] <=> [$b['file'], $b['line'], $b['rule']]);

// Dedupe: php+tpl 'any'-scoped rules can double-report the same file:line:rule
$seen = [];
$violations = array_values(array_filter($violations, function ($v) use (&$seen) {
    $key = $v['file'] . ':' . $v['line'] . ':' . $v['rule'];
    if (isset($seen[$key])) {
        return false;
    }
    $seen[$key] = true;

    return true;
}));
$errors = count(array_filter($violations, fn($v) => $v['level'] === 'error'));
$warns = count($violations) - $errors;

if ($format === 'json') {
    echo json_encode([
        'violations' => $violations,
        'summary' => [
            'files_scanned' => $scanned,
            'files_disabled' => $filesDisabled,
            'line_suppressions' => $lineSuppressions,
            'errors' => $errors,
            'warnings' => $warns,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
} else {
    foreach ($violations as $v) {
        printf("%s:%d  %s  %s  %s\n", $v['file'], $v['line'], strtoupper($v['level']), $v['rule'], $v['message']);
    }
    printf("\n%s: %d file(s) scanned, %d error(s), %d warning(s), %d line suppression(s), %d file(s) via lint-disable-file%s\n",
        ($errors > 0 || ($strict && $warns > 0)) ? 'FAILED' : 'PASSED',
        $scanned, $errors, $warns, $lineSuppressions, $filesDisabled,
        $warns > 0 ? ' (RZ-004 warnings: review template data provenance per rule pack)' : '');
}

exit($errors > 0 || ($strict && $warns > 0) ? 1 : 0);

// ── Helpers ──────────────────────────────────────────────────────────────

function violation(string $file, int $line, string $rule, string $level, string $message): array
{
    return ['file' => $file, 'line' => $line, 'rule' => $rule, 'level' => $level, 'message' => $message];
}

/**
 * Line-level suppression check: `// lint-allow: RZ-xxx` on the same or previous line.
 */
function isSuppressed(array $lines, int $index, string $ruleId): bool
{
    $current = $lines[$index] ?? '';
    $prev = $index > 0 ? $lines[$index - 1] : '';
    $needle = 'lint-allow: ' . $ruleId;

    return str_contains($current, $needle) || str_contains($prev, $needle);
}

function findModuleRoot(string $file): ?string
{
    $dir = dirname($file);
    for ($up = 0; $up < 6 && $dir !== '/' && $dir !== '' && $dir !== '.'; $up++) {
        if (is_file($dir . '/module.php')) return $dir;
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

/**
 * RZ-017 matcher: line-level `use`/FQCN references that resolve (via $nsMap,
 * lower-cased 'vendor\module' keys) to a module other than the owning one.
 *
 * @param list<string> $lines
 * @param array<string, string> $nsMap
 *
 * @return list<array{0: int, 1: string, 2: string, 3: string}> [lineNo, ns, targetDir, ownerLabel]
 */
function rz017Scan(array $lines, array $nsMap, ?string $ownerRoot): array
{
    $hits = [];
    $ownerKey = $ownerRoot !== null ? strtolower(str_replace('\\', '/', $ownerRoot)) : '';

    foreach ($lines as $i => $line) {
        $hitNs = null;
        if (preg_match('/^\s*use\s+([A-Za-z_]\w*)[\\\\]([A-Za-z_]\w*)(?=[\\\\]|;)/', $line, $m)) {
            $hitNs = strtolower($m[1] . '\\' . $m[2]);
        } elseif (preg_match('/[\\\\]([A-Za-z_]\w*)[\\\\]([A-Za-z_]\w*)(?=[\\\\]|::)/', $line, $m)) {
            $hitNs = strtolower($m[1] . '\\' . $m[2]);
        }
        if ($hitNs !== null && isset($nsMap[$hitNs])) {
            $targetDir = strtolower(str_replace('\\', '/', $nsMap[$hitNs]));
            if ($ownerKey === '' || $ownerKey !== $targetDir) {
                $hits[] = [$i + 1, $hitNs, $targetDir, $ownerKey === '' ? 'outside any module' : $ownerKey];
            }
        }
    }

    return $hits;
}

/**
 * RZ-017 pre-pass: collect [module_code, root-dir] for every module.php found
 * under $root (excludes honored), so cross-module namespace imports can be
 * resolved against real sibling manifests instead of guesses.
 *
 * @param list<string> $excludes
 *
 * @return list<array{0: string, 1: string}>
 */
function discoverModuleManifests(string $root, array $excludes): array
{
    $found = [];
    if (is_file($root)) {
        $root = dirname($root);
    }

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    } catch (UnexpectedValueException) {
        return $found;
    }

    foreach ($it as $spl) {
        /** @var SplFileInfo $spl */
        if (!$spl->isFile() || $spl->getFilename() !== 'module.php') continue;
        $file = str_replace('\\', '/', $spl->getPathname());
        foreach ($excludes as $ex) {
            if ($ex !== '' && str_contains($file, $ex)) continue 2;
        }
        $content = @file_get_contents($spl->getPathname());
        if ($content !== false && preg_match('/[\'"]module_code[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $found[] = [$m[1], str_replace('\\', '/', $spl->getPath())];
        }
    }

    return $found;
}

function moduleDefinesBridgeGate(string $moduleRoot): bool
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $spl) {
        if ($spl->isFile() && strtolower($spl->getExtension()) === 'php') {
            $content = @file_get_contents($spl->getPathname());
            if ($content !== false && str_contains($content, '__onBridgeCall')) return true;
        }
    }
    return false;
}

function selfTest(): int
{
    $cases = [
        // [scope, code, expected rule id or null for clean]
        ['php', "<?php\nrequire '../auth/default/helpers/x.php';\n", 'RZ-001'],
        ['php', "<?php\n\$c = file_get_contents('/srv/app/sites/mysite/dist.php');\n", 'RZ-001'],
        ['php', "<?php\n\$pdo = new PDO('mysql:host=localhost');\n", 'RZ-003'],
        ['php', "<?php\n\$r = \$pdo->prepare(\"SELECT * FROM t WHERE x='1'\");\n", 'RZ-003'],
        ['php', "<?php\n\$q = \$_GET['q'] ?? '';\n", 'RZ-003'],
        ['php', "<?php\n\$s->getSearchTextSyntax('title', \$userInput);\n", 'RZ-003'],
        ['php', "<?php\n\$svc = \$this->resolve('Razy\\\\Application');\n", 'RZ-005'],
        ['php', "<?php\nglobal \$container;\n", 'RZ-008'],
        ['php', "<?php\neval(base64_decode(\$code));\n", 'RZ-011'],
        ['php', "<?php\nshell_exec('whoami');\n", 'RZ-011'],
        ['php', "<?php\n\$this->thread()->spawnPHPCode('echo ' . \$input);\n", 'RZ-011'],
        ['php', "<?php\nfile_put_contents('/etc/passwd', \$x);\n", 'RZ-006'],
        ['php', "<?php\nfile_put_contents('autoload/lock.json', \$x);\n", 'RZ-007'],
        ['php', "<?php\nfile_put_contents('.htaccess', \$rules);\n", 'RZ-013'],
        ['php', "<?php\n\$mm = \$this->getMigrationManager();\n\$mm->migrate();\n", 'RZ-016'],
        ['php', "<?php\n\$done = \$manager->runMigrations([\$migration]);\n", null], // module-local runner is not the door API
        // Clean code — must NOT violate
        ['php', "<?php\n\$db->prepare()->select('*')->from('posts')->where('title~=:q')->assign(['q' => '%' . \$keyword . '%'])->query();\n", null],
        ['php', "<?php\n\$post = \$this->api('golden/provider')->findUser(1);\n", null],
        ['php', "<?php\n\$e = htmlspecialchars(\$raw, ENT_QUOTES, 'UTF-8');\n", null],
        ['php', "<?php\n\$path = \$this->getDataPath('exports/') . \$safe . '.json';\n", null],
        ['php', "<?php\nrequire __DIR__ . '/helpers/format.php';\n", null], // same-module require is legal
        ['tpl', "<li>{\$comment.body}</li>\n", 'RZ-004'],
        ['tpl', "<li>{\$comment.body->escape}</li>\n", null],
        ['tpl', "<li>{\$static.title->upper}</li>\n", null],
        ['tpl', "<li>{\$name|upper}</li>\n", 'RZ-004'],           // legacy no-op pipe must be visible
        ['tpl', "<li>{\$nick|'anon'}</li>\n", null],               // quoted fallback is legit
        ['tpl', "<!-- lesson -->\n<code> spawnPHPCode(\$userInput) </code>\n", 'RZ-011'],
    ];

    $failed = 0;
    foreach ($cases as [$scope, $code, $expect]) {
        $lines = explode("\n", $code);
        $hit = null;
        foreach (RULES as $rule) {
            if ($rule['scope'] !== $scope && $rule['scope'] !== 'any') continue;
            foreach ($rule['regex'] as $re) {
                foreach ($lines as $i => $line) {
                    if (@preg_match($re, $line)) { $hit = $rule['id']; break 3; }
                }
            }
        }
        $ok = $hit === $expect;
        $failed += $ok ? 0 : 1;
        printf("%s  expect=%-7s got=%-7s  %.60s\n", $ok ? 'ok  ' : 'FAIL', $expect ?? '-', $hit ?? '-', str_replace("\n", ' ', trim($code)));
    }

    // RZ-017 structural fixture: sibling import hits, own-namespace import and
    // framework FQCN stay clean.
    $nsMap = ['golden\\provider' => '/srv/modules/golden/provider/default'];
    $foreign = ["<?php\n", "use golden\\provider\\Helper;\n"];
    $own = ["<?php\n", "use golden\\provider\\Helper;\n"];
    $fqcn = ["<?php\n", "\\golden\\provider\\Thing::go();\n"];
    $framework = ["<?php\n", "\\Razy\\Database::table('x');\n"];

    $structural = [
        ['ok  ', count(rz017Scan($foreign, $nsMap, '/srv/modules/golden/consumer/default')) === 1],
        ['ok  ', rz017Scan($own, $nsMap, '/srv/modules/golden/provider/default') === []],
        ['ok  ', count(rz017Scan($fqcn, $nsMap, '/srv/modules/golden/consumer/default')) === 1],
        ['ok  ', rz017Scan($framework, $nsMap, '/srv/modules/golden/consumer/default') === []],
    ];
    foreach ($structural as [$label, $pass]) {
        $failed += $pass ? 0 : 1;
        printf("%s  expect=RZ-017 got=%s  (structural)\n", $pass ? 'ok  ' : 'FAIL', $pass ? 'as expected' : 'MISSED');
    }
    printf("\n%s: %d/%d fixtures passed\n", $failed === 0 ? 'PASSED' : 'FAILED', count($cases) - $failed, count($cases));
    return $failed === 0 ? 0 : 1;
}
