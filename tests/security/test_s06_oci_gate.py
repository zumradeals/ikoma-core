"""S0.6 fail-closed OCI release identity and provenance contracts (synthetic only)."""
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/security/s06-oci-gate.py"
REPO = "zumradeals/ikoma-core"
NAME = "ghcr.io/zumradeals/ikoma-access-postgres-candidate"
COMMIT = "b" * 40
REGISTRY_DIGEST = "sha256:" + "a" * 64
IMAGE_ID = "sha256:" + "c" * 64
UPSTREAM = "6456aaa0f3c854d199d0f037f068eb97515b7513"


class ReleaseContract(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.tmp = Path(tmp.name)
        self.binary = self.tmp / "gosu"
        self.binary.write_bytes(b"synthetic-upstream-binary")
        lock = json.loads((ROOT / "docs/security/S0.4-IMAGE-LOCK.json").read_text())
        lock["reproducible_gosu_binary_sha256"] = hashlib.sha256(
            self.binary.read_bytes()).hexdigest()
        self.lock = self.tmp / "lock.json"
        self.lock.write_text(json.dumps(lock))
        self.metadata = self.tmp / "meta.txt"
        self.metadata.write_text(
            "gosu: go1.27.3\n\tpath\tgithub.com/tianon/gosu\n"
            "\tbuild\tvcs.revision=" + UPSTREAM + "\n\tbuild\tvcs.modified=false\n")
        self.record = self.tmp / "release.json"
        self.scan = self.tmp / "scan.json"
        self.scan.write_text(json.dumps({
            "image_id": IMAGE_ID, "high": 0, "critical": 0,
            "decision": "READY_FOR_ADDITIONAL_REVIEW"
        }))
        self.refs = self.tmp / "refs.txt"
        self.refs.write_text(NAME + "@" + REGISTRY_DIGEST + "\n")

    def call(self, *args):
        return subprocess.run([sys.executable, str(SCRIPT), *map(str, args)],
                              capture_output=True, text=True)

    def publish(self, confirmation="RELEASE_CANDIDATE_ONLY"):
        return self.call(
            "publish", "--lock", self.lock, "--repository", REPO,
            "--image-name", NAME, "--commit", COMMIT,
            "--confirmation", confirmation, "--digest", REGISTRY_DIGEST,
            "--local-image-id", IMAGE_ID, "--gosu", self.binary,
            "--metadata", self.metadata, "--output", self.record)

    def consume(self):
        return self.call(
            "consumer", "--lock", self.lock, "--repository", REPO,
            "--image-name", NAME, "--commit", COMMIT,
            "--gosu", self.binary, "--metadata", self.metadata,
            "--release-record", self.record, "--image-id", IMAGE_ID,
            "--repo-digests", self.refs, "--scan-gate", self.scan,
            "--output", self.tmp / "consumed.json")

    def test_publish_consume_immutable_manifest_digest(self):
        p = self.publish()
        self.assertEqual(p.returncode, 0, p.stderr)
        record = json.loads(self.record.read_text())
        self.assertEqual(record["immutable_ref"], NAME + "@" + REGISTRY_DIGEST)
        self.assertNotEqual(record["local_image_id"], record["digest"])
        result = self.consume()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads((self.tmp / "consumed.json").read_text())[
            "production_deployment_authorized"])

    def test_no_permission_token_rejected(self):
        self.assertNotEqual(self.publish("YES").returncode, 0)

    def test_mutable_digest_rejected(self):
        p = self.call(
            "publish", "--lock", self.lock, "--repository", REPO,
            "--image-name", NAME, "--commit", COMMIT,
            "--confirmation", "RELEASE_CANDIDATE_ONLY", "--digest", "latest",
            "--local-image-id", IMAGE_ID, "--gosu", self.binary,
            "--metadata", self.metadata, "--output", self.record)
        self.assertNotEqual(p.returncode, 0)

    def test_tampered_registry_digest_rejected(self):
        self.assertEqual(self.publish().returncode, 0)
        data = json.loads(self.record.read_text())
        data["digest"] = "sha256:" + "f" * 64
        self.record.write_text(json.dumps(data))
        self.assertNotEqual(self.consume().returncode, 0)

    def test_mismatched_pull_digest_rejected(self):
        self.assertEqual(self.publish().returncode, 0)
        self.refs.write_text(NAME + "@sha256:" + "f" * 64 + "\n")
        self.assertNotEqual(self.consume().returncode, 0)

    def test_critical_scan_rejected(self):
        self.assertEqual(self.publish().returncode, 0)
        self.scan.write_text(json.dumps({
            "image_id": IMAGE_ID, "high": 0, "critical": 1,
            "decision": "STOP_SECURITY_OPEN"
        }))
        self.assertNotEqual(self.consume().returncode, 0)

    def test_modified_gosu_rejected(self):
        self.binary.write_bytes(b"modified payload")
        self.assertNotEqual(self.publish().returncode, 0)

    def test_foreign_registry_rejected(self):
        p = self.call(
            "publish", "--lock", self.lock, "--repository", REPO,
            "--image-name", "ghcr.io/unknown/repo",
            "--commit", COMMIT,
            "--confirmation", "RELEASE_CANDIDATE_ONLY",
            "--digest", REGISTRY_DIGEST,
            "--local-image-id", IMAGE_ID, "--gosu", self.binary,
            "--metadata", self.metadata, "--output", self.record)
        self.assertNotEqual(p.returncode, 0)


if __name__ == "__main__":
    unittest.main()
