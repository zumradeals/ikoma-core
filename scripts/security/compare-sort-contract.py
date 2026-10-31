#!/usr/bin/env python3
"""Cross-platform name-order contract: never treat default locale order as portable."""
import json
from pathlib import Path
import sys

root=Path(sys.argv[1])
expected_fields=("explicit_order","normalized_explicit_order","priority_tiebreak_order",
    "normalized_names","search_ecole","search_kone","index_names")
out={"scope":"synthetic data only", "contract":"PROPOSED; no production adoption", "images":{}, "before_after":{}}
docs={}
for os_name in ("debian","alpine"):
    phases={}
    for phase in ("before","reindexed","restored"):
        path=root/f"{os_name}-{phase}.json"
        if not path.is_file():
            raise SystemExit("STOP: missing "+str(path))
        lines=[s.strip() for s in path.read_text().splitlines() if s.strip()]
        if len(lines)!=1:
            raise SystemExit("STOP: ambiguous JSON snapshot "+str(path))
        row=json.loads(lines[0])
        if row.get("pg_major")!="18" or row.get("encoding")!="UTF8" or row.get("count")!=22:
            raise SystemExit("STOP: PostgreSQL mismatch or incomplete fixture")
        if not all(row.get(k) is not None for k in expected_fields+("implicit_order",)):
            raise SystemExit("STOP: missing contract output "+str(path))
        if len(row["explicit_order"])!=22 or len(set(row["explicit_order"]))!=22:
            raise SystemExit("STOP: duplicate or lost IDs")
        if len(row["index_names"])!=3:
            raise SystemExit("STOP: required indexes absent")
        phases[phase]=row
    before=phases["before"]
    for phase in ("reindexed","restored"):
        if phases[phase]!=before:
            raise SystemExit("STOP: reindex/restore changed records or sort for "+os_name+": "+phase)
    if len(before["search_ecole"])!=4 or len(before["search_kone"])!=4:
        raise SystemExit("STOP: normalized membership search altered")
    vip_first="00000000-0000-4000-8000-000000000020"
    if before["priority_tiebreak_order"][0]!=vip_first:
        raise SystemExit("STOP: VIP priority / score order broken")
    docs[os_name]=before
    out["before_after"][os_name]="REINDEX_RESTORE_IDENTICAL"
    out["images"][os_name]=(root/f"{os_name}-image-id.txt").read_text().strip()

base=docs["debian"]
other=docs["alpine"]
out["implicit_order_equal"]=base["implicit_order"]==other["implicit_order"]
out["metadata"]={n:{k:docs[n][k] for k in ("pg_major","encoding","collation","collation_provider")} for n in docs}
out["portable_candidate_checks"]={k:base[k]==other[k] for k in expected_fields}
out["different_implicit_order_on_same_fixture"]=not out["implicit_order_equal"]
out["warnings"]=[
    "Portable candidate changes Debian legacy presentation order; executive contract approval required.",
    "This verifies 22 synthetic UUID records; not production data or index migration.",
    "Real application code has NOT been changed to use COLLATE C.",
    "pg_trgm scoring and JavaScript Unicode equivalence require separate end-to-end tests.",
]
out["decision"]=("CANDIDATE_COMPARABLE_NOT_APPROVED" if all(out["portable_candidate_checks"].values())
                 else "STOP_PORTABLE_CONTRACT_MISMATCH")
(root/"contract-verdict.json").write_text(json.dumps(out,ensure_ascii=False,indent=2)+"\n")
print("Candidate:",out["decision"],"implicit order equal:",out["implicit_order_equal"],
      "reindex+restore: PASS on both")
if out["decision"].startswith("STOP_"):
    raise SystemExit(1)
