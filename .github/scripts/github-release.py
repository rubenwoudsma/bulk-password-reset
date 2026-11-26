#!/usr/bin/env python3
"""Prepare a tested plugin release, then publish it only for a matching Git tag."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile


REPOSITORY = "rubenwoudsma/bulk-password-reset"
SLUG = "bulk-password-reset"


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def version_number(version):
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("Expected a numeric version such as 2.0.0, without a v prefix")
    return version


def field(content, name):
    match = re.search(r"^\s*(?:\*\s*)?" + re.escape(name) + r":\s*([^\r\n]+)$", content, re.M)
    if not match:
        raise ValueError(f"Missing metadata: {name}")
    return match[1].strip()


def changes(readme, version):
    section = re.search(r"^== Changelog ==\s*\n(.*?)(?=^== [^=].*? ==\s*$|\Z)", readme, re.M | re.S)
    headings = list(re.finditer(r"^=\s*([^=\n]+?)\s*=$", section[1], re.M)) if section else []
    if not headings or headings[0][1].strip() != version:
        raise ValueError("The first changelog entry must match the release version")
    end = headings[1].start() if len(headings) > 1 else len(section[1])
    notes = section[1][headings[0].end():end].strip()
    if not notes:
        raise ValueError("Release changelog is empty")
    return notes


def package_files(package):
    files = {}
    with zipfile.ZipFile(package) as archive:
        for entry in archive.infolist():
            if entry.is_dir():
                continue
            if not entry.filename.startswith(SLUG + "/"):
                raise ValueError("Unexpected plugin ZIP root")
            relative = entry.filename[len(SLUG) + 1:]
            if (relative in files or any(part in {"", ".", ".."} for part in relative.split("/"))
                    or "\\" in relative or (entry.external_attr >> 16) & 0o170000 == 0o120000):
                raise ValueError("Invalid, duplicate or symlink entry in plugin ZIP")
            files[relative] = hashlib.sha256(archive.read(entry)).hexdigest()
    if not files:
        raise ValueError("Plugin ZIP is empty")
    return files


def source_files(workspace):
    paths = ["bulk_password_reset.php", "uninstall.php", "readme.txt", "LICENSE",
             "assets/admin.css", "assets/email-settings.js", "assets/ruben-woudsma-logo.png", "assets/plugin-icon.png"]
    for directory in ("includes", "languages"):
        for path in (workspace / directory).rglob("*"):
            if path.is_symlink():
                raise ValueError("Runtime source contains a symlink")
            if path.is_file():
                paths.append(path.relative_to(workspace).as_posix())
    if any((workspace / path).is_symlink() for path in paths):
        raise ValueError("Runtime source contains a symlink")
    return {path: digest(workspace / path) for path in sorted(paths)}


def summary(markdown):
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as output:
            output.write(markdown + "\n")
    print(markdown)


def prepare(workspace, version):
    version_number(version)
    source_sha = os.environ.get("GITHUB_SHA", "")
    if not re.fullmatch(r"[0-9a-f]{40}", source_sha):
        raise ValueError("Expected the tested source commit SHA")
    readme = (workspace / "readme.txt").read_text(encoding="utf-8")
    plugin = (workspace / "bulk_password_reset.php").read_text(encoding="utf-8")
    if field(plugin, "Version") != version or field(readme, "Stable tag") != version:
        raise ValueError("Release version differs from plugin/readme metadata")
    changelog = changes(readme, version)
    package = workspace / "release-input" / f"{SLUG}-{version}.zip"
    files = package_files(package)
    if files != source_files(workspace):
        raise ValueError("The CI package differs from the reviewed runtime source")
    package_sha = digest(package)
    marker = f"<!-- bulk-password-reset-release:{source_sha} -->"
    notes = (f"{marker}\n\n## Changes in {version}\n\n{changelog}\n\n"
             f"## Installation\n\nDownload **{package.name}** from this release's Assets and install it using "
             "WordPress → Plugins → Add New Plugin → Upload Plugin.\n\n"
             f"Requires WordPress **{field(readme, 'Requires at least')}+** and PHP **{field(readme, 'Requires PHP')}+**.\n\n"
             f"SHA256: `{package_sha}`\n\n"
             "WordPress.org distribution and review are handled separately from this GitHub Release.\n")
    output = workspace / "github-release"
    output.mkdir(exist_ok=True)
    expected = {package.name, "SHA256SUMS.txt", "notes.md", "release.json"}
    if any(path.name not in expected or path.is_symlink() for path in output.iterdir()):
        raise ValueError("Release output contains unexpected files")
    shutil.copyfile(package, output / package.name)
    (output / "notes.md").write_text(notes, encoding="utf-8")
    (output / "SHA256SUMS.txt").write_text(f"{package_sha}  {package.name}\n", encoding="utf-8")
    metadata = {"schema": 1, "version": version, "source_sha": source_sha, "package": package.name,
                "sha256": package_sha, "notes_sha256": digest(output / "notes.md"), "files": files}
    (output / "release.json").write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    summary(f"## GitHub release candidate {version}\n\nNotes were generated from the matching readme changelog. "
            f"The **{package.name}** ZIP is the exact CI package; all {len(files)} runtime files match the source.\n\n"
            f"SHA256: `{package_sha}`\n\nDownload **github-release-candidate** to inspect notes, ZIP, checksum and metadata. "
            "This preparation job does not create a GitHub Release. Publishing requires a version-tag push; "
            "manual workflow runs remain previews.")
    return metadata


def candidate(directory):
    data = json.loads((directory / "release.json").read_text(encoding="utf-8"))
    version_number(data["version"])
    if (data["schema"] != 1 or data["package"] != f"{SLUG}-{data['version']}.zip"
            or not re.fullmatch(r"[0-9a-f]{40}", data["source_sha"])):
        raise ValueError("Invalid release metadata")
    if (digest(directory / data["package"]) != data["sha256"]
            or package_files(directory / data["package"]) != data["files"]
            or digest(directory / "notes.md") != data["notes_sha256"]):
        raise ValueError("Release artifact integrity check failed")
    if (directory / "SHA256SUMS.txt").read_text(encoding="utf-8").strip() != f"{data['sha256']}  {data['package']}":
        raise ValueError("Release checksum file differs from the package")
    return data


def gh(*arguments):
    result = subprocess.run(["gh", *map(str, arguments)], check=True, capture_output=True, text=True, timeout=120)
    return result.stdout


def verify_tag(data):
    reference = json.loads(gh("api", f"repos/{REPOSITORY}/git/ref/tags/{data['version']}"))["object"]
    for _ in range(8):
        if reference["type"] != "tag":
            break
        reference = json.loads(gh("api", f"repos/{REPOSITORY}/git/tags/{reference['sha']}"))["object"]
    if reference["type"] != "commit" or reference["sha"] != data["source_sha"]:
        raise ValueError("Remote Git tag does not point to the tested commit")


def find_release(version):
    pages = json.loads(gh("api", f"repos/{REPOSITORY}/releases?per_page=100", "--paginate", "--slurp"))
    matches = [release for page in pages for release in page if release["tag_name"] == version]
    if len(matches) > 1:
        raise ValueError("Multiple releases use this tag; refusing to select one")
    return matches[0] if matches else None


def verify_assets(data, *, exact):
    with tempfile.TemporaryDirectory() as temporary:
        directory = Path(temporary)
        gh("release", "download", data["version"], "--repo", REPOSITORY,
           "--pattern", data["package"], "--pattern", "SHA256SUMS.txt", "--dir", directory)
        package = directory / data["package"]
        checksum = digest(package)
        if package_files(package) != data["files"] or (exact and checksum != data["sha256"]):
            raise ValueError("GitHub Release ZIP differs from the tested runtime")
        if (directory / "SHA256SUMS.txt").read_text(encoding="utf-8").strip() != f"{checksum}  {data['package']}":
            raise ValueError("GitHub Release checksum does not match its ZIP")


def publish(directory):
    data = candidate(directory)
    version = data["version"]
    if (os.environ.get("GITHUB_REPOSITORY") != REPOSITORY or os.environ.get("GITHUB_EVENT_NAME") != "push"
            or os.environ.get("GITHUB_REF") != f"refs/tags/{version}"
            or os.environ.get("GITHUB_SHA") != data["source_sha"]):
        raise ValueError("Publishing requires the matching version-tag push in the owning repository")
    verify_tag(data)
    release = find_release(version)
    if release and not release["draft"]:
        # A rerun may rebuild identical files with different ZIP timestamps.
        # Verify the published contents and its own checksum; never replace it.
        if not (release.get("body") or "").strip():
            raise ValueError("Existing published release has no release notes")
        if f"<!-- bulk-password-reset-release:{data['source_sha']} -->" not in release["body"]:
            raise ValueError("Existing published release is not associated with the tested commit")
        verify_assets(data, exact=False)
        summary(f"## GitHub Release {version}\n\nExisting published release and assets match the tested runtime. "
                f"They were left unchanged.\n\n{release['html_url']}")
        return
    if release and f"<!-- bulk-password-reset-release:{data['source_sha']} -->" not in (release.get("body") or ""):
        raise ValueError("Existing draft was not prepared by this workflow for the tested commit")
    if not release:
        gh("release", "create", version, "--repo", REPOSITORY, "--verify-tag", "--draft",
           "--title", f"Bulk Password Reset {version}", "--notes-file", directory / "notes.md")
    else:
        gh("release", "edit", version, "--repo", REPOSITORY, "--draft",
           "--title", f"Bulk Password Reset {version}", "--notes-file", directory / "notes.md")
    upload = ["release", "upload", version, "--repo", REPOSITORY,
              directory / data["package"], directory / "SHA256SUMS.txt"]
    if release:
        upload.append("--clobber")  # Only this workflow's still-unpublished draft.
    gh(*upload)
    verify_assets(data, exact=True)
    verify_tag(data)
    gh("release", "edit", version, "--repo", REPOSITORY, "--draft=false", "--verify-tag")
    summary(f"## GitHub Release {version} published\n\nRelease notes, the tested installable ZIP and its checksum are available at "
            f"https://github.com/{REPOSITORY}/releases/tag/{version}\n\nSVN publication is controlled separately.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("prepare", "publish"))
    parser.add_argument("--version")
    args = parser.parse_args()
    try:
        if args.command == "prepare":
            prepare(Path.cwd(), args.version or "")
        else:
            publish(Path.cwd() / "github-release")
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile, subprocess.SubprocessError) as error:
        print(f"GitHub release failed: {error}", file=sys.stderr)
        sys.exit(1)
