"""Exceptions examinées dans docs/REVUE_SECURITE.md ; toute autre alerte bloque."""
import json
import sys
from pathlib import Path
from urllib.parse import urlparse


def approved(alert):
    ref = alert.get('alertRef')
    risk = str(alert.get('riskcode'))
    instances = alert.get('instances', [])
    if not instances:
        return False
    for instance in instances:
        url = urlparse(instance.get('uri', ''))
        if url.hostname != 'localhost' or url.scheme != 'http':
            return False
        path = url.path or '/'
        if ref == '10010' and risk == '1':
            if instance.get('param') != 'XSRF-TOKEN' or instance.get('evidence') != 'Set-Cookie: XSRF-TOKEN':
                return False
        elif ref == '10109' and risk == '0':
            if path != '/' or 'type="module"' not in instance.get('evidence', ''):
                return False
        elif ref == '10112' and risk == '0':
            if path != '/' or instance.get('param') != 'laravel-session':
                return False
        elif ref == '10049-1' and risk == '0':
            if path not in ['/', '/sitemap.xml'] or instance.get('evidence') not in ['private', 'no-store']:
                return False
        elif ref == '10049-3' and risk == '0':
            if not (path.startswith('/build/') or path == '/robots.txt'):
                return False
        else:
            return False
    return True


report = json.loads(Path(sys.argv[1]).read_text())
if not report.get('site'):
    print('Rapport ZAP sans site analysé : refusé.')
    sys.exit(2)
blocked, exceptions = [], []
for site in report.get('site', []):
    for alert in site.get('alerts', []):
        result = {'regle': alert.get('alertRef'), 'alerte': alert.get('name'), 'risque': alert.get('riskcode')}
        if not approved(alert):
            # Diagnostic : l'instance exacte (chemin, paramètre, preuve) pour décider en connaissance de cause, jamais pour assouplir à l'aveugle.
            result['instances'] = [{'chemin': urlparse(i.get('uri', '')).path or '/', 'parametre': i.get('param'), 'preuve': (i.get('evidence') or '')[:80]} for i in alert.get('instances', [])]
        (exceptions if approved(alert) else blocked).append(result)
print(json.dumps({'exceptions_documentees': exceptions, 'alertes_a_examiner': blocked}, ensure_ascii=False))
sys.exit(1 if blocked else 0)
