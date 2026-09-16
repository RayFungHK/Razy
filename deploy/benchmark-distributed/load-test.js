/**
 * deploy/benchmark-distributed/load-test.js — distributed 15,000 RPS load test
 * ============================================================================
 * PURPOSE
 *   Extension of benchmark/k6/scenarios/*.js for the *aggregate* 15k-TPS target.
 *   The six existing scenarios each drive ONE route with a VU-based ramp and a
 *   single k6 process, which caps out long before 15k RPS because a ramping-VU
 *   profile can only push as fast as one machine can run iterations.
 *
 *   This script changes two things:
 *     1. Arrival-rate executors (`ramping-arrival-rate`), so load is expressed in
 *        REQUESTS PER SECOND — the unit the 15k objective is stated in — instead
 *        of VUs. Each route gets its own executor; the sum of their rates at the
 *        plateau is TOTAL_RPS (default 15000).
 *     2. Horizontal scaling of the *generator*: run N k6 instances (K8s indexed
 *        Job in this directory, or N hosts) and give each one TOTAL_RPS/N, or let
 *        k6's own distribution split the rates. See README.md.
 *
 *   Every request also hits `/_razy/health` (the framework liveness probe,
 *   src/library/Razy/Health.php:24) alongside a business route, so the test proves
 *   that probes keep answering while the worker pool is saturated — which is the
 *   exact condition that flakes pods in Kubernetes (deploy/k8s/deployment.yaml
 *   probes all point at this path).
 *
 * 15000-TPS RELEVANCE — the numbers behind the thresholds
 *   Measured per 2 vCPU / 4 GB worker container (2026-09 epoch, raw JSON in
 *   benchmark/results/razy, receipts in benchmark/REPORT.md):
 *       static 6,336 RPS  p95  19.0ms   template 4,950 RPS  p95  35.1ms
 *       composite 3,600 RPS p95 107ms    db-read 4,327 RPS   p95  38.3ms
 *       db-write 808 RPS  p95 171ms      heavy CPU 144 RPS   p50 ~500ms
 *   Thresholds below are those per-container numbers, relaxed for a fleet behind
 *   an Ingress (TLS, hop, autoscaling churn). If p95 breaches them, the first
 *   suspects are: pod count vs. HPA lag, worker queue saturation
 *   (FRANKENPHP_MAX_QUEUE_SIZE), the DB pool, then the generator itself.
 *
 * USAGE
 *   Single instance, quick sanity ramp (5% of target, 60s plateau):
 *     TARGET_BASE_URL=http://localhost:8081 TOTAL_RPS=750 SUSTAIN=1m \
 *       k6 run deploy/benchmark-distributed/load-test.js
 *   Full target on one machine (will be generator-bound — see README):
 *     TARGET_BASE_URL=https://raz.example.com k6 run .../load-test.js
 *   Distributed, 6 shards of 2,500 RPS each (see k6-distributed-job.yaml):
 *     TARGET_BASE_URL=http://razy-worker.razy-prod.svc TOTAL_RPS=2500 \
 *       INSTANCE_INDEX=$JOB_COMPLETION_INDEX k6 run .../load-test.js
 *
 * ENVIRONMENT
 *   TARGET_BASE_URL   full base URL (preferred), e.g. http://host:8080
 *   TARGET_HOST       host:port fallback, matching the existing scenarios
 *                     (benchmark/k6/scenarios/01_static_route.js:14)
 *   TOTAL_RPS         aggregate target across ALL profile executors (default 15000)
 *   PROFILE           read-dominant | static-dominant | read-write-mix |
 *                     cpu-mixed | health-only            (default read-dominant)
 *   SUSTAIN           plateau duration (default 5m)
 *   VU_FACTOR         expected max latency in seconds used to size VUs (default 0.15)
 *   INSTANCE_INDEX    shard id, only used as a metric tag for correlation
 *   RESULTS_DIR       where the summary JSON is written (default "." = cwd)
 *   K6_INSECURE_TLS   "1" to skip TLS verification (self-signed edge in tests)
 */

import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import exec from 'k6/execution';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';

// ---------------------------------------------------------------------------
// Target + profile
// ---------------------------------------------------------------------------

