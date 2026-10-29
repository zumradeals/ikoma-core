import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


class ZapGateTest(unittest.TestCase):
    def run_gate(self, alert=None, empty=False):
        report = {} if empty else {'site': [{'alerts': [] if alert is None else [alert]}]}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'rapport.json'
            path.write_text(json.dumps(report))
            result = subprocess.run([sys.executable, 'scripts/security/zap-gate.py', str(path)], capture_output=True)
            return result.returncode

    def test_only_csrf_cookie_is_excepted(self):
        alert = {'alertRef': '10010', 'riskcode': '1', 'instances': [{'uri': 'http://localhost', 'param': 'XSRF-TOKEN', 'evidence': 'Set-Cookie: XSRF-TOKEN'}]}
        self.assertEqual(self.run_gate(alert), 0)
        alert['instances'][0]['param'] = 'laravel-session'
        self.assertEqual(self.run_gate(alert), 1)

    def test_cached_authenticated_page_is_never_excepted(self):
        alert = {'alertRef': '10049-3', 'riskcode': '0', 'instances': [{'uri': 'http://localhost/build/assets/app.js'}]}
        self.assertEqual(self.run_gate(alert), 0)
        alert['instances'][0]['uri'] = 'http://localhost/administration/comptes'
        self.assertEqual(self.run_gate(alert), 1)

    def test_unknown_alert_or_changed_severity_blocks(self):
        alert = {'alertRef': '10112', 'riskcode': '0', 'instances': [{'uri': 'http://localhost', 'param': 'laravel-session', 'evidence': 'laravel-session'}]}
        self.assertEqual(self.run_gate(alert), 0)
        alert['instances'][0].update(param='XSRF-TOKEN', evidence='XSRF-TOKEN')
        self.assertEqual(self.run_gate(alert), 0)
        alert['instances'][0]['evidence'] = 'valeur non approuvée'
        self.assertEqual(self.run_gate(alert), 1)
        alert['instances'][0]['evidence'] = 'XSRF-TOKEN'
        alert['riskcode'] = '3'
        self.assertEqual(self.run_gate(alert), 1)
        alert['alertRef'] = 'inconnue'
        self.assertEqual(self.run_gate(alert), 1)

    def test_absent_analysis_is_not_a_green_scan(self):
        self.assertEqual(self.run_gate(empty=True), 2)
        self.assertEqual(self.run_gate(), 0)


if __name__ == '__main__':
    unittest.main()
