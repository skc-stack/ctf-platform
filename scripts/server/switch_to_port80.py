#!/usr/bin/env python3
"""Switch Apache httpd.conf to serve CTF Server on port 80 only.

Edits:
- DocumentRoot → C:/Users/ai/CTF/ctf-server/public
- <Directory>    → C:/Users/ai/CTF/ctf-server/public
- Remove old CTF-Server-only <Directory> block (line 551-556)
- Remove VirtualHost *:8080 block (line 557-570)
- Add Alias /pma → C:/Users/ai/CTF/pma
"""
import re, sys

path = r'C:\Apache24\conf\httpd.conf'
with open(path, 'r', encoding='utf-8') as f:
    src = f.read()

orig = src

# 1. Change DocumentRoot
src = src.replace(
    'DocumentRoot "C:/Users/ai/CTF"',
    'DocumentRoot "C:/Users/ai/CTF/ctf-server/public"',
    1,
)

# 2. Change the main <Directory> (only the first occurrence)
src = src.replace(
    '<Directory "C:/Users/ai/CTF">',
    '<Directory "C:/Users/ai/CTF/ctf-server/public">',
    1,
)

# 3. Remove the standalone CTF routing <Directory> block (the one we appended earlier)
src = re.sub(
    r'\n# === CTF Server routing ===\n<Directory "C:/Users/ai/CTF/ctf-server/public">\n    Options -Indexes \+FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n',
    '\n',
    src,
    count=1,
)

# 4. Remove the entire 8080 vhost block
src = re.sub(
    r'\n# === CTF Server vhost \(port 8080\) ===\n# Use http://localhost:8080/ to access CTF Server directly\.\n# Keeps DocumentRoot on port 80 for pma/, DVWA/, etc\.\nListen 8080\n<VirtualHost \*:8080>\n    DocumentRoot "C:/Users/ai/CTF/ctf-server/public"\n    <Directory "C:/Users/ai/CTF/ctf-server/public">\n        Options -Indexes \+FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n    ErrorLog "C:/Apache24/logs/ctf-server-error.log"\n    CustomLog "C:/Apache24/logs/ctf-server-access.log" combined\n</VirtualHost>\n',
    '\n',
    src,
    count=1,
)

# 5. Append Alias for /pma (so phpMyAdmin keeps working from /pma/)
alias_block = (
    '\n# === Aliases (paths outside DocumentRoot) ===\n'
    'Alias /pma "C:/Users/ai/CTF/pma"\n'
    '<Directory "C:/Users/ai/CTF/pma">\n'
    '    Options -Indexes +FollowSymLinks\n'
    '    AllowOverride All\n'
    '    Require all granted\n'
    '</Directory>\n'
)
if 'Alias /pma' not in src:
    src = src.rstrip() + '\n' + alias_block

if src == orig:
    print("No changes made (patterns not found). Aborting.")
    sys.exit(1)

with open(path, 'w', encoding='utf-8') as f:
    f.write(src)
print("OK — httpd.conf updated.")
