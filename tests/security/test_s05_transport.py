"""S0.5 transport-contract tests using a tiny fabricated Docker archive only."""
import hashlib
import io
import json
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/security/s05-transport-gate.py"
UPSTREAM = "6456aaa0f3c854d199d0f037f068eb97515b7513"

class TransportContract(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.p = Path(tmp.name)
        self.lock = json.loads((ROOT / "docs/security/S0.4-IMAGE-LOCK.json").read_text())
        self.bin = self.p / "gosu"
        self.bin.write_bytes(b"reproducible-fake-upstream")
        self.lock["reproducible_gosu_binary_sha256"] = hashlib.sha256(self.bin.read_bytes()).hexdigest()
        (self.p / "lock.json").write_text(json.dumps(self.lock))
        (self.p / "gosu.version").write_text(
            "go1.27.3\n\tpath\tgithub.com/tianon/gosu\n"
            "\tbuild\tvcs.revision=" + UPSTREAM + "\n\tbuild\tvcs.modified=false\n")
        config = b'{"created":"2026-11-01","config":{"User":"postgres"}}'
        digest = hashlib.sha256(config).hexdigest()
        self.id = "sha256:" + digest
        (self.p / "source.id").write_text(self.id)
        (self.p / "loaded.id").write_text(self.id)
        (self.p / "scan.json").write_text(json.dumps({
            "image_id":self.id, "high":0, "critical":0,
            "decision":"READY_FOR_ADDITIONAL_REVIEW"}))
        archive = self.p / "image.tar"
        manifest = json.dumps([{
            "Config":digest+".json",
            "RepoTags":["ikoma-s05:rc"],
            "Layers":["layer.tar"]
        }]).encode()
        with tarfile.open(archive, "w") as out:
            for name, data in [(digest+".json",config),
                               ("layer.tar",b"layer-dummy"),("manifest.json",manifest)]:
                info=tarfile.TarInfo(name)
                info.size=len(data)
                out.addfile(info, io.BytesIO(data))

    def call(self, *args):
        return subprocess.run([sys.executable, str(SCRIPT), *map(str,args)],
                              capture_output=True, text=True)

    def create(self):
        return self.call("create",
            "--archive", self.p/"image.tar",
            "--lock", self.p/"lock.json",
            "--gosu", self.bin,
            "--metadata", self.p/"gosu.version",
            "--image-id", self.p/"source.id",
            "--source-commit", "a"*40,
            "--run-id", "123",
            "--output", self.p/"transport.json")

    def verify(self):
        return self.call("verify",
            "--archive", self.p/"image.tar",
            "--manifest", self.p/"transport.json",
            "--lock", self.p/"lock.json",
            "--gosu", self.bin,
            "--metadata", self.p/"gosu.version",
            "--loaded-image-id", self.p/"loaded.id",
            "--scan-gate", self.p/"scan.json",
            "--output", self.p/"checked.json")

    def test_archive_can_be_reimported_identically(self):
        self.assertEqual(self.create().returncode, 0, "creation should pass")
        result=self.verify()
        self.assertEqual(result.returncode,0,result.stderr)
        evidence=json.loads((self.p/"checked.json").read_text())
        self.assertEqual(evidence["fresh_runner_load"],"PASS")
        self.assertIsNone(evidence["registry_manifest_digest"])
        self.assertFalse(evidence["production_deployment_authorized"])

    def test_tampered_archive_is_rejected(self):
        self.assertEqual(self.create().returncode,0)
        with open(self.p/"image.tar","ab") as fp: fp.write(b"altered")
        self.assertNotEqual(self.verify().returncode,0)

    def test_loaded_image_digest_mismatch_rejected(self):
        self.assertEqual(self.create().returncode,0)
        (self.p/"loaded.id").write_text("sha256:"+"f"*64)
        self.assertNotEqual(self.verify().returncode,0)

    def test_high_vulnerability_rejected(self):
        self.assertEqual(self.create().returncode,0)
        (self.p/"scan.json").write_text(json.dumps({
            "image_id":self.id,"high":1,"critical":0,"decision":"STOP_SECURITY_OPEN"}))
        self.assertNotEqual(self.verify().returncode,0)

    def test_invalid_gosu_source_rejected(self):
        (self.p/"gosu.version").write_text("go1.27.3\n")
        self.assertNotEqual(self.create().returncode,0)

if __name__ == "__main__":
    unittest.main()
