"""Syncer: periodic pull of challenge list from Server.

Systemd timer triggers this every 5 minutes (configurable). It compares the
Server's list against `ctf_target.installed_challenges` and:
  - Installs new challenges (not in local table).
  - Re-installs when the Server-side version > local version.
  - Leaves unchanged entries alone (no unnecessary re-installs).

We do NOT auto-delete challenges when they disappear from the Server —
the local install_path is preserved until the student manually removes it,
so a transient Server-side hiccup never bricks a challenge.
"""
from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

from .config import Config
from .credential import Credential
from .installer import Installer, InstallError
from .local_db import LocalDBConfig, connect
from .server_api import ServerAPI


log = logging.getLogger("ctf-agent.syncer")


@dataclass
class SyncReport:
    installed: list[str]      # challenge_ids that were newly installed
    updated: list[str]        # challenge_ids that were upgraded to a newer version
    skipped: list[str]        # challenge_ids already up-to-date
    failed: list[str]         # challenge_ids whose install failed

    def __str__(self) -> str:
        return (
            f"SyncReport(installed={len(self.installed)}, "
            f"updated={len(self.updated)}, "
            f"skipped={len(self.skipped)}, "
            f"failed={len(self.failed)})"
        )


class Syncer:
    def __init__(self, config: Config, credential: Credential,
                 db_config: LocalDBConfig, installer: Installer):
        self.config = config
        self.credential = credential
        self.db_config = db_config
        self.installer = installer
        self.api = ServerAPI(config, credential)

    def sync_once(self) -> SyncReport:
        """Run one sync cycle. Called by systemd timer and CLI."""
        report = SyncReport(installed=[], updated=[], skipped=[], failed=[])
        try:
            resp = self.api.list_challenges()
        except Exception as e:
            log.error("list_challenges failed: %s", e)
            report.failed.append("<list_challenges>")
            return report

        challenges = resp.body.get("data", {}).get("challenges", [])
        if not isinstance(challenges, list):
            log.warning("unexpected challenges payload shape")
            return report

        for entry in challenges:
            cid = entry.get("challenge_id")
            version = entry.get("version")
            sha256 = entry.get("sha256")
            if not (cid and version and sha256):
                log.warning("challenge entry missing fields: %r", entry)
                continue

            local = self._local_version(cid)
            if local is not None and local >= int(version):
                report.skipped.append(cid)
                continue

            # Need (re-)install.
            try:
                dl = self.api.download_challenge(int(entry["id"]))
                self.installer.install(
                    zip_bytes=dl.raw,
                    expected_sha256=sha256,
                    expected_challenge_id=cid,
                    expected_version=int(version),
                )
                if local is None:
                    report.installed.append(cid)
                else:
                    report.updated.append(cid)
            except (InstallError, Exception) as e:
                log.error("install %s v%s failed: %s", cid, version, e)
                report.failed.append(cid)

        return report

    def _local_version(self, challenge_id: str) -> Optional[int]:
        with connect(self.db_config) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT version FROM installed_challenges WHERE challenge_id = %s",
                    (challenge_id,),
                )
                row = cur.fetchone()
        if row is None:
            return None
        return int(row["version"])
