/**
 * Scenario 3: DB Read — Single-Row SELECT via ORM/Query Builder
 *
 * Reads one row from the `benchmark_posts` table by primary key.
 * Measures DB connection + query execution + hydration cost.
 *
 * Usage:
 *   TARGET_HOST=localhost:8080 k6 run benchmark/k6/scenarios/03_db_read.js
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
                { duration: '60s', target: 200 },
                { duration: '30s', target: 200 },
                { duration: '30s', target: 0 },
            ],
            startTime: '30s',
            tags: { phase: 'load' },
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<100', 'p(99)<250'],
        'success_rate':      ['rate>0.99'],
    },
};

export default function () {
    // Random post ID between 1–1000 (pre-seeded)
    const id = Math.floor(Math.random() * 1000) + 1;
    const res = http.get(`${BASE}/benchmark/db-read?id=${id}`);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 200);

    check(res, {
        'status is 200':      (r) => r.status === 200,
        'has id field':       (r) => JSON.parse(r.body).id !== undefined,
        'duration < 100ms':   (r) => r.timings.duration < 100,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/03_db_read_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
