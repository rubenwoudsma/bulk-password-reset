"""SVN preparation must describe exactly the ZIP and never perform a commit."""

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch
import zipfile


SPEC = importlib.util.spec_from_file_location("svn_report", Path(__file__).resolve().parents[2] / ".github/scripts/svn-report.py")
REPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(REPORT)
# Deliberately fictional test data; independent of the plugin's release version.
FIXTURE_VERSION = "9.8.7"


class SvnReportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.workspace, self.checkout = self.root / "workspace", self.root / "checkout"
        self.runtime = self.workspace / "build/bulk-password-reset"
        self.runtime.mkdir(parents=True)
        (self.runtime / "bulk_password_reset.php").write_text("<?php // reviewed runtime\n", encoding="utf-8")
        (self.runtime / "readme.txt").write_text(f"Stable tag: {FIXTURE_VERSION}\n", encoding="utf-8")
        assets = self.workspace / ".wordpress-org"
        assets.mkdir()
        (assets / "icon.png").write_bytes(b"test-asset")
        for target in (self.checkout / "trunk", self.checkout / "tags" / FIXTURE_VERSION):
            shutil.copytree(self.runtime, target)
        shutil.copytree(assets, self.checkout / "assets")
        self.package = self.workspace / f"dist/bulk-password-reset-{FIXTURE_VERSION}.zip"
        self.package.parent.mkdir()
        with zipfile.ZipFile(self.package, "w") as package:
            for path in self.runtime.iterdir():
                package.write(path, "bulk-password-reset/" + path.name)
        self.env = patch.dict(os.environ, {"RELEASE_VERSION": FIXTURE_VERSION, "SVN_DRY_RUN": "true",
                              "SVN_IDENTITY_OUTCOME": "success", "SVN_ACTION_OUTCOME": "success",
                              "GITHUB_SHA": "reviewed-revision", "GITHUB_STEP_SUMMARY": str(self.root / "summary.md")})
        self.env.start()
        self.addCleanup(self.env.stop)

    def test_runtime_assets_and_zip_match(self):
        runtime, assets, package = REPORT.verify_trees(self.workspace, self.checkout, FIXTURE_VERSION)
        self.assertEqual({"bulk_password_reset.php", "readme.txt"}, set(runtime))
        self.assertEqual({"icon.png"}, set(assets))
        self.assertEqual(self.package.stat().st_size, package["bytes"])
        self.assertEqual(64, len(package["sha256"]))

    def test_extra_legacy_files_are_detected_in_both_svn_targets(self):
        for relative in ("trunk", f"tags/{FIXTURE_VERSION}"):
            with self.subTest(target=relative):
                extra = self.checkout / relative / "obsolete-handler.php"
                extra.write_text("old reset handler", encoding="utf-8")
                with self.assertRaisesRegex(ValueError, "differs from the ZIP"):
                    REPORT.verify_trees(self.workspace, self.checkout, FIXTURE_VERSION)
                extra.unlink()

    def test_changed_directory_asset_is_detected(self):
        (self.checkout / "assets/icon.png").write_bytes(b"different-icon")
        with self.assertRaisesRegex(ValueError, "assets differ"):
            REPORT.verify_trees(self.workspace, self.checkout, FIXTURE_VERSION)

    def test_modified_zip_cannot_pass_tree_verification(self):
        with zipfile.ZipFile(self.package, "w") as package:
            package.writestr("bulk-password-reset/bulk_password_reset.php", "unreviewed-code")
        with self.assertRaisesRegex(ValueError, "differs from the installable ZIP"):
            REPORT.verify_trees(self.workspace, self.checkout, FIXTURE_VERSION)

    def test_invalid_version_and_package_paths_are_rejected(self):
        for version in ("../../outside", f"v{FIXTURE_VERSION}", FIXTURE_VERSION + "\n", FIXTURE_VERSION + ";false"):
            with self.subTest(version=version), self.assertRaises(ValueError):
                REPORT.verify_trees(self.workspace, self.checkout, version)
        for path in ("another-plugin/file.php", "bulk-password-reset/../file.php"):
            with zipfile.ZipFile(self.package, "w") as package:
                package.writestr(path, "unreviewed")
            with self.subTest(path=path), self.assertRaises(ValueError):
                REPORT.zip_manifest(self.package)

    @unittest.skipIf(os.name == "nt", "Creating symlinks requires additional Windows privileges")
    def test_symlink_to_another_tree_is_rejected(self):
        (self.runtime / "external.php").symlink_to(self.root / "outside.php")
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            REPORT.manifest(self.runtime)

    def test_svn_conflicts_and_missing_files_are_rejected(self):
        for attributes in ('item="conflicted"', 'item="missing"', 'item="normal" props="conflicted"',
                           'item="normal" tree-conflicted="true"'):
            with self.subTest(attributes=attributes), self.assertRaises(ValueError):
                REPORT.status_counts(f'<status><target><entry><wc-status {attributes}/></entry></target></status>')

    def run_report(self, status_xml='<status><target><entry><wc-status item="added"/></entry></target></status>'):
        def svn_read(arguments, output):
            values = {"status": status_xml, "info": '<info><entry revision="123"/></info>',
                      "diff": "SVN review diff\n"}
            output.write_text(values[arguments[0]], encoding="utf-8")
            return values[arguments[0]]
        with patch.object(REPORT, "svn_read", side_effect=svn_read) as reader, contextlib.redirect_stdout(io.StringIO()):
            code = REPORT.create_report(self.workspace, self.checkout)
        data = json.loads((self.workspace / "svn-upload-report/plan.json").read_text(encoding="utf-8"))
        markdown = (self.workspace / "svn-upload-report/summary.md").read_text(encoding="utf-8")
        return code, data, markdown, reader

    def test_preview_report_contains_evidence_and_uses_only_read_commands(self):
        code, data, markdown, reader = self.run_report()
        self.assertEqual(0, code)
        self.assertTrue(data["success"])
        self.assertEqual("preview", data["mode"])
        self.assertFalse(data["write_authentication_verified"])
        self.assertEqual({"added": 1}, data["svn_entries"])
        self.assertIn("No SVN commit was performed", markdown)
        self.assertIn("does not verify the password", markdown)
        self.assertEqual({"status", "info", "diff"}, {call.args[0][0] for call in reader.call_args_list})
        self.assertEqual(markdown, (self.root / "summary.md").read_text(encoding="utf-8"))

    def test_failed_action_is_not_reported_as_success_or_valid_credentials(self):
        with patch.dict(os.environ, {"SVN_ACTION_OUTCOME": "failure"}):
            code, data, markdown, reader = self.run_report()
        self.assertEqual(1, code)
        self.assertFalse(data["success"])
        self.assertFalse(data["write_authentication_verified"])
        self.assertIn("did not complete", markdown)
        reader.assert_not_called()

    def test_remaining_changes_after_live_action_are_a_failure(self):
        with patch.dict(os.environ, {"SVN_DRY_RUN": "false"}):
            code, data, markdown, _ = self.run_report()
        self.assertEqual(1, code)
        self.assertFalse(data["success"])
        self.assertIn("uncommitted changes", markdown)


if __name__ == "__main__":
    unittest.main()
