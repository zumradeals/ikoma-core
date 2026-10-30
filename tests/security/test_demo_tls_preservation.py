"""Contrat de non-régression : un déploiement n'écrase pas un vhost HTTPS existant."""
from pathlib import Path
import subprocess
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "deploy/demo/deploy.sh"


class DemoTlsPreservationTests(unittest.TestCase):
    def test_shell_syntax(self):
        result = subprocess.run(["sh", "-n", str(SCRIPT)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_existing_nginx_vhost_is_preserved(self):
        script = SCRIPT.read_text()
        nginx_branch = script.split("  nginx)\n", 1)[1].split("  apache)\n", 1)[0]
        existing, initial = nginx_branch.split("    else\n", 1)
        self.assertIn('if [ -f "$CONF" ]; then', existing)
        self.assertIn("Vhost existant préservé", existing)
        self.assertNotIn('cat > "$CONF"', existing)
        self.assertNotIn("certbot --nginx", existing)
        self.assertIn('cat > "$CONF"', initial)
        self.assertIn("certbot --nginx", initial)

    def test_https_check_is_authentic_and_route_exists(self):
        script = SCRIPT.read_text()
        self.assertIn('https://$DOMAIN/sante', script)
        self.assertIn('--resolve "$DOMAIN:443:127.0.0.1"', script)
        self.assertNotIn('--insecure', script)
        self.assertNotIn('curl -k', script)


if __name__ == "__main__":
    unittest.main()
