#!/usr/bin/env python3
"""
Benchmark Report Generator — 2026-09 symmetric epoch.

Four stacks, one measurement protocol (COMPETITOR-LANDSCAPE.md §5):
  worker pass:  Razy (FrankenPHP worker) vs Laravel (Octane/frankenphp)
                — the same runtime family on both sides: THE framework table
  fpm pass:     Razy (php:8.3-fpm) vs Laravel (php:8.3-fpm)
                — the plain-FPM baseline the audit asked for, both sides
  ratio:        what worker mode buys EACH stack (the honest runtime column)

Reads raw k6 JSON (--summary-export) from benchmark/results/<target>/.
A pass whose raw JSON is absent renders "not run" — never fabricated numbers.
Embeds the toolchain receipts (results/toolchain.txt): no receipts, no verdict.

Usage:
    python3 benchmark/scripts/generate-report.py [--output benchmark/REPORT.md]
"""

import argparse
import json
import re
import statistics
import sys
from datetime import datetime
from pathlib import Path

STACKS = ['razy', 'laravel', 'razy-fpm', 'laravel-fpm']

SCENARIO_LABELS = {
    '01_static_route': '1. Static Route (framework dispatch)',
    '02_template_render': '2. Template Render (engine, 10 vars)',
    '03_db_read': '3. DB Read (single SELECT)',
    '04_db_write': '4. DB Write (single INSERT)',
    '05_composite': '5. Composite (DB + template)',
    '06_heavy_cpu': '6. Heavy CPU (md5 loop)',
}


def load_k6_summary(filepath: Path) -> dict:
    with open(filepath, 'r', encoding='utf-8') as f:
        return json.load(f)


def extract_metrics(summary: dict) -> dict:
    metrics = summary.get('metrics', {})
    http_reqs = metrics.get('http_reqs', {})
    http_dur = metrics.get('http_req_duration', {})
    checks = metrics.get('checks', {})

    return {
        'rps': http_reqs.get('values', {}).get('rate', 0),
        'total_reqs': http_reqs.get('values', {}).get('count', 0),
        'p50': http_dur.get('values', {}).get('med', 0),
        'p90': http_dur.get('values', {}).get('p(90)', 0),
        'p95': http_dur.get('values', {}).get('p(95)', 0),
        'p99': http_dur.get('values', {}).get('p(99)', 0),
        'avg': http_dur.get('values', {}).get('avg', 0),
        'max': http_dur.get('values', {}).get('max', 0),
        'success_rate': checks.get('values', {}).get('rate', 0) * 100,
    }


def aggregate_runs(run_metrics: list) -> dict:
    if not run_metrics:
        return {}
    result = {}
    for key in run_metrics[0].keys():
        values = [m[key] for m in run_metrics]
        result[key] = {
            'mean': statistics.mean(values),
            'stddev': statistics.stdev(values) if len(values) > 1 else 0,
            'min': min(values),
            'max': max(values),
        }
    return result


def collect_results(results_dir: Path) -> dict:
    scenarios = {}
    if not results_dir.exists():
        return scenarios
    for f in sorted(results_dir.glob('*_run*.json')):
        match = re.match(r'^(\d{2}_[a-z_]+)_run(\d+)\.json$', f.name)
        if not match:
            continue
        name, num = match.group(1), int(match.group(2))
        try:
            metrics = extract_metrics(load_k6_summary(f))
            metrics['run'] = num
            scenarios.setdefault(name, []).append(metrics)
        except (json.JSONDecodeError, KeyError) as e:
            print(f'Warning: failed to parse {f}: {e}', file=sys.stderr)
    return scenarios


def fmt(value: float, unit: str = '') -> str:
    if unit == 'ms':
        return f'{value:.2f} ms'
    if unit == '%':
        return f'{value:.2f}%'
    if value > 1000:
        return f'{value:,.0f}'
    return f'{value:.2f}'


def ratio(a: float, b: float) -> str:
    if b == 0:
        return '—'
    r = a / b
    return f'{r:.2f}x'


def head_to_head(lines: list, left: dict, right: dict, left_name: str, right_name: str):
    """RPS + latency comparison table for two stacks."""
    lines.append('')
    lines.append(f'| Scenario | {left_name} RPS | {right_name} RPS | RPS ratio | {left_name} p95 | {right_name} p95 | {left_name} ok% | {right_name} ok% |')
    lines.append('|---|---:|---:|:--:|---:|---:|---:|---:|')

    scenarios = sorted(set(list(left.keys()) + list(right.keys())))
    for sc in scenarios:
        label = SCENARIO_LABELS.get(sc, sc)
        la = aggregate_runs(left.get(sc, [])) if left.get(sc) else None
        ra = aggregate_runs(right.get(sc, [])) if right.get(sc) else None
        l_rps = fmt(la['rps']['mean']) if la else 'not run'
        r_rps = fmt(ra['rps']['mean']) if ra else 'not run'
        rps_ratio = ratio(la['rps']['mean'], ra['rps']['mean']) if la and ra else '—'
        l_p95 = fmt(la['p95']['mean'], 'ms') if la else '—'
        r_p95 = fmt(ra['p95']['mean'], 'ms') if ra else '—'
        l_ok = fmt(la['success_rate']['mean'], '%') if la else '—'
        r_ok = fmt(ra['success_rate']['mean'], '%') if ra else '—'
        lines.append(f'| {label} | {l_rps} | {r_rps} | {rps_ratio} | {l_p95} | {r_p95} | {l_ok} | {r_ok} |')


