"""
Parse `garage bucket list` / `johnny bucket list` stdout.

Garage 2.x prints a table:
  ID (16 hex) \\t Created (YYYY-MM-DD) \\t Global aliases \\t Local aliases

Older releases used other layouts (global alias first, long hex id last, etc.).
"""
from __future__ import annotations

import re
from typing import Final

_NAME_OK: Final = re.compile(r"^[a-z0-9][a-z0-9._-]*$")
_HEX16: Final = re.compile(r"^[0-9a-f]{16}$", re.IGNORECASE)
_HEX_LONG: Final = re.compile(r"^[0-9a-f]{32,128}$", re.IGNORECASE)
_UUID_LINE: Final = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\s+([a-z0-9][a-z0-9._-]*)$",
    re.IGNORECASE,
)
_UUID_FIRST: Final = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$",
    re.IGNORECASE,
)
_DATE_PREFIX: Final = re.compile(r"^\d{4}-\d{2}-\d{2}")


def _add_aliases_from_cell(cell: str, out: list[str]) -> None:
    for part in re.split(r",\s*", cell):
        alias = part.strip()
        if not alias:
            continue
        # Abbreviated lists like "a, b (+2 more)" — take first token that looks like a name
        if "(" in alias:
            alias = alias.split("(", 1)[0].strip()
        if _NAME_OK.match(alias):
            out.append(alias)


def parse_bucket_list_stdout(stdout: str) -> list[str]:
    out: list[str] = []
    for raw in stdout.splitlines():
        line = raw.rstrip()
        if not line:
            continue
        low = line.lower()
        if "list of buckets" in low:
            continue
        stripped = line.replace("|", "").strip()
        if stripped.lower().startswith("id") and "global" in low and "alias" in low:
            continue
        if stripped.lower().startswith("id") and "created" in low:
            continue

        # Garage 2.x — tab-separated: ID (16 hex), Created, Global aliases, Local aliases (optional)
        cols_raw = [c.strip() for c in line.split("\t")]
        if (
            len(cols_raw) >= 3
            and _HEX16.match(cols_raw[0])
            and _DATE_PREFIX.match(cols_raw[1])
        ):
            _add_aliases_from_cell(cols_raw[2], out)
            continue

        # Same layout, space-padded (format_table)
        m_pad = re.match(
            r"^([0-9a-f]{16})\s+(\d{4}-\d{2}-\d{2})\s+(.+?)\s{2,}(.+)$",
            stripped,
            re.IGNORECASE,
        )
        if m_pad and _DATE_PREFIX.match(m_pad.group(2)):
            _add_aliases_from_cell(m_pad.group(3).strip(), out)
            continue

        m = _UUID_LINE.match(stripped)
        if m:
            out.append(m.group(1))
            continue

        if "\t" in stripped:
            cols = [c.strip() for c in stripped.split("\t") if c.strip()]
        else:
            cols = re.split(r"\s{2,}|\s+", stripped)
            cols = [c for c in cols if c]
        if len(cols) < 2:
            continue
        last = cols[-1]
        if _HEX_LONG.match(last):
            _add_aliases_from_cell(cols[0], out)
            continue
        if _UUID_FIRST.match(cols[0]) and _NAME_OK.match(cols[-1]):
            out.append(cols[-1])
    return list(dict.fromkeys(out))
