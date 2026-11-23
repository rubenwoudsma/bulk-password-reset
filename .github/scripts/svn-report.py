#!/usr/bin/env python3
"""Describe the staged SVN tree without performing any repository writes."""

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
import zipfile


SLUG = "bulk-password-reset"


def manifest(directory):
    if not directory.is_dir() or directory.is_symlink():
        raise ValueError(f"Expected a real directory: {directory.name}")
    files = {}
    for path in sorted(directory.rglob("*")):
        if path.is_symlink():
            raise ValueError("Symlinks are not allowed in the prepared upload")
        if path.is_file():
            relative = path.relative_to(directory).as_posix()
            files[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    if not files:
        raise ValueError(f"No files found: {directory.name}")
    return files


def zip_manifest(path):
    files = {}
    with zipfile.ZipFile(path) as package:
        for entry in package.infolist():
            if entry.is_dir():
                continue
            if not entry.filename.startswith(SLUG + "/"):
                raise ValueError("Unexpected package root")
            relative = entry.filename[len(SLUG) + 1:]
            if relative in files or not relative or any(part in {".", ".."} for part in relative.split("/")):
                raise ValueError("Duplicate or invalid package path")
            files[relative] = hashlib.sha256(package.read(entry)).hexdigest()
    return files


def verify_trees(workspace, working_copy, version):
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("A numeric major.minor.patch version is required")
    runtime = manifest(workspace / "build" / SLUG)
    package_path = workspace / "dist" / f"{SLUG}-{version}.zip"
    if zip_manifest(package_path) != runtime:
        raise ValueError("Built runtime differs from the installable ZIP")
    for target in (working_copy / "trunk", working_copy / "tags" / version):
        if manifest(target) != runtime:
            raise ValueError(f"Staged SVN {target.relative_to(working_copy).as_posix()} differs from the ZIP")
    assets = manifest(workspace / ".wordpress-org")
    if manifest(working_copy / "assets") != assets:
        raise ValueError("Staged directory assets differ from the reviewed source")
    return runtime, assets, {"name": package_path.name, "bytes": package_path.stat().st_size,
                            "sha256": hashlib.sha256(package_path.read_bytes()).hexdigest()}


def svn_read(arguments, output):
    result = subprocess.run(["svn", "--non-interactive", *arguments], check=True,
                            capture_output=True, text=True, timeout=120)
    output.write_text(result.stdout, encoding="utf-8")
    return result.stdout


def status_counts(xml):
    root = ET.fromstring(xml)
    if root.tag != "status":
        raise ValueError("Invalid SVN status report")
    counts = Counter()
    for entry in root.iter("wc-status"):
        item = entry.get("item", "unknown")
        if item not in {"added", "deleted", "modified", "replaced", "normal", "none"}:
            raise ValueError(f"Unexpected SVN working-copy state: {item}")
        if entry.get("props") == "conflicted" or entry.get("tree-conflicted") == "true":
            raise ValueError("Unresolved SVN conflict")
        counts[item] += 1
    return dict(sorted(counts.items()))


def text(value):
    # Report text cannot create Markdown links, HTML or extra table rows.
    value = str(value)
    for token, replacement in (("&", "&amp;"), ("<", "&lt;"), (">", "&gt;"),
                               ("|", "&#124;"), ("`", "&#96;"), ("[", "&#91;"), ("]", "&#93;")):
        value = value.replace(token, replacement)
    return " ".join(value.split())


def create_report(workspace, working_copy):
    output = workspace / "svn-upload-report"
    output.mkdir(parents=True, exist_ok=True)
    version = os.environ.get("RELEASE_VERSION", "")
    dry_run = os.environ.get("SVN_DRY_RUN") == "true"
    report = {"version": version, "source_sha": os.environ.get("GITHUB_SHA", ""),
              "mode": "preview" if dry_run else "upload", "success": False,
              "write_authentication_verified": False if dry_run else None, "problems": []}
    try:
        if os.environ.get("SVN_IDENTITY_OUTCOME") != "success":
            raise ValueError("Release identity or reviewed-runtime verification did not pass")
        if os.environ.get("SVN_ACTION_OUTCOME") != "success":
            raise ValueError("The SVN action did not complete successfully; inspect the job log")
        runtime, assets, package = verify_trees(workspace, working_copy, version)
        status_xml = svn_read(["status", "--xml", str(working_copy)], output / "status.xml")
        report["svn_entries"] = status_counts(status_xml)
        info = svn_read(["info", "--xml", str(working_copy)], output / "repository.xml")
        report["working_copy_revision"] = ET.fromstring(info).find("entry").get("revision")
        svn_read(["diff", "--summarize", str(working_copy)], output / "changes.txt")
        svn_read(["diff", "--no-diff-deleted", str(working_copy)], output / "changes.patch")
        report.update(runtime=runtime, assets=assets, package=package, success=True)
        if not dry_run and report["svn_entries"]:
            raise ValueError("SVN action left uncommitted changes; upload needs investigation")
    except (OSError, ValueError, KeyError, AttributeError, ET.ParseError,
            zipfile.BadZipFile, subprocess.SubprocessError) as error:
        report["success"] = False
        report["problems"].append(str(error))
    heading = "SVN upload preparation" if dry_run else "SVN upload result"
    lines = [f"# Bulk Password Reset — {heading}", "",
             "**✅ Preparation completed. No SVN commit was performed.**" if dry_run and report["success"] else
             "**✅ SVN action completed and the working copy matches the package.**" if report["success"] else
             "**❌ Preparation/upload did not complete. Review the problems below and the job log.**", "",
             f"Version: **{text(version)}** · Source revision: `{text(report['source_sha'])}`", ""]
    if report["success"]:
        lines += [f"Runtime: **{len(report['runtime'])} files**, identical in the ZIP, SVN trunk, and tags/{text(version)}.",
                  f"Directory assets: **{len(report['assets'])} files**, identical to the reviewed source.",
                  f"SVN working-copy root revision: **{text(report['working_copy_revision'])}**.", "",
                  "| SVN entry state | Count |", "| --- | ---: |"]
        lines += [f"| {text(key)} | {value} |" for key, value in report["svn_entries"].items()]
        if not report["svn_entries"]:
            lines.append("| Uncommitted changes | 0 |")
        lines += ["", f"Package: **{text(report['package']['name'])}** · {report['package']['bytes']} bytes",
                  f"SHA256: `{report['package']['sha256']}`", ""]
    if dry_run:
        lines += ["Both saved SVN secrets are required by the deployment action, but this preview uses public SVN reads. "
                  "It does not verify the password or commit permissions.", "",
                  "A manual preview never creates a Git tag or commits to SVN. The live upload still requires a numeric Git tag, "
                  "all CI checks, and WPORG_DEPLOY_ENABLED=true. Keep that gate false while reviewing the original closure notice.", ""]
    lines += ["An SVN upload and reopening the WordPress.org listing are separate steps. "
              "The Plugins team has requested an upload while the plugin is closed; only that team can approve reopening.", "",
              "Download **wordpress-org-svn-report** from this run's Artifacts section for the ZIP, "
              "file hashes, SVN changes/patch, status and revision evidence. Retention: 14 days."]
    if report["problems"]:
        lines += ["", "## Problems", ""] + [f"- {text(problem)}" for problem in report["problems"]]
    markdown = "\n".join(lines) + "\n"
    (output / "summary.md").write_text(markdown, encoding="utf-8")
    (output / "plan.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
            summary.write(markdown)
    print(markdown)
    return 0 if report["success"] else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--working-copy", required=True, type=Path)
    args = parser.parse_args()
    sys.exit(create_report(Path.cwd(), args.working_copy))
