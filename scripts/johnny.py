#!/usr/bin/env python3
"""
Johnny CLI: Garage passthrough + backup target management (SFTP).
Config: /etc/johnny/backup.json (root-only writes).
"""
from __future__ import annotations

import argparse
import json
import os
import pwd
import subprocess
import sys
import uuid
from getpass import getpass
from pathlib import Path
from typing import Any

GARAGE = "/usr/local/bin/garage"
GARAGE_CFG = "/etc/johnny/garage.toml"
BACKUP_JSON = Path("/etc/johnny/backup.json")
RUN_USER = "johnny"
NIGHTLY = "/usr/local/share/johnny/scripts/johnny-nightly-backup.sh"
VERSION_FILE = Path("/usr/local/share/johnny/VERSION")


def die(msg: str, code: int = 1) -> None:
    print(f"Error: {msg}", file=sys.stderr)
    sys.exit(code)


def require_root() -> None:
    if os.geteuid() != 0:
        die("This command must be run as root (sudo).")


def garage_exec(args: list[str]) -> int:
    if os.geteuid() == 0:
        cmd = ["sudo", "-u", RUN_USER, GARAGE, "-c", GARAGE_CFG, *args]
    else:
        try:
            pw = pwd.getpwnam(RUN_USER)
        except KeyError:
            die("System user 'johnny' not found.")
        if os.getuid() not in (0, pw.pw_uid):
            die("Run 'sudo johnny ...' for Garage commands (or run as user 'johnny').")
        cmd = [GARAGE, "-c", GARAGE_CFG, *args]
    return subprocess.call(cmd)


def load_backup_config() -> dict[str, Any]:
    if not BACKUP_JSON.is_file():
        return _default_backup_config()
    try:
        with open(BACKUP_JSON, "r", encoding="utf-8") as f:
            data = json.load(f)
    except (json.JSONDecodeError, OSError) as e:
        die(f"Cannot read {BACKUP_JSON}: {e}")
    if "targets" not in data:
        data["targets"] = []
    if "retention_days" not in data:
        data["retention_days"] = 90
    if "remote_base_path" not in data:
        data["remote_base_path"] = "johnny-backups"
    return data


def _default_backup_config() -> dict[str, Any]:
    return {
        "version": 1,
        "retention_days": 90,
        "remote_base_path": "johnny-backups",
        "targets": [],
    }


def save_backup_config(data: dict[str, Any]) -> None:
    BACKUP_JSON.parent.mkdir(parents=True, exist_ok=True)
    tmp = BACKUP_JSON.with_suffix(".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, sort_keys=True)
        f.write("\n")
    os.chmod(tmp, 0o600)
    tmp.replace(BACKUP_JSON)


def cmd_backup_list(_args: argparse.Namespace) -> None:
    require_root()
    cfg = load_backup_config()
    print(f"Retention: {cfg['retention_days']} days")
    print(f"Remote base path (on each SFTP host): {cfg['remote_base_path']}")
    print()
    if not cfg["targets"]:
        print("No backup targets configured.")
        return
    for t in cfg["targets"]:
        print(f"- {t['name']}")
        print(f"    host={t['host']} port={t['port']} user={t['user']}")


def _find_target(cfg: dict[str, Any], name: str) -> dict[str, Any] | None:
    for t in cfg["targets"]:
        if t["name"] == name:
            return t
    return None


def cmd_backup_create(args: argparse.Namespace) -> None:
    require_root()
    cfg = load_backup_config()
    if _find_target(cfg, args.name):
        die(f"Target '{args.name}' already exists.")
    host = args.host or input("SFTP host (IP or hostname): ").strip()
    if not host:
        die("Host is required.")
    port = args.port if args.port is not None else int(input("Port [22]: ").strip() or "22")
    user = args.user or input("SFTP user: ").strip()
    if not user:
        die("User is required.")
    if args.password:
        password = args.password
    else:
        password = getpass("SFTP password: ")
        if not password:
            die("Password is required.")
    target = {
        "id": str(uuid.uuid4()),
        "name": args.name,
        "host": host,
        "port": port,
        "user": user,
        "password": password,
    }
    cfg["targets"].append(target)
    save_backup_config(cfg)
    print(f"Created backup target '{args.name}'.")
    _ssh_keyscan(host, port)


def _ssh_keyscan(host: str, port: int) -> None:
    kh = Path("/root/.ssh/known_hosts")
    kh.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    try:
        out = subprocess.check_output(
            ["ssh-keyscan", "-p", str(port), "-H", host],
            stderr=subprocess.DEVNULL,
            text=True,
            timeout=30,
        )
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired, FileNotFoundError) as e:
        print(f"Warning: ssh-keyscan failed ({e}); first SFTP connection may prompt for host key.", file=sys.stderr)
        return
    if not out.strip():
        return
    existing = kh.read_text() if kh.is_file() else ""
    with open(kh, "a", encoding="utf-8") as f:
        for line in out.splitlines():
            if line and line not in existing:
                f.write(line + "\n")
    os.chmod(kh, 0o600)
    print("Added host key to /root/.ssh/known_hosts")


def cmd_backup_delete(args: argparse.Namespace) -> None:
    require_root()
    cfg = load_backup_config()
    before = len(cfg["targets"])
    cfg["targets"] = [t for t in cfg["targets"] if t["name"] != args.name]
    if len(cfg["targets"]) == before:
        die(f"No target named '{args.name}'.")
    save_backup_config(cfg)
    print(f"Removed backup target '{args.name}'.")


