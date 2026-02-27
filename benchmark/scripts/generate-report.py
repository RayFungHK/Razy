#!/usr/bin/env python3
"""
Benchmark Report Generator — Aggregate k6 results into comparison tables.

Reads k6 JSON summary files from benchmark/results/{razy,laravel}/ and
produces a Markdown comparison report.

Usage:
    python3 benchmark/scripts/generate-report.py [--output benchmark/REPORT.md]
"""

import argparse
import json
import os
import re
import statistics
import sys
from datetime import datetime
from pathlib import Path


def load_k6_summary(filepath: Path) -> dict:
    """Load a k6 --summary-export JSON file."""
    with open(filepath, 'r', encoding='utf-8') as f:
        return json.load(f)


def extract_metrics(summary: dict) -> dict:
    """Extract key metrics from a k6 summary."""
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
        'min': http_dur.get('values', {}).get('min', 0),
        'max': http_dur.get('values', {}).get('max', 0),
        'success_rate': checks.get('values', {}).get('rate', 0) * 100,
    }


def aggregate_runs(run_metrics: list[dict]) -> dict:
    """Aggregate metrics across multiple runs (mean ± stddev)."""
    if not run_metrics:
        return {}

    keys = run_metrics[0].keys()
    result = {}

    for key in keys:
        values = [m[key] for m in run_metrics]
        result[key] = {
            'mean': statistics.mean(values),
            'stddev': statistics.stdev(values) if len(values) > 1 else 0,
            'min': min(values),
            'max': max(values),
        }

    return result


def collect_results(results_dir: Path) -> dict:
    """Collect all k6 results for a target, grouped by scenario."""
    scenarios = {}

    if not results_dir.exists():
        return scenarios

    for f in sorted(results_dir.glob('*_run*.json')):
        # Parse: 01_static_route_run1.json
        match = re.match(r'^(\d{2}_[a-z_]+)_run(\d+)\.json$', f.name)
        if not match:
            continue

        scenario_name = match.group(1)
        run_num = int(match.group(2))

        if scenario_name not in scenarios:
            scenarios[scenario_name] = []

        try:
            summary = load_k6_summary(f)
            metrics = extract_metrics(summary)
            metrics['run'] = run_num
            scenarios[scenario_name].append(metrics)
        except (json.JSONDecodeError, KeyError) as e:
            print(f'Warning: Failed to parse {f}: {e}', file=sys.stderr)

    return scenarios


def format_metric(value: float, unit: str = '', decimals: int = 2) -> str:
    """Format a metric value for display."""
    if unit == 'ms':
        return f'{value:.{decimals}f} ms'
    if unit == '%':
        return f'{value:.1f}%'
    if value > 1000:
        return f'{value:,.0f}'
    return f'{value:.{decimals}f}'


