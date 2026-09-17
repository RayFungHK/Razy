/**
 * Scenario 9: Gate Refusal — the 503 negative path at load
 *
 * The gated module's migration is declared but never applied (a deploy
 * caught mid-flight, permanently, for measurement). Every request must be
 * ANSWERED — 503 with the named module and the exact deploy command — not
 * hang, not 404-silence, not a handler writing into absent tables. The cost
 * being measured: how cheap is the framework's honest refusal under 200 VUs.
 *
 * Success here IS the 503 (inverted check set).
 *
 * Usage: TARGET_HOST=localhost:8085 k6 run benchmark/k6/scenarios/09_gate_refusal.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE = `http://${__ENV.TARGET_HOST || 'localhost:8085'}`;

const reqDuration = new Trend('req_duration_ms', true);
const refusedRate = new Rate('refused_rate');
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
        // refusal must stay CHEAP and STABLE — same budgets as the pass path
        'http_req_duration': ['p(95)<50', 'p(99)<100'],
        'refused_rate':      ['rate>0.995'],
    },
};

export default function () {
    const res = http.get(`${BASE}/gf/ping`, { redirectResponse: false });

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    refusedRate.add(res.status === 503);

    check(res, {
        'status is 503':       (r) => r.status === 503,
        'names the module':    (r) => (r.body || '').includes('bench/gate-refused'),
        'duration < 50ms':     (r) => r.timings.duration < 50,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/09_gate_refusal_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}

import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';
