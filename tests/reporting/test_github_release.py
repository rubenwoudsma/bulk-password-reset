"""Verify release assets, tag identity, draft recovery, and publication boundaries."""

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile


SPEC = importlib.util.spec_from_file_location("github_release", Path(__file__).resolve().parents[2] / ".github/scripts/github-release.py")
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)
SHA = "a" * 40


class GithubReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.workspace = self.root / "workspace"
        self.workspace.mkdir()
        values = {
            "bulk_password_reset.php": "<?php\n/**\n * Version: 2.0.0\n */\n",
            "readme.txt": "Stable tag: 2.0.0\nRequires at least: 6.8\nRequires PHP: 8.2\n\n== Changelog ==\n\n"
                          "= 2.0.0 =\n* Secure reset links.\n* Protect privileged accounts.\n\n= 1.3.3 =\n* Historical release.\n\n"
                          "== Upgrade Notice ==\n= 2.0.0 =\nReview settings.\n",
            "uninstall.php": "<?php // cleanup\n", "LICENSE": "GPL-2.0-or-later\n",
            "assets/admin.css": ".plugin {}\n", "assets/ruben-woudsma-logo.png": "fixture-logo",
            "assets/plugin-icon.png": "fixture-plugin-icon",
            "includes/class-service.php": "<?php // service\n", "languages/bulk-password-reset.pot": "fixture catalog\n",
        }
        for name, value in values.items():
            path = self.workspace / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(value, encoding="utf-8")
        self.input = self.workspace / "release-input/bulk-password-reset-2.0.0.zip"
        self.input.parent.mkdir()
        with zipfile.ZipFile(self.input, "w") as archive:
            for name in values:
                archive.write(self.workspace / name, "bulk-password-reset/" + name)
        self.env = patch.dict(os.environ, {"GITHUB_SHA": SHA, "GITHUB_REPOSITORY": RELEASE.REPOSITORY,
                              "GITHUB_EVENT_NAME": "push", "GITHUB_REF": "refs/tags/2.0.0",
                              "GITHUB_STEP_SUMMARY": str(self.root / "summary.md")})
        self.env.start()
        self.addCleanup(self.env.stop)
        with contextlib.redirect_stdout(io.StringIO()):
            self.data = RELEASE.prepare(self.workspace, "2.0.0")
        self.output = self.workspace / "github-release"
        self.notes = (self.output / "notes.md").read_text(encoding="utf-8")

    def test_preparation_uses_exact_ci_zip_and_current_changelog(self):
        self.assertEqual(self.input.read_bytes(), (self.output / self.input.name).read_bytes())
        self.assertIn("Secure reset links", self.notes)
        self.assertNotIn("Historical release", self.notes)
        self.assertNotIn("Review settings", self.notes)
        self.assertIn("WordPress **6.8+** and PHP **8.2+**", self.notes)
        self.assertEqual(RELEASE.digest(self.input) + "  " + self.input.name,
                         (self.output / "SHA256SUMS.txt").read_text(encoding="utf-8").strip())
        self.assertEqual(self.data, RELEASE.candidate(self.output))

    def test_mismatched_version_or_empty_changelog_is_rejected(self):
        for version in ("v2.0.0", "../../outside", "2.0.0\n", "2.0.1"):
            with self.subTest(version=version), self.assertRaises(ValueError):
                RELEASE.prepare(self.workspace, version)
        with self.assertRaises(ValueError):
            RELEASE.changes("== Changelog ==\n= 2.0.0 =\n\n= 1.3.3 =\n* old", "2.0.0")

    def test_ci_package_cannot_contain_development_or_changed_runtime_files(self):
        with zipfile.ZipFile(self.input, "a") as archive:
            archive.writestr("bulk-password-reset/tests/test.php", "development-only")
        with self.assertRaisesRegex(ValueError, "differs from the reviewed runtime"):
            RELEASE.prepare(self.workspace, "2.0.0")

    def test_changed_notes_or_checksums_cannot_be_published(self):
        (self.output / "notes.md").write_text("different notes", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "integrity"):
            RELEASE.candidate(self.output)
        (self.output / "notes.md").write_text(self.notes, encoding="utf-8")
        (self.output / "SHA256SUMS.txt").write_text("wrong checksum", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "checksum"):
            RELEASE.candidate(self.output)

    def draft(self):
        return {"tag_name": "2.0.0", "draft": True, "body": self.notes,
                "html_url": "https://github.com/example/release"}

    def exercise(self, existing=None, *, tag_sha=SHA, fail_upload=False, bad_assets=False, alternate_zip=False):
        def gh(*arguments):
            args = list(map(str, arguments))
            if args[0] == "api":
                if "/git/ref/tags/" in args[1]:
                    return json.dumps({"object": {"type": "tag", "sha": "b" * 40}})
                if "/git/tags/" in args[1]:
                    return json.dumps({"object": {"type": "commit", "sha": tag_sha}})
                return json.dumps([[existing] if existing else []])
            if args[:2] == ["release", "upload"] and fail_upload:
                raise subprocess.CalledProcessError(1, ["gh", "release", "upload"])
            if args[:2] == ["release", "download"]:
                target = Path(args[args.index("--dir") + 1])
                if alternate_zip:
                    with zipfile.ZipFile(self.output / self.input.name) as original, zipfile.ZipFile(target / self.input.name, "w") as rebuilt:
                        for entry in original.infolist():
                            replacement = zipfile.ZipInfo(entry.filename, (2000, 1, 1, 0, 0, 0))
                            rebuilt.writestr(replacement, original.read(entry))
                    checksum = RELEASE.digest(target / self.input.name)
                    (target / "SHA256SUMS.txt").write_text(f"{checksum}  {self.input.name}\n", encoding="utf-8")
                else:
                    for name in (self.input.name, "SHA256SUMS.txt"):
                        shutil.copyfile(self.output / name, target / name)
                if bad_assets:
                    (target / "SHA256SUMS.txt").write_text("wrong checksum", encoding="utf-8")
            return ""
        mock = patch.object(RELEASE, "gh", side_effect=gh)
        return mock

    def mutations(self, mock):
        return [list(map(str, call.args)) for call in mock.call_args_list
                if call.args[0] == "release" and call.args[1] in {"create", "edit", "upload"}]

    def publish(self):
        with contextlib.redirect_stdout(io.StringIO()):
            RELEASE.publish(self.output)

    def test_new_release_uploads_and_verifies_assets_before_publishing(self):
        with self.exercise() as mock:
            self.publish()
        mutations = self.mutations(mock)
        self.assertEqual(["create", "upload", "edit"], [args[1] for args in mutations])
        self.assertIn("--draft", mutations[0])
        self.assertIn("--verify-tag", mutations[0])
        self.assertNotIn("--clobber", mutations[1])
        self.assertIn("--draft=false", mutations[2])
        commands = [list(map(str, call.args)) for call in mock.call_args_list]
        download = next(index for index, args in enumerate(commands) if args[:2] == ["release", "download"])
        publish = next(index for index, args in enumerate(commands) if "--draft=false" in args)
        self.assertLess(download, publish)

    def test_failed_upload_leaves_release_unpublished(self):
        with self.exercise(fail_upload=True) as mock, self.assertRaises(subprocess.CalledProcessError):
            self.publish()
        self.assertFalse(any("--draft=false" in args for args in self.mutations(mock)))

    def test_existing_managed_draft_can_resume(self):
        with self.exercise(self.draft()) as mock:
            self.publish()
        mutations = self.mutations(mock)
        self.assertNotIn("create", [args[1] for args in mutations])
        self.assertTrue(any("--clobber" in args for args in mutations))
        self.assertIn("--draft=false", mutations[-1])

    def test_other_draft_is_never_overwritten(self):
        draft = self.draft()
        draft["body"] = "Manually prepared release"
        with self.exercise(draft) as mock, self.assertRaisesRegex(ValueError, "Existing draft"):
            self.publish()
        self.assertEqual([], self.mutations(mock))

    def test_published_release_is_verified_without_replacing_assets(self):
        published = dict(self.draft(), draft=False)
        with self.exercise(published, alternate_zip=True) as mock:
            self.publish()
        self.assertEqual([], self.mutations(mock))

    def test_published_asset_or_revision_mismatch_is_a_failure(self):
        published = dict(self.draft(), draft=False)
        with self.exercise(published, bad_assets=True) as mock, self.assertRaisesRegex(ValueError, "checksum"):
            self.publish()
        self.assertEqual([], self.mutations(mock))
        published["body"] = "Unrelated source revision"
        with self.exercise(published) as mock, self.assertRaisesRegex(ValueError, "tested commit"):
            self.publish()
        self.assertEqual([], self.mutations(mock))

    def test_manual_run_or_wrong_repository_cannot_publish(self):
        for change in ({"GITHUB_EVENT_NAME": "workflow_dispatch"}, {"GITHUB_REPOSITORY": "example/fork"},
                       {"GITHUB_REF": "refs/heads/master"}):
            with self.subTest(change=change), patch.dict(os.environ, change), self.exercise() as mock, self.assertRaises(ValueError):
                self.publish()
            mock.assert_not_called()

    def test_moved_remote_tag_cannot_publish(self):
        with self.exercise(tag_sha="c" * 40) as mock, self.assertRaisesRegex(ValueError, "Remote Git tag"):
            self.publish()
        self.assertEqual([], self.mutations(mock))

    def test_corrupt_uploaded_asset_keeps_new_release_as_a_draft(self):
        with self.exercise(bad_assets=True) as mock, self.assertRaisesRegex(ValueError, "checksum"):
            self.publish()
        self.assertFalse(any("--draft=false" in args for args in self.mutations(mock)))


if __name__ == "__main__":
    unittest.main()
