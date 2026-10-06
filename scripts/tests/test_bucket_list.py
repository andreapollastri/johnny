"""Tests for scripts/johnny_garage_bucket_list.py (used by the nightly backup).

Run from the repo root: python3 -m unittest discover -s scripts/tests
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from johnny_garage_bucket_list import parse_bucket_list_stdout  # noqa: E402

# `garage bucket list` from Garage 2.4.1 (space-padded columns, trailing spaces kept).
GARAGE_2_4 = (
    "ID                Created     Global aliases  Local aliases\n"
    "3422c394a403d778  2026-10-07  default         \n"
    "f1b17fa555341ee2  2026-10-07  media           \n"
    "6fd1ee2fbf0085d2  2026-10-07  tenant-acme     \n"
)


class ParseBucketListTest(unittest.TestCase):
    def test_garage_2_4_space_padded_table(self) -> None:
        self.assertEqual(parse_bucket_list_stdout(GARAGE_2_4), ["default", "media", "tenant-acme"])

    def test_garage_2_tab_separated_table(self) -> None:
        out = "ID\tCreated\tGlobal aliases\tLocal aliases\n3422c394a403d778\t2026-10-07\tdefault, www\t\n"
        self.assertEqual(parse_bucket_list_stdout(out), ["default", "www"])

    def test_legacy_alias_first_layout(self) -> None:
        out = "List of buckets:\n  media    \t  \t" + "a" * 64 + "\n"
        self.assertEqual(parse_bucket_list_stdout(out), ["media"])

    def test_duplicates_and_blank_lines_are_ignored(self) -> None:
        self.assertEqual(parse_bucket_list_stdout(GARAGE_2_4 + "\n" + GARAGE_2_4), ["default", "media", "tenant-acme"])


if __name__ == "__main__":
    unittest.main()
