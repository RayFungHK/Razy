/**
 * Scenario 9b: Ungated Control — the identical handler WITHOUT the gate
 *
 * plain-plain serves the byte-identical 'ok' through the same dispatcher on
 * the same worker, but its routes carry no ready declaration. 07 minus 9b is
 * the honest per-request cost of the v1.1 readiness gate (memo-hot), and
 * 08 minus 9b is the vacuous-case tax. Same site, same process — the cleanest
 * control the harness can get.
 *
 * Usage: TARGET_HOST=localhost:8085 k6 run benchmark/k6/scenarios/09b_ungated_control.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE = `http://${__ENV.TARGET_HOST || 'localhost:8085'}`;

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
        rampup: {
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
        'http_req_duration': ['p(95)<50', 'p(99)<100'],
        'success_rate':      ['rate>0.99'],
    },
};

export default function () {
    const res = http.get(`${BASE}/pp/ping`);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 200);

    check(res, {
        'status is 200':   (r) => r.status === 200,
        'body equals ok':  (r) => r.body === 'ok',
        'duration < 50ms': (r) => r.timings.duration < 50,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/09b_ungated_control_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}

import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';
