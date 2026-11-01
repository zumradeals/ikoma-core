#!/usr/bin/env python3
"""Expose only PHP exception *class names* from a fictitious CI Laravel log.

Never print log lines, exception messages, SQL, URLs, tracebacks or identifiers.
"""
from collections import Counter
from pathlib import Path
import json
import re
import sys

file = Path(sys.argv[1])
if not file.is_file():
    print(json.dumps({"safe_php_exception_types": [], "log_available": False}))
    raise SystemExit(0)

# The application intentionally logs "Erreur applicative" with class and SQLSTATE.
# Class names are a bounded allowlist of identifier characters only.
matches = re.findall(
    r'Erreur applicative[^\n]{0,2000}?"type"\s*:\s*"([a-zA-Z_\\]{1,180}[a-zA-Z_0-9\\]*)"',
    file.read_text(errors="replace"),
)
counts = Counter(matches[-150:])
print(json.dumps({"safe_php_exception_types": [
    {"class": name.replace("\\\\", "\\"), "count": count}
    for name, count in counts.most_common(10)
], "log_available": True}))
