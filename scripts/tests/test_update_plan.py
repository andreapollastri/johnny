"""Tests for scripts/johnny-update: what each channel would install, and why nothing moves.

Each test builds a repository of its own and runs the updater in a sandbox
(JOHNNY_UPDATE_SANDBOX stands in for /): check, channel, auto and status only,
the commands that change nothing on the server. Needs git and jq.

Run from the repo root: python3 -m unittest discover -s scripts/tests
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

UPDATER = Path(__file__).resolve().parents[1] / "johnny-update"


def git(cwd: Path, *args: str) -> str:
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "Test",
        "GIT_AUTHOR_EMAIL": "test@example.com",
        "GIT_COMMITTER_NAME": "Test",
        "GIT_COMMITTER_EMAIL": "test@example.com",
    }
    return subprocess.run(
        ["git", "-c", "init.defaultBranch=main", "-c", "advice.detachedHead=false", *args],
        cwd=cwd, env=env, check=True, capture_output=True, text=True,
    ).stdout.strip()


@unittest.skipUnless(shutil.which("git") and shutil.which("jq"), "git and jq are needed")
class UpdatePlanTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="johnny-update-test-"))
        self.origin = self.tmp / "origin"
        self.root = self.tmp / "root"
        self.checkout = self.tmp / "checkout"
        self.origin.mkdir()
        git(self.origin, "init", "-q")
        (self.root / "etc/johnny").mkdir(parents=True)
        (self.root / "etc/johnny/repo.path").write_text(f"{self.checkout}\n")
        self.write_conf(repo=str(self.origin))

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    # --- helpers

    def write_conf(self, repo: str, channel: str = "stable") -> None:
        (self.root / "etc/johnny/update.env").write_text(
            f"UPDATE_CHANNEL={channel}\nAUTO_UPDATE=1\nSYSTEM_UPDATE=1\nSYSTEM_REBOOT=0\nJOHNNY_REPO={repo}\n"
        )

    def commit(self, version: str, message: str, tag: str | None = None) -> str:
        (self.origin / "VERSION").write_text(version + "\n")
        git(self.origin, "add", "VERSION")
        git(self.origin, "commit", "-q", "--allow-empty", "-m", message)
        sha = git(self.origin, "rev-parse", "HEAD")
        if tag:
            git(self.origin, "tag", tag)
        return sha

    def install(self, ref: str) -> None:
        """The server's checkout, on REF."""
        if not self.checkout.exists():
            git(self.tmp, "clone", "-q", str(self.origin), str(self.checkout))
        git(self.checkout, "fetch", "-q", "origin")
        git(self.checkout, "checkout", "-q", "--detach", ref)

    def run_updater(self, *args: str) -> subprocess.CompletedProcess[str]:
        env = {**os.environ, "JOHNNY_UPDATE_SANDBOX": str(self.root)}
        env.pop("JOHNNY_REPO_ROOT", None)
        return subprocess.run(["bash", str(UPDATER), *args], env=env, capture_output=True, text=True)

    def check(self) -> dict:
        result = self.run_updater("check")
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def state(self) -> dict:
        return json.loads((self.root / "var/lib/johnny-update/state.json").read_text())

    # --- stable

    def test_no_release_yet(self) -> None:
        c1 = self.commit("1.1.0", "first")
        self.install(c1)
        out = self.check()
        self.assertFalse(out["update_available"])
        self.assertEqual(out["waiting_reason"], "no-release")
        self.assertEqual(out["current"], f"main-{c1[:7]}")

    def test_the_newest_release_whatever_its_tag_style(self) -> None:
        c1 = self.commit("1.0.0", "one", tag="1.0.0")
        self.commit("1.1.0", "two", tag="v1.1.0")
        self.commit("1.9.0", "nine", tag="1.9")
        self.commit("1.10.0", "ten", tag="1.10.0")
        head = self.commit("1.11.0", "on main, not released")
        self.install(c1)
        out = self.check()
        self.assertEqual(out["current"], "1.0.0")
        self.assertEqual(out["latest"], "1.10.0")
        self.assertEqual(out["target"], "1.10.0")
        self.assertTrue(out["update_available"])
        state = self.state()
        self.assertEqual(state["current_commit"], c1)
        self.assertEqual(state["main"], f"main-{head[:7]}")
        self.assertTrue(state["auto"])
        self.assertTrue(state["system"]["auto"])

    def test_up_to_date_on_the_newest_release(self) -> None:
        self.commit("1.0.0", "one", tag="1.0.0")
        c2 = self.commit("1.2.0", "two", tag="1.2")
        self.install(c2)
        out = self.check()
        self.assertEqual(out["current"], "1.2")
        self.assertFalse(out["update_available"])
        self.assertEqual(out["waiting_reason"], "")

    def test_a_main_build_newer_than_the_release_waits_for_the_next(self) -> None:
        self.commit("1.2.0", "release", tag="1.2.0")
        c2 = self.commit("1.3.0", "after the release")
        self.install(c2)
        out = self.check()
        self.assertFalse(out["update_available"])
        self.assertEqual(out["waiting_reason"], "behind")
        self.assertIn("newer than 1.2.0", out["waiting"])

        # a release that contains it moves it on
        self.commit("1.3.0", "release", tag="1.3.0")
        out = self.check()
        self.assertTrue(out["update_available"])
        self.assertEqual(out["target"], "1.3.0")

    def test_a_history_that_differs_is_diverged(self) -> None:
        self.commit("1.1.0", "one")
        self.install("HEAD")
        (self.checkout / "local.txt").write_text("a local commit\n")
        git(self.checkout, "add", "local.txt")
        git(self.checkout, "commit", "-q", "-m", "local")
        self.commit("1.2.0", "release", tag="1.2.0")
        out = self.check()
        self.assertFalse(out["update_available"])
        self.assertEqual(out["waiting_reason"], "diverged")

    # --- beta

    def test_beta_follows_main_and_stable_never_goes_back(self) -> None:
        c1 = self.commit("1.2.0", "release", tag="1.2.0")
        c2 = self.commit("1.3.0", "on main")
        self.install(c1)

        result = self.run_updater("channel", "beta")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("UPDATE_CHANNEL=beta", (self.root / "etc/johnny/update.env").read_text())
        out = self.check()
        self.assertEqual(out["channel"], "beta")
        self.assertEqual(out["target"], f"main-{c2[:7]}")
        self.assertTrue(out["update_available"])

        self.install(c2)
        self.run_updater("channel", "stable")
        out = self.check()
        self.assertEqual(out["channel"], "stable")
        self.assertEqual(out["waiting_reason"], "behind")

    # --- the rest

    def test_an_unreachable_repository_is_recorded(self) -> None:
        c1 = self.commit("1.1.0", "one")
        self.install(c1)
        self.write_conf(repo=str(self.tmp / "nowhere"))
        result = self.run_updater("check")
        self.assertEqual(result.returncode, 1)
        self.assertFalse(json.loads(result.stdout)["ok"])
        self.assertEqual(self.state()["waiting_reason"], "unreachable")

    def test_auto_and_system_auto_switches(self) -> None:
        self.install(self.commit("1.1.0", "one"))
        self.assertEqual(self.run_updater("auto", "off").returncode, 0)
        self.assertEqual(self.run_updater("system-auto", "off").returncode, 0)
        conf = (self.root / "etc/johnny/update.env").read_text()
        self.assertIn("AUTO_UPDATE=0", conf)
        self.assertIn("SYSTEM_UPDATE=0", conf)
        self.assertEqual(conf.count("AUTO_UPDATE="), 1)
        status = json.loads(self.run_updater("status").stdout)
        self.assertFalse(status["auto"])
        self.assertFalse(status["system"]["auto"])
        self.assertEqual(self.run_updater("auto", "maybe").returncode, 2)


if __name__ == "__main__":
    unittest.main()
