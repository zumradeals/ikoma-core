#!/usr/bin/env python3
"""Verify full Trivy image identity, count high/critical and block unsafe PG adoption."""
import json
import re
import sys
from pathlib import Path

report_path, id_path, output_path = map(Path, sys.argv[1:4])
expected = id_path.read_text().strip()
if not re.fullmatch(r"sha256:[0-9a-f]{64}", expected):
    raise SystemExit("STOP: unexpected image ID")
raw = json.loads(report_path.read_text())
metadata = raw.get("Metadata") or {}
os_info = metadata.get("OS") or {}
results = raw.get("Results")
if (raw.get("SchemaVersion") != 2 or raw.get("ArtifactType") != "container_image"
        or (raw.get("Trivy") or {}).get("Version") != "0.76.1"
        or metadata.get("ImageID") != expected
        or os_info.get("Family") != "alpine" or not os_info.get("Name")
        or not isinstance(results, list)
        or not any(isinstance(r, dict) and r.get("Class") == "os-pkgs"
                   and r.get("Type") == "alpine" for r in results)):
    raise SystemExit("STOP: incomplete, mismatched or unverifiable PostgreSQL scan")

high, critical, go = 0, 0, []
for target in results:
    if not isinstance(target, dict):
        raise SystemExit("STOP: invalid scan target")
    vulnerabilities = target.get("Vulnerabilities") or []
    if not isinstance(vulnerabilities, list):
        raise SystemExit("STOP: invalid vulnerability list")
    for issue in vulnerabilities:
        if (not isinstance(issue, dict)
                or issue.get("Severity") not in ["UNKNOWN", "LOW", "MEDIUM", "HIGH", "CRITICAL"]
                or not issue.get("VulnerabilityID") or not issue.get("PkgName")
                or not issue.get("InstalledVersion")):
            raise SystemExit("STOP: invalid vulnerability record")
        if issue["Severity"] == "HIGH": high += 1
        if issue["Severity"] == "CRITICAL": critical += 1
        if issue["Severity"] in ("HIGH", "CRITICAL") and issue["PkgName"] == "stdlib":
            go.append({"cve": issue["VulnerabilityID"],
                       "installed": issue["InstalledVersion"],
                       "severity": issue["Severity"]})
summary = {"scope": "PG Alpine candidate only, not production",
           "image_id": expected, "trivy": "0.76.1", "os": os_info,
           "high": high, "critical": critical, "gosu_go_stdlib": go,
           "decision": "STOP_SECURITY_OPEN" if high + critical else "READY_FOR_ADDITIONAL_REVIEW",
           "note": "A passing fixture and a zero-symbol check never override this risk gate"}
output_path.parent.mkdir(parents=True, exist_ok=True)
output_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
print("PostgreSQL Alpine scan:", high, "HIGH;", critical, "CRITICAL")
if high + critical:
    raise SystemExit("STOP: unresolved HIGH/CRITICAL PostgreSQL candidate findings")
