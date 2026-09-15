"""Tests for credential persistence + atomic write + permission tightening."""
from __future__ import annotations

import json
import os
import stat

import pytest

from src.credential import Credential, CredentialError


@pytest.fixture
def cred_path(tmp_path):
    return str(tmp_path / "device.json")


def _sample_credential() -> Credential:
    return Credential(
        device_id=42,
        device_uuid="11111111-2222-3333-4444-555555555555",
        device_token="deadbeef" * 8,
        activated_at="2026-09-14T10:00:00Z",
    )


def test_save_then_load_roundtrip(cred_path):
    c = _sample_credential()
    c.save(cred_path)
    assert os.path.isfile(cred_path)
    loaded = Credential.load(cred_path)
    assert loaded.device_id == 42
    assert loaded.device_uuid == "11111111-2222-3333-4444-555555555555"
    assert loaded.device_token == c.device_token


def test_load_missing_file(tmp_path):
    with pytest.raises(CredentialError, match="not found"):
        Credential.load(str(tmp_path / "absent.json"))


def test_load_corrupt_file(tmp_path):
    p = tmp_path / "device.json"
    p.write_text("not json{{", encoding="utf-8")
    with pytest.raises(CredentialError, match="Failed to read"):
        Credential.load(str(p))


def test_load_missing_field(tmp_path):
    p = tmp_path / "device.json"
    p.write_text(json.dumps({"device_id": 1}), encoding="utf-8")
    with pytest.raises(CredentialError, match="missing field"):
        Credential.load(str(p))


def test_save_creates_parent_directory(tmp_path):
    nested = tmp_path / "deep" / "down" / "device.json"
    c = _sample_credential()
    c.save(str(nested))
    assert os.path.isfile(str(nested))


def test_save_is_atomic_via_tmp_then_rename(cred_path):
    """Saving must not leave a .tmp behind on success."""
    c = _sample_credential()
    c.save(cred_path)
    assert os.path.isfile(cred_path)
    assert not os.path.isfile(cred_path + ".tmp")


def test_save_applies_0600_on_posix(cred_path):
    """On POSIX systems, the file must end up with 0600 permissions."""
    if os.name != "posix":
        pytest.skip("POSIX-only test")
    c = _sample_credential()
    c.save(cred_path)
    mode = stat.S_IMODE(os.stat(cred_path).st_mode)
    assert mode == 0o600


def test_delete_removes_file(cred_path):
    c = _sample_credential()
    c.save(cred_path)
    assert Credential.exists(cred_path) is True
    Credential.delete(cred_path)
    assert Credential.exists(cred_path) is False


def test_exists_returns_false_for_missing(tmp_path):
    assert Credential.exists(str(tmp_path / "nope.json")) is False
