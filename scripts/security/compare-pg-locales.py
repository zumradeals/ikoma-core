#!/usr/bin/env python3
"""Candidate-only PostgreSQL Debian/Alpine equivalence evidence. Never tests customer data."""
import json
from pathlib import Path
import sys

folder=Path(sys.argv[1])
def read(name):
    path=folder/(name+".txt")
    if not path.is_file():
        raise SystemExit("missing-fixture-output-"+name)
    result={}
    for line in path.read_text().splitlines():
        key,sep,value=line.partition("|")
        if not sep or not key or key in result:
            raise SystemExit("invalid-or-duplicate-fixture-key-"+name)
        result[key]=value
    needed={"server_version","locale","provider","encoding","implicit_sort","sort","unaccent","search","trigram","indexes"}
    if not needed.issubset(result):
        raise SystemExit("incomplete-database-evidence-"+name)
    if not result["server_version"].startswith("18."):
        raise SystemExit("unexpected-postgresql-version-"+name)
    if result["encoding"]!="UTF8":
        raise SystemExit("unexpected-postgresql-encoding-"+name)
    return result
debian,alpine=read("debian"),read("alpine")
comparisons={k:{"equal":debian[k]==alpine[k],
                "debian":debian[k],"alpine":alpine[k]}
             for k in ("sort","unaccent","search","trigram","indexes")}
metadata={k:{"debian":debian[k],"alpine":alpine[k]}
          for k in ("server_version","locale","provider","encoding")}
differences=[k for k,v in comparisons.items() if not v["equal"]]
summary={"decision":"BLOCK_ADOPTION_REVIEW_REQUIRED" if differences else "MATCH_ON_FIXTURE_ONLY",
         "fixture_only":True,"diff_keys":differences,
         "historical_implicit_sort_diverges":debian["implicit_sort"]!=alpine["implicit_sort"],
         "historical_implicit_sort":{"debian":debian["implicit_sort"],"alpine":alpine["implicit_sort"]},
         "metadata":metadata,"comparisons":comparisons,
         "limitations":["A limited fixture cannot establish compatibility of existing customer databases.",
                        "Never reuse a PostgreSQL data directory across base distributions.",
                        "Index/collation versions and REINDEX risk require separate migration rehearsal."]}
(folder/"comparison.json").write_text(json.dumps(summary,indent=2,ensure_ascii=False)+"\n")
print("PostgreSQL synthetic-fixture parity verdict:",summary["decision"],"different:",",".join(differences) or "none")
# The historical implicit sort remains recorded; only the adopted explicit contract is gated.
if differences:
    raise SystemExit(1)
