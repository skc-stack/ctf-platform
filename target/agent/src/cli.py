"""CLI entry point — installs as /usr/local/bin/ctf-agent.

Subcommands:
  status     — print local status, server reachability, installed challenges
  sync       — run one sync cycle
  heartbeat  — send one heartbeat
  list       — list installed challenges
  reset ID   — reset challenge ID
  doctor     — self-diagnostics
  activate   — paste an activation code and write credential
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from .config import Config, _platform_default
from .credential import Credential, CredentialError
from .installer import Installer
from .local_db import LocalDBConfig, connect
from .local_api import create_app
from .resetter import Resetter
from .server_api import ServerAPI, ServerAPIError
from .syncer import Syncer


def _cfg() -> Config:
    try:
        return Config.load()
    except FileNotFoundError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)


def _cred_or_exit(cfg: Config) -> Credential:
    try:
        return Credential.load(cfg.credential_path)
    except CredentialError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)


def cmd_status(args, cfg: Config) -> int:
    cred = None
    try:
        cred = Credential.load(cfg.credential_path)
    except CredentialError:
        pass

    info = {
        "agent_version": cfg.agent_version,
        "target_version": cfg.target_version,
        "server_url": cfg.server_url,
        "credential_present": cred is not None,
        "challenge_root": cfg.challenge_root,
    }
    if cred:
        try:
            resp = ServerAPI(cfg, cred).info()
            info["server_reachable"] = resp.ok
            info["device"] = resp.body.get("data", {})
        except ServerAPIError as e:
            info["server_reachable"] = False
            info["server_error"] = e.message

    # List locally installed challenges.
    db = LocalDBConfig.load_from_file()
    try:
        with connect(db) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT challenge_id, version, sha256, install_path, "
                    "       installed_at, updated_at "
                    "FROM installed_challenges ORDER BY challenge_id"
                )
                rows = cur.fetchall()
        info["installed_challenges"] = [
            {
                "challenge_id": r["challenge_id"],
                "version": int(r["version"]),
                "sha256": r["sha256"][:16] + "...",
                "install_path": r["install_path"],
                "installed_at": str(r["installed_at"]),
                "updated_at": str(r["updated_at"]),
            }
            for r in rows
        ]
    except Exception as e:
        info["local_db_error"] = str(e)

    print(json.dumps(info, indent=2, ensure_ascii=False))
    return 0


def cmd_sync(args, cfg: Config) -> int:
    cred = _cred_or_exit(cfg)
    db = LocalDBConfig.load_from_file()
    installer = Installer(db, cfg.challenge_root)
    syncer = Syncer(cfg, cred, db, installer)
    report = syncer.sync_once()
    print(report)
    if report.failed:
        return 1
    return 0


def cmd_heartbeat(args, cfg: Config) -> int:
    cred = _cred_or_exit(cfg)
    try:
        ServerAPI(cfg, cred).heartbeat()
        print("heartbeat sent")
        return 0
    except ServerAPIError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1


def cmd_list(args, cfg: Config) -> int:
    db = LocalDBConfig.load_from_file()
    with connect(db) as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT challenge_id, version, install_path FROM installed_challenges "
                "ORDER BY challenge_id"
            )
            rows = cur.fetchall()
    if not rows:
        print("(no installed challenges)")
        return 0
    for r in rows:
        print(f"{r['challenge_id']:<30} v{int(r['version'])}  {r['install_path']}")
    return 0


def cmd_reset(args, cfg: Config) -> int:
    if not args.challenge_id:
        print("ERROR: challenge_id required", file=sys.stderr)
        return 2
    db = LocalDBConfig.load_from_file()
    resetter = Resetter(db, cfg.challenge_root)
    try:
        result = resetter.reset(args.challenge_id)
        print(json.dumps({
            "challenge_id": result.challenge_id,
            "dropped_db": result.dropped_db,
            "restored_files": result.restored_files,
            "success": result.success,
        }, indent=2, ensure_ascii=False))
        return 0
    except ValueError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1


def cmd_doctor(args, cfg: Config) -> int:
    """Self-diagnostics — exit 0 if everything looks healthy."""
    problems = []
    # Config exists.
    print(f"[ok] config: {cfg.config_path}")
    # Credential dir exists + writable.
    cred_dir = Path(cfg.credential_path).parent
    if not cred_dir.is_dir():
        problems.append(f"credential dir missing: {cred_dir}")
    else:
        print(f"[ok] credential dir: {cred_dir}")
    # Challenge root exists + writable.
    cr = Path(cfg.challenge_root)
    cr.mkdir(parents=True, exist_ok=True)
    print(f"[ok] challenge root: {cr}")
    # DB connectivity.
    try:
        db = LocalDBConfig.load_from_file()
        with connect(db) as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT 1")
                cur.fetchone()
        print("[ok] local MariaDB reachable")
    except Exception as e:
        problems.append(f"DB unreachable: {e}")
    # Server reachability (if credential present).
    try:
        cred = Credential.load(cfg.credential_path)
        ServerAPI(cfg, cred).heartbeat()
        print("[ok] server reachable, heartbeat sent")
    except CredentialError:
        print("[skip] no credential — activate first to test server")
    except ServerAPIError as e:
        problems.append(f"server unreachable: {e}")

    if problems:
        print("\nPROBLEMS:")
        for p in problems:
            print(f"  - {p}")
        return 1
    return 0


def cmd_activate(args, cfg: Config) -> int:
    """Read code from stdin (single line) and call /api/v1/device/activate."""
    print("Paste activation code (e.g. ACT-XXXX-XXXX-XXXX):", file=sys.stderr)
    code = input().strip()
    if not code:
        print("ERROR: empty code", file=sys.stderr)
        return 2
    # The Portal/CLI user gives us a UUID + name; for now use placeholder.
    # Real activation from Portal will use the device's actual UUID.
    device_uuid = args.device_uuid or "00000000-0000-0000-0000-000000000000"
    device_name = args.device_name or "ctf-target"
    # Direct HTTP call (no Credential needed yet).
    import requests
    url = cfg.server_url.rstrip("/") + "/api/v1/device/activate"
    r = requests.post(url, json={
        "activation_code": code,
        "device_uuid": device_uuid,
        "device_name": device_name,
    }, timeout=cfg.request_timeout_sec)
    if not (200 <= r.status_code < 300):
        print(f"ERROR: activate failed ({r.status_code}): {r.text}", file=sys.stderr)
        return 1
    data = r.json().get("data", {})
    cred = Credential(
        device_id=int(data["device_id"]),
        device_uuid=str(data["device_uuid"]),
        device_token=str(data["device_token"]),
        activated_at=_now_iso(),
    )
    cred.save(cfg.credential_path)
    print(f"activated. device_id={cred.device_id}")
    return 0


def _now_iso() -> str:
    from datetime import datetime, timezone
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(prog="ctf-agent")
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("status", help="show local + server status")
    sub.add_parser("sync", help="run one sync cycle")
    sub.add_parser("heartbeat", help="send one heartbeat to server")
    sub.add_parser("list", help="list installed challenges")
    sub.add_parser("doctor", help="self-diagnostics")
    sub.add_parser("activate", help="activate with a code from stdin")

    p_reset = sub.add_parser("reset", help="reset a challenge")
    p_reset.add_argument("challenge_id", help="challenge_id to reset")

    args = parser.parse_args(argv)
    cfg = _cfg()

    if args.cmd == "status":
        return cmd_status(args, cfg)
    elif args.cmd == "sync":
        return cmd_sync(args, cfg)
    elif args.cmd == "heartbeat":
        return cmd_heartbeat(args, cfg)
    elif args.cmd == "list":
        return cmd_list(args, cfg)
    elif args.cmd == "doctor":
        return cmd_doctor(args, cfg)
    elif args.cmd == "activate":
        return cmd_activate(args, cfg)
    elif args.cmd == "reset":
        return cmd_reset(args, cfg)
    return 2


if __name__ == "__main__":
    sys.exit(main())
