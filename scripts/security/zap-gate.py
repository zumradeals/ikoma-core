"""Pas d'ignorance globale : toute alerte doit passer ou être justifiée par règle et contexte."""
import json
import sys
from pathlib import Path

report = json.loads(Path(sys.argv[1]).read_text())
blocked = []
for site in report.get('site', []):
    for alert in site.get('alerts', []):
        # Aucune exception tant que la recette n'a pas produit un cas concret examiné.
        blocked.append({'regle': alert.get('pluginid'), 'alerte': alert.get('name'), 'risque': alert.get('riskcode')})
print(json.dumps({'alertes_a_examiner': blocked}, ensure_ascii=False))
sys.exit(1 if blocked else 0)
