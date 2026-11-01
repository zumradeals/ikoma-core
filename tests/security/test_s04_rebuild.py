"""S0.4 unit guards: synthetic inputs only; never substitute for image scans."""
import json
import hashlib
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/security/s04-rebuild-check.py"
SOURCE = ROOT / "docker/qualification/postgres-gosu-go127-alpine.Dockerfile"
UPSTREAM = "6456aaa0f3c854d199d0f037f068eb97515b7513"


class S04GateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.p = Path(self.temp.name)
        lock = json.loads((ROOT / "docs/security/S0.4-IMAGE-LOCK.json").read_text())
        lock["reproducible_gosu_binary_sha256"] = hashlib.sha256(
            b"reconstructed-upstream-binary").hexdigest()
        self.lock = self.p / "lock.json"
        self.lock.write_text(json.dumps(lock))

    def call(self, *args):
        return subprocess.run([sys.executable, str(SCRIPT), *map(str, args)],
                              capture_output=True, text=True, check=False)

    def test_pin_two_parent_digests(self):
        output = self.p / "locked.Dockerfile"
        evidence = self.p / "parents.json"
        locked = json.loads(self.lock.read_text())["parents"]
        r = self.call("pin", "--lock", self.lock, "--source", SOURCE, "--output", output,
                      "--evidence", evidence,
                      "--golang", locked["golang"]["locked_ref"],
                      "--postgres", locked["postgres"]["locked_ref"])
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("FROM golang@sha256:", output.read_text())
        self.assertIn("FROM postgres@sha256:", output.read_text())
        self.assertNotIn("FROM postgres:18.6-alpine3.24", output.read_text())
        self.assertFalse(json.loads(evidence.read_text())["production_compose_modified"])

    def test_reject_ambiguous_parent(self):
        r = self.call("pin", "--lock", self.lock, "--source", SOURCE,
                      "--output", self.p / "candidate", "--evidence", self.p / "proof",
                      "--golang", "golang:1.27.3-alpine3.24",
                      "--postgres", "postgres@sha256:" + "b" * 64)
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("STOP S0.4", r.stderr)

    def fake_fixture(self):
        ids = ["sha256:" + "a" * 64, "sha256:" + "b" * 64]
        for i, name in enumerate(("a", "b")):
            (self.p / f"gosu-{name}").write_bytes(b"reconstructed-upstream-binary")
            (self.p / f"gosu-{name}.version").write_text(
                "gosu: go1.27.3\n\tpath\tgithub.com/tianon/gosu\n"
                "\tbuild\tvcs.revision=" + UPSTREAM + "\n\tbuild\tvcs.modified=false\n")
            (self.p / f"image-{name}.id").write_text(ids[i] + "\n")
            (self.p / f"gate-{name}.json").write_text(json.dumps({
                "image_id": ids[i], "high": 0, "critical": 0,
                "decision": "READY_FOR_ADDITIONAL_REVIEW"}))
        return ["verify", "--lock", self.lock, "--a", self.p / "gosu-a", "--b", self.p / "gosu-b",
                "--a-metadata", self.p / "gosu-a.version",
                "--b-metadata", self.p / "gosu-b.version",
                "--a-imageid", self.p / "image-a.id",
                "--b-imageid", self.p / "image-b.id",
                "--scan-a", self.p / "gate-a.json",
                "--scan-b", self.p / "gate-b.json",
                "--evidence", self.p / "out.json"]

    def test_reproducible_binary_accepted(self):
        r = self.call(*self.fake_fixture())
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertTrue(json.loads((self.p / "out.json").read_text())["binary_reproducible"])

    def test_nonreproducible_binary_rejected(self):
        args = self.fake_fixture()
        (self.p / "gosu-b").write_bytes(b"different-binary")
        self.assertIn("STOP S0.4", self.call(*args).stderr)

    def test_incoherent_scan_rejected(self):
        args = self.fake_fixture()
        (self.p / "gate-b.json").write_text(json.dumps({
            "image_id": "sha256:" + "b" * 64, "high": 1,
            "critical": 0, "decision": "STOP_SECURITY_OPEN"}))
        self.assertIn("STOP S0.4", self.call(*args).stderr)

    def test_wrong_vcs_provenance_rejected(self):
        args = self.fake_fixture()
        (self.p / "gosu-a.version").write_text("gosu: go1.27.3\n")
        self.assertIn("STOP S0.4", self.call(*args).stderr)


if __name__ == "__main__":
    unittest.main()
