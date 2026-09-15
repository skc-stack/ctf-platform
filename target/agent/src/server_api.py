"""Server API client.

Wraps the HTTPS calls the Agent makes to CTF Server using its device token.
Kept thin so it can be mocked in tests — every method takes/returns plain
Python types, no global state beyond the configured Credential.

All requests include:
  - Authorization: Bearer <device_token>
  - X-Device-ID: <device_uuid>
  - User-Agent: ctf-agent/<agent_version> target/<target_version>
"""
from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Optional
from urllib.parse import urljoin

import requests

from .config import Config
from .credential import Credential


@dataclass
class ServerResponse:
    """Lightweight wrapper to keep test mocking ergonomic."""
    status_code: int
    body: dict          # parsed JSON or {} on non-JSON
    raw: bytes          # raw response body for debugging

    @property
    def ok(self) -> bool:
        return 200 <= self.status_code < 300


class ServerAPIError(Exception):
    """Raised when the Server returns a non-2xx response we cannot recover from."""

    def __init__(self, status_code: int, message: str):
        self.status_code = status_code
        self.message = message
        super().__init__(f"Server {status_code}: {message}")


class ServerAPI:
    """Stateless client for the CTF Server Device API."""

    def __init__(self, config: Config, credential: Credential):
        self.config = config
        self.credential = credential
        self._session = requests.Session()
        self._session.headers.update(self._base_headers())

    # ----- public API methods -----

    def info(self) -> ServerResponse:
        return self._get("/api/v1/device/info")

    def heartbeat(self) -> ServerResponse:
        return self._post("/api/v1/device/heartbeat", {
            "agent_version": self.config.agent_version,
            "target_version": self.config.target_version,
        })

    def list_challenges(self) -> ServerResponse:
        """GET /api/v1/device/challenges — list of challenges for this device."""
        return self._get("/api/v1/device/challenges")

    def download_challenge(self, challenge_id: int) -> ServerResponse:
        """GET /api/v1/device/challenges/{id}/download — returns ZIP bytes."""
        return self._get(f"/api/v1/device/challenges/{challenge_id}/download",
                         return_raw=True)

    def validate_task(self, task_token: str) -> ServerResponse:
        """POST /api/v1/device/task/validate — bind device to task and get entrypoint."""
        return self._post("/api/v1/device/task/validate", {"task_token": task_token})

    def complete_task(self, task_token: str, ok: bool, detail: str = "") -> ServerResponse:
        """POST /api/v1/device/task/complete — report automatic verification result.

        ok=True means the local verifier passed; the server will re-verify
        and award points. We never tell the server the score — only success.
        """
        return self._post("/api/v1/device/task/complete", {
            "task_token": task_token,
            "ok": ok,
            "detail": detail,
        })

    # ----- internal -----

    def _base_headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self.credential.device_token}",
            "X-Device-ID": self.credential.device_uuid,
            "User-Agent": f"ctf-agent/{self.config.agent_version} "
                          f"target/{self.config.target_version}",
            "Accept": "application/json",
        }

    def _url(self, path: str) -> str:
        return urljoin(self.config.server_url.rstrip("/") + "/", path.lstrip("/"))

    def _get(self, path: str, *, return_raw: bool = False) -> ServerResponse:
        url = self._url(path)
        try:
            r = self._session.get(url, timeout=self.config.request_timeout_sec)
        except requests.RequestException as e:
            raise ServerAPIError(0, f"network error: {e}") from e
        return self._wrap(r, return_raw=return_raw)

    def _post(self, path: str, payload: dict) -> ServerResponse:
        url = self._url(path)
        try:
            r = self._session.post(url, json=payload,
                                   timeout=self.config.request_timeout_sec)
        except requests.RequestException as e:
            raise ServerAPIError(0, f"network error: {e}") from e
        return self._wrap(r)

    @staticmethod
    def _wrap(r: requests.Response, return_raw: bool = False) -> ServerResponse:
        body: dict = {}
        raw = r.content or b""
        if r.headers.get("Content-Type", "").startswith("application/json") and raw:
            try:
                parsed = json.loads(raw.decode("utf-8"))
                if isinstance(parsed, dict):
                    body = parsed
            except (ValueError, UnicodeDecodeError):
                pass
        if return_raw:
            return ServerResponse(status_code=r.status_code, body=body, raw=raw)
        # Non-2xx: surface the error message from the JSON body if present.
        if not (200 <= r.status_code < 300):
            msg = body.get("error") or r.reason or "unknown"
            raise ServerAPIError(r.status_code, msg)
        return ServerResponse(status_code=r.status_code, body=body, raw=raw)
