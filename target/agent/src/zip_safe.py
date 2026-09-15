"""Safe ZIP extraction with Zip Slip prevention.

Server-side ZipValidator has its PHP counterpart (Server's src/Security/ZipValidator.php).
This is the Python equivalent — used by installer.py to safely unpack downloaded
challenge ZIPs without ever escaping the challenge root.

Threat model:
  - Zip Slip: relative paths like `../../../etc/passwd` resolved by extract.
  - Absolute paths: `/etc/shadow` or `C:\\Windows\\...`.
  - Symlinks: a ZIP entry that is a symlink pointing outside the root.
  - Oversized: a ZIP bomb — limit total uncompressed size.

Defenses:
  - Per-entry path resolution + check that final path stays inside the root.
  - Reject absolute paths outright.
  - Reject symlink entries entirely (no creation).
  - Reject Windows drive-letter paths (`C:`) when running on POSIX, vice versa.
  - Optional: cap total uncompressed bytes.
"""
from __future__ import annotations

import os
import zipfile
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterable, Optional, Tuple


class ZipSafetyError(Exception):
    """Raised when a ZIP entry fails safety checks.

    Attributes:
        entry_name: the offending entry (basename or full path)
        reason: short machine-readable reason
    """
    def __init__(self, entry_name: str, reason: str, detail: str = ""):
        self.entry_name = entry_name
        self.reason = reason
        self.detail = detail
        msg = f"Unsafe ZIP entry {entry_name!r}: {reason}"
        if detail:
            msg += f" — {detail}"
        super().__init__(msg)


@dataclass
class ZipLimits:
    """Safety limits applied during extraction."""
    max_total_uncompressed_bytes: int = 500 * 1024 * 1024     # 500 MB
    max_single_entry_uncompressed_bytes: int = 100 * 1024 * 1024   # 100 MB
    max_entry_count: int = 10_000


def _resolve_inside(root: Path, entry_name: str) -> Path:
    """Resolve `entry_name` against `root` and assert it stays inside.

    Raises ZipSafetyError if the resolved path escapes the root.
    """
    # Reject absolute paths (POSIX or Windows).
    p = PurePosixPath(entry_name)
    if p.is_absolute():
        raise ZipSafetyError(entry_name, "absolute_path")

    # Reject Windows drive letters.
    if len(entry_name) >= 2 and entry_name[1] == ":":
        raise ZipSafetyError(entry_name, "drive_letter")

    # Normalise and join with root, then resolve symlinks-of-parent for the check.
    target = (root / entry_name).resolve()
    root_resolved = root.resolve()
    try:
        target.relative_to(root_resolved)
    except ValueError:
        raise ZipSafetyError(
            entry_name,
            "path_escape",
            f"resolves to {target} outside root {root_resolved}",
        )
    return target


def safe_extract(
    zip_path: str,
    dest_dir: str,
    limits: Optional[ZipLimits] = None,
    *,
    on_progress: Optional[Iterable[int]] = None,
) -> int:
    """Extract a ZIP to `dest_dir` with safety checks.

    Returns the number of entries extracted (regular files only — directories
    are created implicitly).

    Raises ZipSafetyError on the first unsafe entry encountered.
    Raises OSError on disk failure.
    """
    limits = limits or ZipLimits()
    root = Path(dest_dir).resolve()
    root.mkdir(parents=True, exist_ok=True)

    total_uncompressed = 0
    entry_count = 0

    with zipfile.ZipFile(zip_path, "r") as zf:
        # Pre-flight: check entry count + sizes before extracting.
        infos = zf.infolist()
        if len(infos) > limits.max_entry_count:
            raise ZipSafetyError(
                "<zip>",
                "too_many_entries",
                f"{len(infos)} > {limits.max_entry_count}",
            )
        for info in infos:
            entry_count += 1
            # Symlink (external_attr high bytes: 0xA0000000 == symlink file type)
            if (info.external_attr >> 16) & 0o170000 == 0o120000:
                raise ZipSafetyError(info.filename, "symlink")
            # Directory entry: just create it.
            if info.is_dir():
                target = _resolve_inside(root, info.filename)
                target.mkdir(parents=True, exist_ok=True)
                continue
            # File entry: check size + resolve path.
            if info.file_size > limits.max_single_entry_uncompressed_bytes:
                raise ZipSafetyError(
                    info.filename,
                    "entry_too_large",
                    f"{info.file_size} > {limits.max_single_entry_uncompressed_bytes}",
                )
            total_uncompressed += info.file_size
            if total_uncompressed > limits.max_total_uncompressed_bytes:
                raise ZipSafetyError(
                    "<zip>",
                    "total_too_large",
                    f"{total_uncompressed} > {limits.max_total_uncompressed_bytes}",
                )
            target = _resolve_inside(root, info.filename)
            target.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(info, "r") as src, open(target, "wb") as dst:
                # Read in 64 KiB chunks so we don't load huge files in memory.
                while True:
                    chunk = src.read(64 * 1024)
                    if not chunk:
                        break
                    dst.write(chunk)
    return entry_count


def validate_zip(zip_path: str, limits: Optional[ZipLimits] = None) -> Tuple[bool, str]:
    """Validate a ZIP without extracting it.

    Returns (ok, reason_or_empty). Used by tests and pre-flight checks.
    """
    try:
        with zipfile.ZipFile(zip_path, "r") as zf:
            infos = zf.infolist()
            for info in infos:
                if (info.external_attr >> 16) & 0o170000 == 0o120000:
                    return False, f"symlink:{info.filename}"
                try:
                    _resolve_inside(Path("/tmp"), info.filename)
                except ZipSafetyError as e:
                    return False, f"{e.reason}:{e.entry_name}"
            return True, ""
    except zipfile.BadZipFile as e:
        return False, f"bad_zip:{e}"
