"""Configuration loader for CTF Target Agent.

Reads from JSON config at /etc/ctf-agent/config.json (Linux deploy)
or a fallback path during local development.
"""
from __future__ import annotations

import json
import os
import sys
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Optional


# Standard Linux paths used in production deployment.
DEFAULT_CONFIG_PATH = "/etc/ctf-agent/config.json"
DEFAULT_CREDENTIAL_PATH = "/var/lib/ctf-agent/device.json"
DEFAULT_CHALLENGE_ROOT = "/srv/ctf/challenges"
DEFAULT_LOG_PATH = "/var/log/ctf-agent/agent.log"

# Windows / dev fallback.
WINDOWS_FALLBACK_DIR = os.path.join(os.path.expanduser("~"), ".ctf-agent-dev")


def _windows_fallback_paths():
    """Fallback paths for local dev on Windows (where /etc and /var don't exist)."""
    base = Path(WINDOWS_FALLBACK_DIR)
    return {
        "config_path": str(base / "config.json"),
        "credential_path": str(base / "device.json"),
        "challenge_root": str(base / "challenges"),
        "log_path": str(base / "agent.log"),
    }


def _platform_default_paths() -> dict:
    """Return a dict of all default paths for the current platform.

    Keys: config_path, credential_path, challenge_root, log_path.
    """
    if sys.platform == "win32":
        return _windows_fallback_paths()
    return {
        "config_path": DEFAULT_CONFIG_PATH,
        "credential_path": DEFAULT_CREDENTIAL_PATH,
        "challenge_root": DEFAULT_CHALLENGE_ROOT,
        "log_path": DEFAULT_LOG_PATH,
    }


def _platform_default(key: str) -> str:
    """Return the OS-appropriate default path for the given key.

    Accepts either short names (`config`, `credential`, `challenge_root`, `log`)
    or long names (`config_path`, `credential_path`, ...) — they all map to the
    same four paths.
    """
    paths = _platform_default_paths()
    short_to_long = {
        "config": "config_path",
        "credential": "credential_path",
        "challenge_root": "challenge_root",
        "log": "log_path",
    }
    key = short_to_long.get(key, key)
    return paths[key]


@dataclass
class Config:
    """Agent configuration loaded from JSON.

    Required fields (server_url, agent_version, target_version) must
    be provided. Optional fields use OS-appropriate defaults.
    """
    server_url: str                          # e.g. https://ctf.lab.local
    agent_version: str = "0.1.0"
    target_version: str = "ubuntu-24.04"

    # Optional / path overrides
    config_path: str = field(default_factory=lambda: _platform_default("config"))
    credential_path: str = field(default_factory=lambda: _platform_default("credential"))
    challenge_root: str = field(default_factory=lambda: _platform_default("challenge_root"))
    log_path: str = field(default_factory=lambda: _platform_default("log"))

    # Network behavior
    request_timeout_sec: int = 30
    sync_interval_min: int = 5

    @classmethod
    def load(cls, path: Optional[str] = None) -> "Config":
        """Load config from a JSON file.

        On missing file, creates a sample config file at the default path so the
        user has something to edit, then raises FileNotFoundError with a clear
        message.
        """
        config_path = path or _platform_default("config")
        if not os.path.isfile(config_path):
            cls._write_sample(config_path)
            raise FileNotFoundError(
                f"Config not found at {config_path}. A sample has been written there — "
                "edit it (set server_url) and re-run."
            )
        with open(config_path, "r", encoding="utf-8") as f:
            data = json.load(f)
        # Provide platform defaults for any path field the user did not set.
        for k in ("config_path", "credential_path", "challenge_root", "log_path"):
            data.setdefault(k, _platform_default(k))
        # Pin the just-loaded path back into the dataclass.
        data["config_path"] = config_path
        return cls(**data)

    @staticmethod
    def _write_sample(path: str) -> None:
        """Write a sample config to disk so the user has something to edit."""
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        sample = {
            "server_url": "https://ctf.lab.local",
            "agent_version": "0.1.0",
            "target_version": "ubuntu-24.04",
            "request_timeout_sec": 30,
            "sync_interval_min": 5,
            "_comment": (
                "device_id and device_token are NOT here — they live in credential_path "
                "(/var/lib/ctf-agent/device.json) and are written by the activation flow."
            ),
        }
        with open(path, "w", encoding="utf-8") as f:
            json.dump(sample, f, indent=2)
            f.write("\n")

    def to_dict(self) -> dict:
        return asdict(self)
