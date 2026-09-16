<?php

/**
 * Benchmark Standalone Controller — Razy FrankenPHP Worker Mode.
 *
 * All 6 benchmark endpoints as controller methods for maximum performance.
 * No file I/O at route dispatch time (no separate closure files).
 *
 * SYMMETRY REWRITE (COMPETITOR-LANDSCAPE.md §5, absorbing RAZY-ANALYSIS-REPORT
 * §6.2): the 2026-02 original measured string concatenation where Laravel ran
 * Blade, and raw PDO where Laravel ran Eloquent — the audit called the "5x"
 * headline an exaggeration and was right. Everything measured now goes through
 * Razy's own production path: loadTemplate()/Source (vs Blade), Database +
 * Statement query builder (vs Eloquent/Query Builder). Worker persistence is
 * kept on both sides (Octane workers persist connections too).
 */

namespace Razy\Module\app;

use Razy\Agent;
use Razy\Controller;
use Razy\Database;

return new class() extends Controller {
    /** @var Database|null Persistent Razy Database handle (reused across worker requests) */
    private static ?Database $db = null;

    public function __onInit(Agent $agent): bool
    {
        $agent->addRoute('GET /benchmark/static', 'staticRoute');
        $agent->addRoute('GET /benchmark/template', 'templateRender');
        $agent->addRoute('GET /benchmark/db-read', 'dbRead');
        $agent->addRoute('POST /benchmark/db-write', 'dbWrite');
        $agent->addRoute('GET /benchmark/composite', 'composite');
        $agent->addRoute('GET /benchmark/heavy', 'heavyCpu');

        return true;
    }

    // ── Scenario 1: Static route ────────────────────────────
    public function staticRoute(): void
    {
        \header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
    }

    // ── Scenario 2: Template render (10 variables) ──────────
    // Razy Template engine (vs Laravel Blade) — the symmetry fix for caveat 1.
    public function templateRender(): void
    {
        $vars = [];
        for ($i = 1; $i <= 10; $i++) {
            $vars["var_{$i}"] = "value_{$i}_" . \str_repeat('x', 50);
        }

        \header('Content-Type: text/html; charset=utf-8');

        $source = $this->loadTemplate('benchmark/vars');
        $source->assign($vars);
        echo $source->output();
    }

    // ── Scenario 3: DB read — single-row SELECT ─────────────
    // Razy Database + Statement builder vs Laravel's Query Builder (DB::table)
    // — same layer both sides; neither stack runs its ORM model layer here.
    public function dbRead(): void
    {
        \header('Content-Type: application/json; charset=utf-8');

        $id = (int) ($_GET['id'] ?? 1);

        try {
            $db = self::getDb();
            $row = $db->prepare()
                ->select('id,title,body,created_at')
                ->from('benchmark_posts')
                ->where('id=:id')
                ->assign(['id' => $id])
                ->limit(1)
                ->lazy();

            if (!$row) {
                \http_response_code(404);
                echo \json_encode(['error' => 'not found']);

                return;
            }

            echo \json_encode($row);
        } catch (\Throwable $e) {
            \http_response_code(500);
            echo \json_encode(['error' => $e->getMessage()]);
        }
    }

    // ── Scenario 4: DB write — single INSERT ────────────────
    public function dbWrite(): void
    {
        \header('Content-Type: application/json; charset=utf-8');

        $input = \json_decode(\file_get_contents('php://input'), true) ?: [];
        $message = $input['message'] ?? 'benchmark-' . \time();
        $level = $input['level'] ?? 'info';

        try {
            $db = self::getDb();
            $db->execute($db->insert('benchmark_logs', ['message', 'level', 'created_at'])
                ->assign([
                    'message' => $message,
                    'level' => $level,
                    'created_at' => \date('Y-m-d H:i:s'),
                ]));

            \http_response_code(201);
            echo \json_encode(['id' => $db->lastID(), 'message' => $message]);
        } catch (\Throwable $e) {
            \http_response_code(500);
            echo \json_encode(['error' => $e->getMessage()]);
        }
    }

    // ── Scenario 5: Composite — DB + template ───────────────
    public function composite(): void
    {
        \header('Content-Type: text/html; charset=utf-8');

        $id = (int) ($_GET['id'] ?? 1);

        try {
            $db = self::getDb();
            $row = $db->prepare()
                ->select('id,title,body,created_at')
                ->from('benchmark_posts')
                ->where('id=:id')
                ->assign(['id' => $id])
                ->limit(1)
                ->lazy();

            if (!$row) {
                $row = ['id' => 0, 'title' => 'Not Found', 'body' => '', 'created_at' => ''];
            }

            $source = $this->loadTemplate('benchmark/post');
            $source->assign($row);
            echo $source->output();
        } catch (\Throwable $e) {
            \http_response_code(500);
            echo '<!DOCTYPE html><html><body><p>Error: ' . \htmlspecialchars($e->getMessage()) . '</p></body></html>';
        }
    }

    // ── Scenario 6: Heavy CPU — hash computation ────────────
    public function heavyCpu(): void
    {
        \header('Content-Type: application/json; charset=utf-8');

        $iterations = (int) ($_GET['iterations'] ?? 500000);
        $iterations = \min($iterations, 5000000);

        $start = \hrtime(true);
        $hash = 'seed';
        for ($i = 0; $i < $iterations; $i++) {
            $hash = \md5($hash);
        }
        $elapsed = (\hrtime(true) - $start) / 1e6;

        echo \json_encode([
            'iterations' => $iterations,
            'elapsed_ms' => \round($elapsed, 2),
            'hash' => \substr($hash, 0, 8),
        ]);
    }

    // ── Persistent Database handle ──────────────────────────
    private static function getDb(): Database
    {
        if (self::$db === null) {
            $db = new Database('benchmark');
            $db->connectWithDriver('mysql', [
                'host' => \getenv('BENCH_DB_HOST') ?: 'mysql',
                'port' => (int) (\getenv('BENCH_DB_PORT') ?: 3306),
                'database' => 'benchmark',
                'username' => \getenv('BENCH_DB_USER') ?: 'benchmark',
                'password' => \getenv('BENCH_DB_PASS') ?: 'benchmark',
                'persistent' => true,
            ]);
            self::$db = $db;
        }

        return self::$db;
    }
};
