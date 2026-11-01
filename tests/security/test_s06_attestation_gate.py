"""S0.6 attest-gate unit tests: synthesized inputs, no live registry/secret."""
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/security/s06-attestation-gate.py"
SHA = "c1da933241a29d86fd8ec858b25f1e7883104928"
DIGEST = "sha256:" + "a" * 64
IMAGE = "ghcr.io/zumradeals/ikoma-access-postgres-candidate"
REPO = "zumradeals/ikoma-core"


class AttestationGate(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        self.record = self.root / "release.json"
        self.verified = self.root / "verified.json"
        self.output = self.root / "evidence.json"
        self.record.write_text(json.dumps({
            "schema": 1,
            "status": "PUBLISHED_CANDIDATE_UNVERIFIED_ON_SECOND_RUNNER",
            "source_repository": REPO,
            "source_commit": SHA,
            "registry_name": IMAGE,
            "tag": IMAGE + ":rc-" + SHA,
            "digest": DIGEST,
            "immutable_ref": IMAGE + "@" + DIGEST,
            "production_deployment_authorized": False,
            "real_data_migration_authorized": False,
        }))
        self.verified.write_text(json.dumps([{
            "verificationResult": {
                "statement": {
                    "predicateType": "https://slsa.dev/provenance/v1",
                    "subject": [{"name": IMAGE, "digest": {"sha256": DIGEST.split(":")[1]}}]
                }
            }
        }]))

    def run_check(self):
        return subprocess.run([sys.executable, str(SCRIPT),
            "--release-record", str(self.record),
            "--verified-json", str(self.verified),
            "--commit", SHA,
            "--digest", DIGEST,
            "--repository", REPO,
            "--output", str(self.output)
        ], capture_output=True, text=True)

    def test_exact_signer_verified_result_accepts_subject(self):
        p = self.run_check()
        self.assertEqual(p.returncode, 0, p.stderr)
        self.assertIn("ATTESTATION_SIGNATURE", self.output.read_text())

    def test_empty_cli_json_rejected(self):
        self.verified.write_text("")
        self.assertNotEqual(self.run_check().returncode, 0)

    def test_empty_attestation_array_rejected(self):
        self.verified.write_text("[]")
        self.assertNotEqual(self.run_check().returncode, 0)

    def test_wrong_subject_digest_rejected(self):
        d=json.loads(self.verified.read_text())
        d[0]["verificationResult"]["statement"]["subject"][0]["digest"]["sha256"]="b"*64
        self.verified.write_text(json.dumps(d))
        self.assertNotEqual(self.run_check().returncode,0)

    def test_other_predicate_rejected(self):
        d=json.loads(self.verified.read_text())
        d[0]["verificationResult"]["statement"]["predicateType"]="https://example.org/not-slsa"
        self.verified.write_text(json.dumps(d))
        self.assertNotEqual(self.run_check().returncode,0)

    def test_other_image_rejected(self):
        d=json.loads(self.verified.read_text())
        d[0]["verificationResult"]["statement"]["subject"][0]["name"]="ghcr.io/other/image"
        self.verified.write_text(json.dumps(d))
        self.assertNotEqual(self.run_check().returncode,0)

    def test_release_record_tamper_rejected(self):
        d=json.loads(self.record.read_text())
        d["source_commit"]="e"*40
        self.record.write_text(json.dumps(d))
        self.assertNotEqual(self.run_check().returncode,0)

    def test_production_authorization_rejected(self):
        d=json.loads(self.record.read_text())
        d["production_deployment_authorized"]=True
        self.record.write_text(json.dumps(d))
        self.assertNotEqual(self.run_check().returncode,0)


if __name__ == "__main__":
    unittest.main()
