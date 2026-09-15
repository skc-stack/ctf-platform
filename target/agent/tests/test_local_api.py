"""Tests for the local Flask API — uses Flask's test_client (no live socket)."""
from __future__ import annotations

import json
import tempfile
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from src.config import Config
from src.credential import Credential
from src.local_api import create_app


@pytest.fixture
def tmp_config(tmp_path):
    cfg_path = tmp_path / "config.json"
    cfg_path.write_text(json.dumps({
        "server_url": "https://ctf.test",
        "agent_version": "0.1.0",
        "target_version": "test",
        "credential_path": str(tmp_path / "device.json"),
        "challenge_root": str(tmp_path / "challenges"),
    }), encoding="utf-8")
    return Config.load(str(cfg_path))


@pytest.fixture
def tmp_credential(tmp_config):
    cred_path = tmp_config.credential_path
    cred = Credential(
        device_id=1,
        device_uuid="00000000-0000-0000-0000-000000000001",
        device_token="t" * 32,
        activated_at="2026-09-14T00:00:00Z",
    )
    cred.save(cred_path)
    return cred


@pytest.fixture
def app(tmp_config, tmp_credential):
    return create_app(config=tmp_config, credential=tmp_credential)


@pytest.fixture
def client(app):
    return app.test_client()


# ----- /status -----------------------------------------------------------

def test_status_ok(client):
    r = client.get("/status")
    assert r.status_code == 200
    data = r.get_json()
    assert data["agent_version"] == "0.1.0"
    assert data["credential_present"] is True


def test_status_when_no_credential(tmp_config):
    app = create_app(config=tmp_config, credential=None)
    client = app.test_client()
    r = client.get("/status")
    assert r.status_code == 200
    assert r.get_json()["credential_present"] is False


# ----- /activate ---------------------------------------------------------

def test_activate_missing_code(client):
    r = client.post("/activate", json={})
    assert r.status_code == 400
    assert "required" in r.get_json()["error"]


@patch("src.local_api.ServerAPI")
def test_activate_success_writes_credential(mock_api_cls, tmp_config):
    """Successful activate writes device.json with server-returned token."""
    # Mock the requests.Session inside ServerAPI so we don't hit network.
    mock_session = MagicMock()
    mock_resp = MagicMock()
    mock_resp.status_code = 200
    mock_resp.headers = {"Content-Type": "application/json"}
    mock_resp.content = json.dumps({
        "data": {
            "device_id": 99,
            "device_uuid": "00000000-0000-0000-0000-000000000abc",
            "device_token": "tok" + "x" * 29,
        }
    }).encode()
    mock_resp.json.return_value = json.loads(mock_resp.content.decode())
    mock_session.post.return_value = mock_resp
    mock_api_cls.return_value._session = mock_session

    app = create_app(config=tmp_config, credential=None)
    client = app.test_client()
    r = client.post("/activate", json={
        "activation_code": "ACT-AAAA-BBBB-CCCC",
        "device_uuid": "00000000-0000-0000-0000-000000000abc",
        "device_name": "Test",
    })
    assert r.status_code == 200, r.get_json()
    assert r.get_json()["device_id"] == 99
    # And the credential file was written.
    saved = Credential.load(tmp_config.credential_path)
    assert saved.device_id == 99
    assert saved.device_uuid == "00000000-0000-0000-0000-000000000abc"


# ----- /sync -------------------------------------------------------------

@patch("src.local_api.Syncer")
def test_sync_returns_report(mock_syncer_cls, client):
    mock_report = MagicMock()
    # asdict() is what create_app does to the dataclass.
    from dataclasses import dataclass, field, asdict
    @dataclass
    class FakeReport:
        installed: list = field(default_factory=list)
        updated: list = field(default_factory=list)
        skipped: list = field(default_factory=list)
        failed: list = field(default_factory=list)
    rep = FakeReport(installed=["DEMO-001"])
    mock_syncer_cls.return_value.sync_once.return_value = rep

    r = client.post("/sync")
    assert r.status_code == 200
    body = r.get_json()
    assert body["ok"] is True
    assert body["report"]["installed"] == ["DEMO-001"]