def generate_report(razy_results: dict, laravel_results: dict) -> str:
    """Generate a Markdown comparison report."""
    lines = []
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    lines.append('# Performance Benchmark Report')
    lines.append(f'')
    lines.append(f'**Generated:** {now}')
    lines.append(f'**Comparison:** Razy (FrankenPHP Worker) vs Laravel (Octane/Swoole)')
    lines.append('')

    # Scenario name mapping
    scenario_labels = {
        '01_static_route': '1. Static Route (Baseline)',
        '02_template_render': '2. Template Render (10 vars)',
        '03_db_read': '3. DB Read (Single SELECT)',
        '04_db_write': '4. DB Write (Single INSERT)',
        '05_composite': '5. Composite (DB + Template)',
        '06_heavy_cpu': '6. Heavy CPU / Blocked I/O',
    }

    # ── Summary table ─────────────────────────────────────────
    lines.append('## Summary Comparison')
    lines.append('')
    lines.append('| Scenario | Metric | Razy (FrankenPHP) | Laravel (Octane) | Delta |')
    lines.append('|----------|--------|------------------:|-----------------:|------:|')

    all_scenarios = sorted(set(list(razy_results.keys()) + list(laravel_results.keys())))

    for scenario in all_scenarios:
        label = scenario_labels.get(scenario, scenario)
        razy_runs = razy_results.get(scenario, [])
        laravel_runs = laravel_results.get(scenario, [])

        razy_agg = aggregate_runs(razy_runs) if razy_runs else None
        laravel_agg = aggregate_runs(laravel_runs) if laravel_runs else None

        for metric_key, metric_label, unit in [
            ('rps', 'RPS', ''),
            ('p50', 'p50', 'ms'),
            ('p95', 'p95', 'ms'),
            ('p99', 'p99', 'ms'),
            ('success_rate', 'Success', '%'),
        ]:
            razy_val = format_metric(razy_agg[metric_key]['mean'], unit) if razy_agg and metric_key in razy_agg else 'N/A'
            laravel_val = format_metric(laravel_agg[metric_key]['mean'], unit) if laravel_agg and metric_key in laravel_agg else 'N/A'

            # Calculate delta
            delta = ''
            if razy_agg and laravel_agg and metric_key in razy_agg and metric_key in laravel_agg:
                r = razy_agg[metric_key]['mean']
                l = laravel_agg[metric_key]['mean']
                if l != 0:
                    if metric_key == 'rps':
                        # Higher is better for RPS
                        pct = ((r - l) / l) * 100
                        delta = f'+{pct:.1f}%' if pct > 0 else f'{pct:.1f}%'
                    elif metric_key == 'success_rate':
                        delta = f'{r - l:+.1f}pp'
                    else:
                        # Lower is better for latency
                        pct = ((l - r) / l) * 100
                        delta = f'+{pct:.1f}%' if pct > 0 else f'{pct:.1f}%'

            row_label = f'**{label}**' if metric_key == 'rps' else ''
            lines.append(f'| {row_label} | {metric_label} | {razy_val} | {laravel_val} | {delta} |')

        lines.append(f'| | | | | |')

    lines.append('')

    # ── Detailed per-scenario sections ────────────────────────
    lines.append('## Detailed Results')
    lines.append('')

    for scenario in all_scenarios:
        label = scenario_labels.get(scenario, scenario)
        lines.append(f'### {label}')
        lines.append('')

        for target_name, runs in [('Razy', razy_results.get(scenario, [])),
                                   ('Laravel', laravel_results.get(scenario, []))]:
            if not runs:
                lines.append(f'**{target_name}:** No data collected')
                lines.append('')
                continue

            agg = aggregate_runs(runs)
            lines.append(f'**{target_name}** ({len(runs)} runs):')
            lines.append('')
            lines.append(f'| Metric | Mean | Std Dev | Min | Max |')
            lines.append(f'|--------|-----:|--------:|----:|----:|')

            for key, label_m, unit in [
                ('rps', 'RPS', ''),
                ('total_reqs', 'Total Requests', ''),
                ('avg', 'Avg Latency', 'ms'),
                ('p50', 'p50 Latency', 'ms'),
                ('p90', 'p90 Latency', 'ms'),
                ('p95', 'p95 Latency', 'ms'),
                ('p99', 'p99 Latency', 'ms'),
                ('min', 'Min Latency', 'ms'),
                ('max', 'Max Latency', 'ms'),
                ('success_rate', 'Success Rate', '%'),
            ]:
                if key in agg:
                    a = agg[key]
                    lines.append(
                        f'| {label_m} '
                        f'| {format_metric(a["mean"], unit)} '
                        f'| ±{format_metric(a["stddev"], unit)} '
                        f'| {format_metric(a["min"], unit)} '
                        f'| {format_metric(a["max"], unit)} |'
                    )

            lines.append('')

    # ── Analysis notes ────────────────────────────────────────
    lines.append('## Analysis Guidelines')
    lines.append('')
    lines.append('- **Static route delta** reveals pure framework dispatch overhead.')
    lines.append('- **p95/p99 ratio** shows tail-latency stability under load.')
    lines.append('- If RPS is high but memory spikes, the throughput is not sustainable.')
    lines.append('- Compare memory/CPU from `metrics/` logs alongside these numbers.')
    lines.append('- Heavy CPU scenario: check if fast requests degrade when heavy runs concurrently.')
    lines.append('')
    lines.append('## Reproduction')
    lines.append('')
    lines.append('```bash')
    lines.append('cd benchmark')
    lines.append('docker compose up -d')
    lines.append('./scripts/run-all.sh razy localhost:8081 3')
    lines.append('./scripts/run-all.sh laravel localhost:8082 3')
    lines.append('python3 scripts/generate-report.py --output REPORT.md')
    lines.append('```')

    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser(description='Generate benchmark comparison report')
    parser.add_argument('--results-dir', default='benchmark/results',
                        help='Root results directory')
    parser.add_argument('--output', default='benchmark/REPORT.md',
                        help='Output Markdown file path')
    args = parser.parse_args()

    results_root = Path(args.results_dir)

    print(f'Collecting results from {results_root} ...')
    razy_results = collect_results(results_root / 'razy')
    laravel_results = collect_results(results_root / 'laravel')

    if not razy_results and not laravel_results:
        print('No results found. Run benchmarks first:')
        print('  ./benchmark/scripts/run-all.sh razy localhost:8081 3')
        print('  ./benchmark/scripts/run-all.sh laravel localhost:8082 3')
        sys.exit(1)

    print(f'  Razy scenarios:   {list(razy_results.keys())}')
    print(f'  Laravel scenarios: {list(laravel_results.keys())}')

    report = generate_report(razy_results, laravel_results)

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(report)

    print(f'Report written to {output_path}')


if __name__ == '__main__':
    main()
