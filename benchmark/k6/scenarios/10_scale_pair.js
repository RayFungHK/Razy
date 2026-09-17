/**
 * Scenario 10: Assembly-scale dispatch (pair-A/B instrument).
 *
 * Same ramp as 01 (the published shape), but the TARGET is parameterized so
 * the pair harness (benchmark/pair_ab.py) can drive any arm at any path.
 * The path is a lazy route in a 60-module multi-site dist — every boot here
 * assembles 60 manifests + 60 __onInit declarations + 60 route rows, which
 * is the regime the compiled-boot replay actually attacks (the 6-route
 * standalone baseline sits beneath the host noise floor).
 *
 *   TARGET_HOST=bench-fpm-caddy:8091 TARGET_PATH=/x17/ping k6 run 10_scale_pair.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE = `http://${__ENV.TARGET_HOST || 'localhost:8080'}`;
const PATH = __ENV.TARGET_PATH || '/x1/ping';

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
                { duration: '30s', target: 200 },  // sustained peak
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
    const res = http.get(`${BASE}${PATH}`);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 200);

    check(res, {
        'status is 200':       (r) => r.status === 200,
        'body equals ok':      (r) => r.body === 'ok',
        'duration < 50ms':     (r) => r.timings.duration < 50,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    return {
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}

import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.3/index.js';
