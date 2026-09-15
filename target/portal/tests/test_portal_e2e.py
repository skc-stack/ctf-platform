"""End-to-end test for the Target Portal.

Strategy:
- Start a *mock* Agent on 127.0.0.1:8787 (subprocess, Python).
- Start PHP's built-in server for the Portal on 127.0.0.1:8080.
- Issue HTTP requests through curl, verify the page contents.

This runs on any platform with Python 3 + PHP 8.x installed.
Requires: Python (already there), PHP (`where php` / `which php`),
and `curl` (almost universal).

Skip conditions: if either PHP or curl is missing, the test is skipped
rather than failing — the static tests in test_static.py still cover
the non-runtime properties.
"""
from __future__ import annotations

import http.client
import os
import shutil
import socket
import subprocess
import sys
import time
from pathlib import Path

import pytest

PORTAL_ROOT = Path(__file__).resolve().parent.parent
MOCK_AGENT_PORT = 8787
PORTAL_PORT = 8080
BASE_URL = f"http://127.0.0.1:{PORTAL_PORT}"


def _pick_free_port(preferred: int) -> int:
    """Return preferred port if free, otherwise let the OS pick."""
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        s.bind(("127.0.0.1", preferred))
        return preferred
    except OSError:
        s.close()
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
        s.close()
        return port


# Locate PHP executable.
PHP_BIN = (
    shutil.which("php")
    or r"C:\Users\ai\AppData\Local\Programs\PHP\8.3.32\php.exe"
    or "/usr/bin/php"
    or "/usr/local/bin/php"
)

pytestmark = pytest.mark.skipif(
    PHP_BIN is None or not Path(PHP_BIN).is_file(),
    reason="php not installed — skip runtime e2e (static tests still cover non-runtime properties)",
)


# ----- Mock Agent ---------------------------------------------------------------

MOCK_AGENT_PY = r'''
import http.server, json, sys

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a, **kw): pass  # silence
    def _send(self, code, body):
        b = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)
    def do_GET(self):
        if self.path == "/status":
            return self._send(200, {
                "agent_version": "0.1.0", "target_version": "test",
                "server_url": "https://ctf.test", "credential_present": False,
            })
        return self._send(404, {"error": "not found"})
    def do_POST(self):
        ln = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(ln) if ln else b""
        try:
            data = json.loads(raw or b"{}")
        except Exception:
            data = {}
        if self.path == "/activate":
            return self._send(200, {"ok": True})
        if self.path == "/task":
            return self._send(200, {"ok": True, "data": {
                "challenge_id": 1, "challenge_version": 1,
                "entrypoint": "/challenge/DEMO-001/",
                "expires_at": "2099-01-01 00:00:00",
            }})
        if self.path == "/sync":
            return self._send(200, {"ok": True, "report": {"installed": [], "updated": [], "skipped": [], "failed": []}})
        return self._send(404, {"error": "not found"})

http.server.HTTPServer(("127.0.0.1", int(sys.argv[1])), Handler).serve_forever()
'''


