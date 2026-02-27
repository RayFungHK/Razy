<?php

/**
 * Laravel Benchmark Routes.
 *
 * Mirror of the Razy benchmark endpoints for fair comparison.
 * Drop this file into routes/web.php (or routes/api.php) of a fresh
 * Laravel 11+ project with Octane (Swoole) installed.
 *
 * Endpoints:
 *   GET  /benchmark/static     → Static route (returns "ok")
 *   GET  /benchmark/template   → Template render (10 variables)
 *   GET  /benchmark/db-read    → Single-row SELECT
 *   POST /benchmark/db-write   → Single INSERT
 *   GET  /benchmark/composite  → DB read + template render
 *   GET  /benchmark/heavy      → CPU-intensive computation
 *
 * @license MIT
 */

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ── Scenario 1: Static Route ────────────────────────────────────
Route::get('/benchmark/static', function () {
    return response('ok', 200)->header('Content-Type', 'text/plain');
});

// ── Scenario 2: Template Render ─────────────────────────────────
Route::get('/benchmark/template', function () {
    $vars = [];
    for ($i = 1; $i <= 10; $i++) {
        $vars["var_{$i}"] = "value_{$i}_" . str_repeat('x', 50);
    }

    return view('benchmark.template', $vars);
});

// ── Scenario 3: DB Read ─────────────────────────────────────────
Route::get('/benchmark/db-read', function (Request $request): JsonResponse {
    $id = (int) $request->query('id', 1);

    $post = DB::table('benchmark_posts')
        ->where('id', $id)
        ->first(['id', 'title', 'body', 'created_at']);

    if (! $post) {
        return response()->json(['error' => 'not found'], 404);
    }

    return response()->json($post);
});

// ── Scenario 4: DB Write ────────────────────────────────────────
Route::post('/benchmark/db-write', function (Request $request): JsonResponse {
    $message = $request->input('message', 'benchmark-' . time());
    $level   = $request->input('level', 'info');

    $id = DB::table('benchmark_logs')->insertGetId([
        'message'    => $message,
        'level'      => $level,
        'created_at' => now(),
    ]);

    return response()->json(['id' => $id, 'message' => $message], 201);
});

// ── Scenario 5: Composite (DB + Template) ───────────────────────
Route::get('/benchmark/composite', function (Request $request) {
    $id = (int) $request->query('id', 1);

    $post = DB::table('benchmark_posts')
        ->where('id', $id)
        ->first(['id', 'title', 'body', 'created_at']);

    if (! $post) {
        $post = (object) ['id' => 0, 'title' => 'Not Found', 'body' => '', 'created_at' => ''];
    }

    return view('benchmark.composite', ['post' => $post]);
});

// ── Scenario 6: Heavy CPU ───────────────────────────────────────
Route::get('/benchmark/heavy', function (Request $request): JsonResponse {
    $iterations = min((int) $request->query('iterations', 500000), 5000000);

    $start = hrtime(true);
    $hash = 'seed';
    for ($i = 0; $i < $iterations; $i++) {
        $hash = md5($hash);
    }
    $elapsed = (hrtime(true) - $start) / 1e6;

    return response()->json([
        'iterations' => $iterations,
        'elapsed_ms' => round($elapsed, 2),
        'hash'       => substr($hash, 0, 8),
    ]);
});