def test_sync_requires_credential(tmp_config):
    app = create_app(config=tmp_config, credential=None)
    client = app.test_client()
    # No credential on disk → 401
    r = client.post("/sync")
    assert r.status_code == 401
    assert "credential not found" in r.get_json()["error"]


# ----- /task -------------------------------------------------------------

@patch("src.local_api.ServerAPI")
def test_task_validates_token_and_returns_entrypoint(mock_api_cls, tmp_config, tmp_credential):
    from src.server_api import ServerResponse
    mock_api_cls.return_value.validate_task.return_value = ServerResponse(
        status_code=200,
        body={"data": {
            "challenge_id": "DEMO-001",
            "entrypoint": "/challenge/DEMO-001/",
            "challenge_version": 1,
        }},
        raw=b"",
    )
    app = create_app(config=tmp_config, credential=tmp_credential)
    client = app.test_client()
    r = client.post("/task", json={"task_token": "TASK-FAKE"})
    assert r.status_code == 200
    body = r.get_json()
    assert body["entrypoint"] == "/challenge/DEMO-001/"


def test_task_requires_token(client):
    r = client.post("/task", json={})
    assert r.status_code == 400


@patch("src.local_api.ServerAPI")
def test_task_forwards_device_mismatch_error(mock_api_cls, tmp_config, tmp_credential):
    """A 403 device_mismatch from Server must bubble up as 403."""
    from src.server_api import ServerAPIError
    mock_api_cls.return_value.validate_task.side_effect = ServerAPIError(
        403, "此 Task 已被綁定到其他裝置"
    )
    app = create_app(config=tmp_config, credential=tmp_credential)
    r = app.test_client().post("/task", json={"task_token": "TASK-AAAA-BBBB-CCCC-DDDD"})
    assert r.status_code == 403
    assert "綁定" in r.get_json()["error"]


@patch("src.local_api.ServerAPI")
def test_task_forwards_unknown_token_error(mock_api_cls, tmp_config, tmp_credential):
    from src.server_api import ServerAPIError
    mock_api_cls.return_value.validate_task.side_effect = ServerAPIError(
        400, "Task token 不存在"
    )
    app = create_app(config=tmp_config, credential=tmp_credential)
    r = app.test_client().post("/task", json={"task_token": "TASK-XXXX-XXXX-XXXX-XXXX"})
    assert r.status_code == 400
    assert "不存在" in r.get_json()["error"]


# ----- /reset ------------------------------------------------------------

@patch("src.local_api.Resetter")
def test_reset_drops_and_returns(mock_resetter_cls, client):
    from dataclasses import dataclass, field, asdict
    @dataclass
    class FakeResult:
        challenge_id: str = "DEMO-001"
        dropped_db: bool = True
        restored_files: list = field(default_factory=lambda: ["web/"])
        success: bool = True
    mock_resetter_cls.return_value.reset.return_value = FakeResult()

    r = client.post("/reset", json={"challenge_id": "DEMO-001"})
    assert r.status_code == 200
    assert r.get_json()["result"]["dropped_db"] is True


def test_reset_requires_challenge_id(client):
    r = client.post("/reset", json={})
    assert r.status_code == 400


@patch("src.local_api.Resetter")
def test_reset_404_when_unknown(mock_resetter_cls, client):
    mock_resetter_cls.return_value.reset.side_effect = ValueError("challenge 'X' not installed locally")
    r = client.post("/reset", json={"challenge_id": "X"})
    assert r.status_code == 404


# ----- /heartbeat --------------------------------------------------------

@patch("src.local_api.ServerAPI")
def test_heartbeat_ok(mock_api_cls, client):
    from src.server_api import ServerResponse
    mock_api_cls.return_value.heartbeat.return_value = ServerResponse(
        status_code=200, body={}, raw=b"",
    )
    r = client.post("/heartbeat")
    assert r.status_code == 200
    assert r.get_json()["ok"] is True
