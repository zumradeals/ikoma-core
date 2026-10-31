"""Sonde R04 sur la base fictive du runner Actions uniquement, sans données métier."""
import json
import os
from pathlib import Path
import re
import subprocess


def command(args, data=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True, timeout=120)
    if result.returncode:
        raise RuntimeError('Sonde R04 en échec ; aucune sortie privée publiée.')
    return result.stdout.strip()


def main():
    if os.environ.get('GITHUB_ACTIONS') != 'true':
        raise RuntimeError('Sonde réservée à la recette GitHub Actions fictive.')
    compose = ['docker', 'compose', '--env-file', 'prod-data/env', '-f', 'docker-compose.prod.yml']
    container = command(compose + ['ps', '-q', 'db'])
    if not re.fullmatch(r'[a-f0-9]{64}', container):
        raise RuntimeError('Conteneur DB de recette absent.')
    image = command(['docker', 'inspect', '--format', '{{.Image}}', container])
    if not re.fullmatch(r'sha256:[a-f0-9]{64}', image):
        raise RuntimeError('Identité DB absente.')
    run = ['docker', 'run', '--rm', '--network', 'none', '--read-only', '--entrypoint']
    version = command(run + ['gosu', image, '--version'])
    go = re.search(r'\bgo(\d+\.\d+\.\d+)\b', version)
    if not go or not re.fullmatch(r'1\.\d+[^\r\n]*', version):
        raise RuntimeError('Version gosu/Go non établie.')
    digest = command(run + ['sha256sum', image, '/usr/local/bin/gosu']).split()[0]
    if not re.fullmatch(r'[a-f0-9]{64}', digest):
        raise RuntimeError('Empreinte gosu absente.')
    nobody = command(run + ['gosu', image, 'nobody', 'id', '-u'])
    if not nobody.isdigit() or int(nobody) == 0:
        raise RuntimeError('Abandon de privilèges gosu non confirmé.')
    status = command(compose + ['exec', '-T', 'db', 'sh', '-c',
                               'while read -r field values; do case "$field" in Uid:) echo "$values";; esac; done < /proc/1/status'])
    uids = status.split()
    if len(uids) != 4 or any(not uid.isdigit() or int(uid) == 0 for uid in uids):
        raise RuntimeError('Processus PostgreSQL encore privilégié ou UID indéterminé.')
    sql = """
BEGIN;
CREATE TEMP TABLE r04_fixture (value integer);
INSERT INTO r04_fixture VALUES (1), (2);
GRANT SELECT, INSERT, UPDATE, DELETE ON r04_fixture TO ikoma_app;
SET LOCAL ROLE ikoma_app;
INSERT INTO r04_fixture VALUES (3);
UPDATE r04_fixture SET value=4 WHERE value=3;
DELETE FROM r04_fixture WHERE value=4;
DO $$ BEGIN
 IF (SELECT sum(value) FROM r04_fixture) <> 3 THEN RAISE EXCEPTION 'R04 SQL fictif'; END IF;
END $$;
RESET ROLE;
DO $$ BEGIN
 IF NOT has_table_privilege('ikoma_app','public.audit_logs','SELECT')
 OR NOT has_table_privilege('ikoma_app','public.audit_logs','INSERT')
 OR has_table_privilege('ikoma_app','public.audit_logs','UPDATE')
 OR has_table_privilege('ikoma_app','public.audit_logs','DELETE')
 OR has_table_privilege('ikoma_app','public.audit_logs','TRUNCATE')
 OR has_table_privilege('ikoma_app','public.access_events','UPDATE')
 OR has_table_privilege('ikoma_app','public.access_events','DELETE')
 OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname='ikoma_app' AND (rolsuper OR rolcreatedb OR rolcreaterole))
 THEN RAISE EXCEPTION 'R04 droits inattendus'; END IF;
END $$;
ROLLBACK;
SELECT 'R04_SQL_OK';
"""
    output = command(compose + ['exec', '-T', 'db', 'psql', '-U', 'ikoma', '-d', 'ikoma',
                                '-v', 'ON_ERROR_STOP=1', '-At'], sql)
    if output.splitlines()[-1] != 'R04_SQL_OK':
        raise RuntimeError('Recette SQL non terminée.')
    print(json.dumps({'image_id': image, 'gosu_version': version, 'go_embedded_version': go.group(1),
                      'gosu_sha256': digest, 'gosu_nobody_uid': int(nobody), 'postgres_pid1_uids': list(map(int, uids)),
                      'sql_fixture_rolled_back': True, 'audit_append_only_permissions': True,
                      'backup_restore_evidence': 'étape deploy/prod/ci-recette.sh précédente, base fictive',
                      'scope': 'mesure du candidat courant, aucune correction ni exception de vulnérabilité'},
                     ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.TimeoutExpired, ValueError, IndexError):
        raise SystemExit('Sonde R04 refusée : mesure ou contrôle SQL non établi.')
