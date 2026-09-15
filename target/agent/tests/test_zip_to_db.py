"""Tests for the install pipeline (no live DB — uses a mock LocalDB).

We stub out local_db.connect so installer.install can run end-to-end
without needing a real MariaDB. The DB-touching methods are exercised
in test_installer_db_integration.py if a DB is available, otherwise we
rely on these mocked tests to cover the orchestration logic.
"""
from __future__ import annotations

import hashlib
import json
import zipfile
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from src.installer import Installer, InstallError
from src.local_db import LocalDBConfig
from src.manifest import Manifest


def _make_zip(path: Path, *, files: dict[str, str]) -> Path:
    """files maps archive name → content. Add manifest.json automatically."""
    archive = path / "challenge.zip"
    full = dict(files)
    full.setdefault("manifest.json", json.dumps({
        "schema_version": 1,
        "challenge_id": "DEMO-001",
        "version": 1,
        "type": "web",
        "difficulty": "easy",
        "title": "Demo",
        "entrypoint": "/challenge/DEMO-001/",
        "verification": {"type": "flag", "flag_static": "flag{abc}"},
    }))
    with zipfile.ZipFile(archive, "w") as zf:
        for name, content in full.items():
            zf.writestr(name, content)
    return archive


def test_verify_sha256():
    data = b"hello world"
    assert Installer.verify_sha256(data) == hashlib.sha256(data).hexdigest()


def test_install_succeeds_with_matching_sha(tmp_path):
    """Full pipeline runs; DB calls are mocked."""
    zip_path = _make_zip(tmp_path, files={"hello.txt": "world"})
    zip_bytes = zip_path.read_bytes()
    expected_sha = hashlib.sha256(zip_bytes).hexdigest()

    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))

    # Mock out local_db.connect so we don't need a real DB.
    fake_conn = MagicMock()
    fake_conn.__enter__ = lambda s: fake_conn
    fake_conn.__exit__ = lambda s, *a: None

    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"):
        result = installer.install(
            zip_bytes=zip_bytes,
            expected_sha256=expected_sha,
            expected_challenge_id="DEMO-001",
            expected_version=1,
        )

    assert result.challenge_id == "DEMO-001"
    assert result.version == 1
    install_path = Path(result.install_path)
    assert (install_path / "hello.txt").read_text() == "world"
    assert (install_path / "manifest.json").is_file()


def test_install_fails_on_sha_mismatch(tmp_path):
    zip_path = _make_zip(tmp_path, files={})
    zip_bytes = zip_path.read_bytes()
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    with patch("src.installer.connect"), patch("src.installer.create_database"):
        with pytest.raises(InstallError, match="sha256 mismatch"):
            installer.install(
                zip_bytes=zip_bytes,
                expected_sha256="0" * 64,
                expected_challenge_id="DEMO-001",
                expected_version=1,
            )


def test_install_fails_on_challenge_id_mismatch(tmp_path):
    """Manifest's challenge_id differs from what the Server said."""
    zip_path = tmp_path / "challenge.zip"
    with zipfile.ZipFile(zip_path, "w") as zf:
        zf.writestr("manifest.json", json.dumps({
            "schema_version": 1, "challenge_id": "WRONG-ID", "version": 1,
            "type": "web", "difficulty": "easy", "title": "x",
            "entrypoint": "/x/", "verification": {"type": "flag", "flag_static": "flag{x}"},
        }))
    zip_bytes = zip_path.read_bytes()
    sha = hashlib.sha256(zip_bytes).hexdigest()
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    with patch("src.installer.connect"), patch("src.installer.create_database"):
        with pytest.raises(InstallError, match="does not match expected"):
            installer.install(zip_bytes, sha, "DEMO-001", 1)


def test_install_fails_on_version_mismatch(tmp_path):
    zip_path = tmp_path / "challenge.zip"
    with zipfile.ZipFile(zip_path, "w") as zf:
        zf.writestr("manifest.json", json.dumps({
            "schema_version": 1, "challenge_id": "DEMO-001", "version": 99,
            "type": "web", "difficulty": "easy", "title": "x",
            "entrypoint": "/x/", "verification": {"type": "flag", "flag_static": "flag{x}"},
        }))
    zip_bytes = zip_path.read_bytes()
    sha = hashlib.sha256(zip_bytes).hexdigest()
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    with patch("src.installer.connect"), patch("src.installer.create_database"):
        with pytest.raises(InstallError, match="manifest.version"):
            installer.install(zip_bytes, sha, "DEMO-001", 1)


def test_install_cleans_up_tmp_zip(tmp_path):
    zip_path = _make_zip(tmp_path, files={})
    zip_bytes = zip_path.read_bytes()
    expected_sha = hashlib.sha256(zip_bytes).hexdigest()
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    fake_conn = MagicMock()
    fake_conn.__enter__ = lambda s: fake_conn
    fake_conn.__exit__ = lambda s, *a: None
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"):
        installer.install(zip_bytes, expected_sha, "DEMO-001", 1)
    # No leftover .tmp or stray zips in .tmp/
    tmp_dir = tmp_path / "challenges" / ".tmp"
    leftovers = list(tmp_dir.glob("*.zip"))
    assert leftovers == []


def test_install_replaces_previous_version(tmp_path):
    """Installing v2 must remove v1's files (only latest version on disk)."""
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    fake_conn = MagicMock()
    fake_conn.__enter__ = lambda s: fake_conn
    fake_conn.__exit__ = lambda s, *a: None

    # Install v1 first
    zip_path1 = _make_zip(tmp_path, files={"v1.txt": "one"})
    zip_bytes1 = zip_path1.read_bytes()
    sha1 = hashlib.sha256(zip_bytes1).hexdigest()
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"):
        installer.install(zip_bytes1, sha1, "DEMO-001", 1)
    install_path = tmp_path / "challenges" / "DEMO-001"
    assert (install_path / "v1.txt").exists()

    # Now install v2 — manifest version differs.
    zip_path2 = tmp_path / "challenge2.zip"
    with zipfile.ZipFile(zip_path2, "w") as zf:
        zf.writestr("manifest.json", json.dumps({
            "schema_version": 1, "challenge_id": "DEMO-001", "version": 2,
            "type": "web", "difficulty": "easy", "title": "x",
            "entrypoint": "/x/", "verification": {"type": "flag", "flag_static": "flag{x}"},
        }))
        zf.writestr("v2.txt", "two")
    zip_bytes2 = zip_path2.read_bytes()
    sha2 = hashlib.sha256(zip_bytes2).hexdigest()
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"):
        installer.install(zip_bytes2, sha2, "DEMO-001", 2)
    assert (install_path / "v2.txt").exists()
    assert not (install_path / "v1.txt").exists()  # v1 wiped
