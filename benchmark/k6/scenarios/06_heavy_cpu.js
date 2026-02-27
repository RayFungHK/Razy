/**
 * Scenario 6: Heavy CPU / Blocked I/O — Worker Management Stress
 *
 * Simulates a CPU-intensive or long-blocking request.
 * Tests how each framework's worker pool handles slow requests without
 * starving other concurrent fast requests.
 *
 * Sends two parallel request types:
 *   - /benchmark/heavy  (slow: ~500ms simulated CPU work)
 *   - /benchmark/static (fast: returns "ok" instantly)
 *
 * Compares how well the worker pool isolates slow requests.
 *
 * Usage:
 *   TARGET_HOST=localhost:8080 k6 run benchmark/k6/scenarios/06_heavy_cpu.js
 */
import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';

const BASE = `http://${__ENV.TARGET_HOST || 'localhost:8080'}`;

// Separate metrics for heavy vs fast requests
const heavyDuration = new Trend('heavy_req_duration_ms', true);
const fastDuration  = new Trend('fast_req_duration_ms', true);
const heavySuccess  = new Rate('heavy_success_rate');
const fastSuccess   = new Rate('fast_success_rate');

export const options = {
    scenarios: {
        // Heavy CPU-bound requests (lower concurrency)
        heavy: {
            executor: 'constant-vus',
            vus: 30,
            duration: '180s',
            exec: 'heavyRequest',
            tags: { type: 'heavy' },
        },
        // Fast requests running alongside heavy ones
        fast: {
            executor: 'constant-vus',
            vus: 50,
            duration: '180s',
            exec: 'fastRequest',
            tags: { type: 'fast' },
        },
    },
    thresholds: {
        'heavy_req_duration_ms': ['p(95)<2000'],
        'fast_req_duration_ms':  ['p(95)<100'],   // Fast should stay fast even under load
        'fast_success_rate':     ['rate>0.99'],
    },
};

export function heavyRequest() {
    const res = http.get(`${BASE}/benchmark/heavy?iterations=500000`);

    heavyDuration.add(res.timings.duration);
    heavySuccess.add(res.status === 200);

    check(res, {
        '[heavy] status 200':     (r) => r.status === 200,
        '[heavy] duration < 2s':  (r) => r.timings.duration < 2000,
    });

    sleep(0.1);
}

export function fastRequest() {
    const res = http.get(`${BASE}/benchmark/static`);

    fastDuration.add(res.timings.duration);
    fastSuccess.add(res.status === 200);

    check(res, {
        '[fast] status 200':      (r) => r.status === 200,
        '[fast] duration < 50ms': (r) => r.timings.duration < 50,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/06_heavy_cpu_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
