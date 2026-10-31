#!/usr/bin/env python3
"""Contrôle strict des résultats de la qualification expérimentale (pas un VEX ni une exception Trivy)."""
import argparse
import hashlib
import json
from pathlib import Path


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def trivy(path):
    doc = json.loads(Path(path).read_text())
    if doc.get("ArtifactType") != "container_image" or not isinstance(doc.get("Results"), list):
        raise RuntimeError("Rapport Trivy invalide")
    if not any(row.get("Class") == "os-pkgs" for row in doc["Results"]):
        raise RuntimeError("Aucune couverture des paquets OS")
    items = [
        (v["VulnerabilityID"], v["PkgName"], v.get("InstalledVersion"), v.get("Severity"))
        for row in doc["Results"] for v in (row.get("Vulnerabilities") or [])
    ]
    return {
        "image_id": doc.get("Metadata", {}).get("ImageID"),
        "os": doc.get("Metadata", {}).get("OS"),
        "high": sum(x[3] == "HIGH" for x in items),
        "critical": sum(x[3] == "CRITICAL" for x in items),
        "critical_findings": sorted([
            {"cve": x[0], "package": x[1], "version": x[2]}
            for x in items if x[3] == "CRITICAL"
        ], key=lambda row: (row["cve"], row["package"])),
        "high_findings": sorted([
            {"cve": x[0], "package": x[1], "version": x[2]}
            for x in items if x[3] == "HIGH"
        ], key=lambda row: (row["cve"], row["package"])),
        "cve_2026_6653": sorted(set((v[1], v[2]) for v in items if v[0] == "CVE-2026-6653")),
        "cve_2025_68121": sorted(set((v[1], v[2]) for v in items if v[0] == "CVE-2025-68121")),
        "report_sha256": sha(path),
    }


def alpine(ns):
    data = {"candidate_only": True, "php": trivy(ns.php), "postgres": trivy(ns.postgres)}
    for service in ("php", "postgres"):
        if not data[service]["image_id"]:
            raise RuntimeError("ID candidat non démontré")
    # Évaluer spécifiquement la CVE étudiée ; les autres alertes restent visibles.
    data["libxml2_not_detected"] = all(not data[s]["cve_2026_6653"] for s in ("php", "postgres"))
    Path(ns.output).write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
    if not data["libxml2_not_detected"]:
        raise RuntimeError("CVE libxml2 encore détectée : candidate non qualifiée")
    # Ceci ne valide pas les risques nouveaux ou la compatibilité applicative complète.
    print("CVE libxml2 absente des deux images expérimentales, sous réserve de compatibilité")


def gosu(ns):
    if sha(ns.binary) != "52c8749d0142edd234e9d6bd5237dff2d81e71f43537e2f4f66f75dd4b243dd0":
        raise RuntimeError("gosu différent du binaire R04 : STOP")
    advisory = json.loads(Path(ns.advisory).read_text())
    if advisory.get("id") != "GO-2026-4337" or "CVE-2025-68121" not in advisory.get("aliases", []):
        raise RuntimeError("Avis officiel Go absent ou divergent")
    # JSON par événements, pas un unique objet JSON.
    # Govulncheck émet un flux JSON (objets successifs, parfois indentés sur plusieurs lignes).
    raw_stream = Path(ns.govulncheck).read_text()
    events, pos, decoder = [], 0, json.JSONDecoder()
    while pos < len(raw_stream):
        while pos < len(raw_stream) and raw_stream[pos].isspace():
            pos += 1
        if pos >= len(raw_stream):
            break
        event, pos = decoder.raw_decode(raw_stream, pos)
        if not isinstance(event, dict):
            raise RuntimeError("Événement govulncheck non conforme")
        events.append(event)
    if not events:
        raise RuntimeError("Flux govulncheck vide")
    config = next((event.get("config") for event in events if isinstance(event.get("config"), dict)), None)
    if not config or config.get("scan_mode") != "binary" or config.get("scan_level") != "symbol":
        raise RuntimeError("Govulncheck n'atteste pas d'une analyse binaire au niveau symbole")
    findings = [event["finding"] for event in events if "finding" in event]
    relevant = [f for f in findings if f.get("osv") == "GO-2026-4337"]
    symbol_findings = [f for f in relevant if any(t.get("function") for t in f.get("trace", []))]
    summary = {
        "binary_sha256": sha(ns.binary),
        "govulncheck_sha256": sha(ns.govulncheck),
        "official_advisory_sha256": sha(ns.advisory),
        "scanner": config.get("scanner_name"),
        "scan_mode": config.get("scan_mode"),
        "scan_level": config.get("scan_level"),
        "go_advisory": advisory["id"],
        "findings_for_cve": len(relevant),
        "vulnerable_symbols_found": len(symbol_findings),
        "interpretation": "preuve de symboles uniquement, pas de certification ni dispense automatique Trivy",
    }
    Path(ns.output).write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n")
    if symbol_findings:
        raise RuntimeError("Symboles vulnérables détectés dans gosu : STOP")
    print("Aucun symbole vulnérable signalé sur le binaire exact par govulncheck ; décision humaine requise")


def main():
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="action", required=True)
    a = sub.add_parser("alpine")
    a.add_argument("--php", required=True)
    a.add_argument("--postgres", required=True)
    a.add_argument("--output", required=True)
    g = sub.add_parser("gosu")
    g.add_argument("--binary", required=True)
    g.add_argument("--advisory", required=True)
    g.add_argument("--govulncheck", required=True)
    g.add_argument("--output", required=True)
    ns = p.parse_args()
    (alpine if ns.action == "alpine" else gosu)(ns)


if __name__ == "__main__":
    main()