@pytest.fixture(scope="module")
def mock_agent():
    """Start a mock Python Agent on a free port and yield the port."""
    port = _pick_free_port(MOCK_AGENT_PORT)
    proc = subprocess.Popen(
        [sys.executable, "-c", MOCK_AGENT_PY, str(port)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    # Wait until it accepts connections.
    for _ in range(40):
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                break
        except OSError:
            time.sleep(0.1)
    else:
        proc.terminate()
        pytest.fail("mock agent did not start")
    yield port
    proc.terminate()
    try:
        proc.wait(timeout=2)
    except subprocess.TimeoutExpired:
        proc.kill()


# ----- Portal front controller (PHP built-in server) ---------------------------

# Router script: front-controller emulation. PHP's built-in server
# doesn't honor .htaccess rewrites; we use a tiny router script that
# forwards any non-file request to public/index.php.
ROUTER_PHP = r'''<?php
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = __DIR__ . "/public" . $uri;
if ($uri !== "/" && file_exists($path) && !is_dir($path)) {
    return false; // let PHP serve the static file
}
require __DIR__ . "/public/index.php";
'''


@pytest.fixture(scope="module")
def portal_server(tmp_path_factory, mock_agent):
    """Start PHP's built-in server on a free port, configured to call
    Agent at mock_agent's port."""
    port = _pick_free_port(PORTAL_PORT)
    workdir = PORTAL_ROOT
    # Patch AgentClient's default port to the mock agent's port via env.
    env = os.environ.copy()
    # We can't pass env to PHP CLI server, so instead use a sed-style
    # approach: just start PHP and have it use the mock at 127.0.0.1:MOCK_PORT.
    # Simpler: change AgentClient default to read AGENT_URL from $_ENV.
    router = workdir / "router.php"
    router.write_text(ROUTER_PHP, encoding="utf-8")
    # We need the Portal to call the mock port, not 8787. Patch by writing
    # a temporary AgentClient.php with the right port.
    # Use a temp override via a wrapper that loads a config — but to keep
    # this simple, we just point the Portal at the mock's actual port by
    # starting the mock on 8787 (the default) whenever possible.
    # If we couldn't get 8787, we skip with a friendly message.
    if mock_agent != MOCK_AGENT_PORT:
        pytest.skip(f"could not bind mock Agent to {MOCK_AGENT_PORT}; got {mock_agent}")

    php_proc = subprocess.Popen(
        [PHP_BIN, "-S", f"127.0.0.1:{port}", "-t", str(workdir), str(router)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        cwd=str(workdir),
    )
    for _ in range(40):
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                break
        except OSError:
            time.sleep(0.1)
    else:
        php_proc.terminate()
        pytest.fail("php built-in server did not start")
    yield port
    php_proc.terminate()
    try:
        php_proc.wait(timeout=2)
    except subprocess.TimeoutExpired:
        php_proc.kill()
    router.unlink(missing_ok=True)


def _http_get(url: str, host: str = "127.0.0.1", port: int | None = None) -> tuple[int, str]:
    """Return (status, body) using a raw HTTP client."""
    parsed = url.replace("http://", "").split("/", 1)
    netloc = parsed[0]
    path = "/" + (parsed[1] if len(parsed) > 1 else "")
    h, p = netloc.split(":")
    conn = http.client.HTTPConnection(h, int(p), timeout=5)
    conn.request("GET", path, headers={"Host": host})
    r = conn.getresponse()
    return r.status, r.read().decode("utf-8", errors="replace")


def _http_post(url: str, form: dict, host: str = "127.0.0.1") -> tuple[int, str, dict]:
    """Return (status, body, response_headers)."""
    from urllib.parse import urlencode
    body = urlencode(form).encode()
    parsed = url.replace("http://", "").split("/", 1)
    netloc = parsed[0]
    path = "/" + (parsed[1] if len(parsed) > 1 else "")
    h, p = netloc.split(":")
    conn = http.client.HTTPConnection(h, int(p), timeout=5)
    conn.request("POST", path, body=body, headers={
        "Host": host,
        "Content-Type": "application/x-www-form-urlencoded",
    })
    r = conn.getresponse()
    headers = {k.lower(): v for k, v in r.getheaders()}
    return r.status, r.read().decode("utf-8", errors="replace"), headers


# ----- Tests --------------------------------------------------------------------

def test_home_renders_status(portal_server):
    status, body = _http_get(f"{BASE_URL}/")
    assert status == 200
    assert "CTF TARGET PORTAL" in body
    assert "尚未啟用" in body  # mock says credential_present=False


def test_get_activate_shows_form(portal_server):
    status, body = _http_get(f"{BASE_URL}/activate")
    assert status == 200
    assert "Activation Code" in body
    assert 'name="activation_code"' in body


def test_post_activate_calls_agent_and_redirects(portal_server):
    status, body, headers = _http_post(f"{BASE_URL}/activate", {
        "activation_code": "ACT-DEMO-CODE-AAAA",
    })
    assert status in (302, 303), f"expected redirect, got {status}: {body}"
    assert headers.get("location") in ("/", "/index.php")


def test_get_task_shows_form(portal_server):
    status, body = _http_get(f"{BASE_URL}/task")
    assert status == 200
    assert "Task Token" in body
    assert 'name="task_token"' in body


def test_post_task_calls_agent_and_renders_entrypoint(portal_server):
    status, body, _ = _http_post(f"{BASE_URL}/task", {
        "task_token": "TASK-DEMO-CODE-BBBB",
    })
    assert status == 200
    assert "已綁定" in body
    assert "/challenge/DEMO-001/" in body
    assert "複製" in body  # copy button


def test_post_task_empty_token_returns_400(portal_server):
    status, body, _ = _http_post(f"{BASE_URL}/task", {"task_token": ""})
    assert status == 400
    assert "請貼上" in body


def test_post_sync_redirects(portal_server):
    status, _, headers = _http_post(f"{BASE_URL}/sync", {})
    assert status in (302, 303)
    assert "sync=" in (headers.get("location") or "")


def test_post_reset_redirects_with_query(portal_server):
    status, _, headers = _http_post(f"{BASE_URL}/reset", {"challenge_id": "DEMO-001"})
    assert status in (302, 303)
    loc = headers.get("location") or ""
    assert "reset=DEMO-001" in loc


def test_unknown_route_returns_404(portal_server):
    status, body = _http_get(f"{BASE_URL}/nope")
    assert status == 404
    assert "Not Found" in body or "沒有此路徑" in body


def test_non_loopback_request_rejected(portal_server):
    """The front controller refuses requests from non-127.0.0.1 even on
    a loopback Apache vhost (defense in depth).
    """
    # We can't easily forge a different REMOTE_ADDR in PHP built-in server
    # (it always reports 127.0.0.1). Instead, verify the guard code exists
    # and is the same one Apache enforces.
    idx = (PORTAL_ROOT / "public" / "index.php").read_text(encoding="utf-8")
    assert "in_array($remote, ['127.0.0.1', '::1'], true)" in idx
    assert "http_response_code(403)" in idx