def cmd_backup_update(args: argparse.Namespace) -> None:
    require_root()
    cfg = load_backup_config()
    t = _find_target(cfg, args.name)
    if not t:
        die(f"No target named '{args.name}'.")
    if args.host is not None:
        t["host"] = args.host
    if args.port is not None:
        t["port"] = args.port
    if args.user is not None:
        t["user"] = args.user
    if args.password is not None:
        t["password"] = args.password
    elif args.password_prompt:
        p = getpass("New SFTP password (empty to keep): ")
        if p:
            t["password"] = p
    save_backup_config(cfg)
    _ssh_keyscan(t["host"], int(t["port"]))
    print(f"Updated backup target '{args.name}'.")


def cmd_backup_run(_args: argparse.Namespace) -> None:
    require_root()
    py = "/usr/local/share/johnny/scripts/johnny-nightly-backup.py"
    if os.path.isfile(py):
        os.execv("/usr/bin/python3", ["/usr/bin/python3", py])
    if not os.path.isfile(NIGHTLY):
        die(f"Missing nightly backup script; reinstall Johnny.")
    os.execv("/bin/bash", ["bash", NIGHTLY])


def cmd_backup_set_retention(args: argparse.Namespace) -> None:
    require_root()
    cfg = load_backup_config()
    cfg["retention_days"] = int(args.days)
    save_backup_config(cfg)
    print(f"Retention set to {args.days} days.")


def build_backup_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="johnny backup", description="Manage SFTP backup destinations.")
    sub = p.add_subparsers(dest="sub", required=True)

    sub.add_parser("list", help="List configured backup targets")

    c = sub.add_parser("create", help="Add a new SFTP backup target")
    c.add_argument("name", help="Label, e.g. vps-us-abc")
    c.add_argument("--host", help="Hostname or IP")
    c.add_argument("--port", type=int, help="SSH port (default 22)")
    c.add_argument("--user", help="SFTP username")
    c.add_argument("--password", help="SFTP password (avoid; prefer prompt)")

    d = sub.add_parser("delete", help="Remove a backup target")
    d.add_argument("name", help="Target label to remove")

    u = sub.add_parser("update", help="Change host/port/user/password for a target")
    u.add_argument("name", help="Target label")
    u.add_argument("--host", help="New hostname or IP")
    u.add_argument("--port", type=int, help="New SSH port")
    u.add_argument("--user", help="New username")
    u.add_argument("--password", help="New password")
    u.add_argument(
        "-p",
        "--password-prompt",
        action="store_true",
        help="Prompt for new password (keeps old if empty)",
    )

    sub.add_parser("run", help="Run the nightly backup job now (same as cron)")

    r = sub.add_parser("set-retention", help="Set how many days of dated folders to keep on SFTP servers")
    r.add_argument("days", type=int, help="Days (default project default is 90)")

    return p


def dispatch_backup(argv: list[str]) -> None:
    parser = build_backup_parser()
    args = parser.parse_args(argv)
    if args.sub == "list":
        cmd_backup_list(args)
    elif args.sub == "create":
        cmd_backup_create(args)
    elif args.sub == "delete":
        cmd_backup_delete(args)
    elif args.sub == "update":
        cmd_backup_update(args)
    elif args.sub == "run":
        cmd_backup_run(args)
    elif args.sub == "set-retention":
        cmd_backup_set_retention(args)
    else:
        parser.print_help()
        sys.exit(1)


def cmd_version() -> None:
    if VERSION_FILE.is_file():
        print(f"Johnny {VERSION_FILE.read_text().strip()}")
    else:
        print("Johnny (VERSION not installed — run install.sh or migration 001 / update)")


def cmd_update(argv: list[str]) -> None:
    require_root()
    pull = False
    pos: list[str] = []
    for a in argv:
        if a in ("--pull", "--git-pull"):
            pull = True
        else:
            pos.append(a)
    repo = pos[0] if pos else os.environ.get("JOHNNY_REPO", "")
    if not repo:
        die("Usage: sudo johnny update /path/to/johnny [--pull]  (or set JOHNNY_REPO)")
    update_sh = "/usr/local/share/johnny/scripts/update.sh"
    if not os.path.isfile(update_sh):
        candidate = os.path.join(repo, "scripts", "update.sh")
        if os.path.isfile(candidate):
            update_sh = candidate
    if not os.path.isfile(update_sh):
        die(f"Missing update.sh (expected {update_sh} or under the repo scripts/).")
    extra = [repo]
    if pull:
        extra.append("--pull")
    os.execv("/bin/bash", ["/bin/bash", update_sh, *extra])


def print_help() -> None:
    print("""Johnny — Garage + SFTP backup helpers

Usage:
  johnny version | -V | --version
  johnny update /path/to/johnny [--pull]   (git pull optional + sync + panel refresh; needs JOHNNY_REPO if no path)
  johnny backup list | create | delete | update | run | set-retention
  johnny <garage-args>...     (passed to garage -c /etc/johnny/garage.toml as user 'johnny')

Examples:
  johnny version
  sudo johnny update /opt/johnny --pull
  sudo johnny backup list
  sudo johnny backup create vps-us-abc --host 203.0.113.10 --user backup
  sudo johnny backup set-retention 90
  sudo johnny status
  sudo johnny bucket list

Use sudo for backup subcommands. For Garage CLI, use sudo (or run as user 'johnny').
""")


def main() -> None:
    argv = sys.argv[1:]
    if not argv:
        sys.exit(garage_exec(["status"]))
    if argv[0] in ("-h", "--help", "help"):
        print_help()
        return
    if argv[0] in ("version", "-V", "--version"):
        cmd_version()
        return
    if argv[0] == "update":
        cmd_update(argv[1:])
        return
    if argv[0] == "backup":
        dispatch_backup(argv[1:])
        return
    sys.exit(garage_exec(argv))


if __name__ == "__main__":
    main()
