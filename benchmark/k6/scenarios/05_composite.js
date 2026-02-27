/**
 * Scenario 5: Concurrency Stress — Composite DB Read + Template Render
 *
 * Simulates a realistic request: fetch data from DB, render into a template.
 * Tests the combined framework, DB, and template paths under high concurrency.
 *
 * Usage:
 *   TARGET_HOST=localhost:8080 k6 run benchmark/k6/scenarios/05_composite.js
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
        stress: {
            executor: 'ramping-vus',
            startVUs: 20,
            stages: [
                { duration: '30s', target: 100 },
                { duration: '60s', target: 200 },
                { duration: '60s', target: 300 },
                { duration: '60s', target: 400 },
                { duration: '30s', target: 400 },  // sustained peak
                { duration: '30s', target: 0 },
            ],
            startTime: '30s',
            tags: { phase: 'stress' },
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<300', 'p(99)<500'],
        'success_rate':      ['rate>0.98'],
    },
};

export default function () {
    const id = Math.floor(Math.random() * 1000) + 1;
    const res = http.get(`${BASE}/benchmark/composite?id=${id}`);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 200);

    check(res, {
        'status is 200':        (r) => r.status === 200,
        'body contains title':  (r) => r.body.includes('<title>'),
        'body length > 200':    (r) => r.body.length > 200,
        'duration < 300ms':     (r) => r.timings.duration < 300,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/05_composite_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
