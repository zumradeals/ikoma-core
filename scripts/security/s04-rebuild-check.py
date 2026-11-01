#!/usr/bin/env python3
"""S0.4: pin qualification parents and verify two independent gosu rebuilds.

This script never connects to PostgreSQL, modifies Compose, or moves data.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path

UPSTREAM_COMMIT = "6456aaa0f3c854d199d0f037f068eb97515b7513"
GO_VERSION = "go1.27.3"
PARENTS = {
    "golang:1.27.3-alpine3.24": "golang",
    "postgres:18.6-alpine3.24": "postgres",
}
REVISION = "vcs.revision=" + UPSTREAM_COMMIT

def stop(why):
    raise SystemExit("STOP S0.4: " + why)

def sha256(p):
    return hashlib.sha256(Path(p).read_bytes()).hexdigest()

def pin(args):
    source = Path(args.source).read_text()
    resolved = {}
    for base, repo in PARENTS.items():
        digest = getattr(args, repo)
        if not re.fullmatch(re.escape(repo) + r"@sha256:[0-9a-f]{64}", digest):
            stop("expected immutable digest reference for " + repo)
        if source.count("FROM " + base) != 1:
            stop("qualified Dockerfile changed for " + repo)
        source = source.replace("FROM " + base, "FROM " + digest, 1)
        resolved[repo] = {"source_tag": base, "locked_ref": digest}
    if "GOSU_SOURCE_SHA=" + UPSTREAM_COMMIT not in source:
        stop("upstream source commit changed")
    if "CGO_ENABLED=0 go build -trimpath -buildvcs=true" not in source:
        stop("qualified build flags changed")
    target = Path(args.output)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(source)
    Path(args.evidence).write_text(json.dumps({
        "scope": "independent-laboratory-only",
        "production_compose_modified": False,
        "upstream_commit": UPSTREAM_COMMIT,
        "parents": resolved,
        "candidate_dockerfile_sha256": hashlib.sha256(source.encode()).hexdigest(),
    }, indent=2) + "\n")

def verify(args):
    binaries = [Path(args.a), Path(args.b)]
    details = [Path(args.a_metadata), Path(args.b_metadata)]
    ids = [Path(args.a_imageid).read_text().strip(),
           Path(args.b_imageid).read_text().strip()]
    digests = [sha256(p) for p in binaries]
    if not all(re.fullmatch(r"sha256:[0-9a-f]{64}", i) for i in ids):
        stop("invalid final image IDs")
    if digests[0] != digests[1]:
        stop("gosu output is not bit-for-bit reproducible")
    for i, meta_path in enumerate(details):
        info = meta_path.read_text()
        if not (GO_VERSION in info and
                "path\tgithub.com/tianon/gosu" in info and
                REVISION in info and "vcs.modified=false" in info):
            stop("source/compiler metadata missing in build " + str(i+1))
    if Path(args.scan_a).is_file() is False or Path(args.scan_b).is_file() is False:
        stop("missing independent Trivy candidate scans")
    for i, fp in enumerate([args.scan_a, args.scan_b]):
        result = json.loads(Path(fp).read_text())
        if (result.get("image_id") != ids[i] or
                result.get("high") != 0 or result.get("critical") != 0 or
                result.get("decision") != "READY_FOR_ADDITIONAL_REVIEW"):
            stop("image scan failed or mismatched for rebuild " + str(i+1))
    Path(args.evidence).write_text(json.dumps({
        "scope": "two-isolated-CI-rebuilds-not-release-approval",
        "gosu_binary_sha256": digests[0],
        "binary_reproducible": True,
        "upstream_commit": UPSTREAM_COMMIT,
        "compiler": GO_VERSION,
        "first_image_id": ids[0],
        "second_image_id": ids[1],
        "both_scans_no_high_critical": True,
        "migration_authorized": False,
        "deployment_authorized": False,
    }, indent=2) + "\n")
    print("S0.4: independent gosu rebuilds and image security PASS")

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    x = sub.add_parser("pin")
    for arg in ("source", "output", "evidence", "golang", "postgres"):
        x.add_argument("--" + arg.replace("_", "-"), required=True)
    x.set_defaults(action=pin)
    y = sub.add_parser("verify")
    for arg in ("a", "b", "a_metadata", "b_metadata", "a_imageid",
                "b_imageid", "scan_a", "scan_b", "evidence"):
        y.add_argument("--" + arg.replace("_", "-"), required=True)
    y.set_defaults(action=verify)
    args = parser.parse_args()
    args.action(args)
