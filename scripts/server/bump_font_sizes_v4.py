#!/usr/bin/env python3
"""Third bump — focus on the smallest sizes (labels, hints, metadata).

After this:
    14 → 16
    15 → 17
    16 → 18
    17 → 18 (smaller bump — already in body range)
    19 → 20
"""
import re

path = r'C:\Users\ai\CTF\ctf-server\public\assets\css\site.css'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

mapping = [
    (19, 21),
    (17, 18),
    (16, 18),
    (15, 17),
    (14, 16),
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

with open(path, 'w', encoding='utf-8') as f:
    f.write(new)

seen = sorted(set(int(m.group(2)) for m in pattern.finditer(new)))
print(f"Font sizes after third bump: {seen}")
print("OK — small font sizes bumped.")
