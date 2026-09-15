#!/usr/bin/env python3
"""Enable AllowOverride All on DocumentRoot's <Directory> block.

The block at line 258 was inherited from the original Apache sample,
still saying AllowOverride None and Options Indexes FollowSymLinks.
We want:
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
"""
import re, sys

path = r'C:\Apache24\conf\httpd.conf'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

orig = src

# Within the <Directory "C:/Users/ai/CTF/ctf-server/public"> block,
# flip Options and AllowOverride.
block_pattern = re.compile(
    r'(<Directory "C:/Users/ai/CTF/ctf-server/public">)(.*?)(</Directory>)',
    re.DOTALL,
)
def repl(m):
    body = m.group(2)
    body = re.sub(r'^\s*Options\s+Indexes\s+FollowSymLinks\s*$',
                  '    Options -Indexes +FollowSymLinks', body, flags=re.M)
    body = re.sub(r'^\s*AllowOverride\s+None\s*$',
                  '    AllowOverride All', body, flags=re.M)
    return m.group(1) + body + m.group(3)

src2 = block_pattern.sub(repl, src, count=1)
if src2 == src:
    print("Pattern not found, aborting.")
    sys.exit(1)
src = src2

with open(path, 'w', encoding='utf-8') as f:
    f.write(src)
print("OK — AllowOverride All set on DocumentRoot directory.")
