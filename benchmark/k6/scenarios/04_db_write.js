/**
 * Scenario 4: DB Write — Single INSERT Operation
 *
 * Inserts a row into `benchmark_logs` table.
 * Measures write latency and connection handling under concurrency.
 *
 * Usage:
 *   TARGET_HOST=localhost:8080 k6 run benchmark/k6/scenarios/04_db_write.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';

const BASE = `http://${__ENV.TARGET_HOST || 'localhost:8080'}`;

const reqDuration = new Trend('req_duration_ms', true);
const successRate = new Rate('success_rate');
const totalReqs   = new Counter('total_requests');

export const options = {
    scenarios: {
        warmup: {
            executor: 'constant-vus',
            vus: 10,
            duration: '30s',
            startTime: '0s',
            tags: { phase: 'warmup' },
        },
        load: {
            executor: 'ramping-vus',
            startVUs: 10,
            stages: [
                { duration: '30s', target: 50 },
                { duration: '60s', target: 100 },
                { duration: '60s', target: 150 },
                { duration: '30s', target: 150 },
                { duration: '30s', target: 0 },
            ],
            startTime: '30s',
            tags: { phase: 'load' },
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<200', 'p(99)<500'],
        'success_rate':      ['rate>0.99'],
    },
};

export default function () {
    const payload = JSON.stringify({
        message: `benchmark-log-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
        level:   'info',
    });

    const params = {
        headers: { 'Content-Type': 'application/json' },
    };

    const res = http.post(`${BASE}/benchmark/db-write`, payload, params);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 201 || res.status === 200);

    check(res, {
        'status is 2xx':      (r) => r.status >= 200 && r.status < 300,
        'has id in response': (r) => {
            try { return JSON.parse(r.body).id !== undefined; } catch { return false; }
        },
        'duration < 200ms':   (r) => r.timings.duration < 200,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/04_db_write_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
