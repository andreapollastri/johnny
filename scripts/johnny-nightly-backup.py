#!/usr/bin/env python3
"""
Nightly job: for each SFTP target in /etc/johnny/backup.json, sync all Garage buckets to
remote_base_path/YYYY-MM-DD/bucket/ and purge dated folders older than retention_days.
Run as root on the primary Johnny VPS.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path

CONFIG = Path("/etc/johnny/backup.json")
CRED = Path("/etc/johnny/credentials/backup-internal-s3.env")
GARAGE = "/usr/local/bin/garage"
GARAGE_CFG = "/etc/johnny/garage.toml"
RUN_USER = "johnny"
RCLONE_CONF = Path("/etc/johnny/rclone-nightly.conf")


def die(msg: str, code: int = 1) -> None:
    print(f"johnny-nightly: {msg}", file=sys.stderr)
    sys.exit(code)


def johnny_local_rclone_block(access_key: str, secret_key: str) -> str:
    """rclone [johnny_local] — must match what S3 clients use (global bucket aliases)."""
    return f"""[johnny_local]
type = s3
provider = Other
env_auth = false
access_key_id = {access_key}
secret_access_key = {secret_key}
endpoint = http://127.0.0.1:3900
region = johnny
force_path_style = true
"""


def list_buckets() -> list[str]:
    """Global bucket names from `garage bucket list` (all buckets), not S3 ListBuckets (key-scoped)."""
    r = subprocess.run(
        ["sudo", "-u", RUN_USER, GARAGE, "-c", GARAGE_CFG, "bucket", "list"],
        capture_output=True,
        text=True,
    )
    if r.returncode != 0:
        msg = (r.stderr or r.stdout or "").strip() or f"exit {r.returncode}"
        print(f"johnny-nightly: garage bucket list failed: {msg}", file=sys.stderr)
        return []
    buckets: list[str] = []
    hex_id = re.compile(r"^[0-9a-f]{32,128}$", re.IGNORECASE)
    uuid_line = re.compile(
        r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\s+([a-z0-9][a-z0-9._-]*)$",
        re.IGNORECASE,
    )
    uuid_first = re.compile(
        r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
        re.IGNORECASE,
    )
    name_ok = re.compile(r"^[a-z0-9][a-z0-9._-]*$")
    for line in r.stdout.splitlines():
        line = line.rstrip()
        if not line:
            continue
        if "list of buckets" in line.lower():
            continue
        stripped = line.replace("|", "").strip()
        m = uuid_line.match(stripped)
        if m:
            buckets.append(m.group(1))
            continue
        if "\t" in stripped:
            cols = [c.strip() for c in stripped.split("\t") if c.strip()]
        else:
            cols = re.split(r"\s{2,}|\s+", stripped)
            cols = [c for c in cols if c]
        if len(cols) < 2:
            continue
        last = cols[-1]
        if hex_id.match(last):
            for alias in cols[0].split(","):
                alias = alias.strip()
                if alias and name_ok.match(alias):
                    buckets.append(alias)
            continue
        if uuid_first.match(cols[0]) and name_ok.match(cols[-1]):
            buckets.append(cols[-1])
    return list(dict.fromkeys(buckets))


def ensure_backup_key_on_buckets(buckets: list[str]) -> None:
    for b in buckets:
        subprocess.run(
            ["sudo", "-u", RUN_USER, GARAGE, "-c", GARAGE_CFG, "bucket", "allow", "--read", "--key", "johnny-backup", b],
            capture_output=True,
        )


def load_cred() -> tuple[str, str]:
    data = CRED.read_text(encoding="utf-8")
    ak = sk = ""
    for line in data.splitlines():
        line = line.strip()
        if line.startswith("export "):
            line = line[7:]
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        k, v = k.strip(), v.strip().strip('"').strip("'")
        if k == "JOHNNY_S3_ACCESS_KEY_ID":
            ak = v
        elif k == "JOHNNY_S3_SECRET_ACCESS_KEY":
            sk = v
    if not ak or not sk:
        die(f"Missing keys in {CRED}")
    return ak, sk


def write_rclone_conf(sftp_name: str, host: str, port: int, user: str, password: str, access_key: str, secret_key: str) -> None:
    obs = subprocess.check_output(["rclone", "obscure", password], text=True).strip()
    RCLONE_CONF.write_text(
        f"""{johnny_local_rclone_block(access_key, secret_key)}
