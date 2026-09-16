"""Local MariaDB helpers for Target VM.

Two scopes:
  - `ctf_target` DB: the Agent's own state (installed_challenges table — to be added)
  - `ctf_<challenge_id>` DBs: one per installed challenge, with their own tables

Connection pool is intentionally simple — the Agent runs as a single
systemd service, so a per-thread connect pattern is fine. For the
challenge DB operations we use a dedicated user with limited privs
(see install.sh for grants).
"""
from __future__ import annotations

import json
import os
from contextlib import contextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, Iterator, Optional

import pymysql
from pymysql.cursors import DictCursor


@dataclass
class LocalDBConfig:
    host: str = "127.0.0.1"
    port: int = 3306
    user: str = "ctf_agent"
    password: str = ""
    # Default DB the Agent uses for its own tables (ctf_target).
    default_db: str = "ctf_target"

    @classmethod
    def load_from_file(cls, path: Optional[str] = None) -> "LocalDBConfig":
        """Load DB config from /etc/ctf-agent/agent_db.json (created by install.sh)."""
        config_path = path or "/etc/ctf-agent/agent_db.json"
        if os.path.isfile(config_path):
            try:
                with open(config_path, "r", encoding="utf-8") as f:
                    data = json.load(f)
                return cls(
                    host=data.get("host", "127.0.0.1"),
                    port=int(data.get("port", 3306)),
                    user=data.get("user", "ctf_agent"),
                    password=data.get("password", ""),
                )
            except (json.JSONDecodeError, ValueError, KeyError):
                pass  # Fall back to defaults
        return cls()


@contextmanager
def connect(cfg: LocalDBConfig, db: Optional[str] = None) -> Iterator[pymysql.connections.Connection]:
    """Open a short-lived connection. Caller commits / rolls back explicitly.

    Use `with connect(cfg) as conn:` and `conn.commit()` / `conn.rollback()`.
    Yields the connection; closes on exit.
    """
    conn = pymysql.connect(
        host=cfg.host,
        port=cfg.port,
        user=cfg.user,
        password=cfg.password,
        database=db or cfg.default_db,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=DictCursor,
    )
    try:
        yield conn
    finally:
        try:
            conn.close()
        except Exception:
            pass


def execute_script(conn: pymysql.connections.Connection, sql_text: str) -> int:
    """Execute a multi-statement SQL script and return total affected row count.

    PyMySQL doesn't auto-handle multi-statement; we split on `;` at end-of-line.
    This is good enough for setup.sql files we author — not for arbitrary user input.
    """
    total = 0
    statements: list[str] = []
    buf: list[str] = []
    for line in sql_text.splitlines():
        stripped = line.strip()
        # Skip pure comments / empty lines.
        if not stripped or stripped.startswith("--"):
            continue
        buf.append(line)
        if stripped.endswith(";"):
            statements.append("\n".join(buf))
            buf = []
    if buf:
        statements.append("\n".join(buf))

    with conn.cursor() as cur:
        for stmt in statements:
            cur.execute(stmt)
            total += cur.rowcount
    return total


def drop_database(cfg: LocalDBConfig, name: str) -> None:
    """Drop a database entirely. Used by resetter."""
    safe = _safe_db_name(name)
    with connect(cfg, db=None) as conn:
        with conn.cursor() as cur:
            cur.execute(f"DROP DATABASE IF EXISTS `{safe}`")
        conn.commit()


def create_database(cfg: LocalDBConfig, name: str) -> None:
    """Create a database if it doesn't exist."""
    safe = _safe_db_name(name)
    with connect(cfg, db=None) as conn:
        with conn.cursor() as cur:
            cur.execute(f"CREATE DATABASE IF NOT EXISTS `{safe}` "
                        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        conn.commit()


def _safe_db_name(name: str) -> str:
    """Defensive: only allow [A-Za-z0-9_]. Anything else raises."""
    if not name or not all(c.isalnum() or c == "_" for c in name):
        raise ValueError(f"invalid db name: {name!r}")
    return name
