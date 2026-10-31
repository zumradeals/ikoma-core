"""Contrats de refus du contrôle final ; Docker/Trivy simulés, jamais de serveur réel."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('final_images', 'scripts/security/final-images.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
REV = 'a' * 40
IDS = {name: 'sha256:' + str(i) * 64 for i, name in enumerate(gate.SERVICES, 1)}
SECRET = 'fictif-' * 8


class Runtime:
    def __init__(self, problem=None, service='php', severity=None):
        self.problem, self.service, self.severity = problem, service, severity
        self.scans = []
        self.references = {s: ('postgres:18.6-trixie' if s == 'db' else f'ikoma-{s}:test') for s in gate.SERVICES}

    def run(self, args, **kwargs):
        def done(value='', code=0, stderr=''):
            return subprocess.CompletedProcess(args, code, value, stderr)

        if args[0] == 'git':
            if 'rev-parse' in args:
                return done(REV)
            return done(' M app/Code.php' if self.problem == 'dirty' else '')
        if args[0] == 'docker':
            if args[1] == 'compose':
                if 'config' in args:
                    services = {s: {'image': r, 'environment': {'SECRET': SECRET}} for s, r in self.references.items()}
                    services.update({s: {'image': self.references['php']} for s in ('migrator', 'scheduler', 'maintenance')})
                    # Comme Compose réel : le service de maintenance est hors du profil par défaut.
                    if '--profile' not in args:
                        services.pop('maintenance')
                    if self.problem == 'alias':
                        services['maintenance']['image'] = 'autre:version'
                    return done(json.dumps({'services': services, 'secrets': {'private': SECRET}}))
                service = args[-1]
                return done('' if self.problem == 'container' and service == self.service else service)
            if args[1:3] == ['image', 'inspect']:
                service = next(s for s, r in self.references.items() if r == args[-1])
                if self.problem == 'image' and service == self.service:
                    return done(code=1, stderr=SECRET)
                labels = {gate.REVISION: REV, 'org.opencontainers.image.source': gate.SOURCE}
                if self.problem == 'label' and service == self.service:
                    labels[gate.REVISION] = 'b' * 40
                digests = ['postgres@sha256:' + '4' * 64] if service == 'db' else []
                if self.problem == 'db_digest' and service == 'db':
                    digests = []
                return done(json.dumps([{'Id': IDS[service], 'Architecture': 'amd64', 'Os': 'linux',
                                         'RepoDigests': digests, 'Config': {'Labels': labels, 'Env': [SECRET]}}]))
            if args[1] == 'inspect':
                return done(json.dumps('sha256:' + '9' * 64 if self.problem == 'runtime' and args[-1] == self.service else IDS[args[-1]]))
            if args[1] == 'run':
                service = next(s for s, ident in IDS.items() if ident in args)
                self.assert_sandbox(args)
                if service == 'nginx':
                    return done(stderr='nginx version: nginx/1.30.2\n')
                return done('8.5.12' if service == 'php' else 'postgres (PostgreSQL) 18.6 (Debian 18.6-1.pgdg13+1)')
        if args[0] == 'trivy-test':
            if '--version' in args:
                return done('Version: 0.76.1\n')
            cache = Path(args[args.index('--cache-dir') + 1])
            if '--download-db-only' in args:
                if self.problem == 'database':
                    return done(code=1, stderr=SECRET)
                (cache / 'db').mkdir(parents=True)
                (cache / 'db/trivy.db').write_bytes(b'base fictive')
                (cache / 'db/metadata.json').write_text(json.dumps({'Version': 2, 'UpdatedAt': '2026-10-31T00:00:00Z'}))
                return done()
            service = next(s for s, ident in IDS.items() if ident == args[-1])
            self.scans.append(service)
            assert args[args.index('--image-src') + 1] == 'docker'
            assert '--ignore-unfixed=false' in args and '--skip-db-update' in args
            assert Path(args[args.index('--ignorefile') + 1]).read_text() == ''
            assert not any(k.startswith('TRIVY_') for k in kwargs['env'])
            if self.problem == 'scanner' and service == self.service:
                return done(code=1, stderr=SECRET)
            if self.problem == 'timeout' and service == self.service:
                raise subprocess.TimeoutExpired(args, 600, output=SECRET)
            if self.problem == 'report_missing' and service == self.service:
                return done()
            path = Path(args[args.index('--output') + 1])
            data = {'SchemaVersion': 2, 'ArtifactType': 'container_image', 'Trivy': {'Version': '0.76.1'},
                    'Metadata': {'ImageID': IDS[service], 'OS': {'Family': 'alpine' if service == 'nginx' else 'debian', 'Name': 'test'},
                                 'ImageConfig': {'config': {'Env': [SECRET]}}},
                    'Results': [{'Target': IDS[service], 'Class': 'os-pkgs', 'Type': 'alpine' if service == 'nginx' else 'debian'}]}
            if self.severity and service == self.service:
                data['Results'][0]['Vulnerabilities'] = [{'VulnerabilityID': 'CVE-TEST', 'PkgName': 'test',
                                                         'InstalledVersion': '1', 'Severity': self.severity}]
            if service == self.service:
                if self.problem == 'wrong_image':
                    data['Metadata']['ImageID'] = 'sha256:' + '9' * 64
                if self.problem == 'empty_report':
                    data['Results'] = []
                if self.problem == 'wrong_scanner_version':
                    data['Trivy']['Version'] = '0.1.0'
            path.write_text('{' if self.problem == 'bad_json' and service == self.service else json.dumps(data))
            return done()
        raise AssertionError('commande inattendue : ' + repr(args))

    @staticmethod
    def assert_sandbox(args):
        assert args[args.index('--network') + 1] == 'none'
        assert '--read-only' in args and '--volume' not in args and '--privileged' not in args


class FinalImagesTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        for name in ('docker-compose.prod.yml', 'docker/prod/Dockerfile', 'composer.lock', 'package-lock.json'):
            f = self.root / name
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text('fictif')
        self.out = self.root / 'preuves'

    def scan(self, runtime):
        with patch.object(gate.subprocess, 'run', side_effect=runtime.run), patch.dict(os.environ, {'TRIVY_IGNORE_UNFIXED': 'true', 'GITHUB_SHA': REV}):
            return gate.scan(self.root, self.out, 'trivy-test')

    def test_three_clean_images_and_private_metadata_removed(self):
        runtime = Runtime()
        self.assertEqual(self.scan(runtime), 0)
        self.assertEqual(runtime.scans, list(gate.SERVICES))
        self.assertEqual(gate.verify(self.out), 0)
        for path in self.out.iterdir():
            self.assertNotIn(SECRET, path.read_text())
        manifest = json.loads((self.out / 'manifest.json').read_text())
        self.assertEqual(manifest['source_revision'], REV)
        self.assertTrue(manifest['images']['db']['repo_digests'])
        self.assertEqual(manifest['images']['php']['repo_digests'], [])

    def test_high_and_critical_without_a_fix_block_and_all_images_still_scanned(self):
        for service in gate.SERVICES:
            for severity in ('HIGH', 'CRITICAL'):
                with self.subTest(service=service, severity=severity):
                    self.out = self.root / (service + severity)
                    runtime = Runtime(service=service, severity=severity)
                    self.assertEqual(self.scan(runtime), 1)
                    self.assertEqual(runtime.scans, list(gate.SERVICES))
                    self.assertEqual(gate.verify(self.out), 1)

    def test_lower_severities_are_retained_without_changing_high_threshold(self):
        self.assertEqual(self.scan(Runtime(severity='MEDIUM')), 0)
        report = json.loads((self.out / 'image-php.json').read_text())
        self.assertEqual(report['Results'][0]['Vulnerabilities'][0]['Severity'], 'MEDIUM')

    def test_missing_image_or_runtime_identity_or_revision_never_passes(self):
        for problem in ('image', 'container', 'runtime', 'label', 'db_digest'):
            with self.subTest(problem=problem):
                self.out = self.root / problem
                runtime = Runtime(problem, 'db' if problem == 'db_digest' else 'php')
                self.assertEqual(self.scan(runtime), 2)
                self.assertIn('nginx', runtime.scans)

    def test_scan_errors_and_incomplete_reports_never_pass(self):
        for problem in ('scanner', 'timeout', 'report_missing', 'wrong_image', 'empty_report', 'bad_json', 'wrong_scanner_version'):
            with self.subTest(problem=problem):
                self.out = self.root / problem
                runtime = Runtime(problem)
                self.assertEqual(self.scan(runtime), 2)
                self.assertEqual(runtime.scans, list(gate.SERVICES))
                for path in self.out.iterdir():
                    self.assertNotIn(SECRET, path.read_text())

    def test_database_unavailable_is_not_a_success(self):
        self.assertEqual(self.scan(Runtime('database')), 2)
        self.assertEqual(gate.verify(self.out), 2)

    def test_dirty_sources_or_divergent_php_alias_refused(self):
        for problem in ('dirty', 'alias'):
            with self.subTest(problem=problem):
                self.out = self.root / problem
                runtime = Runtime(problem)
                self.assertEqual(self.scan(runtime), 2)
                self.assertEqual(runtime.scans, [])

    def test_old_report_cannot_be_reused(self):
        self.scan(Runtime())
        with self.assertRaises(gate.EvidenceError):
            self.scan(Runtime('scanner'))

    def test_deleted_or_modified_report_is_refused_by_independent_verifier(self):
        self.scan(Runtime())
        path = self.out / 'image-db.json'
        contents = path.read_text()
        path.unlink()
        self.assertEqual(gate.verify(self.out), 2)
        path.write_text(contents + ' ')
        self.assertEqual(gate.verify(self.out), 2)

    def test_manifest_without_source_or_database_or_full_set_is_refused(self):
        self.scan(Runtime())
        path = self.out / 'manifest.json'
        original = json.loads(path.read_text())
        for field in ('scanner', 'source_revision', 'threshold'):
            data = dict(original)
            del data[field]
            path.write_text(json.dumps(data))
            self.assertEqual(gate.verify(self.out), 2)
        original['images'].pop('db')
        path.write_text(json.dumps(original))
        self.assertEqual(gate.verify(self.out), 2)

    def test_absent_collection_is_refused(self):
        self.assertEqual(gate.verify(self.out), 2)


if __name__ == '__main__':
    unittest.main()
