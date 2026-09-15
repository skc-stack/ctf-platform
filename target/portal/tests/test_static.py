"""Static security + lint tests for the Target Portal.

Run: python3 -m pytest tests/ -v

These tests are Windows/Linux portable. They do NOT start Apache or talk
to the Server / Agent — they just enforce properties of the source tree.
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

import pytest


PORTAL_ROOT = Path(__file__).resolve().parent.parent
SRC_DIRS = [PORTAL_ROOT / "src", PORTAL_ROOT / "public", PORTAL_ROOT / "views"]


def _all_php_files() -> list[Path]:
    files: list[Path] = []
    for d in SRC_DIRS:
        if d.is_dir():
            files.extend(p for p in d.rglob("*.php") if p.is_file())
    return files


def _php_lint(path: Path) -> tuple[bool, str]:
    """Return (ok, stderr). Skip if php isn't installed (e.g. dev Windows)."""
    php = shutil.which("php")
    if php is None:
        return True, "php not installed; lint skipped"
    proc = subprocess.run([php, "-l", str(path)], capture_output=True, text=True)
    return (proc.returncode == 0), (proc.stdout + proc.stderr)


# ----- All PHP files must parse ----------------------------------------------------

def test_all_php_files_parse_cleanly():
    files = _all_php_files()
    assert files, "no PHP files found under src/, public/, views/"
    failures = []
    for p in files:
        ok, msg = _php_lint(p)
        if not ok:
            failures.append(f"{p.relative_to(PORTAL_ROOT)}: {msg.strip()}")
    assert not failures, "PHP lint failures:\n" + "\n".join(failures)


# ----- No banned function calls anywhere in source ------------------------------

BANNED = ("shell_exec", "system", "passthru", "proc_open", "popen", "exec")
# Match function-name followed by optional whitespace and an opening paren,
# excluding:
#   - comments (//, /*, #)  — handled by stripping lines starting with //
#   - docblocks (/** ... */)
#   - string literals — we don't parse PHP, so we do a coarse allow-list:
#       the only allowed occurrence of "exec" is in $disabledFnRegex / docs
#       that explain WHY it's banned. We treat each match on its own line
#       and only flag when the function is clearly being called.
ALLOWED_CONTEXT_FILES = {
    # Apache config legitimately mentions these strings to disable them.
    PORTAL_ROOT / "apache" / "ctf-portal.conf",
}


def _strip_php_comments(text: str) -> str:
    """Coarsely remove // and /* */ comments so we don't false-positive on docs."""
    out_lines = []
    in_block = False
    for line in text.splitlines():
        if in_block:
            if "*/" in line:
                in_block = False
                line = line.split("*/", 1)[1]
            else:
                continue
        # Strip block openers mid-line
        if "/*" in line:
            head, _, rest = line.partition("/*")
            if "*/" in rest:
                _, _, rest2 = rest.partition("*/")
                line = head + rest2
            else:
                line = head
                in_block = True
        # Strip // line comments (very coarse — doesn't handle 'https://' in mid-line)
        if "//" in line and not line.lstrip().startswith("/*"):
            # Allow // inside regex patterns? skip if before "http" or "schema"
            if "://" not in line:
                line = line.split("//", 1)[0]
        out_lines.append(line)
    return "\n".join(out_lines)


def test_no_banned_function_calls_in_portal_source():
    """Portal source MUST NOT call shell_exec/system/exec/etc.

    Apache config IS allowed to name these strings (it disables them).
    Tests/docs mentioning them in plain prose (in a comment-like context)
    are also allowed as long as no call site is present.
    """
    issues = []
    for p in _all_php_files():
        text = p.read_text(encoding="utf-8", errors="replace")
        cleaned = _strip_php_comments(text)
        for fn in BANNED:
            # Match fn(...) with whitespace and possibly type hints.
            # Require a "(" immediately after the name to avoid matching e.g. "exec".
            # in a doc comment. We've stripped comments, so a remaining match is real code.
            pattern = re.compile(rf"\b{re.escape(fn)}\s*\(")
            for m in pattern.finditer(cleaned):
                snippet = cleaned[max(0, m.start() - 20):m.end() + 20].replace("\n", " ")
                issues.append(f"{p.relative_to(PORTAL_ROOT)}: calls {fn}(...) at: ...{snippet}...")
    assert not issues, "Banned function calls detected:\n" + "\n".join(issues)


