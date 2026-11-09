"""Lecture seule : liste les HIGH/CRITICAL des rapports d'images finales (journal CI).

N'altère ni le seuil, ni les rapports, ni le code de sortie du contrôle : il rend visible
ce que le blocage « HIGH/CRITICAL détectées » contient. Sortie : une ligne par constat.
"""
import json
import sys
from pathlib import Path

SERVICES = ('php', 'db', 'nginx')


def main(directory):
    total = 0
    for service in SERVICES:
        path = directory / f'image-{service}.json'
        if not path.is_file():
            print(f'[{service}] rapport absent')
            continue
        report = json.loads(path.read_text())
        os_info = report.get('Metadata', {}).get('OS', {})
        print(f"[{service}] {report.get('Metadata', {}).get('ImageID', '?')[:19]} base={os_info.get('Family')} {os_info.get('Name')}")
        for target in report.get('Results', []):
            for v in target.get('Vulnerabilities', []):
                if v.get('Severity') not in ('HIGH', 'CRITICAL'):
                    continue
                total += 1
                print(f"  {v['Severity']:8} {v['VulnerabilityID']} | {target.get('Target')} | {v['PkgName']} {v['InstalledVersion']}"
                      f" | corrigé en: {v.get('FixedVersion') or 'AUCUN'} | statut: {v.get('Status', '?')}")
    print(f'Total HIGH/CRITICAL : {total}')


if __name__ == '__main__':
    main(Path(sys.argv[1]))
