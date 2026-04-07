#!/usr/bin/env python3
"""
One-shot: grant johnny-default (read+write+owner) and johnny-backup (read) on every bucket.
Used by scripts/migrations/006_*.sh during `johnny update`. Run as root.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys

GARAGE = "/usr/local/bin/garage"
GARAGE_CFG = "/etc/johnny/garage.toml"
RUN_USER = "johnny"
DEFAULT_KEY = "johnny-default"
BACKUP_KEY = "johnny-backup"


def list_buckets() -> list[str]:
    r = subprocess.run(
        ["sudo", "-u", RUN_USER, GARAGE, "-c", GARAGE_CFG, "bucket", "list"],
        capture_output=True,
        text=True,
    )
    if r.returncode != 0:
        msg = (r.stderr or r.stdout or "").strip() or f"exit {r.returncode}"
        print(f"ensure-system-keys: garage bucket list failed: {msg}", file=sys.stderr)
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


def main() -> int:
    if os.geteuid() != 0:
        print("ensure-system-keys: run as root (sudo).", file=sys.stderr)
        return 1
    buckets = list_buckets()
    if not buckets:
        print("ensure-system-keys: no buckets (or could not list).")
        return 0
    for b in buckets:
        r1 = subprocess.run(
            [
                "sudo",
                "-u",
                RUN_USER,
                GARAGE,
                "-c",
                GARAGE_CFG,
                "bucket",
                "allow",
                "--read",
                "--write",
                "--owner",
                b,
                "--key",
                DEFAULT_KEY,
            ],
            capture_output=True,
            text=True,
        )
        if r1.returncode != 0:
            err = (r1.stderr or r1.stdout or "").strip()
            print(f"ensure-system-keys: johnny-default on '{b}': {err}", file=sys.stderr)
        r2 = subprocess.run(
            [
                "sudo",
                "-u",
                RUN_USER,
                GARAGE,
                "-c",
                GARAGE_CFG,
                "bucket",
                "allow",
                "--read",
                b,
                "--key",
                BACKUP_KEY,
            ],
            capture_output=True,
            text=True,
        )
        if r2.returncode != 0:
            err = (r2.stderr or r2.stdout or "").strip()
            print(f"ensure-system-keys: johnny-backup on '{b}': {err}", file=sys.stderr)
        print(f"ensure-system-keys: {b} — system keys applied.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
