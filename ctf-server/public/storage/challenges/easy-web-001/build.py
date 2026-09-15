#!/usr/bin/env python3
"""XSS-REFLECTED/build.py — package the reflective XSS challenge into a ZIP.

Usage:
  python3 build.py <slug>

The Server auto-generates slugs as `{difficulty}-{category}-{NNN}`.
After creating the challenge via the Teacher UI (no ZIP), copy the
auto-slug and pass it here. The script will substitute it into the
manifest and produce a matching ZIP.
"""
from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DIST = ROOT / "dist"
SLUG = sys.argv[1] if len(sys.argv) > 1 else "easy-web-001"

if not re.match(r"^[A-Za-z0-9_-]+$", SLUG):
    print(f"ERROR: invalid slug {SLUG!r}", file=sys.stderr)
    sys.exit(1)


def main() -> int:
    DIST.mkdir(exist_ok=True)
    manifest_path = ROOT / "manifest.json"
    manifest = manifest_path.read_text(encoding="utf-8").replace("{{SLUG}}", SLUG)
    web_dir = ROOT / "web"

    zip_path = DIST / f"{SLUG}.zip"
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("manifest.json", manifest)
        for f in sorted(web_dir.rglob("*")):
            if f.is_file():
                arc = f.relative_to(web_dir).as_posix()
                zf.write(f, f"web/{arc}")
                print(f"  + web/{arc}")
    print(f"Wrote {zip_path}  ({zip_path.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
