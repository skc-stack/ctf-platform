#!/usr/bin/env python3
"""Second bump: +2 to +4 across the board.

Current → new (after first bump):
    12 → 14
    13 → 15
    14 → 16
    15 → 17
    16 → 18
    17 → 19
    18 → 20
    19 → 22
    20 → 22
    22 → 24
    24 → 28
    28 → 32
    32 → 38
    36 → 42
    42 → 48
    52 → 60
    58 → 66
"""
import re, sys

path = r'C:\Users\ai\CTF\ctf-server\public\assets\css\site.css'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

mapping = [
    (66, 72),
    (58, 66),
    (52, 60),
    (42, 48),
    (36, 42),
    (32, 38),
    (28, 32),
    (24, 28),
    (22, 24),
    (20, 22),
    (19, 22),
    (18, 20),
    (17, 19),
    (16, 18),
    (15, 17),
    (14, 16),
    (13, 15),
    (12, 14),
]

pattern = re.compile(r'(font-size:\s*)(\d+)px\b')

def repl(m):
    prefix = m.group(1)
    val = int(m.group(2))
    for old, new in mapping:
        if val == old:
            return f"{prefix}{new}px"
    return m.group(0)

new = pattern.sub(repl, src)

before = sorted(set(int(m.group(2)) for m in pattern.finditer(src)))
after  = sorted(set(int(m.group(2)) for m in pattern.finditer(new)))
print(f"Font sizes seen: {before}")

with open(path, 'w', encoding='utf-8') as f:
    f.write(new)
print("OK — font sizes bumped (second pass).")
