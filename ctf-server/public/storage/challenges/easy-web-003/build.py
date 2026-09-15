#!/usr/bin/env python3
"""challenge-example/build.py — package DEMO-001 into a ZIP.

Cross-platform: works on Windows / macOS / Linux without depending on
the system `zip` command. Run:

    python3 build.py

Output: dist/DEMO-001.zip
"""
from __future__ import annotations

import os
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SRC = ROOT / "DEMO-001"
DIST = ROOT / "dist"
ZIP = DIST / "DEMO-001.zip"


def main() -> int:
    if not SRC.is_dir():
        print(f"ERROR: {SRC} not found", file=sys.stderr)
        return 1
    DIST.mkdir(exist_ok=True)

    with zipfile.ZipFile(ZIP, "w", zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(SRC):
            # Don't recurse into dist/ if it lives inside DEMO-001 for some reason.
            dirs[:] = [d for d in dirs if d != "dist"]
            for fn in files:
                full = Path(root) / fn
                arc = full.relative_to(SRC).as_posix()
                zf.write(full, arc)
                print(f"  + {arc}")

    print(f"Wrote {ZIP}")
    print(f"  size = {ZIP.stat().st_size} bytes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
