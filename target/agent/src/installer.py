"""Installer: download → sha256 → safe unzip → manifest parse → setup.sql → register.

End-to-end pipeline that takes a Server-returned ZIP and turns it into a
working challenge on disk + in the local DB. Called by syncer.py for new
or updated challenges.

Steps (each one a separate method so tests can exercise them individually):
  1. fetch_zip()         — download bytes from Server
  2. verify_sha256()     — hash and compare against expected
  3. write_to_tmp()      — write to {challenge_root}/.tmp/{uuid}.zip (atomic rename)
  4. safe_extract()      — unpack under {challenge_root}/{challenge_id}/{version}/
  5. parse_manifest()    — load manifest.json from the extracted root
  6. run_setup_sql()     — execute manifest.database.setup_sql against ctf_<id>
  7. register_install()  — record in ctf_target.installed_challenges
"""
from __future__ import annotations

import hashlib
import os
import shutil
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from .local_db import LocalDBConfig, connect, create_database, execute_script
from .manifest import Manifest, ManifestError
from .zip_safe import ZipSafetyError, safe_extract


@dataclass
class InstallResult:
    challenge_id: str
    version: int
    install_path: str
    manifest: Manifest


class InstallError(Exception):
    """Raised when any step of the install pipeline fails."""


class Installer:
    def __init__(self, db_config: LocalDBConfig, challenge_root: str):
        self.db_config = db_config
        self.challenge_root = Path(challenge_root)

    # --- top-level entry point ---

    def install(self, zip_bytes: bytes, expected_sha256: str, expected_challenge_id: str,
                expected_version: int) -> InstallResult:
        """Full install pipeline.

        Raises InstallError on any failure.
        """
        # 1) Verify sha256 before we write anything to disk.
        actual_sha = self.verify_sha256(zip_bytes)
        if actual_sha.lower() != expected_sha256.lower():
            raise InstallError(
                f"sha256 mismatch: expected {expected_sha256}, got {actual_sha}"
            )

        # 2) Write to a tmp file inside challenge_root/.tmp/
        tmp_dir = self.challenge_root / ".tmp"
        tmp_dir.mkdir(parents=True, exist_ok=True)
        tmp_zip = tmp_dir / f"{expected_challenge_id}-v{expected_version}-{actual_sha[:12]}.zip"
        tmp_zip.write_bytes(zip_bytes)

        try:
            # 3) Decide final path and safe-extract.
            # Layout: {challenge_root}/{challenge_id}/ — only the latest version
            # lives on disk. Wipe any prior version before extracting.
            install_path = self.challenge_root / expected_challenge_id
            if install_path.exists():
                shutil.rmtree(install_path)

            try:
                safe_extract(str(tmp_zip), str(install_path))
            except ZipSafetyError as e:
                raise InstallError(f"unsafe ZIP entry: {e}") from e

            # 4) Parse manifest.
            manifest_path = install_path / "manifest.json"
            try:
                manifest = Manifest.load(str(manifest_path))
            except ManifestError as e:
                raise InstallError(f"manifest invalid: {e}") from e

            if manifest.challenge_id != expected_challenge_id:
                raise InstallError(
                    f"manifest.challenge_id {manifest.challenge_id!r} "
                    f"does not match expected {expected_challenge_id!r}"
                )
            if manifest.version != expected_version:
                raise InstallError(
                    f"manifest.version {manifest.version} != expected {expected_version}"
                )

            # 5) Run setup.sql against the per-challenge DB.
            if manifest.database:
                self._run_setup_sql(install_path, manifest)

            # 6) Register in the Agent's local DB.
            self._register_install(manifest, str(install_path), actual_sha)

            return InstallResult(
                challenge_id=expected_challenge_id,
                version=expected_version,
                install_path=str(install_path),
                manifest=manifest,
            )
        finally:
            # Always clean up the tmp zip.
            if tmp_zip.exists():
                try:
                    tmp_zip.unlink()
                except OSError:
                    pass

    # --- individual steps (also exposed for tests) ---

    @staticmethod
    def verify_sha256(data: bytes) -> str:
        return hashlib.sha256(data).hexdigest()

    def _run_setup_sql(self, install_path: Path, manifest: Manifest) -> None:
        assert manifest.database is not None
        db_name = manifest.database.name
        sql_path = install_path / manifest.database.setup_sql
        if not sql_path.is_file():
            raise InstallError(
                f"manifest declares database.setup_sql={manifest.database.setup_sql!r} "
                f"but file not found in package"
            )
        sql_text = sql_path.read_text(encoding="utf-8")
        # Create the DB first, then connect into it and run the script.
        create_database(self.db_config, db_name)
        with connect(self.db_config, db=db_name) as conn:
            execute_script(conn, sql_text)
            conn.commit()

    def _register_install(self, manifest: Manifest, install_path: str, sha256: str) -> None:
        """Upsert into ctf_target.installed_challenges.

        Table schema (created by install.sh on Linux; we CREATE IF NOT EXISTS
        here so dev environments work without manual setup):
        """
        with connect(self.db_config) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    "CREATE TABLE IF NOT EXISTS installed_challenges ("
                    "  challenge_id VARCHAR(190) NOT NULL PRIMARY KEY,"
                    "  version INT UNSIGNED NOT NULL,"
                    "  install_path VARCHAR(500) NOT NULL,"
                    "  sha256 CHAR(64) NOT NULL,"
                    "  manifest_json LONGTEXT NOT NULL,"
                    "  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                    "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP "
                    "    ON UPDATE CURRENT_TIMESTAMP"
                    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                )
                cur.execute(
                    "REPLACE INTO installed_challenges "
                    "  (challenge_id, version, install_path, sha256, manifest_json) "
                    "VALUES (%s, %s, %s, %s, %s)",
                    (manifest.challenge_id, manifest.version, install_path, sha256,
                     _manifest_to_json(manifest)),
                )
            conn.commit()


def _manifest_to_json(m: Manifest) -> str:
    import json
    return json.dumps({
        "schema_version": m.schema_version,
        "challenge_id": m.challenge_id,
        "version": m.version,
        "type": m.type,
        "difficulty": m.difficulty,
        "title": m.title,
        "entrypoint": m.entrypoint,
        "description": m.description,
        "verification_type": m.verification.type,
    }, ensure_ascii=False)
