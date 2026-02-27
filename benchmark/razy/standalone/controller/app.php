<?php

/**
 * Benchmark Standalone Controller — Razy FrankenPHP Worker Mode.
 *
 * All 6 benchmark endpoints as controller methods for maximum performance.
 * No file I/O at route dispatch time (no separate closure files).
 */

namespace Razy\Module\app;

use Razy\Agent;
use Razy\Controller;

return new class() extends Controller {
    /** @var \PDO|null Persistent DB connection (reused across worker requests) */
    private static ?\PDO $pdo = null;

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
    public function templateRender(): void
    {
        $vars = [];
        for ($i = 1; $i <= 10; $i++) {
            $vars["var_{$i}"] = "value_{$i}_" . \str_repeat('x', 50);
        }

        \header('Content-Type: text/html; charset=utf-8');

        $html = '<!DOCTYPE html><html><head><title>Benchmark Template</title></head><body>';
        foreach ($vars as $key => $value) {
            $html .= "<p><strong>{$key}</strong>: {$value}</p>\n";
        }
        $html .= '</body></html>';
        echo $html;
    }

    // ── Scenario 3: DB read — single-row SELECT ─────────────
    public function dbRead(): void
    {
        \header('Content-Type: application/json; charset=utf-8');

        $id = (int) ($_GET['id'] ?? 1);

        try {
            $pdo = self::getPdo();
            $stmt = $pdo->prepare('SELECT id, title, body, created_at FROM benchmark_posts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

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
            $pdo = self::getPdo();
            $stmt = $pdo->prepare('INSERT INTO benchmark_logs (message, level, created_at) VALUES (:message, :level, NOW())');
            $stmt->execute(['message' => $message, 'level' => $level]);

            $id = $pdo->lastInsertId();
            \http_response_code(201);
            echo \json_encode(['id' => (int) $id, 'message' => $message]);
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
            $pdo = self::getPdo();
            $stmt = $pdo->prepare('SELECT id, title, body, created_at FROM benchmark_posts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $row = ['id' => 0, 'title' => 'Not Found', 'body' => '', 'created_at' => ''];
            }

            $html = '<!DOCTYPE html><html><head><title>' . \htmlspecialchars($row['title']) . '</title></head><body>';
            $html .= '<article>';
            $html .= '<h1>' . \htmlspecialchars($row['title']) . '</h1>';
            $html .= '<time>' . \htmlspecialchars($row['created_at']) . '</time>';
            $html .= '<div class="body">' . \nl2br(\htmlspecialchars($row['body'])) . '</div>';
            $html .= '</article>';
            $html .= '</body></html>';
            echo $html;
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

    // ── Persistent PDO connection ───────────────────────────
    private static function getPdo(): \PDO
    {
        if (self::$pdo === null) {
            $dsn  = \getenv('BENCH_DB_DSN')  ?: 'mysql:host=127.0.0.1;port=3306;dbname=benchmark';
            $user = \getenv('BENCH_DB_USER') ?: 'benchmark';
            $pass = \getenv('BENCH_DB_PASS') ?: 'benchmark';

            self::$pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_PERSISTENT         => true,
            ]);
        }

        return self::$pdo;
    }
};
