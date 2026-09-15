"""Tests for the syncer — new install, version update, skip up-to-date."""
from __future__ import annotations

import hashlib
import json
import zipfile
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from src.config import Config
from src.credential import Credential
from src.installer import Installer
from src.local_db import LocalDBConfig
from src.server_api import ServerResponse
from src.syncer import Syncer


def _make_zip(path: Path, *, version: int) -> bytes:
    p = path / f"v{version}.zip"
    with zipfile.ZipFile(p, "w") as zf:
        zf.writestr("manifest.json", json.dumps({
            "schema_version": 1,
            "challenge_id": "DEMO-001",
            "version": version,
            "type": "web",
            "difficulty": "easy",
            "title": f"v{version}",
            "entrypoint": f"/challenge/DEMO-001/v{version}/",
            "verification": {"type": "flag", "flag_static": f"flag{{v{version}}}"},
        }))
    return p.read_bytes()


def _stub_config(tmp_path: Path) -> Config:
    cfg_file = tmp_path / "config.json"
    cfg_file.write_text(json.dumps({
        "server_url": "https://ctf.test",
        "agent_version": "0.1.0",
        "target_version": "test",
    }), encoding="utf-8")
    return Config.load(str(cfg_file))


def _stub_credential() -> Credential:
    return Credential(
        device_id=1,
        device_uuid="00000000-0000-0000-0000-000000000001",
        device_token="x" * 32,
        activated_at="2026-09-14T00:00:00Z",
    )


def _stub_api(list_challenges: list, downloads: dict[int, bytes]) -> MagicMock:
    api = MagicMock()
    api.list_challenges.return_value = ServerResponse(
        status_code=200,
        body={"data": {"challenges": list_challenges}},
        raw=b"",
    )

    def _download(cid):
        return ServerResponse(
            status_code=200,
            body={},
            raw=downloads[int(cid)],
        )
    api.download_challenge.side_effect = _download
    return api


def _make_fake_db(local_version: int | None = 0) -> MagicMock:
    """Build a fake connection whose SELECT returns the given local version.

    A MagicMock cursor's fetchone() returns a MagicMock by default; we override
    that so _local_version() reads back an actual integer.
    """
    fake_cursor = MagicMock()
    fake_cursor.fetchone.return_value = (
        {"version": local_version} if local_version is not None else None
    )
    fake_conn = MagicMock()
    fake_conn.cursor.return_value.__enter__ = lambda s: fake_cursor
    fake_conn.cursor.return_value.__exit__ = lambda s, *a: None
    fake_conn.__enter__ = lambda s: fake_conn
    fake_conn.__exit__ = lambda s, *a: None
    return fake_conn


def test_sync_installs_new_challenge(tmp_path):
    cfg = _stub_config(tmp_path)
    cred = _stub_credential()
    zip_v1 = _make_zip(tmp_path, version=1)
    sha_v1 = hashlib.sha256(zip_v1).hexdigest()

    api = _stub_api(
        list_challenges=[
            {"id": 1, "challenge_id": "DEMO-001", "version": 1, "sha256": sha_v1},
        ],
        downloads={1: zip_v1},
    )
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))

    # local_version=None means "not installed yet"
    fake_conn = _make_fake_db(local_version=None)

    syncer = Syncer(cfg, cred, db_cfg, installer)
    syncer.api = api

    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        report = syncer.sync_once()

    assert report.installed == ["DEMO-001"]
    assert report.skipped == []
    assert report.failed == []
    assert (tmp_path / "challenges" / "DEMO-001" / "manifest.json").is_file()


def test_sync_skips_up_to_date(tmp_path):
    cfg = _stub_config(tmp_path)
    cred = _stub_credential()
    db_cfg = LocalDBConfig()

    zip_v1 = _make_zip(tmp_path, version=1)
    sha_v1 = hashlib.sha256(zip_v1).hexdigest()
    api = _stub_api(
        list_challenges=[{"id": 1, "challenge_id": "DEMO-001", "version": 1, "sha256": sha_v1}],
        downloads={1: zip_v1},
    )
    # First sync: not yet installed (local_version=None).
    fake_conn = _make_fake_db(local_version=None)
    installer = Installer(db_cfg, str(tmp_path / "challenges"))
    syncer = Syncer(cfg, cred, db_cfg, installer)
    syncer.api = api
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        first = syncer.sync_once()
    assert first.installed == ["DEMO-001"]

    # Second sync: same version already on disk (local_version=1).
    fake_conn = _make_fake_db(local_version=1)
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        second = syncer.sync_once()
    assert second.skipped == ["DEMO-001"]
    assert second.installed == []
    # download_challenge called once (only the first sync).
    assert api.download_challenge.call_count == 1


def test_sync_updates_to_new_version(tmp_path):
    cfg = _stub_config(tmp_path)
    cred = _stub_credential()
    db_cfg = LocalDBConfig()
    zip_v1 = _make_zip(tmp_path, version=1)
    sha_v1 = hashlib.sha256(zip_v1).hexdigest()
    zip_v2 = _make_zip(tmp_path, version=2)
    sha_v2 = hashlib.sha256(zip_v2).hexdigest()

    installer = Installer(db_cfg, str(tmp_path / "challenges"))

    # 1st sync: not installed yet, installs v1.
    fake_conn = _make_fake_db(local_version=None)
    api1 = _stub_api(
        list_challenges=[{"id": 1, "challenge_id": "DEMO-001", "version": 1, "sha256": sha_v1}],
        downloads={1: zip_v1},
    )
    syncer = Syncer(cfg, cred, db_cfg, installer)
    syncer.api = api1
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        syncer.sync_once()

    # 2nd sync: local has v1, Server offers v2 → update.
    fake_conn = _make_fake_db(local_version=1)
    api2 = _stub_api(
        list_challenges=[{"id": 1, "challenge_id": "DEMO-001", "version": 2, "sha256": sha_v2}],
        downloads={1: zip_v2},
    )
    syncer.api = api2
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        report = syncer.sync_once()
    assert report.updated == ["DEMO-001"]
    assert report.installed == []
    # v2 manifest should be on disk.
    assert (tmp_path / "challenges" / "DEMO-001" / "manifest.json").is_file()
    # Read manifest to confirm it's the v2 one.
    import json as _json
    manifest = _json.loads((tmp_path / "challenges" / "DEMO-001" / "manifest.json").read_text())
    assert manifest["version"] == 2


def test_sync_reports_failure_on_install_error(tmp_path):
    cfg = _stub_config(tmp_path)
    cred = _stub_credential()
    db_cfg = LocalDBConfig()
    installer = Installer(db_cfg, str(tmp_path / "challenges"))

    # Server-side SHA does NOT match — installer will reject.
    zip_bytes = _make_zip(tmp_path, version=1)
    api = _stub_api(
        list_challenges=[{"id": 1, "challenge_id": "DEMO-001", "version": 1, "sha256": "f" * 64}],
        downloads={1: zip_bytes},
    )
    syncer = Syncer(cfg, cred, db_cfg, installer)
    syncer.api = api
    fake_conn = _make_fake_db(local_version=None)
    with patch("src.installer.connect", return_value=fake_conn), \
         patch("src.installer.create_database"), \
         patch("src.syncer.connect", return_value=fake_conn):
        report = syncer.sync_once()
    assert "DEMO-001" in report.failed
    assert report.installed == []