# ----- No backtick execution, no eval() ----------------------------------------

def test_no_backtick_or_eval_in_portal_source():
    issues = []
    for p in _all_php_files():
        text = p.read_text(encoding="utf-8", errors="replace")
        cleaned = _strip_php_comments(text)
        if re.search(r"\beval\s*\(", cleaned):
            issues.append(f"{p.relative_to(PORTAL_ROOT)}: uses eval()")
        # Backtick shell exec. Coarse: only flag in non-doc files.
        # We do NOT strip strings, so a comment that contains a backtick may match.
        # In practice, Portal source has no backticks.
        if "`" in cleaned and "echo `" not in cleaned:
            # Allow backticks in heredoc-like / template literal syntax if any.
            # Portal uses neither, so this is a strict check.
            for line in cleaned.splitlines():
                if "`" in line and not line.strip().startswith("#"):
                    issues.append(f"{p.relative_to(PORTAL_ROOT)}: backtick in: {line.strip()}")
                    break
    assert not issues, "eval/backtick detected:\n" + "\n".join(issues)


# ----- Front controller rejects non-loopback -----------------------------------

def test_front_controller_rejects_non_loopback():
    idx = (PORTAL_ROOT / "public" / "index.php").read_text(encoding="utf-8")
    # Must check REMOTE_ADDR against loopback
    assert "127.0.0.1" in idx and "REMOTE_ADDR" in idx, \
        "index.php must check REMOTE_ADDR against loopback"
    # Must call http_response_code(403)
    assert "http_response_code(403)" in idx, \
        "index.php must return 403 for non-loopback"


# ----- Apache vhost has disable_functions and loopback-only binding -------------

def test_apache_vhost_has_disable_functions_and_loopback_only():
    conf = (PORTAL_ROOT / "apache" / "ctf-portal.conf").read_text(encoding="utf-8")
    assert "127.0.0.1:80" in conf, "vhost must bind to 127.0.0.1:80"
    for fn in BANNED:
        assert fn in conf, f"vhost must disable {fn}"
    assert "open_basedir" in conf, "vhost must set open_basedir"
    assert "Require ip 127.0.0.0/8" in conf or "Require ip 127.0.0.0/8 ::1" in conf, \
        "vhost must scope <Location /> to loopback"


# ----- Routes covered -----------------------------------------------------------

def test_routes_match_documented_table():
    """Routes in public/index.php must match the documented table in README.md."""
    idx = (PORTAL_ROOT / "public" / "index.php").read_text(encoding="utf-8")
    expected = [
        ("GET", "/"),
        ("GET", "/activate"),
        ("POST", "/activate"),
        ("POST", "/sync"),
        ("GET", "/task"),
        ("POST", "/task"),
        ("POST", "/reset"),
    ]
    for method, path in expected:
        # Look for $router->get('/path', ...) or ->post(...)
        m = re.search(rf'\$router->{method.lower()}\s*\(\s*["\']{re.escape(path)}["\']', idx)
        assert m, f"missing route {method} {path}"


# ----- Agent URL is hardcoded to 127.0.0.1 --------------------------------------

def test_agent_client_uses_loopback_only():
    ac = (PORTAL_ROOT / "src" / "AgentClient.php").read_text(encoding="utf-8")
    assert "127.0.0.1:8787" in ac, "AgentClient must use 127.0.0.1:8787 by default"


# ----- No FLAG_MASTER_SECRET or server-side secrets ----------------------------

def test_no_server_secrets_in_portal():
    """Portal must never see FLAG_MASTER_SECRET or server DB credentials."""
    for p in _all_php_files():
        text = p.read_text(encoding="utf-8", errors="replace").lower()
        assert "flag_master_secret" not in text, f"{p.name}: FLAG_MASTER_SECRET leaked"
        assert "ctf_server_app" not in text, f"{p.name}: server DB user leaked"
        assert "db_password" not in text, f"{p.name}: DB password key leaked"
        assert "private_key" not in text, f"{p.name}: private key reference leaked"
