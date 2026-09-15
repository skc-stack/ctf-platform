#!/usr/bin/env python3
"""Bump font sizes throughout site.css for better readability.

Mapping (small → bigger):
    11px → 12px
    12px → 13px
    13px → 14px
    14px → 15px
    15px → 16px
    16px → 18px (form fields, stat numbers, table headers stay)
    18px → 20px
    22px → 24px
    24px → 26px
    28px → 32px
    32px → 36px
    36px → 42px
    40px → 48px
    48px → 56px
    56px → 64px
"""
import re, sys

path = r'C:\Users\ai\CTF\ctf-server\public\assets\css\site.css'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

# Map small → big (process in descending order to avoid double-bumping)
mapping = [
    ('56px', '64px'),
    ('48px', '56px'),
    ('40px', '48px'),
    ('36px', '42px'),
    ('32px', '36px'),
    ('28px', '32px'),
    ('24px', '28px'),
    ('22px', '24px'),
    ('18px', '20px'),
    ('16px', '18px'),
    ('15px', '16px'),
    ('14px', '16px'),  # 14 also bumps to 16
    ('13px', '15px'),
    ('12px', '14px'),
    ('11px', '13px'),
]

for old, new in mapping:
    src = re.sub(rf'\b{re.escape(old)}\b', new, src)

with open(path, 'w', encoding='utf-8') as f:
    f.write(src)
print("OK — font sizes bumped.")
