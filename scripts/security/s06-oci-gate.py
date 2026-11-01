#!/usr/bin/env python3
"""S0.6 GitHub-hosted OCI candidate release gate (never migrates databases).

The registry manifest digest, Docker local image ID and gosu SHA256 are
different integrity contracts, and may NEVER be substituted for each other.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path

SHA = r"[0-9a-f]{40}"
DIGEST = r"sha256:[0-9a-f]{64}"
LOCK = Path("docs/security/S0.4-IMAGE-LOCK.json")
REPO = "zumradeals/ikoma-core"
NAME = "ghcr.io/zumradeals/ikoma-access-postgres-candidate"
UPSTREAM = "6456aaa0f3c854d199d0f037f068eb97515b7513"


def stop(why):
    raise SystemExit("STOP S0.6: " + why)


def source_lock(path):
    try:
        lock = json.loads(Path(path).read_text())
        assert lock["schema_version"] == 1
        assert lock["go_compiler"] == "go1.27.3"
        assert lock["gosu_upstream_commit"] == UPSTREAM
        assert lock["production_adoption_authorized"] is False
        assert lock["real_data_migration_authorized"] is False
        assert re.fullmatch(r"[0-9a-f]{64}", lock["reproducible_gosu_binary_sha256"])
        assert re.fullmatch(r"[0-9a-f]{64}", lock["qualified_dockerfile_sha256"])
        assert lock["parents"]["postgres"]["locked_ref"].startswith("postgres@sha256:")
        assert lock["parents"]["golang"]["locked_ref"].startswith("golang@sha256:")
        return lock
    except (KeyError, ValueError, TypeError, OSError, AssertionError):
        stop("invalid or missing S0.4 Git source lock")


def file_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fp:
        for buf in iter(lambda: fp.read(1024 * 1024), b""):
            h.update(buf)
    return h.hexdigest()


def attest_binary(args, lock):
    if file_sha256(args.gosu) != lock["reproducible_gosu_binary_sha256"]:
        stop("gosu differs from authoritative Git SHA256")
    meta = Path(args.metadata).read_text()
    if not all(x in meta for x in ("go1.27.3", "path\tgithub.com/tianon/gosu",
                                  "vcs.revision=" + UPSTREAM, "vcs.modified=false")):
        stop("gosu source provenance or compiler mismatch")


def identity(args):
    if args.repository != REPO:
        stop("invalid repository identity")
    if not re.fullmatch(SHA, args.commit):
        stop("invalid source commit")
    if args.image_name != NAME:
        stop("unexpected image repository")
    return NAME + ":rc-" + args.commit


def publish(args):
    lock = source_lock(args.lock)
    tag = identity(args)
    if args.confirmation != "RELEASE_CANDIDATE_ONLY":
        stop("release approval token absent")
    if not re.fullmatch(DIGEST, args.digest):
        stop("registry manifest digest missing or malformed")
    if not re.fullmatch(DIGEST, args.local_image_id):
        stop("local Docker image ID missing or malformed")
    attest_binary(args, lock)
    entry = {
        "schema": 1,
        "status": "PUBLISHED_CANDIDATE_UNVERIFIED_ON_SECOND_RUNNER",
        "kind": "ghcr-oci-manifest-not-docker-config",
        "registry_name": NAME,
        "tag": tag,
        "digest": args.digest,
        "immutable_ref": NAME + "@" + args.digest,
        "source_commit": args.commit,
        "source_repository": REPO,
        "local_image_id": args.local_image_id,
        "gosu_sha256": lock["reproducible_gosu_binary_sha256"],
        "upstream_commit": UPSTREAM,
        "parent_refs": {k:v["locked_ref"] for k,v in lock["parents"].items()},
        "attestation_expected": True,
        "production_deployment_authorized": False,
        "real_data_migration_authorized": False,
    }
    Path(args.output).write_text(json.dumps(entry, indent=2, sort_keys=True) + "\n")
    print("S0.6 candidate OCI release record PASS (independent consumer check required)")


def consumer(args):
    lock = source_lock(args.lock)
    try:
        item = json.loads(Path(args.release_record).read_text())
        expected_tag = identity(args)
        assert item["schema"] == 1
        assert item["status"] == "PUBLISHED_CANDIDATE_UNVERIFIED_ON_SECOND_RUNNER"
        assert item["tag"] == expected_tag
        assert item["source_commit"] == args.commit
        assert item["source_repository"] == REPO
        assert item["registry_name"] == NAME
        assert re.fullmatch(DIGEST, item["digest"])
        assert item["immutable_ref"] == NAME + "@" + item["digest"]
        assert item["gosu_sha256"] == lock["reproducible_gosu_binary_sha256"]
        assert item["parent_refs"] == {k:v["locked_ref"] for k,v in lock["parents"].items()}
        assert item["production_deployment_authorized"] is False
        assert item["real_data_migration_authorized"] is False
        assert item["attestation_expected"] is True
    except (OSError, KeyError, ValueError, TypeError, AssertionError):
        stop("release reference or provenance record invalid")
    if not re.fullmatch(DIGEST, args.image_id):
        stop("imported image ID missing")
    attest_binary(args, lock)
    # The Trivy gate is checked on this runner and must name this exact
    # imported image ID, irrespective of the separate registry manifest digest.
    try:
        scan = json.loads(Path(args.scan_gate).read_text())
        if (scan["image_id"] != args.image_id or scan["high"] != 0 or
                scan["critical"] != 0 or scan["decision"] != "READY_FOR_ADDITIONAL_REVIEW"):
            stop("consumer Trivy report mismatched or unsafe")
    except (KeyError, ValueError, TypeError, OSError):
        stop("consumer Trivy gate incomplete")
    refs = Path(args.repo_digests).read_text().splitlines()
    if item["immutable_ref"] not in refs:
        stop("imported image does not expose expected registry digest")
    Path(args.output).write_text(json.dumps({
        "schema": 1,
        "status": "CONSUMED_AND_SECURITY_CHECKED",
        "immutable_ref": item["immutable_ref"],
        "source_commit": args.commit,
        "gosu_sha256": lock["reproducible_gosu_binary_sha256"],
        "second_runner_scan": "PASS",
        "source_attestation": "VERIFIED_BY_SEPARATE_GITHUB_CLI_STEP",
        "postgresql_fixture": "VERIFIED_BY_SEPARATE_CI_STEP",
        "production_deployment_authorized": False,
        "real_data_migration_authorized": False,
    }, indent=2, sort_keys=True) + "\n")
    print("S0.6 independent OCI digest and binary/Trivy consumer contract PASS")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="mode", required=True)
    for name, func in (("publish",publish),("consumer",consumer)):
        p = sub.add_parser(name)
        shared = ("lock", "repository", "image-name", "commit", "gosu", "metadata", "output")
        specific = ("confirmation","digest","local-image-id") if name == "publish" else ("release-record","image-id","repo-digests","scan-gate")
        for param in (*shared, *specific):
            p.add_argument("--" + param, required=True)
        p.set_defaults(func=func)
    args = parser.parse_args()
    args.func(args)
