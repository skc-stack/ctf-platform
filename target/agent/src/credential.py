"""Device credential storage.

The plain device token is stored at /var/lib/ctf-agent/device.json with mode 0600.
We deliberately do NOT store anything sensitive in config.json — the device
token can rotate (e.g., admin revoke + re-activate), so it lives separately.

The credential is loaded into memory once at startup and used by the API client.
"""
from __future__ import annotations

import json
import os
import stat
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Optional


class CredentialError(Exception):
    """Raised when device credentials are missing or unreadable."""


@dataclass
class Credential:
    """Persisted device credentials.

    device_id is the DB primary key from the CTF Server.
    device_uuid is what the Server returns on activate — used for X-Device-ID header.
    device_token is the plain Bearer token (only this side ever sees it).
    """
    device_id: int
    device_uuid: str
    device_token: str
    activated_at: str            # ISO 8601

    @classmethod
    def load(cls, path: str) -> "Credential":
        if not os.path.isfile(path):
            raise CredentialError(f"device credential not found at {path}. Activate first.")
        try:
            with open(path, "r", encoding="utf-8") as f:
                data = json.load(f)
        except (OSError, json.JSONDecodeError) as e:
            raise CredentialError(f"Failed to read credential at {path}: {e}") from e
        required = ("device_id", "device_uuid", "device_token", "activated_at")
        for k in required:
            if k not in data:
                raise CredentialError(f"Credential at {path} missing field: {k}")
        return cls(**data)

    def save(self, path: str) -> None:
        """Save to disk with restrictive permissions (0600).

        On Linux: writes atomically via tmp+rename, then chmods to 0600.
        On Windows: writes atomically but does not chmod (no POSIX perms).
        """
        target = Path(path)
        target.parent.mkdir(parents=True, exist_ok=True)
        tmp = target.with_suffix(target.suffix + ".tmp")
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(asdict(self), f, indent=2)
            f.write("\n")
        os.replace(tmp, target)
        # Restrict permissions — root should be the only reader.
        if os.name == "posix":
            os.chmod(target, stat.S_IRUSR | stat.S_IWUSR)  # 0600

    @classmethod
    def exists(cls, path: str) -> bool:
        return os.path.isfile(path)

    @classmethod
    def delete(cls, path: str) -> None:
        if os.path.isfile(path):
            os.remove(path)
