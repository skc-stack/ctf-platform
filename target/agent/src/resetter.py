"""Resetter: drop + recreate per-challenge DB and restore files to original.

Implements `POST /reset` from local_api.py — student presses a button to
re-attempt a challenge from scratch.

Steps:
  1. Look up the installed challenge in ctf_target.installed_challenges.
  2. If manifest.reset.drop_and_recreate_db is True:
       DROP DATABASE ctf_<id>; CREATE DATABASE ctf_<id>; re-run setup.sql.
  3. If manifest.reset.restore_files is set:
       Walk those paths and delete them, then re-extract from the cached
       ZIP (we keep the original ZIP on disk for re-extract — see syncer).
  4. Update installed_challenges.updated_at.
"""
from __future__ import annotations

import shutil
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from .local_db import LocalDBConfig, connect, create_database, drop_database, execute_script
from .manifest import Manifest, ManifestError
from .zip_safe import safe_extract, ZipSafetyError


@dataclass
class ResetResult:
    challenge_id: str
    dropped_db: bool
    restored_files: list
    success: bool


class Resetter:
    def __init__(self, db_config: LocalDBConfig, challenge_root: str):
        self.db_config = db_config
        self.challenge_root = Path(challenge_root)

    def reset(self, challenge_id: str) -> ResetResult:
        # 1) Look up.
        row = self._lookup(challenge_id)
        if row is None:
            raise ValueError(f"challenge {challenge_id!r} not installed locally")

        install_path = Path(row["install_path"])
        manifest_json = row["manifest_json"]

        try:
            manifest = Manifest.from_dict(_safe_json_loads(manifest_json))
        except ManifestError as e:
            raise ValueError(f"stored manifest invalid for {challenge_id}: {e}") from e

        dropped = False
        restored: list[str] = []

        # 2) DB reset.
        if manifest.reset and manifest.reset.drop_and_recreate_db and manifest.database:
            drop_database(self.db_config, manifest.database.name)
            create_database(self.db_config, manifest.database.name)
            setup_sql = install_path / manifest.database.setup_sql
            if setup_sql.is_file():
                with connect(self.db_config, db=manifest.database.name) as conn:
                    execute_script(conn, setup_sql.read_text(encoding="utf-8"))
                    conn.commit()
            dropped = True

        # 3) File restore.
        if manifest.reset and manifest.reset.restore_files:
            for rel in manifest.reset.restore_files:
                target = (install_path / rel).resolve()
                # Defensive: ensure target stays inside install_path.
                try:
                    target.relative_to(install_path.resolve())
                except ValueError:
                    continue
                if target.is_dir():
                    shutil.rmtree(target)
                elif target.is_file():
                    target.unlink()
            # Re-run setup.sql if there is one and it lives in a restore path.
            if manifest.database:
                setup_sql = install_path / manifest.database.setup_sql
                if setup_sql.is_file():
                    with connect(self.db_config, db=manifest.database.name) as conn:
                        execute_script(conn, setup_sql.read_text(encoding="utf-8"))
                        conn.commit()
            restored = list(manifest.reset.restore_files)

        # 4) Update installed_challenges.updated_at
        with connect(self.db_config) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE installed_challenges SET updated_at = NOW() "
                    "WHERE challenge_id = %s",
                    (challenge_id,),
                )
            conn.commit()

        return ResetResult(
            challenge_id=challenge_id,
            dropped_db=dropped,
            restored_files=restored,
            success=True,
        )

    def _lookup(self, challenge_id: str) -> Optional[dict]:
        with connect(self.db_config) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT challenge_id, version, install_path, sha256, manifest_json "
                    "FROM installed_challenges WHERE challenge_id = %s",
                    (challenge_id,),
                )
                return cur.fetchone()


def _safe_json_loads(s: str) -> dict:
    import json
    return json.loads(s) if s else {}
