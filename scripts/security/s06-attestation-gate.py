#!/usr/bin/env python3
"""Gate one GitHub CLI-verified SLSA attestation for an exact GHCR OCI manifest.

The gh CLI must have already verified the cryptographic signature and GitHub
repository identity using 'gh attestation verify --format json --repo ...'.
This script checks the returned subject/predicate, not an unverified bundle.
"""
import argparse
import json
import re
from pathlib import Path

REPO = "zumradeals/ikoma-core"
IMAGE = "ghcr.io/zumradeals/ikoma-access-postgres-candidate"
PREDICATE = "https://slsa.dev/provenance/v1"
SHA40 = r"[0-9a-f]{40}"
SHA256 = r"sha256:[0-9a-f]{64}"


def stop(why):
    raise SystemExit("STOP S0.6 VERIFY: " + why)


def expected_record(args):
    if args.repository != REPO or not re.fullmatch(SHA40, args.commit):
        stop("repository / commit mismatch")
    if not re.fullmatch(SHA256, args.digest):
        stop("invalid registry manifest SHA256")
    try:
        record = json.loads(Path(args.release_record).read_text())
        if not isinstance(record, dict):
            stop("release record is not a JSON object")
        checks = (
            record.get("schema") == 1,
            record.get("status") == "PUBLISHED_CANDIDATE_UNVERIFIED_ON_SECOND_RUNNER",
            record.get("source_repository") == REPO,
            record.get("source_commit") == args.commit,
            record.get("registry_name") == IMAGE,
            record.get("tag") == IMAGE + ":rc-" + args.commit,
            record.get("digest") == args.digest,
            record.get("immutable_ref") == IMAGE + "@" + args.digest,
            record.get("production_deployment_authorized") is False,
            record.get("real_data_migration_authorized") is False,
        )
        if not all(checks):
            stop("release metadata differ from explicit GitHub workflow inputs")
    except (OSError, ValueError, TypeError):
        stop("release record missing or invalid")
    return record


def verified_attestation(args):
    record = expected_record(args)
    try:
        entries = json.loads(Path(args.verified_json).read_text())
    except (OSError, ValueError, TypeError):
        stop("missing, empty or malformed JSON from gh signature verification")
    if not isinstance(entries, list) or not entries:
        stop("no cryptographically verified attestation entries returned by gh")
    subject_digest = args.digest.removeprefix("sha256:")
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        result = entry.get("verificationResult") or {}
        if not isinstance(result, dict):
            continue
        statement = result.get("statement") or {}
        if not isinstance(statement, dict):
            continue
        if statement.get("predicateType") != PREDICATE:
            continue
        subjects = statement.get("subject") or []
        if not isinstance(subjects, list):
            continue
        for subject in subjects:
            if not isinstance(subject, dict):
                continue
            digest = subject.get("digest") or {}
            if (subject.get("name") == IMAGE and
                    isinstance(digest, dict) and
                    digest.get("sha256") == subject_digest):
                Path(args.output).write_text(json.dumps({
                    "schema": 1,
                    "status": "OCI_ATTESTATION_SIGNATURE_AND_EXACT_SUBJECT_VERIFIED",
                    "source_repository": REPO,
                    "source_commit": args.commit,
                    "registry_ref": record["immutable_ref"],
                    "predicate_type": PREDICATE,
                    "verified_subject_count": 1,
                    "production_deployment_authorized": False,
                    "real_data_migration_authorized": False,
                }, indent=2, sort_keys=True) + "\n")
                print("S0.6 OCI signature, SLSA provenance and exact registry manifest subject: PASS")
                return
    stop("no valid SLSA attestation names the exact candidate manifest digest")


def main():
    p = argparse.ArgumentParser()
    for name in ("release-record", "verified-json", "commit", "digest", "repository", "output"):
        p.add_argument("--" + name, required=True)
    verified_attestation(p.parse_args())


if __name__ == "__main__":
    main()
