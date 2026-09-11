#!/usr/bin/env python3
"""
Extract lines containing CJK characters from the CROSS-TENANT-PROCESS-FLOW.md
file and write a JSON & plain-text file with line numbers and simple context.

Usage:
  python tools/extract_chinese.py

Outputs:
  architecture/CROSS-TENANT-PROCESS-FLOW.chinese_lines.json
  architecture/CROSS-TENANT-PROCESS-FLOW.chinese_lines.txt

This is intended to help batch-translation workflows.
"""
import re
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'architecture' / 'CROSS-TENANT-PROCESS-FLOW.md'
OUT_JSON = ROOT / 'architecture' / 'CROSS-TENANT-PROCESS-FLOW.chinese_lines.json'
OUT_TXT = ROOT / 'architecture' / 'CROSS-TENANT-PROCESS-FLOW.chinese_lines.txt'

CJK_RE = re.compile(r'[\u4e00-\u9fff\u3000-\u303f\uff00-\uffef]')

def extract():
    if not SRC.exists():
        print(f"Source not found: {SRC}")
        return 1

    lines = SRC.read_text(encoding='utf-8').splitlines()
    results = []

    for i, line in enumerate(lines):
        if CJK_RE.search(line):
            prev_line = lines[i-1] if i-1 >= 0 else ''
            next_line = lines[i+1] if i+1 < len(lines) else ''
            results.append({
                'line': i+1,
                'text': line,
                'prev': prev_line,
                'next': next_line,
            })

    # Write JSON
    OUT_JSON.write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding='utf-8')

    # Write human-readable text file
    with OUT_TXT.open('w', encoding='utf-8') as fh:
        for item in results:
            fh.write(f"--- LINE {item['line']} ---\n")
            if item['prev'].strip():
                fh.write(f"PREV: {item['prev']}\n")
            fh.write(f"TEXT: {item['text']}\n")
            if item['next'].strip():
                fh.write(f"NEXT: {item['next']}\n")
            fh.write('\n')

    print(f"Found {len(results)} Chinese-containing lines. Wrote:\n  {OUT_JSON}\n  {OUT_TXT}")
    return 0

if __name__ == '__main__':
    raise SystemExit(extract())
