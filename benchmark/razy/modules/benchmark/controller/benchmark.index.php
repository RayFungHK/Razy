<?php

/**
 * Benchmark Controller Actions — Razy Framework.
 *
 * Implements the 6 benchmark scenario handlers.
 *
 * @license MIT
 */

namespace Benchmark;

use Razy\Controller;

/**
 * Scenario 1: Static route — Pure framework overhead.
 */
function staticRoute(): string
{
    \header('Content-Type: text/plain; charset=utf-8');

    return 'ok';
}

/**
 * Scenario 2: Template render — 10 variables into a simple HTML template.
 */
function templateRender(): string
{
    $vars = [];
    for ($i = 1; $i <= 10; $i++) {
        $vars["var_{$i}"] = "value_{$i}_" . \str_repeat('x', 50);
    }

    \header('Content-Type: text/html; charset=utf-8');

    // Simple inline template rendering (no file I/O)
    $html = '<!DOCTYPE html><html><head><title>Benchmark Template</title></head><body>';
    foreach ($vars as $key => $value) {
        $html .= "<p><strong>{$key}</strong>: {$value}</p>\n";
    }
    $html .= '</body></html>';

    return $html;
}

/**
 * Scenario 3: DB read — Single-row SELECT by primary key.
 */
function dbRead(): string
{
    \header('Content-Type: application/json; charset=utf-8');

    $id = (int) ($_GET['id'] ?? 1);

    try {
        $pdo = getBenchmarkPdo();
        $stmt = $pdo->prepare('SELECT id, title, body, created_at FROM benchmark_posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            \http_response_code(404);

            return \json_encode(['error' => 'not found']);
        }

        return \json_encode($row);
    } catch (\Throwable $e) {
        \http_response_code(500);

        return \json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Scenario 4: DB write — Single INSERT.
 */
function dbWrite(): string
{
    \header('Content-Type: application/json; charset=utf-8');

    $input = \json_decode(\file_get_contents('php://input'), true) ?: [];
    $message = $input['message'] ?? 'benchmark-' . \time();
    $level = $input['level'] ?? 'info';

    try {
        $pdo = getBenchmarkPdo();
        $stmt = $pdo->prepare('INSERT INTO benchmark_logs (message, level, created_at) VALUES (:message, :level, NOW())');
        $stmt->execute(['message' => $message, 'level' => $level]);

        $id = $pdo->lastInsertId();
        \http_response_code(201);

        return \json_encode(['id' => (int) $id, 'message' => $message]);
    } catch (\Throwable $e) {
        \http_response_code(500);

        return \json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Scenario 5: Composite — DB read + template render.
 */
function composite(): string
{
    \header('Content-Type: text/html; charset=utf-8');

    $id = (int) ($_GET['id'] ?? 1);

    try {
        $pdo = getBenchmarkPdo();
        $stmt = $pdo->prepare('SELECT id, title, body, created_at FROM benchmark_posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $row = ['id' => 0, 'title' => 'Not Found', 'body' => '', 'created_at' => ''];
        }

        // Render into HTML template
        $html = '<!DOCTYPE html><html><head><title>' . \htmlspecialchars($row['title']) . '</title></head><body>';
        $html .= '<article>';
        $html .= '<h1>' . \htmlspecialchars($row['title']) . '</h1>';
        $html .= '<time>' . \htmlspecialchars($row['created_at']) . '</time>';
        $html .= '<div class="body">' . \nl2br(\htmlspecialchars($row['body'])) . '</div>';
        $html .= '</article>';
        $html .= '</body></html>';

        return $html;
    } catch (\Throwable $e) {
        \http_response_code(500);

        return '<!DOCTYPE html><html><body><p>Error: ' . \htmlspecialchars($e->getMessage()) . '</p></body></html>';
    }
}

/**
 * Scenario 6: Heavy CPU — Simulated compute-bound work.
 *
 * Performs N iterations of hash computation to simulate CPU load.
 */
function heavyCpu(): string
{
    \header('Content-Type: application/json; charset=utf-8');

    $iterations = (int) ($_GET['iterations'] ?? 500000);
    $iterations = \min($iterations, 5000000); // Safety cap

    $start = \hrtime(true);
    $hash = 'seed';
    for ($i = 0; $i < $iterations; $i++) {
        $hash = \md5($hash);
    }
    $elapsed = (\hrtime(true) - $start) / 1e6; // ms

    return \json_encode([
        'iterations' => $iterations,
        'elapsed_ms' => \round($elapsed, 2),
        'hash' => \substr($hash, 0, 8),
    ]);
}

// ── PDO Connection (persistent, cached per-worker) ──────────────

/**
 * Get or create a persistent PDO connection for benchmarking.
 */
function getBenchmarkPdo(): \PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn  = \getenv('BENCH_DB_DSN')  ?: 'mysql:host=127.0.0.1;port=3306;dbname=benchmark';
        $user = \getenv('BENCH_DB_USER') ?: 'benchmark';
        $pass = \getenv('BENCH_DB_PASS') ?: 'benchmark';

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_PERSISTENT         => true,
        ]);
    }

    return $pdo;
}
