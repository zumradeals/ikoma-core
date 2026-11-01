#!/usr/bin/env python3
"""Fail closed on a final PHP image scan; publish only safe counts and identity."""
import json
import re
import sys
from pathlib import Path

if len(sys.argv) != 4:
    raise SystemExit("usage: qualify-php-image.py TRIVY_JSON EXPECTED_IMAGE_ID RESULT_JSON")

input_file, image_id, output_file = map(Path, (sys.argv[1], sys.argv[2], sys.argv[3]))
# The expected image ID is a file created from the image actually built by CI.
expected = image_id.read_text().strip()
if not re.fullmatch(r"sha256:[a-f0-9]{64}", expected):
    raise SystemExit("STOP: invalid image identity")
raw = json.loads(input_file.read_text())
meta = raw.get("Metadata") or {}
os_info = meta.get("OS") or {}
if (raw.get("SchemaVersion") != 2
        or raw.get("ArtifactType") != "container_image"
        or meta.get("ImageID") != expected
        or os_info.get("Family") != "alpine"
        or not os_info.get("Name")
        or (raw.get("Trivy") or {}).get("Version") != "0.76.1"
        or not isinstance(raw.get("Results"), list)
        or not any(item.get("Class") == "os-pkgs" and item.get("Type") == "alpine"
                   for item in raw["Results"] if isinstance(item, dict))):
    raise SystemExit("STOP: scan coverage, image identity, OS, or scanner version invalid")

counts = {"HIGH": 0, "CRITICAL": 0}
for item in raw["Results"]:
    if not isinstance(item, dict):
        raise SystemExit("STOP: malformed scan result")
    for issue in item.get("Vulnerabilities") or []:
        if (not isinstance(issue, dict)
                or not issue.get("VulnerabilityID")
                or not issue.get("PkgName")
                or issue.get("Severity") not in ("UNKNOWN", "LOW", "MEDIUM", "HIGH", "CRITICAL")):
            raise SystemExit("STOP: malformed finding")
        if issue["Severity"] in counts:
            counts[issue["Severity"]] += 1

result = {"scope": "final-php-image-only", "image_id": expected, "os": "alpine",
          "scanner": "Trivy 0.76.1", "counts": counts, "has_blocker": bool(sum(counts.values()))}
output_file.parent.mkdir(parents=True, exist_ok=True)
output_file.write_text(json.dumps(result, indent=2) + "\n")
print("PHP Alpine final:", counts["HIGH"], "HIGH,", counts["CRITICAL"], "CRITICAL")
if sum(counts.values()):
    raise SystemExit("STOP: HIGH/CRITICAL on final PHP image")