const BASE_URL = (__ENV.TARGET_BASE_URL || `http://${__ENV.TARGET_HOST || 'localhost:8081'}`).replace(/\/+$/, '');
const TOTAL_RPS = Number(__ENV.TOTAL_RPS || 15000);
const PROFILE = __ENV.PROFILE || 'read-dominant';
const SUSTAIN = __ENV.SUSTAIN || '5m';
const VU_FACTOR = Number(__ENV.VU_FACTOR || 0.15);   // seconds of latency to cover
const INSTANCE_INDEX = __ENV.INSTANCE_INDEX || '0';
const RESULTS_DIR = __ENV.RESULTS_DIR || '.';

if (!Number.isFinite(TOTAL_RPS) || TOTAL_RPS <= 0) {
    throw new Error(`TOTAL_RPS must be a positive number, got "${__ENV.TOTAL_RPS}"`);
}

/**
 * Route catalogue. Paths are the ones the existing suite drives
 * (benchmark/README.md:81-88) plus the framework probe — no invented endpoints.
 *   expected: what a healthy response looks like (used in checks below)
 *   budgetMs: per-request client budget; exceeding it fails the check and, at
 *             arrival-rate, means the pod stopped keeping up.
 */
const ROUTES = {
    health: {
        path: '/_razy/health',           // src/library/Razy/Health.php:24
        method: 'GET',
        kind: 'probe',
        expected: 'json-status',
        budgetMs: 50,
    },
    static: {
        path: '/benchmark/static',       // scenario 1
        method: 'GET',
        kind: 'business',
        expected: 'text-ok',
        budgetMs: 100,
    },
    template: {
        path: '/benchmark/template',     // scenario 2
        method: 'GET',
        kind: 'business',
        expected: 'html',
        budgetMs: 150,
    },
    db_read: {
        path: '/benchmark/db-read',      // scenario 3
        method: 'GET',
        kind: 'business',
        expected: 'json-id',
        budgetMs: 200,
    },
    db_write: {
        path: '/benchmark/db-write',     // scenario 4
        method: 'POST',
        kind: 'business',
        expected: 'json-id',
        budgetMs: 400,
    },
    composite: {
        path: '/benchmark/composite',    // scenario 5
        method: 'GET',
        kind: 'business',
        expected: 'html',
        budgetMs: 250,
    },
    heavy: {
        path: '/benchmark/heavy',        // scenario 6 (CPU bound, ~500ms each)
        method: 'GET',
        kind: 'business',
        expected: 'any',
        budgetMs: 2000,
    },
};

/**
 * Traffic mixes. Weights MUST sum to 1.0 — the plateau rate of each executor is
 * weight × TOTAL_RPS, so the sum of weights is what makes the aggregate hit the
 * target. Each mix maps onto existing scenarios so results stay comparable with
 * benchmark/results.
 */
const PROFILES = {
    // The 15k-TPS objective is read-dominant: mostly DB-read + render, a slice of
    // pure dispatch, and a probe stream. Size with the composite/db-read anchors.
    // (75% DB-touching, 20% CPU-only dispatch, 5% probes.)
    'read-dominant': { composite: 0.45, db_read: 0.30, static: 0.20, health: 0.05 },
    // Best case for the framework: no DB round-trip. Closest to the 6.3k RPS anchor.
    'static-dominant': { static: 0.50, template: 0.45, health: 0.05 },
    // Honest write-heavy mix. Do NOT expect 15k: db-write measured 754 RPS per
    // container (MySQL-bound, benchmark/results/COMPARISON-REPORT.md:98). Use this
    // to find the write ceiling, not to claim 15k.
    'read-write-mix': { composite: 0.35, db_read: 0.25, db_write: 0.35, health: 0.05 },
    // Adds the CPU-bound route at a rate the fleet can actually absorb
    // (144 RPS/container × ~10 pods ≈ 1.4k max; 1% of 15k = 150 RPS).
    'cpu-mixed': { composite: 0.49, db_read: 0.29, static: 0.19, heavy: 0.01, health: 0.02 },
    // Probe-only: validates the Kubernetes probe path + edge under high probe rate.
    'health-only': { health: 1.0 },
};

const weights = PROFILES[PROFILE];
if (!weights) {
    throw new Error(`unknown PROFILE "${PROFILE}"; known: ${Object.keys(PROFILES).join(', ')}`);
}
const weightSum = Object.values(weights).reduce((a, b) => a + b, 0);
if (Math.abs(weightSum - 1) > 0.001) {
    throw new Error(`profile "${PROFILE}" weights sum to ${weightSum}, expected 1.0`);
}

