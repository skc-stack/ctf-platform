#!/usr/bin/env bash
# challenge-example/build.sh — package DEMO-001 into a ZIP ready for upload.
#
# Run from the repo root:
#   bash challenge-example/build.sh
#
# Output: challenge-example/dist/DEMO-001.zip
#
# Notes:
#   - The ZIP MUST contain `manifest.json` at the root (the Server's
#     ZipValidator rejects anything that doesn't).
#   - Paths inside the ZIP are checked against Zip Slip on extraction.
#     Our build script writes only safe relative paths.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/DEMO-001"
DIST="$ROOT/dist"
ZIP="$DIST/DEMO-001.zip"

if [[ ! -d "$SRC" ]]; then
  echo "ERROR: $SRC not found" >&2
  exit 1
fi
mkdir -p "$DIST"

# Use Python's zipfile (cross-platform) instead of system `zip`, so this
# works on macOS/Windows/Linux the same way.
python3 - <<PYEOF
import os, zipfile, sys
src = r"$SRC"
out = r"$ZIP"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(src):
        # skip dist/
        dirs[:] = [d for d in dirs if d != "dist"]
        for fn in files:
            full = os.path.join(root, fn)
            arc = os.path.relpath(full, src).replace(os.sep, "/")
            zf.write(full, arc)
            print(f"  + {arc}")
print(f"Wrote {out}")
PYEOF

ls -la "$ZIP"
