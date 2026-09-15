"""Manifest.json parser + schema validation for challenge packages.

A challenge ZIP must contain a `manifest.json` at the root. The manifest
describes the challenge's identity, version, entrypoint, and (optional)
reset semantics. Server-side `src/Services/ChallengeService` (to be added)
parses the same manifest from a different angle — this Python validator
is the second line of defense.

Schema (v1):
{
  "schema_version": 1,
  "challenge_id": "DEMO-001",        # must match the Server-side slug
  "version": 1,                       # positive int, monotonic per challenge
  "type": "web",                      # web | crypto | reverse | pwn | forensic | misc | network
  "difficulty": "easy",               # easy | medium | hard | expert
  "title": "Demo: hidden in HTML",
  "entrypoint": "/challenge/DEMO-001/",
  "verification": {
    "type": "flag",                   # flag | automatic
    "flag_static": "flag{...}",        # ONLY for verification_type=flag
    "automatic": {                    # ONLY for verification_type=automatic
      "script": "verifier/check.sh"
    }
  },
  "database": {                       # optional
    "name": "ctf_demo_001",
    "setup_sql": "setup.sql"
  },
  "reset": {                          # optional
    "drop_and_recreate_db": true,
    "restore_files": ["web/"]
  }
}
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional


SUPPORTED_SCHEMA_VERSIONS = (1,)
ALLOWED_TYPES = ("web", "crypto", "reverse", "pwn", "forensic", "misc", "network")
ALLOWED_DIFFICULTIES = ("easy", "medium", "hard", "expert")
ALLOWED_VERIFICATION_TYPES = ("flag", "automatic")


class ManifestError(Exception):
    """Raised when a manifest is missing or fails validation."""


@dataclass
class VerificationSpec:
    type: str
    flag_static: Optional[str] = None
    automatic_script: Optional[str] = None


@dataclass
class DatabaseSpec:
    name: str
    setup_sql: str


@dataclass
class ResetSpec:
    drop_and_recreate_db: bool = False
    restore_files: list = field(default_factory=list)


@dataclass
class Manifest:
    schema_version: int
    challenge_id: str
    version: int
    type: str
    difficulty: str
    title: str
    entrypoint: str
    verification: VerificationSpec
    database: Optional[DatabaseSpec] = None
    reset: Optional[ResetSpec] = None
    description: str = ""

    @classmethod
    def load(cls, path: str) -> "Manifest":
        """Load + validate a manifest.json from disk."""
        p = Path(path)
        if not p.is_file():
            raise ManifestError(f"manifest not found: {path}")
        try:
            with open(p, "r", encoding="utf-8") as f:
                data = json.load(f)
        except (OSError, json.JSONDecodeError) as e:
            raise ManifestError(f"failed to read manifest: {e}") from e
        return cls.from_dict(data)

    @classmethod
    def from_dict(cls, data: dict) -> "Manifest":
        """Validate and convert a parsed JSON object."""
        if not isinstance(data, dict):
            raise ManifestError("manifest must be a JSON object")

        # Required scalar fields
        schema_version = data.get("schema_version")
        if schema_version not in SUPPORTED_SCHEMA_VERSIONS:
            raise ManifestError(
                f"unsupported schema_version {schema_version!r} "
                f"(supported: {SUPPORTED_SCHEMA_VERSIONS})"
            )
        challenge_id = _require_str(data, "challenge_id", max_len=190)
        version = _require_int(data, "version", min_val=1)
        ctype = _require_str(data, "type", max_len=20)
        if ctype not in ALLOWED_TYPES:
            raise ManifestError(f"invalid type {ctype!r}")
        difficulty = data.get("difficulty", "easy")
        if difficulty not in ALLOWED_DIFFICULTIES:
            raise ManifestError(f"invalid difficulty {difficulty!r}")
        title = _require_str(data, "title", max_len=190)
        entrypoint = _require_str(data, "entrypoint", max_len=500)

        # Verification block
        ver_raw = data.get("verification")
        if not isinstance(ver_raw, dict):
            raise ManifestError("verification block required")
        vtype = ver_raw.get("type")
        if vtype not in ALLOWED_VERIFICATION_TYPES:
            raise ManifestError(f"invalid verification.type {vtype!r}")
        verification = VerificationSpec(
            type=vtype,
            flag_static=ver_raw.get("flag_static"),
            automatic_script=ver_raw.get("automatic", {}).get("script") if isinstance(ver_raw.get("automatic"), dict) else None,
        )
        if verification.type == "flag" and not verification.flag_static:
            raise ManifestError("verification.type=flag requires flag_static")
        if verification.type == "automatic" and not verification.automatic_script:
            raise ManifestError("verification.type=automatic requires automatic.script")

        # Optional database block
        database = None
        if "database" in data:
            db = data["database"]
            if not isinstance(db, dict):
                raise ManifestError("database must be an object")
            database = DatabaseSpec(
                name=_require_str(db, "name", max_len=64),
                setup_sql=_require_str(db, "setup_sql", max_len=200),
            )

        # Optional reset block
        reset = None
        if "reset" in data:
            r = data["reset"]
            if not isinstance(r, dict):
                raise ManifestError("reset must be an object")
            reset = ResetSpec(
                drop_and_recreate_db=bool(r.get("drop_and_recreate_db", False)),
                restore_files=list(r.get("restore_files", [])),
            )

        description = str(data.get("description", ""))

        return cls(
            schema_version=schema_version,
            challenge_id=challenge_id,
            version=version,
            type=ctype,
            difficulty=difficulty,
            title=title,
            entrypoint=entrypoint,
            verification=verification,
            database=database,
            reset=reset,
            description=description,
        )


def _require_str(d: dict, key: str, max_len: int) -> str:
    v = d.get(key)
    if not isinstance(v, str) or not v:
        raise ManifestError(f"{key!r} required (non-empty string)")
    if len(v) > max_len:
        raise ManifestError(f"{key!r} too long ({len(v)} > {max_len})")
    return v


def _require_int(d: dict, key: str, min_val: int = 1) -> int:
    v = d.get(key)
    if not isinstance(v, int) or isinstance(v, bool) or v < min_val:
        raise ManifestError(f"{key!r} required (int >= {min_val})")
    return v
