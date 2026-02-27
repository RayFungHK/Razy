<?php

/**
 * Benchmark Controller — Razy Framework.
 *
 * Provides standardised endpoints for performance comparison testing.
 * Each method corresponds to one benchmark scenario.
 *
 * Endpoints:
 *   GET /benchmark/static     → Scenario 1: Static route (returns "ok")
 *   GET /benchmark/template   → Scenario 2: Template render (10 variables)
 *   GET /benchmark/db-read    → Scenario 3: Single-row SELECT
 *   POST /benchmark/db-write  → Scenario 4: Single INSERT
 *   GET /benchmark/composite  → Scenario 5: DB read + template render
 *   GET /benchmark/heavy      → Scenario 6: CPU-intensive computation
 *
 * @license MIT
 */

return function () {
    $this->addRoute('get::/static', 'staticRoute');
    $this->addRoute('get::/template', 'templateRender');
    $this->addRoute('get::/db-read', 'dbRead');
    $this->addRoute('post::/db-write', 'dbWrite');
    $this->addRoute('get::/composite', 'composite');
    $this->addRoute('get::/heavy', 'heavyCpu');
};