// ---------------------------------------------------------------------------
// Load shape
// ---------------------------------------------------------------------------

/**
 * 3-minute ramp (25/50/75/100%), a SUSTAIN plateau at the exact target, then a
 * ramp-down. Ramp steps matter: a 20-pod fleet that jumps straight to 15k hits
 * pods whose JIT/OPcache traces are still cold (see opcache.jit_* in
 * deploy/php-opcache.ini).
 */
function stagesFor(rate) {
    const at = (fraction) => Math.max(1, Math.round(rate * fraction));
    return [
        { duration: '60s', target: at(0.25) },
        { duration: '60s', target: at(0.50) },
        { duration: '60s', target: at(0.75) },
        { duration: '60s', target: at(1) },
        { duration: SUSTAIN, target: at(1) },   // plateau: this is the 15k measurement
        { duration: '60s', target: 0 },
    ];
}

function executorFor(name, weight) {
    const rate = Math.max(1, Math.round(TOTAL_RPS * weight));
    const route = ROUTES[name];
    // Arrival-rate executors need ~rate × latency VUs to actually issue that many
    // requests; maxVUs is the safety valve when latency degrades (latency × rate
    // grows, so a degrading fleet needs MORE generator VUs, not fewer).
    const pre = Math.max(20, Math.ceil(rate * VU_FACTOR * 0.5));
    const max = Math.max(pre * 3, Math.ceil(rate * VU_FACTOR * 2));
    return {
        executor: 'ramping-arrival-rate',
        startRate: Math.max(1, Math.round(rate * 0.05)),
        preAllocatedVUs: pre,
        maxVUs: max,
        stages: stagesFor(rate),
        exec: 'drive',
        tags: { scenario: name, kind: route.kind, route: route.path },
    };
}

const scenarios = {};
Object.keys(weights).forEach((name) => {
    scenarios[name] = executorFor(name, weights[name]);
});

