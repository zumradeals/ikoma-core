"""Contrôle des trois images finales de recette, sans publication de configuration privée.

0 = contrôles complets sans HIGH/CRITICAL ; 1 = vulnérabilités bloquantes ;
2 = preuve manquante, invalide ou erreur technique. Aucun appel à un serveur réel.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile


SERVICES = ('php', 'db', 'nginx')
TRIVY_VERSION = '0.76.1'
SEVERITIES = ('UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL')
REVISION = 'org.opencontainers.image.revision'
SOURCE = 'https://github.com/zumradeals/ikoma-core'
VULN_FIELDS = ('VulnerabilityID', 'PkgName', 'PkgID', 'PkgPath', 'InstalledVersion',
               'FixedVersion', 'Status', 'Severity', 'SeveritySource', 'VendorSeverity',
               'CVSS', 'PrimaryURL', 'DataSource', 'Title', 'Description', 'References')


class EvidenceError(Exception):
    """Code stable uniquement : ne jamais réémettre stderr ou une configuration Docker."""


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def save(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')


def command(args, *, timeout=120, env=None):
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, env=env)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise EvidenceError('commande_indisponible_ou_delai_depasse') from error
    if result.returncode:
        raise EvidenceError('commande_en_echec')
    return result.stdout


def parsed(args):
    try:
        return json.loads(command(args))
    except (ValueError, TypeError) as error:
        raise EvidenceError('json_commande_invalide') from error


def image_id(value):
    if not isinstance(value, str) or not re.fullmatch(r'sha256:[a-f0-9]{64}', value):
        raise EvidenceError('identifiant_image_invalide')
    return value


def report(raw, expected_id):
    """Valider la couverture OS avant de retirer les champs non publiables."""
    if (not isinstance(raw, dict) or not isinstance(raw.get('Metadata'), dict)
            or not isinstance(raw.get('Trivy'), dict) or raw['Trivy'].get('Version') != TRIVY_VERSION
            or raw.get('SchemaVersion') != 2 or raw.get('ArtifactType') != 'container_image'
            or raw.get('Metadata', {}).get('ImageID') != expected_id
            or not isinstance(raw.get('Results'), list)):
        raise EvidenceError('rapport_invalide_ou_mauvaise_image')
    os_info = raw['Metadata'].get('OS', {})
    if (not isinstance(os_info, dict) or os_info.get('Family') not in ('debian', 'alpine') or not os_info.get('Name')
            or not all(isinstance(r, dict) for r in raw['Results'])
            or not any(r.get('Class') == 'os-pkgs' and r.get('Type') == os_info['Family']
                       for r in raw['Results'])):
        raise EvidenceError('couverture_os_absente')
    clean_results, counts = [], Counter()
    for target in raw['Results']:
        vulnerabilities = target.get('Vulnerabilities')
        if vulnerabilities is None:
            vulnerabilities = []
        if not isinstance(vulnerabilities, list):
            raise EvidenceError('liste_vulnerabilites_invalide')
        clean_vulns = []
        for vuln in vulnerabilities:
            if (not isinstance(vuln, dict) or vuln.get('Severity') not in SEVERITIES
                    or not all(vuln.get(k) for k in ('VulnerabilityID', 'PkgName', 'InstalledVersion'))):
                raise EvidenceError('vulnerabilite_invalide')
            counts[vuln['Severity']] += 1
            clean_vulns.append({k: vuln[k] for k in VULN_FIELDS if k in vuln})
        clean_results.append({k: target[k] for k in ('Target', 'Class', 'Type') if k in target})
        clean_results[-1]['Vulnerabilities'] = clean_vulns
    clean = {k: raw[k] for k in ('SchemaVersion', 'CreatedAt', 'ArtifactName', 'ArtifactType', 'Trivy') if k in raw}
    clean['Metadata'] = {'ImageID': expected_id, 'OS': os_info}
    clean['Results'] = clean_results
    return clean, {s: counts[s] for s in SEVERITIES}


def inventory_service(service, config, compose, revision):
    reference = config['services'][service]['image']
    info = parsed(['docker', 'image', 'inspect', reference])
    if not isinstance(info, list) or len(info) != 1:
        raise EvidenceError('image_absente_ou_ambigue')
    info = info[0]
    ident = image_id(info.get('Id'))
    containers = command(compose + ['ps', '-q', service]).split()
    if len(containers) != 1:
        raise EvidenceError('conteneur_final_absent_ou_ambigu')
    running = parsed(['docker', 'inspect', '--format', '{{json .Image}}', containers[0]])
    if running != ident:
        raise EvidenceError('image_configuree_differente_image_executee')
    labels = info.get('Config', {}).get('Labels') or {}
    if service != 'db' and (labels.get(REVISION) != revision
                            or labels.get('org.opencontainers.image.source') != SOURCE):
        raise EvidenceError('image_sans_correspondance_commit')
    digests = info.get('RepoDigests') or []
    if not isinstance(digests, list) or any(not re.fullmatch(r'[^\s@]+@sha256:[a-f0-9]{64}', d) for d in digests):
        raise EvidenceError('digest_registre_invalide')
    if service == 'db' and not digests:
        raise EvidenceError('digest_postgresql_absent')
    # Les seules commandes d'exécution s'appliquent aux images CI, sans réseau,
    # volume, secret, utilisateur privilégié ou commande métier.
    binary, arguments, pattern = {
        'php': ('php', ['-r', 'echo PHP_VERSION;'], r'\d+\.\d+\.\d+(?:[-\w.]*)'),
        'db': ('postgres', ['--version'], r'postgres \(PostgreSQL\) [\w.() +~-]+'),
        'nginx': ('nginx', ['-v'], r'nginx version: nginx/[\w. +~-]+'),
    }[service]
    # nginx -v écrit normalement sa version sur stderr, capturé sans le publier brut.
    try:
        version = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '--read-only',
                                  '--entrypoint', binary, ident, *arguments],
                                 capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise EvidenceError('version_runtime_indisponible') from error
    value = (version.stdout + version.stderr).strip()
    if version.returncode or not re.fullmatch(pattern, value):
        raise EvidenceError('version_runtime_invalide')
    return {'reference_compose': reference, 'image_id': ident,
            'type_image_id': 'digest SHA-256 de la configuration Docker, pas digest de manifeste OCI',
            'repo_digests': sorted(digests),
            'digest_registre_statut': 'disponible' if digests else 'non publié : image construite localement',
            'architecture': info['Architecture'], 'os': info['Os'],
            'runtime_version': value, 'revision_image': labels.get(REVISION) if service != 'db' else None,
            'liaison_commit': 'label OCI et identité conteneur' if service != 'db' else 'référence Compose du commit et digest tiers résolu',
            'image_executee_identique': True}


def verify(directory):
    """Refuser aussi une collecte interrompue ou un rapport supprimé après le scan."""
    try:
        manifest = json.loads((directory / 'manifest.json').read_text())
        if (not isinstance(manifest, dict) or manifest.get('schema') != 1
                or not isinstance(manifest.get('images'), dict) or set(manifest['images']) != set(SERVICES)
                or not re.fullmatch(r'[a-f0-9]{40}', manifest.get('source_revision', ''))
                or manifest.get('threshold') != ['HIGH', 'CRITICAL']
                or manifest.get('scanner', {}).get('version') != TRIVY_VERSION
                or not re.fullmatch(r'[a-f0-9]{64}', manifest.get('scanner', {}).get('database_sha256', ''))
                or not manifest.get('scanner', {}).get('database_metadata', {}).get('UpdatedAt')):
            return 2
        blocked = False
        for service in SERVICES:
            item = manifest['images'][service]
            if item.get('status') not in ('clean', 'vulnerable'):
                return 2
            if not item.get('runtime_version') or not item.get('image_executee_identique'):
                return 2
            if service != 'db' and item.get('revision_image') != manifest['source_revision']:
                return 2
            if service == 'db' and not item.get('repo_digests'):
                return 2
            path = directory / f'image-{service}.json'
            if sha(path) != item.get('report_sha256'):
                return 2
            _, counts = report(json.loads(path.read_text()), item['image_id'])
            if counts != item.get('counts'):
                return 2
            vulnerable = bool(counts['HIGH'] + counts['CRITICAL'])
            if item['status'] != ('vulnerable' if vulnerable else 'clean'):
                return 2
            blocked |= vulnerable
        expected = 'vulnerable' if blocked else 'clean'
        if manifest.get('status') != expected:
            return 2
        return 1 if blocked else 0
    except (OSError, ValueError, KeyError, TypeError, EvidenceError):
        return 2


def scan(root, directory, trivy):
    if directory.exists() and any(directory.iterdir()):
        raise EvidenceError('dossier_preuves_non_vide')
    directory.mkdir(parents=True, exist_ok=True)
    manifest = {'schema': 1, 'created_at': datetime.now(timezone.utc).isoformat(),
                'status': 'error', 'images': {s: {'status': 'error', 'error': 'analyse_non_executee'} for s in SERVICES},
                'threshold': ['HIGH', 'CRITICAL']}
    path = directory / 'manifest.json'
    save(path, manifest)
    try:
        revision = command(['git', '-C', str(root), 'rev-parse', 'HEAD']).strip()
        if not re.fullmatch(r'[a-f0-9]{40}', revision):
            raise EvidenceError('revision_git_invalide')
        if command(['git', '-C', str(root), 'status', '--porcelain', '--untracked-files=no']).strip():
            raise EvidenceError('sources_suivies_modifiees')
        manifest['source_revision'] = revision
        if os.environ.get('GITHUB_SHA') and os.environ['GITHUB_SHA'] != revision:
            raise EvidenceError('revision_ci_differente_du_checkout')
        manifest['inputs_sha256'] = {name: sha(root / name) for name in (
            'docker-compose.prod.yml', 'docker/prod/Dockerfile', 'composer.lock', 'package-lock.json')}
        manifest['ci'] = {key: os.environ[key] for key in ('GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_SHA') if key in os.environ}
        compose = ['docker', 'compose', '--project-directory', str(root), '--env-file',
                   str(root / 'prod-data/env'), '-f', str(root / 'docker-compose.prod.yml')]
        config = parsed(compose + ['config', '--format', 'json'])
        if not all(s in config.get('services', {}) for s in SERVICES):
            raise EvidenceError('service_final_absent')
        for service in ('migrator', 'scheduler', 'maintenance'):
            if config['services'].get(service, {}).get('image') != config['services']['php']['image']:
                raise EvidenceError('alias_php_divergent')
        # Ne pas publier des versions/configurations héritées d'une variable TRIVY_*.
        env = {k: v for k, v in os.environ.items() if not k.startswith('TRIVY_')}
        with tempfile.TemporaryDirectory(prefix='ikoma-trivy-') as private:
            private = Path(private)
            cache = private / 'cache'
            empty_config, empty_ignore = private / 'config.yaml', private / 'ignore'
            empty_config.write_text('{}\n')
            empty_ignore.write_text('')
            common = ['--config', str(empty_config), '--cache-dir', str(cache)]
            version_text = command([trivy, '--version'], env=env)
            if not re.search(rf'^Version: {re.escape(TRIVY_VERSION)}\s*$', version_text, flags=re.MULTILINE):
                raise EvidenceError('version_scanner_inattendue')
            # Base unique téléchargée au début ; les trois scans refusent toute mise à jour différente.
            command([trivy, 'image', *common, '--download-db-only'], timeout=600, env=env)
            db = cache / 'db/trivy.db'
            metadata = json.loads((cache / 'db/metadata.json').read_text())
            if metadata.get('Version') != 2 or not metadata.get('UpdatedAt'):
                raise EvidenceError('metadonnees_base_absentes')
            manifest['scanner'] = {'version': TRIVY_VERSION, 'database_sha256': sha(db),
                                   'database_metadata': {k: metadata.get(k) for k in ('Version', 'NextUpdate', 'UpdatedAt', 'DownloadedAt')}}
            for service in SERVICES:
                item = manifest['images'][service]
                try:
                    item['stage'] = 'identification'
                    item['reference_compose'] = config['services'][service]['image']
                    item.update(inventory_service(service, config, compose, revision))
                    raw_path = private / f'{service}.json'
                    item['stage'] = 'scan'
                    command([trivy, 'image', *common, '--image-src', 'docker', '--skip-db-update',
                             '--scanners', 'vuln', '--severity', ','.join(SEVERITIES), '--ignore-unfixed=false',
                             '--ignorefile', str(empty_ignore), '--exit-code', '0', '--format', 'json',
                             '--output', str(raw_path), item['image_id']], timeout=600, env=env)
                    item['stage'] = 'validation_rapport'
                    clean, counts = report(json.loads(raw_path.read_text()), item['image_id'])
                    if clean.get('Trivy', {}).get('Version') != TRIVY_VERSION:
                        raise EvidenceError('version_scanner_inattendue')
                    destination = directory / f'image-{service}.json'
                    save(destination, clean)
                    item.update(status='vulnerable' if counts['HIGH'] + counts['CRITICAL'] else 'clean',
                                stage='complete', counts=counts, report_sha256=sha(destination), raw_report_sha256=sha(raw_path))
                    item.pop('error', None)
                except (EvidenceError, OSError, ValueError, KeyError, TypeError) as error:
                    item.update(status='error', error=str(error) if isinstance(error, EvidenceError) else 'preuve_invalide_ou_absente')
                save(path, manifest)
        errors = any(i['status'] == 'error' for i in manifest['images'].values())
        manifest['status'] = 'error' if errors else ('vulnerable' if any(i['status'] == 'vulnerable' for i in manifest['images'].values()) else 'clean')
    except (EvidenceError, OSError, ValueError, KeyError, TypeError) as error:
        manifest['error'] = str(error) if isinstance(error, EvidenceError) else 'preuve_invalide_ou_absente'
    save(path, manifest)
    return verify(directory)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('scan', 'verify'))
    parser.add_argument('--root', type=Path, default=Path.cwd())
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--trivy', default='/tmp/ikoma-security-tools/trivy')
    args = parser.parse_args()
    try:
        code = verify(args.output) if args.action == 'verify' else scan(args.root.resolve(), args.output, args.trivy)
    except EvidenceError:
        code = 2
    print({0: 'Contrôle complet : aucun HIGH/CRITICAL.', 1: 'Livraison bloquée : HIGH/CRITICAL détectées.',
           2: 'Livraison bloquée : preuve absente, invalide ou erreur technique.'}[code])
    return code


if __name__ == '__main__':
    raise SystemExit(main())
