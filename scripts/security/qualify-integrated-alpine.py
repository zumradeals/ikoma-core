#!/usr/bin/env python3
"""Review all three actually running experimental images and the Alpine gosu binary.
No CVE suppression. Block technical errors and any unexpected critical finding.
"""
import hashlib
import json
from pathlib import Path
import sys

root = Path(sys.argv[1])
out = Path(sys.argv[2])

def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def fail(message):
    raise SystemExit("INTEGRATED ALPINE QUALIFICATION STOP: " + message)

services = ("php", "db", "nginx")
data = {"kind": "full-app-ci-candidate-not-a-release", "images": {}}
for service in services:
    path = root / ("trivy-" + service + ".json")
    doc = json.loads(path.read_text())
    expected = (root / ("image-" + service + "-id.txt")).read_text().strip()
    os_info = doc.get("Metadata", {}).get("OS", {})
    if (doc.get("ArtifactType") != "container_image" or
        doc.get("Metadata", {}).get("ImageID") != expected or
        not isinstance(doc.get("Results"), list) or
        not any(t.get("Class") == "os-pkgs" for t in doc["Results"])):
        fail("incoherent image scan: " + service)
    rows = []
    for result in doc["Results"]:
        for vuln in result.get("Vulnerabilities") or []:
            if not all(vuln.get(k) for k in ("VulnerabilityID", "PkgName", "InstalledVersion", "Severity")):
                fail("incomplete CVE record: " + service)
            rows.append({
                "cve": vuln["VulnerabilityID"],
                "package": vuln["PkgName"],
                "installed_version": vuln["InstalledVersion"],
                "fixed_version": vuln.get("FixedVersion"),
                "severity": vuln["Severity"],
            })
    critical = [v for v in rows if v["severity"] == "CRITICAL"]
    high = [v for v in rows if v["severity"] == "HIGH"]
    if any(v["cve"] == "CVE-2026-6653" for v in rows):
        fail("libxml2 CVE still present in " + service)
    if service != "db" and critical:
        fail("unexpected critical in non-database image " + service)
    if service == "db" and any(v["cve"] != "CVE-2025-68121" for v in critical):
        fail("additional critical in PostgreSQL candidate")
    data["images"][service] = {
        "image_id": expected,
        "os": os_info,
        "trivy_report_sha256": sha(path),
        "high_count": len(high),
        "critical_count": len(critical),
        "high_findings": high,
        "critical_findings": critical,
    }

candidate_gosu = root / "alpine-gosu"
go_advisory = json.loads((root / "GO-2026-4337.json").read_text())
if go_advisory.get("id") != "GO-2026-4337" or "CVE-2025-68121" not in go_advisory.get("aliases", []):
    fail("official Go advisory mismatch")

stream = (root / "govulncheck.json").read_text()
decoder = json.JSONDecoder()
events, pos = [], 0
while pos < len(stream):
    while pos < len(stream) and stream[pos].isspace():
        pos += 1
    if pos >= len(stream):
        break
    event, pos = decoder.raw_decode(stream, pos)
    if not isinstance(event, dict):
        fail("malformed govulncheck JSON stream")
    events.append(event)
config = next((x["config"] for x in events if isinstance(x.get("config"), dict)), {})
if config.get("scan_mode") != "binary" or config.get("scan_level") != "symbol":
    fail("govulncheck must analyze binary symbols")
findings = [x["finding"] for x in events if "finding" in x and x["finding"].get("osv") == "GO-2026-4337"]
symbols = [x for x in findings if any(y.get("function") for y in x.get("trace", []))]
data["gosu"] = {
    "binary_sha256": sha(candidate_gosu),
    "version_information_sha256": sha(root / "alpine-gosu-version.txt"),
    "go_advisory": go_advisory["id"],
    "govulncheck_json_sha256": sha(root / "govulncheck.json"),
    "finding_count": len(findings),
    "vulnerable_symbol_count": len(symbols),
    "result": "evidence-of-no-reachable-vulnerable-symbols-not-security-waiver",
}
if symbols:
    fail("actual Go vulnerable symbols found in Alpine PostgreSQL gosu")
if data["images"]["db"]["critical_count"] and not findings:
    fail("Trivy found critical Go CVE but govulncheck did not cover the advisory; manual review required")
data["limits"] = [
    "No release approval or scan exception.",
    "Exact candidate image IDs and local Git commit required for reproducibility.",
    "Alpine differs from Debian in libc/locale/possible indexes; only new fictitious database tested.",
    "All residual HIGH vulnerabilities remain recorded and require disposition.",
]
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
print("Three integrated candidate image scans validated; no additional CRITICAL; Alpine gosu reachability checked.")
