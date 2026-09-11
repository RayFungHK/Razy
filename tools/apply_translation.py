#!/usr/bin/env python3
"""
Apply best-effort mapping translations to CROSS-TENANT-PROCESS-FLOW.md
using a mapping file.

This will replace occurrences of mapped Chinese phrases with English.
It writes a backup copy `*.bak` before editing.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'architecture' / 'CROSS-TENANT-PROCESS-FLOW.md'
MAP = ROOT / 'tools' / 'chinese_to_english_map.json'
BACKUP = SRC.with_suffix('.md.bak')

if not SRC.exists():
    print('Source file not found:', SRC)
    raise SystemExit(1)
if not MAP.exists():
    print('Map file not found:', MAP)
    raise SystemExit(1)

with MAP.open('r', encoding='utf-8') as fh:
    mapping = json.load(fh)

# Sort mapping by length of key desc to replace longer phrases first
items = sorted(mapping.items(), key=lambda kv: -len(kv[0]))

original = SRC.read_text(encoding='utf-8')
BACKUP.write_text(original, encoding='utf-8')

modified = original

for cn, en in items:
    if cn in modified:
        modified = modified.replace(cn, en)

if modified == original:
    print('No replacements applied (no mapped phrases found).')
else:
    SRC.write_text(modified, encoding='utf-8')
    print('Applied mapping replacements. Backup at', BACKUP)
