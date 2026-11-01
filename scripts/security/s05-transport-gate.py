#!/usr/bin/env python3
"""S0.5 CI-only Docker archive contract. Never deploys or reads a real database.

The SHA-256 of a docker-save archive is a *transport archive* digest, not an
OCI registry manifest digest. The config digest must equal the Docker image ID.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import tarfile

HEX64 = r"[a-f0-9]{64}"
HEX40 = r"[a-f0-9]{40}"
TAG = "ikoma-s05:rc"

def stop(msg):
    raise SystemExit("STOP S0.5: " + msg)

def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as src:
        for part in iter(lambda: src.read(1024 * 1024), b""):
            h.update(part)
    return h.hexdigest()

def image_id(path):
    value = Path(path).read_text().strip()
    if not re.fullmatch("sha256:" + HEX64, value):
        stop("invalid image configuration digest")
    return value

def inspect_archive(path):
    p = Path(path)
    if not p.is_file() or p.stat().st_size < 1024:
        stop("archive missing or unexpectedly short")
    try:
        with tarfile.open(p, "r:") as archive:
            members = archive.getmembers()
            names = [m.name for m in members]
            if len(names) != len(set(names)):
                stop("duplicate names in archive")
            for member in members:
                parts = PurePosixPath(member.name).parts
                if (member.name.startswith("/") or ".." in parts
                        or member.issym() or member.islnk()
                        or not (member.isfile() or member.isdir())):
                    stop("unsafe archive entry")
            if "manifest.json" not in names:
                stop("docker image manifest absent")
            manifest_member = archive.extractfile("manifest.json")
            if manifest_member is None:
                stop("manifest unreadable")
            manifest = json.load(manifest_member)
            if not isinstance(manifest, list) or len(manifest) != 1:
                stop("archive must contain exactly one image")
            item = manifest[0]
            if item.get("RepoTags") != [TAG]:
                stop("unexpected tag in exported candidate")
            conf = item.get("Config")
            if not isinstance(conf, str) or not re.fullmatch(HEX64 + r"\.json", conf):
                stop("unexpected image config filename")
            if conf not in names:
                stop("referenced image config absent")
            cfg = archive.extractfile(conf)
            if cfg is None:
                stop("cannot read image configuration")
            digest = hashlib.sha256(cfg.read()).hexdigest()
            if conf != digest + ".json":
                stop("image config content differs from filename")
            layers = item.get("Layers")
            if not isinstance(layers, list) or not layers or not all(isinstance(l, str) and l in names for l in layers):
                stop("missing referenced image layer")
    except (OSError, tarfile.TarError, ValueError, TypeError, KeyError, json.JSONDecodeError):
        stop("invalid docker-save archive")
    return {"archive_sha256": sha256(p), "archive_bytes": p.stat().st_size,
            "config_image_id": "sha256:" + digest, "format": "docker-save-tar"}

def load_lock(p):
    try:
        data = json.loads(Path(p).read_text())
        assert data["schema_version"] == 1
        assert data["production_adoption_authorized"] is False
        assert data["real_data_migration_authorized"] is False
        assert re.fullmatch(HEX64, data["reproducible_gosu_binary_sha256"])
        assert re.fullmatch(HEX64, data["qualified_dockerfile_sha256"])
        assert re.fullmatch(HEX40, data["gosu_upstream_commit"])
        return data
    except (OSError, ValueError, TypeError, KeyError, AssertionError):
        stop("invalid S0.4 source lock")

def ensure_binary(path, metadata, lock):
    if sha256(path) != lock["reproducible_gosu_binary_sha256"]:
        stop("gosu binary differs from Git lock")
    info = Path(metadata).read_text()
    if not all(x in info for x in ("go1.27.3", "path\tgithub.com/tianon/gosu",
                    "vcs.revision=" + lock["gosu_upstream_commit"], "vcs.modified=false")):
        stop("gosu provenance inconsistent")

def create(args):
    lock = load_lock(args.lock)
    img_id = image_id(args.image_id)
    details = inspect_archive(args.archive)
    if details["config_image_id"] != img_id:
        stop("archive does not contain inspected candidate image")
    ensure_binary(args.gosu, args.metadata, lock)
    data = {
        "schema": 1, "kind": "temporary-audited-laboratory-archive",
        "tag": TAG, **details,
        "upstream_commit": lock["gosu_upstream_commit"],
        "gosu_sha256": lock["reproducible_gosu_binary_sha256"],
        "parent_refs": {k: v["locked_ref"] for k,v in lock["parents"].items()},
        "source_commit": args.source_commit,
        "ci_run_id": args.run_id,
        "production_deployment_authorized": False,
        "real_data_migration_authorized": False,
        "registry_manifest_digest": None
    }
    if not re.fullmatch(HEX40, data["source_commit"]):
        stop("invalid source commit SHA")
    Path(args.output).write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")
    print("S0.5: transport archive content and gosu provenance verified")

def verify(args):
    lock = load_lock(args.lock)
    data = json.loads(Path(args.manifest).read_text())
    details = inspect_archive(args.archive)
    if (data.get("schema") != 1 or data.get("tag") != TAG
            or data.get("kind") != "temporary-audited-laboratory-archive"
            or data.get("production_deployment_authorized") is not False
            or data.get("real_data_migration_authorized") is not False
            or data.get("registry_manifest_digest") is not None):
        stop("invalid or misrepresented transport manifest")
    for key, val in details.items():
        if data.get(key) != val:
            stop("archive digest / config mismatch: " + key)
    if details["config_image_id"] != image_id(args.loaded_image_id):
        stop("fresh runner loaded a different image")
    if data.get("gosu_sha256") != lock["reproducible_gosu_binary_sha256"]:
        stop("binary lock mismatch")
    if data.get("upstream_commit") != lock["gosu_upstream_commit"]:
        stop("upstream lock mismatch")
    if data.get("parent_refs") != {k: v["locked_ref"] for k,v in lock["parents"].items()}:
        stop("parents lock mismatch")
    if not re.fullmatch(HEX40, str(data.get("source_commit", ""))):
        stop("source commit malformed")
    ensure_binary(args.gosu, args.metadata, lock)
    scan = json.loads(Path(args.scan_gate).read_text())
    if (scan.get("image_id") != details["config_image_id"] or scan.get("high") != 0
            or scan.get("critical") != 0 or scan.get("decision") != "READY_FOR_ADDITIONAL_REVIEW"):
        stop("transported image scan incomplete or unsafe")
    Path(args.output).write_text(json.dumps({
        "scope": "fresh-runner-transport-qualification",
        "archive_sha256": details["archive_sha256"],
        "config_image_id": details["config_image_id"],
        "gosu_sha256": lock["reproducible_gosu_binary_sha256"],
        "fresh_runner_load": "PASS",
        "independent_full_trivy": "PASS",
        "postgres_fixture": "SEPARATE_CI_STEP",
        "production_deployment_authorized": False,
        "registry_manifest_digest": None,
    }, indent=2, sort_keys=True) + "\n")
    print("S0.5: transported candidate reload and independent security PASS")

if __name__ == "__main__":
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="op", required=True)
    for name, action in (("create",create),("verify",verify)):
        cmd = sub.add_parser(name)
        fields = ["archive", "lock", "gosu", "metadata", "output"]
        fields += ["image-id", "source-commit", "run-id"] if name=="create" else ["manifest", "loaded-image-id", "scan-gate"]
        for field in fields:
            cmd.add_argument("--" + field, required=True)
        cmd.set_defaults(action=action)
    args = p.parse_args()
    args.action(args)