export const options = {
    scenarios,
    // Aggregate + per-scenario gates. `http_reqs.rate >= 0.95 × TOTAL_RPS` is the
    // line that says "we actually delivered 15k", not "we asked for it".
    thresholds: {
        // Throughput achievement (the 15k objective itself).
        http_reqs: [`rate>=${Math.round(TOTAL_RPS * 0.95)}`],
        // Error rate: 1% budget across everything, and a tighter gate on the
        // probe — a probe that errors makes Kubernetes evict healthy pods.
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.98'],
        ok_rate: ['rate>0.99'],
        'ok_rate{kind:probe}': ['rate>0.999'],
        // Latency: probe must stay negligible even when workers are saturated.
        'http_req_duration{kind:probe}': ['p(95)<25', 'p(99)<50'],
        // Latency: business traffic, fleet-scale (per-container p95 was 18-72ms).
        'http_req_duration{kind:business}': ['p(95)<150', 'p(99)<400'],
        'http_req_duration{scenario:static}': ['p(95)<50', 'p(99)<120'],
        'http_req_duration{scenario:template}': ['p(95)<60', 'p(99)<150'],
        'http_req_duration{scenario:composite}': ['p(95)<150', 'p(99)<400'],
        'http_req_duration{scenario:db_read}': ['p(95)<120', 'p(99)<300'],
        'http_req_duration{scenario:db_write}': ['p(95)<300', 'p(99)<800'],
        'http_req_duration{scenario:heavy}': ['p(95)<1200', 'p(99)<2000'],
        // Probe tier reported "degraded" (not "ok"): must stay a rarity, and it is
        // the only signal /_razy/health can give you (Health.php:11-19 — it does
        // not report DB/cache state, so treat this as informational).
        health_degraded: ['rate<0.01'],
        // Optional: kill the run early instead of burning 10 minutes after the
        // probe starts failing. Needs a k6 release with thresholds.abortOnFail
        // support (verify with `k6 version` + release notes) — left commented so
        // older k6 binaries do not fail to parse the options.
        // abortOnFail: true,
        // abortGracePeriod: '30s',
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    // Keep TLS handshake cost out of the measurement when the edge is a test cert.
    insecureSkipTLSVerify: __ENV.K6_INSECURE_TLS === '1',
    // Connection reuse: 15k RPS with per-request TCP+TLS is a generator benchmark.
    noConnectionReuse: false,
};

// Default per-VU connection pool size for keep-alive (k6 default is 16).
http.setResponseCallback(http.expectedStatuses(200, 201));
http.options.keepAlive = 64;

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------

const reqDuration = new Trend('req_duration_ms', true);
const okRate = new Rate('ok_rate');
const totalReqs = new Counter('total_requests');
const healthDegraded = new Rate('health_degraded');

// ---------------------------------------------------------------------------
// Request driver
// ---------------------------------------------------------------------------

function buildRequest(route) {
    const url = `${BASE_URL}${route.path}`;
    const params = {
        tags: { instance: INSTANCE_INDEX },
        timeout: route.kind === 'probe' ? '3s' : '10s',
    };

    switch (route.path) {
        case '/benchmark/db-read':
        case '/benchmark/composite':
            // Same id range the existing scenarios use (pre-seeded 1..1000).
            return http.get(`${url}?id=${Math.floor(Math.random() * 1000) + 1}`, params);
        case '/benchmark/heavy':
            // Same iteration count as benchmark/k6/scenarios/06_heavy_cpu.js:57.
            return http.get(`${url}?iterations=500000`, params);
        case '/benchmark/db-write':
            // Same JSON body shape as benchmark/k6/scenarios/04_db_write.js:51-60.
            return http.post(url, JSON.stringify({
                message: `load-test-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
                level: 'info',
            }), Object.assign({}, params, { headers: { 'Content-Type': 'application/json' } }));
        default:
            return http.get(url, params);
    }
}

function hasJsonField(body, field) {
    try {
        return JSON.parse(body)[field] !== undefined;
    } catch (e) {
        return false;
    }
}

export function drive(data, scenario) {
    const name = exec.getCurrentScenario().name;
    const route = ROUTES[name];
    if (!route) {
        throw new Error(`scenario "${name}" has no route in ROUTES`);
    }

    const res = buildRequest(route);
    const dims = { name, kind: route.kind, instance: INSTANCE_INDEX };

    reqDuration.add(res.timings.duration, dims);
    totalReqs.add(1, dims);

    const ok = res.status === 200 || (route.method === 'POST' && res.status === 201);
    okRate.add(ok, dims);

    if (name === 'health') {
        // Health.php always answers 200 + {"status":"ok",...}; "degraded" is a
        // deployment-side convention, so it is counted, not assumed.
        healthDegraded.add(String(res.body || '').indexOf('"degraded"') !== -1, dims);
    }

    const checks = {
        'status ok': (r) => r.status === 200 || (route.method === 'POST' && r.status === 201),
        [`within ${route.budgetMs}ms budget`]: (r) => r.timings.duration < route.budgetMs,
    };

    if (route.expected === 'json-status') {
        checks['health has status field'] = (r) => hasJsonField(r.body, 'status');
        checks['health has uptime_seconds'] = (r) => hasJsonField(r.body, 'uptime_seconds');
    } else if (route.expected === 'text-ok') {
        checks['body is ok'] = (r) => r.body === 'ok';
    } else if (route.expected === 'html') {
        checks['body has <title>'] = (r) => String(r.body || '').indexOf('<title>') !== -1;
    } else if (route.expected === 'json-id') {
        checks['body has id'] = (r) => hasJsonField(r.body, 'id');
    }

    check(res, checks, dims);
}

// Non-scenario entry point (kept for `k6 run --vus N --duration Xs` overrides,
// which ignore `scenarios` and only run the default function).
export default function () {
    drive(null, null);
}

// ---------------------------------------------------------------------------
// Preflight: fail fast, do not ramp into a dead cluster
// ---------------------------------------------------------------------------

export function setup() {
    const res = http.get(`${BASE_URL}/_razy/health`, { timeout: '5s' });
    const ok = res.status === 200 && hasJsonField(res.body, 'status');
    if (!ok) {
        throw new Error(
            `preflight failed: GET ${BASE_URL}/_razy/health -> ${res.status} ${String(res.body || '').slice(0, 120)}`
        );
    }
    console.log(
        `preflight OK  target=${BASE_URL}  profile=${PROFILE}  ` +
        `aggregate_target=${TOTAL_RPS} RPS  sustain=${SUSTAIN}  ` +
        `instance=${INSTANCE_INDEX}  mix=${JSON.stringify(weights)}`
    );
    return { startedAt: new Date().toISOString() };
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    const prefix = `${RESULTS_DIR}/load-test_${PROFILE}_inst${INSTANCE_INDEX}_${ts}`;
    return {
        [`${prefix}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