[{sftp_name}]
type = sftp
host = {host}
user = {user}
port = {port}
pass = {obs}
shell_type = unix
""",
        encoding="utf-8",
    )
    RCLONE_CONF.chmod(0o600)


def rclone_run(args: list[str]) -> int:
    env = os.environ.copy()
    return subprocess.call(["rclone", *args, "--config", str(RCLONE_CONF)])


def prune_old(sftp_remote: str, base_path: str, retention_days: int) -> None:
    cutoff = datetime.now() - timedelta(days=retention_days)
    r = subprocess.run(
        ["rclone", "lsd", f"{sftp_remote}:{base_path}", "--config", str(RCLONE_CONF)],
        capture_output=True,
        text=True,
    )
    for line in r.stdout.splitlines():
        parts = line.split()
        if not parts:
            continue
        name = parts[-1]
        if not re.match(r"^\d{4}-\d{2}-\d{2}$", name):
            continue
        try:
            d = datetime.strptime(name, "%Y-%m-%d")
        except ValueError:
            continue
        if d.date() < cutoff.date():
            print(f"Pruning {sftp_remote}:{base_path}/{name}")
            subprocess.call(
                ["rclone", "purge", f"{sftp_remote}:{base_path}/{name}", "--config", str(RCLONE_CONF)],
                stderr=subprocess.DEVNULL,
            )


def main() -> None:
    if os.geteuid() != 0:
        die("Run as root.")
    if not CONFIG.is_file():
        die(f"Missing {CONFIG}")
    if not CRED.is_file():
        die(f"Missing {CRED}")
    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    targets = cfg.get("targets") or []
    retention = int(cfg.get("retention_days", 90))
    base_path = cfg.get("remote_base_path", "johnny-backups").strip("/") or "johnny-backups"
    date_str = datetime.now().strftime("%Y-%m-%d")

    access_key, secret_key = load_cred()
    RCLONE_CONF.write_text(johnny_local_rclone_block(access_key, secret_key), encoding="utf-8")
    RCLONE_CONF.chmod(0o600)
    buckets = list_buckets()
    ensure_backup_key_on_buckets(buckets)
    if not targets:
        print("No SFTP backup targets configured.")
        return
    if not buckets:
        print("No buckets in Garage; nothing to sync.")

    for t in targets:
        name = t["name"]
        safe = re.sub(r"[^a-zA-Z0-9_]", "_", name)
        remote = f"sftp_{safe}"
        write_rclone_conf(remote, t["host"], int(t.get("port", 22)), t["user"], t["password"], access_key, secret_key)
        subprocess.call(
            ["rclone", "mkdir", f"{remote}:{base_path}/{date_str}", "--config", str(RCLONE_CONF)],
            stderr=subprocess.DEVNULL,
        )
        for bucket in buckets:
            print(f"Sync bucket '{bucket}' -> {name}:{base_path}/{date_str}/{bucket}/")
            code = rclone_run(
                [
                    "sync",
                    f"johnny_local:{bucket}",
                    f"{remote}:{base_path}/{date_str}/{bucket}",
                    "--transfers",
                    "4",
                    "--checkers",
                    "8",
                    "--stats",
                    "30s",
                    "--stats-one-line",
                ]
            )
            if code != 0:
                print(f"Warning: sync failed for bucket {bucket} on target {name}", file=sys.stderr)
        prune_old(remote, base_path, retention)

    print(f"Johnny nightly backup finished at {datetime.now().isoformat()}")


if __name__ == "__main__":
    main()
