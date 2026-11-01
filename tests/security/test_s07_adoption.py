"""S0.7 safety contracts; synthetic Docker CLI only, no real volume touched."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROD = ROOT / "scripts/security/s07-adoption-preflight.sh"
DEV = ROOT / "scripts/security/s07-dev-preflight.sh"
DIGEST = ("ghcr.io/zumradeals/ikoma-access-postgres-candidate@sha256:"
          "972db7acac7096fe75a5918b8d4692f2199bdb07599d055082b0b2a5b88c731d")

class S07Safety(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.p = Path(tmp.name)
        self.env_file = self.p / "env"
        exe = self.p / "docker"
        exe.write_text(
            "#!/bin/sh\n"
            "case \"$1\" in\n"
            " info) exit \"${MOCK_DOCKER_DOWN:-0}\" ;;\n"
            " volume)\n"
            "   [ \"$2\" = inspect ] || exit 2\n"
            "   case \"$3\" in\n"
            "     ikoma_postgres|ikoma-core_postgres) [ \"${MOCK_LEGACY:-0}\" = 1 ] ;;\n"
            "     ikoma_postgres_s07_alpine) [ \"${MOCK_ALPINE:-0}\" = 1 ] ;;\n"
            "     *) exit 1 ;;\n"
            "   esac ;;\n"
            " compose) [ \"$2\" = config ] || exit 2\n"
            "   printf '{\"name\":\"ikoma-core\"}\\n' ;;\n"
            " *) exit 3 ;;\n"
            "esac\n")
        exe.chmod(0o755)
        self.proc_env = os.environ.copy()
        self.proc_env["PATH"] = str(self.p) + os.pathsep + os.environ["PATH"]
        self.proc_env["MOCK_LEGACY"] = "0"
        self.proc_env["MOCK_ALPINE"] = "0"
        self.proc_env["MOCK_DOCKER_DOWN"] = "0"

    def run_guard(self, mode, script=PROD):
        args = ["sh", str(script)]
        if script == PROD:
            args.extend([mode, str(self.env_file)])
        return subprocess.run(args, env=self.proc_env, capture_output=True, text=True)

    def test_pinned_digest_and_separate_volume_in_both_compose(self):
        for name in ("docker-compose.yml", "docker-compose.prod.yml"):
            content = (ROOT/name).read_text()
            self.assertIn("image: " + DIGEST, content)
            self.assertIn("postgres_s07_alpine:/var/lib/postgresql", content)
            self.assertNotIn("postgres:/var/lib/postgresql", content)

    def test_prod_compose_forces_environment_policy(self):
        content=(ROOT/"docker-compose.prod.yml").read_text()
        self.assertIn("IKOMA_S07_GUARD: ${IKOMA_S07_GUARD:?", content)
        self.assertIn("postgres_s07_alpine:", content)
        self.assertNotIn("  postgres:\n", content)

    def test_fresh_only_on_no_existing_volumes(self):
        self.assertEqual(self.run_guard("fresh").returncode, 0)

    def test_fresh_rejects_legacy_volume(self):
        self.proc_env["MOCK_LEGACY"] = "1"
        self.assertIn("STOP S0.7", self.run_guard("fresh").stderr)

    def test_update_rejects_legacy_volume_even_with_env(self):
        self.env_file.write_text("IKOMA_S07_GUARD=FRESH_EMPTY_INSTALLATION_ONLY\n")
        self.proc_env["MOCK_LEGACY"] = "1"
        self.assertNotEqual(self.run_guard("update").returncode, 0)

    def test_update_rejects_unapproved_old_install(self):
        self.env_file.write_text("IKOMA_VERSION=legacy\n")
        self.assertNotEqual(self.run_guard("update").returncode, 0)

    def test_update_requires_existing_env(self):
        self.assertNotEqual(self.run_guard("update").returncode, 0)

    def test_alpine_volume_without_documented_policy_rejected(self):
        self.proc_env["MOCK_ALPINE"] = "1"
        self.assertNotEqual(self.run_guard("fresh").returncode, 0)

    def test_idempotent_fresh_install_passes_with_previous_policy(self):
        self.env_file.write_text("IKOMA_S07_GUARD=FRESH_EMPTY_INSTALLATION_ONLY\n")
        self.proc_env["MOCK_ALPINE"] = "1"
        self.assertEqual(self.run_guard("fresh").returncode, 0)

    def test_rollback_only_allowed_on_fresh_alpine_policy(self):
        self.env_file.write_text("IKOMA_S07_GUARD=FRESH_EMPTY_INSTALLATION_ONLY\n")
        self.assertEqual(self.run_guard("rollback").returncode, 0)

    def test_any_opaque_env_policy_rejected(self):
        self.env_file.write_text("IKOMA_S07_GUARD=whatever\n")
        self.assertNotEqual(self.run_guard("fresh").returncode, 0)

    def test_unreachable_docker_is_stop(self):
        self.proc_env["MOCK_DOCKER_DOWN"] = "1"
        self.assertNotEqual(self.run_guard("fresh").returncode, 0)

    def test_dev_make_up_blocked_by_legacy_volume(self):
        self.proc_env["MOCK_LEGACY"] = "1"
        self.assertNotEqual(self.run_guard("dev", DEV).returncode, 0)

    def test_dev_make_up_fresh_passes(self):
        self.assertEqual(self.run_guard("dev", DEV).returncode, 0)

if __name__ == "__main__":
    unittest.main()
