"""Local Flask API bound to 127.0.0.1:8787.

Target Portal (PHP, separate process) calls these endpoints to:
  - GET  /status        — service health, server connectivity
  - POST /activate      — paste activation code, write device.json
  - POST /sync          — trigger one syncer cycle, return SyncReport
  - POST /task          — validate a Task Token via Server, return entrypoint
  - POST /reset         — drop + recreate challenge state
  - POST /heartbeat     — forward heartbeat to Server

Bind to 127.0.0.1 ONLY — never 0.0.0.0. The Portal connects over loopback.
"""
from __future__ import annotations

import logging
from dataclasses import asdict
from pathlib import Path
from typing import Optional

from flask import Flask, jsonify, request

from .config import Config, _platform_default
from .credential import Credential, CredentialError
from .installer import Installer, InstallError
from .local_db import LocalDBConfig
from .resetter import Resetter
from .server_api import ServerAPI, ServerAPIError
from .syncer import Syncer


log = logging.getLogger("ctf-agent.local_api")


def create_app(config: Optional[Config] = None,
               credential: Optional[Credential] = None) -> Flask:
    """Build the Flask app. Pass in config + credential for tests; in production
    they are loaded by `start_service()` below.
    """
    app = Flask(__name__)
    cfg = config or Config.load()
    cred = credential

    # ---- /status ---------------------------------------------------------

    @app.get("/status")
    def status():
        info: dict = {
            "agent_version": cfg.agent_version,
            "target_version": cfg.target_version,
            "server_url": cfg.server_url,
            "credential_present": Credential.exists(cfg.credential_path),
        }
        # Try a quick Server ping if we have credentials.
        if cred is not None:
            try:
                resp = ServerAPI(cfg, cred).info()
                info["server_reachable"] = resp.ok
                info["device_id"] = resp.body.get("data", {}).get("device_id")
            except ServerAPIError as e:
                info["server_reachable"] = False
                info["server_error"] = str(e)
        return jsonify(info)

    # ---- /activate -------------------------------------------------------

    @app.post("/activate")
    def activate():
        body = request.get_json(silent=True) or {}
        code = (body.get("activation_code") or "").strip()
        if not code:
            return jsonify({"error": "activation_code required"}), 400
        # The Portal sends the device's UUID; the Agent will pass it to Server.
        device_uuid = (body.get("device_uuid") or "").strip()
        device_name = (body.get("device_name") or "").strip() or "ctf-target"
        try:
            # We can't activate without a Server call — use a one-shot ServerAPI.
            server_api = ServerAPI(cfg, _placeholder_cred(device_uuid))
            import requests
            url = cfg.server_url.rstrip("/") + "/api/v1/device/activate"
            r = server_api._session.post(url, json={
                "activation_code": code,
                "device_uuid": device_uuid,
                "device_name": device_name,
            }, timeout=cfg.request_timeout_sec)
            data = r.json() if r.headers.get("Content-Type", "").startswith("application/json") else {}
            if not (200 <= r.status_code < 300):
                return jsonify({"error": data.get("error", "activate failed")}), r.status_code
            new_cred = Credential(
                device_id=int(data["data"]["device_id"]),
                device_uuid=str(data["data"]["device_uuid"]),
                device_token=str(data["data"]["device_token"]),
                activated_at=_now_iso(),
            )
            new_cred.save(cfg.credential_path)
            return jsonify({"ok": True, "device_id": new_cred.device_id})
        except Exception as e:
            log.exception("activate failed")
            return jsonify({"error": str(e)}), 500

    # ---- /sync -----------------------------------------------------------

    @app.post("/sync")
    def sync():
        c = _require_cred()
        try:
            db = LocalDBConfig.load_from_file()
            installer = Installer(db, cfg.challenge_root)
            syncer = Syncer(cfg, c, db, installer)
            report = syncer.sync_once()
            return jsonify({"ok": True, "report": asdict(report)})
        except Exception as e:
            log.exception("sync failed")
            return jsonify({"error": str(e)}), 500

    # ---- /task -----------------------------------------------------------

    @app.post("/task")
    def task():
        c = _require_cred()
        body = request.get_json(silent=True) or {}
        task_token = (body.get("task_token") or "").strip()
        if not task_token:
            return jsonify({"error": "task_token required"}), 400
        try:
            resp = ServerAPI(cfg, c).validate_task(task_token)
            data = resp.body.get("data", {})
            return jsonify({
                "ok": True,
                "challenge_id": data.get("challenge_id"),
                "entrypoint": data.get("entrypoint"),
                "challenge_version": data.get("challenge_version"),
            })
        except ServerAPIError as e:
            return jsonify({"error": e.message}), e.status_code

    # ---- /reset ----------------------------------------------------------

    @app.post("/reset")
    def reset():
        body = request.get_json(silent=True) or {}
        challenge_id = (body.get("challenge_id") or "").strip()
        if not challenge_id:
            return jsonify({"error": "challenge_id required"}), 400
        try:
            db = LocalDBConfig.load_from_file()
            resetter = Resetter(db, cfg.challenge_root)
            result = resetter.reset(challenge_id)
            return jsonify({"ok": True, "result": asdict(result)})
        except ValueError as e:
            return jsonify({"error": str(e)}), 404
        except Exception as e:
            log.exception("reset failed")
            return jsonify({"error": str(e)}), 500

    # ---- /heartbeat ------------------------------------------------------

    @app.post("/heartbeat")
    def heartbeat():
        c = _require_cred()
        try:
            ServerAPI(cfg, c).heartbeat()
            return jsonify({"ok": True})
        except ServerAPIError as e:
            return jsonify({"error": e.message}), e.status_code

    # ---- helpers ---------------------------------------------------------

    def _require_cred() -> Credential:
        nonlocal cred
        if cred is None:
            try:
                cred = Credential.load(cfg.credential_path)
            except CredentialError as e:
                # 401 so Portal knows to show the activate UI.
                raise _Abort(jsonify({"error": str(e)}), 401)
        return cred

    # Wire the abort helper to Flask's error flow.
    from werkzeug.exceptions import HTTPException
    @app.errorhandler(_Abort)
    def _handle_abort(err: "_Abort"):
        return err.response

    return app


class _Abort(Exception):
    def __init__(self, response, status_code: int):
        self.response = (response, status_code)


def _placeholder_cred(device_uuid: str) -> Credential:
    """A throwaway credential used to build a ServerAPI instance for activation.

    The activate endpoint doesn't need a valid Bearer token (it's the public
    endpoint that exchanges an activation_code for a token). We use empty
    strings so headers are present but harmless.
    """
    return Credential(
        device_id=0,
        device_uuid=device_uuid or "00000000-0000-0000-0000-000000000000",
        device_token="",
        activated_at="",
    )


def _now_iso() -> str:
    from datetime import datetime, timezone
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def start_service(host: str = "127.0.0.1", port: int = 8787) -> None:
    """Production entry: load config + credential, start Flask."""
    cfg = Config.load()
    try:
        cred = Credential.load(cfg.credential_path)
    except CredentialError:
        cred = None  # OK — Portal can still call /status and /activate.
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s %(message)s",
    )
    app = create_app(config=cfg, credential=cred)
    # Werkzeug is fine for an internal 127.0.0.1 service; production should
    # consider gunicorn or uwsgi behind a reverse proxy.
    app.run(host=host, port=port, debug=False, use_reloader=False)


if __name__ == "__main__":
    start_service()
