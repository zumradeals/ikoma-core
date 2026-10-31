# Vérification des livrables S0.1

Les extraits de logs Trivy sont limités à la section d'analyse, sans séquences ANSI et avec les espaces de fin de ligne normalisés. Les tables et leurs horodatages sont conservés ; ces extraits ne sont pas présentés comme les fichiers de logs bruts complets.

Exécuté le 31 octobre 2026 dans un checkout isolé de la référence main. Les commandes ne reconstruisent pas l'application et n'interrogent aucun serveur réel.

| Vérification exécutée | Résultat |
|---|---|
| SHA-256 des ZIP main/PR vs digests Actions | deux correspondances exactes (manifest.json) |
| Métadonnées head/checkout via API et logs des quatre jobs | main conforme ; PR sur merge de test fa13a147… |
| `git diff 9a0ec43f2d128c0555e6138f3c71de44117061fc c987326601133dac2233dab19c384d969cdfb335 --stat` | vide : arbres identiques |
| Comparaison des tuples Trivy PR/main (type/cible, paquet, CVE, version, correctif, gravité) | identiques pour les trois images ; IDs PHP différents conservés |
| Comparaison audits | Composer et Gitleaks identiques ; npm mêmes avis/compteurs, seul fixAvailable de glob diffère |
| Tables production PR/main | 120 couples paquet/CVE/versions et gravités identiques ; tags de commit différents |
| CSV vs sources JSON et tables | 374 lignes, aucune clé d'occurrence dupliquée ; 213 CI + 120 final + 1 Composer + 35 relations npm + 5 règles ZAP |
| `python3 -m unittest discover -s tests/security` | **7 tests réussis** |
| `python3 scripts/security/zap-gate.py <rapport-main-zap.json>` | code 0, cinq exceptions documentées, aucune alerte supplémentaire |
| même gate avec rapport PR39 | code 0, mêmes cinq règles ; nombre d'instances 10112 différent conservé dans le rapport principal |
| `git diff --check` (puis `git diff --cached --check` avant commit) | aucune erreur |

Les rapports ZAP complets utilisés pour exécuter le gate proviennent des archives originales, non du résumé expurgé. Le résumé ne permet pas de rejouer les vérifications d'instances. Les fichiers originaux sont récupérables depuis les IDs d'artefact du manifest tant qu'ils sont conservés par GitHub. Ne pas publier leurs valeurs de cookies ni des journaux d'installation.

Pour reproduire les comptages principaux avec Python déjà présent dans le projet, depuis la racine :

```python
import collections, csv, json
from pathlib import Path
p = Path('docs/security')
rows = list(csv.DictReader((p / 'S0.1-REGISTRE-VULNERABILITES.csv').open()))
keys = [(r['outil'], r['environnement'], r['cible'], r['paquet'],
         r['version_installee'], r['identifiant']) for r in rows]
assert len(rows) == len(set(keys)) == 374
all_v = []
for service, expected in [('php', 125), ('db', 88), ('nginx', 0)]:
    report = json.loads((p / f'preuves/trivy-main-{service}.json').read_text())
    vulnerabilities = [v for target in report['Results']
                       for v in (target.get('Vulnerabilities') or [])]
    assert len(vulnerabilities) == expected
    all_v += vulnerabilities
assert collections.Counter(v['Severity'] for v in all_v) == {'HIGH': 209, 'CRITICAL': 4}
assert len({v['VulnerabilityID'] for v in all_v}) == 110
assert len({v['VulnerabilityID'] for v in all_v if v['Severity'] == 'CRITICAL'}) == 3
final = json.loads((p / 'preuves/trivy-production-php-occurrences.json').read_text())
assert len(final) == 120
assert collections.Counter(v['gravite'] for v in final) == {'HIGH': 118, 'CRITICAL': 2}
assert len({v['id'] for v in final}) == 83
print('Inventaire documentaire cohérent')
```

L'extraction des tables fusionnées traite la cellule vide de `ncurses-bin` comme la même CVE que `ncurses-base`, conformément aux frontières de lignes de la table. Chaque occurrence extraite a été rapprochée du JSON dev par paquet/version/CVE et gravité ; aucun paquet/CVE supplémentaire n'a été inventé. Les enrichissements du registre final provenant du JSON dev sont explicitement indiqués dans sa colonne preuve ; ils ne remplacent pas un JSON production manquant.

Les checks applicatifs, lint PHP/frontend, E2E, reconstruction Docker et scans frais **n'ont pas été exécutés dans S0.1**. Les succès des runs de référence sont des observations historiques. Une CI déclenchée par la PR documentaire utilisera de nouvelles bases externes : elle pourra produire d'autres résultats, qui ne remplacent pas cette photographie du commit de référence.
