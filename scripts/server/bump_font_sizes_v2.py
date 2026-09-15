#!/usr/bin/env python3
"""Bump only font-size values in site.css (leave padding/margin/border alone).

Mapping (designer-tunable; +2 for most, +4 for big hero/stats):
    10 → 12
    11 → 13
    12 → 14
    13 → 15
    14 → 16
    15 → 17
    16 → 18
    17 → 19
    18 → 20
    22 → 24
    24 → 28
    26 → 30
    28 → 32
    32 → 36
    36 → 42
    40 → 46
    44 → 52
    48 → 58
"""
import re, sys

path = r'C:\Users\ai\CTF\ctf-server\public\assets\css\site.css'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

# Ordered ascending → descending to avoid double-substitution
mapping = [
    (48, 58),
    (44, 52),
    (40, 46),
    (36, 42),
    (32, 36),
    (28, 32),
    (26, 30),
    (24, 28),
    (22, 24),
    (18, 20),
    (17, 19),
    (16, 18),
    (15, 17),
    (14, 16),
    (13, 15),
    (12, 14),
    (11, 13),
    (10, 12),
]

# Only replace `font-size: Npx` (with optional space), leave line-height etc alone.
pattern = re.compile(r'(font-size:\s*)(\d+)px\b')

def repl(m):
    prefix = m.group(1)
    val = int(m.group(2))
    for old, new in mapping:
        if val == old:
            return f"{prefix}{new}px"
    return m.group(0)

new = pattern.sub(repl, src)

# Count replacements
count = sum(1 for _ in pattern.finditer(src))
count_after = sum(1 for _ in pattern.finditer(new))
print(f"Replaced {count - count_after} font-size declarations.")

with open(path, 'w', encoding='utf-8') as f:
    f.write(new)
print("OK — site.css font sizes bumped.")