def worker_gain(lines: list, worker: dict, fpm: dict, stack_name: str):
    lines.append('')
    lines.append(f'| Scenario | {stack_name} worker RPS | {stack_name} fpm RPS | gain |')
    lines.append('|---|---:|---:|:--:|')
    scenarios = sorted(set(list(worker.keys()) + list(fpm.keys())))
    for sc in scenarios:
        label = SCENARIO_LABELS.get(sc, sc)
        wa = aggregate_runs(worker.get(sc, [])) if worker.get(sc) else None
        fa = aggregate_runs(fpm.get(sc, [])) if fpm.get(sc) else None
        w_rps = fmt(wa['rps']['mean']) if wa else 'not run'
        f_rps = fmt(fa['rps']['mean']) if fa else 'not run'
        g = ratio(wa['rps']['mean'], fa['rps']['mean']) if wa and fa else '—'
        lines.append(f'| {label} | {w_rps} | {f_rps} | {g} |')


def detail_sections(lines: list, stacks_results: dict):
    lines.append('')
    lines.append('## Detailed Results (mean ± stddev over runs)')
    for target in STACKS:
        res = stacks_results.get(target, {})
        if not res:
            continue
        lines.append('')
        lines.append(f'### {target}')
        for sc in sorted(res.keys()):
            runs = res[sc]
            agg = aggregate_runs(runs)
            lines.append('')
            lines.append(f'**{SCENARIO_LABELS.get(sc, sc)}** ({len(runs)} runs): RPS {fmt(agg["rps"]["mean"])} ±{fmt(agg["rps"]["stddev"])}, '
                         f'p50 {fmt(agg["p50"]["mean"], "ms")}, p95 {fmt(agg["p95"]["mean"], "ms")}, '
                         f'p99 {fmt(agg["p99"]["mean"], "ms")}, success {fmt(agg["success_rate"]["mean"], "%")}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--results-dir', default='benchmark/results')
    parser.add_argument('--output', default='benchmark/REPORT.md')
    args = parser.parse_args()

    root = Path(args.results_dir)
    stacks_results = {t: collect_results(root / t) for t in STACKS}

    lines = []
    lines.append('# Performance Benchmark Report — 2026-09 symmetric epoch')
    lines.append('')
    lines.append(f'Generated: {datetime.now():%Y-%m-%d %H:%M} · k6 2.0.0 (Docker) · '
                 '2 vCPU / 4 GB per stack, shared MySQL 8.0.43, '
                 '3 runs per scenario per stack, warmup + cooldown between all.')
    lines.append('')
    lines.append('Symmetry law (absorbing the 2026-02 audit): real template engines both sides, '
                 'query-builder layer both sides, persistent worker connections both sides, '
                 'identical FrankenPHP runtime for the main comparison, and a plain-FPM '
                 'baseline pass for both stacks. Raw k6 JSON under results/ is the record of truth.')

    # receipts
    receipts = root / 'toolchain.txt'
    if receipts.exists():
        lines.append('')
        lines.append('```')
        lines.append(receipts.read_text(encoding='utf-8').strip())
        lines.append('```')
    else:
        lines.append('')
        lines.append('> **NO TOOLCHAIN RECEIPTS** — numbers below are not a valid run '
                     '(pinned-toolchain rule). results/toolchain.txt missing.')

    lines.append('')
    lines.append('## The framework table (identical runtime: FrankenPHP 1.10 on both sides)')
    head_to_head(lines, stacks_results['razy'], stacks_results['laravel'], 'Razy', 'Laravel')

    lines.append('')
    lines.append('## The FPM baseline table (identical runtime: php:8.3-fpm on both sides)')
    head_to_head(lines, stacks_results['razy-fpm'], stacks_results['laravel-fpm'], 'Razy', 'Laravel')

    lines.append('## What worker mode buys each stack')
    worker_gain(lines, stacks_results['razy'], stacks_results['razy-fpm'], 'Razy')
    worker_gain(lines, stacks_results['laravel'], stacks_results['laravel-fpm'], 'Laravel')

    detail_sections(lines, stacks_results)

    lines.append('')
    lines.append('## Reproduction')
    lines.append('')
    lines.append('```bash')
    lines.append('cd benchmark')
    lines.append('docker compose up -d                                # mysql + razy worker')
    lines.append('docker compose --profile laravel up -d              # laravel worker (frankenphp)')
    lines.append('docker compose --profile fpm up -d                  # both fpm baselines + caddy front')
    lines.append('./scripts/full-run-2026-09.ps1                      # serial 4-pass run, receipts included')
    lines.append('python3 scripts/generate-report.py')
    lines.append('```')

    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text('\n'.join(lines), encoding='utf-8')
    print(f'Report written to {out}')
    for t in STACKS:
        print(f'  {t}: {sorted(stacks_results[t].keys()) or "not run"}')


if __name__ == '__main__':
    main()
