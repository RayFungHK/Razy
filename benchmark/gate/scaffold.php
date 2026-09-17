<?php

/**
 * Gate-site scaffold — generates the benchgate measurement modules.
 *
 *   php scaffold.php            # the four gate modules (scenarios 07-09)
 *   php scaffold.php --scale=50 # + 50 plain modules (scenario 10 boot curve)
 *
 * Module anatomy mirrors the live appdemo layout (module.php at the code dir,
 * controller + package.php under the version dir). The gate is declared via
 * Agent::readyRoutes('self') in the registration controller — the module-wide
 * door of MODULE-LIFECYCLE.md L3; `plain-plain` runs the IDENTICAL handler
 * without the gate, the per-request cost control.
 */

$root = __DIR__ . '/site/sites/benchgate/bench';
$scale = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--scale=(\d+)$/', $arg, $m)) {
        $scale = (int) $m[1];
    }
}

/**
 * module.php — code + route prefix.
 */
function modulePhp(string $code): string
{
    return "<?php\n\nreturn [\n    'module_code' => '{$code}',\n    'author' => 'Razy Framework',\n    'description' => 'Readiness-gate measurement module (benchmark epoch 2026-09).',\n];\n";
}

/**
 * package.php — the URL prefix is set HERE ('alias'), not in module.php:
 * ModuleInfo reads $settings['alias'] (the version manifest) and only then
 * falls back to the module name. A 'route' key in module.php is parsed by
 * nobody (learned the 404 way, 2026-09).
 */
function packagePhp(string $alias): string
{
    return "<?php\n\nreturn [\n    'api_name' => '',\n    'alias' => '{$alias}',\n    'require' => [],\n];\n";
}

/**
 * Registration controller — modern contract: an anonymous class extending
 * Controller registers in __onInit (the bare-closure registration the old
 * demo files show is dead: the loader rejects it with a pointed message).
 * Gated modules declare readyRoutes('self') — the module-wide door of
 * MODULE-LIFECYCLE L3.
 */
function registration(string $name, bool $gated): string
{
    $gate = $gated
        ? "        // The module-wide readiness door (MODULE-LIFECYCLE.md L3).\n        \$agent->readyRoutes('self')\n            ->addLazyRoute('ping', 'ping');\n"
        : "        // Ungated control: identical handler, no readiness predicate.\n        \$agent->addLazyRoute('ping', 'ping');\n";

    return "<?php\n\nuse Razy\\Agent;\nuse Razy\\Controller;\n\n/**\n * {$name} — route registration (benchmark gate site).\n */\n\nreturn new class () extends Controller {\n    public function __onInit(Agent \$agent): bool\n    {\n{$gate}        return true;\n    }\n};\n";
}

/**
 * Action file — bound closure. The dist-module lazy handler ANSWERS via
 * echo (a bare return prints nothing here — the standalone controller path
 * is the one that captures return values; proven live 2026-09).
 */
function handler(string $name): string
{
    return "<?php\n\n/**\n * {$name} action — 'ok', byte-identical across the gate suite.\n */\n\nreturn function () {\n    echo 'ok';\n};\n";
}

/**
 * Migration file (MySQL dialect — this site measures on MySQL 8.0).
 */
function migration(string $table, string $desc): string
{
    return "<?php\n\nuse Razy\\Database\\Migration;\nuse Razy\\Database\\SchemaBuilder;\n\nreturn new class extends Migration {\n    public function up(SchemaBuilder \$schema): void\n    {\n        \$schema->raw('CREATE TABLE IF NOT EXISTS {$table} (\n            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            note VARCHAR(191) NOT NULL\n        )');\n    }\n\n    public function down(SchemaBuilder \$schema): void\n    {\n        \$schema->raw('DROP TABLE IF EXISTS {$table}');\n    }\n\n    public function getDescription(): string\n    {\n        return '{$desc}';\n    }\n};\n";
}

function put(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

/**
 * One measurement module: gate on/off, migration dir present/absent.
 */
function moduleSet(string $root, string $name, string $route, bool $gated, bool $withMigration, bool $migrationPending = false): void
{
    $base = "{$root}/{$name}";
    put("{$base}/module.php", modulePhp("bench/{$name}"));
    put("{$base}/default/package.php", packagePhp($route));
    put("{$base}/default/controller/{$name}.php", registration($name, $gated));
    put("{$base}/default/controller/{$name}.ping.php", handler($name));

    if ($withMigration) {
        // The applied migration is always written; the PENDING one is staged
        // OUTSIDE the migration dir — the container entry copies it in AFTER
        // `migrate` ran, which is exactly how a deploy-in-progress looks:
        // declared, not yet applied, gate answers 503.
        $file = '2026_09_17_000000_CreateBenchLog.php';
        $body = migration('bench_log_' . str_replace('-', '_', $name), 'benchmark gate log table');
        if ($migrationPending) {
            put("{$base}/default/_pending/" . $file, $body);
        } else {
            put("{$base}/default/migration/" . $file, $body);
        }
    }
}

// gate-ready: gated, migration declared AND applied at deploy (entry migrates)
moduleSet($root, 'gate-ready', 'gr', gated: true, withMigration: true);
// gate-vacuous: gated, nothing declared — the common (default) case
moduleSet($root, 'gate-vacuous', 'gv', gated: true, withMigration: false);
// gate-refused: gated, migration declared but never applied -> 503
moduleSet($root, 'gate-refused', 'gf', gated: true, withMigration: true, migrationPending: true);
// plain-plain: the ungated control, identical handler
moduleSet($root, 'plain-plain', 'pp', gated: false, withMigration: false);

// ---- scale modules (scenario 10 boot curve) -----------------------------
for ($i = 1; $i <= $scale; $i++) {
    $name = sprintf('m%03d', $i);
    moduleSet($root, $name, 'x' . $i, gated: false, withMigration: false);
}

echo "scaffolded under {$root} (scale={$scale})\n";
