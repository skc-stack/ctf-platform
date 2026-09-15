"""Tests for zip_safe: Zip Slip prevention, symlink rejection, oversize."""
from __future__ import annotations

import os
import zipfile
from pathlib import Path

import pytest

from src.zip_safe import ZipLimits, ZipSafetyError, safe_extract, validate_zip


@pytest.fixture
def workdir(tmp_path):
    return tmp_path


def _write_normal_zip(path: Path) -> Path:
    """Create a valid ZIP with safe entries: a file + a subdir + nested file."""
    p = path / "ok.zip"
    with zipfile.ZipFile(p, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("hello.txt", "world\n")
        zf.writestr("subdir/inner.txt", "nested\n")
        zf.writestr("manifest.json", '{"k":"v"}')
    return p


def test_safe_extract_normal_zip(workdir):
    zip_path = _write_normal_zip(workdir)
    dest = workdir / "out"
    n = safe_extract(str(zip_path), str(dest))
    assert n == 3
    assert (dest / "hello.txt").read_text() == "world\n"
    assert (dest / "subdir" / "inner.txt").read_text() == "nested\n"


def test_safe_extract_rejects_zip_slip_relative(workdir):
    """An entry '..\\..\\evil.txt' must be rejected."""
    bad = workdir / "slip.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        zf.writestr("../../evil.txt", "pwned")
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"))
    assert exc_info.value.reason in ("path_escape", "absolute_path")


def test_safe_extract_rejects_absolute_posix(workdir):
    """An entry '/etc/passwd' must be rejected."""
    bad = workdir / "abso.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        zf.writestr("/etc/passwd", "pwned")
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"))
    assert exc_info.value.reason == "absolute_path"


def test_safe_extract_rejects_drive_letter(workdir):
    """An entry 'C:\\Windows\\evil.exe' must be rejected."""
    bad = workdir / "drv.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        zf.writestr("C:\\Windows\\evil.exe", "pwned")
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"))
    assert exc_info.value.reason in ("drive_letter", "path_escape")


def test_safe_extract_rejects_symlink(workdir):
    """Symlink entries must be rejected outright."""
    bad = workdir / "sym.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        # Create a symlink entry pointing to /etc.
        info = zipfile.ZipInfo("link")
        info.create_system = 3  # Unix
        info.external_attr = (0o120777 << 16)  # symlink
        zf.writestr(info, "/etc")
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"))
    assert exc_info.value.reason == "symlink"


def test_safe_extract_rejects_oversize_entry(workdir):
    """A single entry exceeding the per-entry limit must be rejected."""
    bad = workdir / "big.zip"
    with zipfile.ZipFile(bad, "w", zipfile.ZIP_STORED) as zf:
        # 1 MB of zeros (STORED so uncompressed_size == file_size)
        zf.writestr("big.bin", b"\x00" * (1024 * 1024))
    limits = ZipLimits(max_single_entry_uncompressed_bytes=512 * 1024)
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"), limits=limits)
    assert exc_info.value.reason == "entry_too_large"


def test_safe_extract_rejects_oversize_total(workdir):
    """Total uncompressed size exceeding the cap must be rejected."""
    bad = workdir / "totalbig.zip"
    with zipfile.ZipFile(bad, "w", zipfile.ZIP_STORED) as zf:
        # Three 200KB files = 600KB total.
        for i in range(3):
            zf.writestr(f"f{i}.bin", b"\x00" * (200 * 1024))
    limits = ZipLimits(max_total_uncompressed_bytes=500 * 1024)
    with pytest.raises(ZipSafetyError) as exc_info:
        safe_extract(str(bad), str(workdir / "out"), limits=limits)
    assert exc_info.value.reason == "total_too_large"


def test_validate_zip_against_clean(workdir):
    """validate_zip returns ok=True for a benign ZIP."""
    zip_path = _write_normal_zip(workdir)
    ok, reason = validate_zip(str(zip_path))
    assert ok is True
    assert reason == ""


def test_validate_zip_against_slip(workdir):
    """validate_zip catches Zip Slip without extracting."""
    bad = workdir / "slip.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        zf.writestr("../../etc/passwd", "x")
    ok, reason = validate_zip(str(bad))
    assert ok is False
    assert "path_escape" in reason or "absolute_path" in reason


def test_safe_extract_does_not_create_root(workdir):
    """Extracting must NOT create files above dest_dir."""
    bad = workdir / "slip2.zip"
    with zipfile.ZipFile(bad, "w") as zf:
        zf.writestr("sub/../../escape.txt", "x")
    dest = workdir / "out"
    with pytest.raises(ZipSafetyError):
        safe_extract(str(bad), str(dest))
    # Ensure no escape file appeared anywhere in the workdir.
    assert not (workdir / "escape.txt").exists()
