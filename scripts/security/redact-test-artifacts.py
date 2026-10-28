"""Retirer les secrets de recette des instantanés textuels Playwright avant publication."""
import json
from pathlib import Path
import re

secrets = set()


def collect(value, key=''):
    if isinstance(value, dict):
        for child_key, child in value.items():
            collect(child, str(child_key))
    elif isinstance(value, list):
        for child in value:
            collect(child, key)
    elif isinstance(value, str) and len(value) >= 4 and re.search(r'password|secret|token|enrol|pin', key, re.I):
        secrets.add(value)


for file in Path('.secrets').glob('*.json'):
    collect(json.loads(file.read_text()))
for file in Path('test-results').rglob('*'):
    if file.suffix in ['.md', '.json', '.txt', '.html'] and file.is_file():
        text = file.read_text()
        for secret in sorted(secrets, key=len, reverse=True):
            text = text.replace(secret, '[SECRET DE RECETTE MASQUÉ]')
        file.write_text(text)
print('Instantanés textuels expurgés des secrets de recette.')
