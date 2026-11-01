#!/usr/bin/env python3
"""Crosswalk every Trivy HIGH/CRITICAL Go CVE to binary govulncheck.
Absence from govulncheck is *unknown*, never automatically 'not applicable'.
"""
import hashlib
import json
from pathlib import Path
import sys

work=Path(sys.argv[1])
out=Path(sys.argv[2])
def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

trivyfile=work/"trivy-postgres.json"
data=json.loads(trivyfile.read_text())
image_id=(work/"postgres-image-id.txt").read_text().strip()
if data.get("ArtifactType")!="container_image" or data.get("Metadata",{}).get("ImageID")!=image_id:
    raise SystemExit("STOP: Trivy report not bound to scanned image")
if not any(r.get("Class")=="os-pkgs" for r in data.get("Results",[])):
    raise SystemExit("STOP: OS packages missing from Trivy")
rows=[v for r in data["Results"] for v in (r.get("Vulnerabilities") or [])
      if v.get("PkgName")=="stdlib" and v.get("Severity") in ("HIGH","CRITICAL")]
# No HIGH/CRITICAL Go findings is acceptable only when the **same extracted**
# gosu binary proves it was compiled with the independently qualified toolchain.
# The final independent Trivy gate still examines ALL packages and severities.
if not rows:
    version_file=work/"gosu-version.txt"
    if not version_file.is_file():
        raise SystemExit("STOP: clean Go scan without extracted-binary metadata")
    version_text=version_file.read_text()
    if ("go1.27.3" not in version_text
            or "path\tgithub.com/tianon/gosu" not in version_text
            or "vcs.revision=6456aaa0f3c854d199d0f037f068eb97515b7513" not in version_text
            or "vcs.modified=false" not in version_text):
        raise SystemExit("STOP: no source-pinned, clean Go 1.27.3 gosu binary provenance")
if len(set(v.get("VulnerabilityID") for v in rows))!=len(rows):
    raise SystemExit("STOP: duplicate Go stdlib CVE; requires separate target accounting")
for v in rows:
    if not all(v.get(k) for k in ("VulnerabilityID","PkgName","InstalledVersion","Severity")):
        raise SystemExit("STOP: incomplete Trivy CVE record")
raw=(work/"govulncheck.json").read_text()
decoder=json.JSONDecoder()
events=[]
p=0
while p<len(raw):
    while p<len(raw) and raw[p].isspace():p+=1
    if p>=len(raw):break
    event,p=decoder.raw_decode(raw,p)
    if not isinstance(event,dict):
        raise SystemExit("STOP: invalid govulncheck event")
    events.append(event)
config=next((v["config"] for v in events if isinstance(v.get("config"),dict)),None)
if not config or config.get("scan_mode")!="binary" or config.get("scan_level")!="symbol":
    raise SystemExit("STOP: missing binary symbol-level govulncheck coverage")
advisories={a["osv"]["id"]:a["osv"] for a in events if isinstance(a.get("osv"),dict) and a["osv"].get("id")}
findings=[a["finding"] for a in events if isinstance(a.get("finding"),dict)]
detail=[]
for v in sorted(rows,key=lambda x:(x["Severity"]!="CRITICAL",x["VulnerabilityID"])):
    cve=v["VulnerabilityID"]
    osv_ids=[k for k,a in advisories.items() if cve in a.get("aliases",[])]
    matches=[f for f in findings if f.get("osv") in osv_ids]
    symbols=sorted(set(t["function"] for f in matches for t in f.get("trace",[]) if t.get("function")))
    # Three states: symbol observed, advisory mapped without symbol, no mapped advisory.
    status=("SYMBOL_DETECTED" if symbols else
            "NO_VULNERABLE_SYMBOL_REPORTED" if osv_ids else
            "UNMAPPED_NEEDS_MANUAL_REVIEW")
    detail.append({
        "cve":cve,"severity":v["Severity"],"package":v["PkgName"],
        "package_version":v["InstalledVersion"],"fixed_version":v.get("FixedVersion"),
        "advisories":sorted(osv_ids),"status":status,
        "matched_findings":len(matches),"vulnerable_symbols":symbols
    })
candidate=work/"gosu"
report={
    "decision":"STOP_REVIEW_REQUIRED" if rows else "NO_STDLIB_HIGH_CRITICAL_FINDINGS",
    "scope":"PostgreSQL Alpine gosu binary - CI fixture only",
    "image_id":image_id,
    "binary_sha256":digest(candidate),
    "go_binary_version_evidence":(work/"gosu-version.txt").read_text().strip() if (work/"gosu-version.txt").is_file() else None,
    "govulncheck_sha256":digest(work/"govulncheck.json"),
    "trivy_sha256":digest(trivyfile),
    "trivy_high":sum(x["severity"]=="HIGH" for x in detail),
    "trivy_critical":sum(x["severity"]=="CRITICAL" for x in detail),
    "unmapped":sum(x["status"]=="UNMAPPED_NEEDS_MANUAL_REVIEW" for x in detail),
    "symbols_detected":sum(x["status"]=="SYMBOL_DETECTED" for x in detail),
    "items":detail,
    "limits":["One vulnerability row is not proof of exploitability.",
              "No finding in a binary-symbol scan is not proof of universal absence of risk.",
              "Manual per-CVE disposition and artifact digest signoff required.",
              "This report does not grant a VEX exception or relax Trivy."],
}
out.parent.mkdir(parents=True,exist_ok=True)
out.write_text(json.dumps(report,indent=2,ensure_ascii=False)+"\n")
print("Go HIGH:",report["trivy_high"],"CRITICAL:",report["trivy_critical"],
      "unmapped:",report["unmapped"],"symbols found:",report["symbols_detected"])
if report["symbols_detected"]:
    raise SystemExit("STOP: vulnerable symbols found; risk escalation required")
