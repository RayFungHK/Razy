/**
 * Scenario 2: Template Render — Template Engine Overhead
 *
 * Renders a simple template with 10 variables.
 * Measures the template compilation/rendering cost on top of routing.
 *
 * Usage:
 *   TARGET_HOST=localhost:8080 k6 run benchmark/k6/scenarios/02_template_render.js
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
        'http_req_duration': ['p(95)<100', 'p(99)<200'],
        'success_rate':      ['rate>0.99'],
    },
};

export default function () {
    const res = http.get(`${BASE}/benchmark/template`);

    reqDuration.add(res.timings.duration);
    totalReqs.add(1);
    successRate.add(res.status === 200);

    check(res, {
        'status is 200':        (r) => r.status === 200,
        'body contains var_1':  (r) => r.body.includes('var_1'),
        'body length > 100':    (r) => r.body.length > 100,
        'duration < 100ms':     (r) => r.timings.duration < 100,
    });

    sleep(0.01);
}

export function handleSummary(data) {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    return {
        [`benchmark/results/02_template_render_${ts}.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: '  ', enableColors: true }),
    };
}
