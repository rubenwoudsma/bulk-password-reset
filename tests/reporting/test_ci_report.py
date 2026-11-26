"""Reporting must distinguish a complete pass from failure or missing evidence."""

import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[2] / ".github/scripts/ci-report.py"
SPEC = importlib.util.spec_from_file_location("ci_report", SCRIPT)
REPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(REPORT)


class ReportTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.env = patch.dict(os.environ, {"GITHUB_SHA": "abc123", "GITHUB_RUN_ID": "42",
                              "GITHUB_REPOSITORY": "example/plugin", "GITHUB_SERVER_URL": "https://github.com",
                              "GITHUB_STEP_SUMMARY": str(self.directory / "step-summary.md")})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.needs = {key: {"result": "success"} for key in ("quality", "integration", "plugin-check")}

    def debug_fixture(self):
        path = self.directory / "wordpress-debug.log"
        REPORT.write_json(self.directory / "wordpress-debug.json", {
            "debug": True, "display": False, "log_errors": True, "display_errors": False,
            "all_errors": True, "configured_log": str(path.resolve()), "error_log": str(path.resolve()),
        })
        path.write_text("[26-Nov-2026 12:00:00 UTC] BPR CI debug logging probe\n", encoding="utf-8")

    def fixture(self, profile="stable"):
        base = {"schema": 1, "profile": profile, "sha": "abc123", "run_id": "42", "status": "success", "report_errors": []}
        quality = dict(base, kind="quality", checks={key: "success" for key in REPORT.QUALITY_CHECKS},
                       audit={"advisories": 0, "abandoned": 0}, packages=[],
                       package_url="https://github.com/example/plugin/actions/runs/42/artifacts/7")
        if profile == "stable":
            REPORT.write_json(self.directory / "collected/quality/result.json", quality)
            REPORT.write_json(self.directory / "collected/plugin/result.json",
                              dict(base, kind="plugin-check", checks={"plugin_check": "success"}))
        for number, matrix in enumerate(REPORT.MATRICES[profile]):
            report = dict(base, kind="integration", matrix=matrix,
                          checks={key: "success" for key in ("install", "platform", "tests")},
                          versions={"wordpress": "7.1.5" if matrix[0] == "latest" else matrix[0], "php": matrix[1] + ".1"},
                          debug={"log": "wordpress-debug.log", "bytes": 60, "sha256": "a" * 64,
                                 "probe_lines": 1, "unreviewed_lines": 0},
                          tests={"tests": 3, "passed": 2, "failures": 0, "errors": 0, "skipped": 1, "assertions": 7})
            REPORT.write_json(self.directory / f"collected/integration-{number}/result.json", report)

    def alter(self, relative, change):
        path = self.directory / "collected" / relative / "result.json"
        data = REPORT.load_json(path)
        change(data)
        REPORT.write_json(path, data)

    def summarize(self, profile="stable"):
        with contextlib.redirect_stdout(io.StringIO()):
            code = REPORT.summarize(self.directory, self.needs, profile)
        return code, REPORT.load_json(self.directory / "overview.json"), (self.directory / "overview.md").read_text(encoding="utf-8")

    def test_complete_matrix_totals_and_native_summary(self):
        self.fixture()
        code, data, text = self.summarize()
        self.assertEqual(0, code)
        self.assertTrue(data["success"])
        self.assertEqual({"tests": 18, "passed": 12, "failures": 0, "errors": 0, "skipped": 6, "assertions": 42}, data["totals"])
        self.assertIn("6/6 environments", text)
        self.assertIn("artifacts/7", text)
        self.assertIn("## WordPress debug logs", text)
        self.assertIn("wordpress-debug.log", text)
        self.assertEqual(text, (self.directory / "step-summary.md").read_text(encoding="utf-8"))

    def test_failed_github_job_cannot_be_overridden_by_successful_reports(self):
        self.fixture()
        self.needs["integration"]["result"] = "failure"
        code, data, text = self.summarize()
        self.assertEqual(1, code)
        self.assertFalse(data["success"])
        self.assertIn("GitHub job integration: failure", text)

    def test_missing_environment_is_not_zero_tests_or_a_pass(self):
        self.fixture()
        (self.directory / "collected/integration-0/result.json").unlink()
        code, data, text = self.summarize()
        self.assertEqual(1, code)
        self.assertEqual(5, data["environments_reported"])
        self.assertIn("5/6 environments", text)
        self.assertIn("Missing report", text)
        self.assertIn("Not reported", text)

    def test_early_setup_failure_preserves_available_matrix_results(self):
        self.fixture()
        def fail(data):
            data["status"] = "failure"
            data["checks"].update(install="failure", platform="skipped", tests="skipped")
            data.pop("tests")
            data["versions"] = {}
            data["report_errors"] = ["JUnit results unavailable"]
        self.alter("integration-0", fail)
        code, data, text = self.summarize()
        self.assertEqual(1, code)
        self.assertEqual(5, data["environments_reported"])
        self.assertIn("JUnit results unavailable", text)
        self.assertIn("Installed versions not recorded", text)

    def test_failed_test_is_in_totals_and_summary_fails(self):
        self.fixture()
        self.alter("integration-0", lambda data: data["tests"].update(passed=1, failures=1))
        code, data, text = self.summarize()
        self.assertEqual(1, code)
        self.assertEqual(1, data["totals"]["failures"])
        self.assertIn("Unsuccessful test cases", text)

    def test_audit_failure_keeps_other_quality_results_visible(self):
        self.fixture()
        def fail(data):
            data["status"] = "failure"
            data["checks"]["audit"] = "failure"
            data["audit"]["advisories"] = 2
        self.alter("quality", fail)
        code, _, text = self.summarize()
        self.assertEqual(1, code)
        self.assertIn("Known development dependency vulnerabilities | ❌ Failed", text)
        self.assertIn("WordPress Coding Standards | ✅ Passed", text)
        self.assertIn("**2 known advisories**", text)

    def test_foreign_and_duplicate_reports_are_rejected(self):
        self.fixture()
        original = REPORT.load_json(self.directory / "collected/integration-0/result.json")
        REPORT.write_json(self.directory / "collected/duplicate/result.json", copy.deepcopy(original))
        self.alter("integration-1", lambda data: data.update(sha="different-commit"))
        code, data, text = self.summarize()
        self.assertEqual(1, code)
        self.assertEqual(5, data["environments_reported"])
        self.assertIn("Duplicate or unexpected report", text)
        self.assertIn("Invalid report identity", text)

    def test_inconsistent_counts_are_unavailable(self):
        self.fixture()
        self.alter("integration-0", lambda data: data["tests"].update(passed=200))
        code, data, _ = self.summarize()
        self.assertEqual(1, code)
        self.assertEqual(5, data["environments_reported"])
        self.assertEqual(10, data["totals"]["passed"])

    def test_report_values_are_text_and_package_link_is_validated(self):
        self.fixture()
        self.alter("quality", lambda data: data.update(package_url="javascript:alert(1)"))
        self.alter("integration-0", lambda data: data["versions"].update(wordpress="<script>|[link](https://example.test)"))
        _, _, text = self.summarize()
        self.assertNotIn("<script>", text)
        self.assertNotIn("[link]", text)
        self.assertNotIn("javascript:", text)
        self.assertIn("&lt;script&gt;&#124;&#91;link&#93;", text)

    def test_prerelease_summary_has_its_own_matrix_and_scope(self):
        self.fixture("prerelease")
        self.needs = {"integration": {"result": "success"}}
        code, data, text = self.summarize("prerelease")
        self.assertEqual(0, code)
        self.assertEqual("prerelease", data["profile"])
        self.assertEqual(4, data["environments_reported"])
        self.assertEqual(8, data["totals"]["passed"])
        self.assertIn("4/4 environments", text)
        self.assertIn("7.2-RC3 / 8.6 / single", text)
        self.assertIn("pre-release compatibility only", text)
        self.assertIn("**prerelease-report**", text)
        self.assertNotIn("Official WordPress Plugin Check | ✅", text)
        self.assertNotIn("Missing report", text)

    def test_missing_prerelease_environment_fails_with_partial_evidence(self):
        self.fixture("prerelease")
        (self.directory / "collected/integration-2/result.json").unlink()
        code, data, text = self.summarize("prerelease")
        self.assertEqual(1, code)
        self.assertEqual(3, data["environments_reported"])
        self.assertIn("3/4 environments", text)
        self.assertIn("Missing report: ('7.2-RC3', '8.6', 'single')", text)

    def test_wrong_profile_is_rejected_even_with_matching_run_identity(self):
        self.fixture("prerelease")
        self.alter("integration-0", lambda data: data.update(profile="stable"))
        code, data, text = self.summarize("prerelease")
        self.assertEqual(1, code)
        self.assertEqual(3, data["environments_reported"])
        self.assertIn("Invalid report identity", text)

    def test_stable_reports_cannot_satisfy_prerelease_checks(self):
        self.fixture()
        code, data, text = self.summarize("prerelease")
        self.assertEqual(1, code)
        self.assertEqual(0, data["environments_reported"])
        self.assertIn("0/4 environments", text)

    def test_unexpected_installed_versions_cannot_pass(self):
        for profile, version in (("prerelease", {"wordpress": "7.1.5"}),
                                 ("prerelease", {"php": "8.5.1"}),
                                 ("stable", {"wordpress": "7.2-RC3"})):
            with self.subTest(profile=profile, version=version):
                self.fixture(profile)
                number = 2 if profile == "prerelease" else 1
                self.alter(f"integration-{number}", lambda data: data["versions"].update(version))
                code, _, text = self.summarize(profile)
                self.assertEqual(1, code)
                self.assertIn("Installed versions do not match", text)

    def test_failed_prerelease_github_job_is_not_a_pass(self):
        self.fixture("prerelease")
        self.needs = {"integration": {"result": "failure"}}
        code, _, text = self.summarize("prerelease")
        self.assertEqual(1, code)
        self.assertIn("GitHub job integration: failure", text)

    def test_record_keeps_prerelease_profile_and_installed_versions(self):
        self.debug_fixture()
        (self.directory / "wordpress-version.txt").write_text("7.2-RC3\n", encoding="utf-8")
        (self.directory / "php-version.txt").write_text("8.6.0", encoding="utf-8")
        (self.directory / "junit.xml").write_text('<testsuite><testcase assertions="1"/></testsuite>', encoding="utf-8")
        with patch.dict(os.environ, {"CI_STEPS": json.dumps({key: {"outcome": "success"}
                                    for key in ("install", "platform", "tests")}), "CI_JOB_STATUS": "success",
                                    "CI_WORDPRESS": "7.2-RC3", "CI_PHP": "8.6", "CI_MODE": "single"}):
            REPORT.record("integration", self.directory, "prerelease")
        result = REPORT.load_json(self.directory / "result.json")
        self.assertEqual("prerelease", result["profile"])
        self.assertEqual({"wordpress": "7.2-RC3", "php": "8.6.0"}, result["versions"])
        self.assertFalse(result["report_errors"])
        self.assertEqual(0, result["debug"]["unreviewed_lines"])

    def test_disabled_or_misdirected_logging_is_rejected(self):
        for change in ({"debug": False}, {"display": True}, {"all_errors": False},
                       {"log_errors": False}, {"display_errors": True},
                       {"configured_log": "/tmp/elsewhere.log"}, {"error_log": "/tmp/elsewhere.log"}):
            with self.subTest(change=change):
                self.debug_fixture()
                settings = REPORT.load_json(self.directory / "wordpress-debug.json")
                settings.update(change)
                REPORT.write_json(self.directory / "wordpress-debug.json", settings)
                with self.assertRaisesRegex(ValueError, "debug settings"):
                    REPORT.debug_counts(self.directory)

    def test_empty_missing_or_duplicate_probe_is_not_a_clean_capture(self):
        self.debug_fixture()
        path = self.directory / "wordpress-debug.log"
        for content in ("", "PHP Warning: example\n", "BPR CI debug logging probe\n" * 2):
            with self.subTest(content=content):
                path.write_text(content, encoding="utf-8")
                with self.assertRaisesRegex(ValueError, "bootstrap probe"):
                    REPORT.debug_counts(self.directory)
        path.unlink()
        with self.assertRaises(OSError):
            REPORT.debug_counts(self.directory)

    def test_unreviewed_log_lines_fail_record_and_are_preserved(self):
        self.debug_fixture()
        path = self.directory / "wordpress-debug.log"
        original = path.read_text(encoding="utf-8") + "PHP Deprecated: example\n  stack trace line\n"
        path.write_text(original, encoding="utf-8")
        (self.directory / "junit.xml").write_text('<testsuite><testcase assertions="1"/></testsuite>', encoding="utf-8")
        self.assertEqual(1, REPORT.record("integration", self.directory))
        result = REPORT.load_json(self.directory / "result.json")
        self.assertEqual(2, result["debug"]["unreviewed_lines"])
        self.assertTrue(result["report_errors"])
        self.assertEqual(original, path.read_text(encoding="utf-8"))

    def test_missing_debug_evidence_fails_both_summary_profiles(self):
        for profile in REPORT.MATRICES:
            with self.subTest(profile=profile):
                self.fixture(profile)
                self.alter("integration-0", lambda data: data.pop("debug"))
                code, _, text = self.summarize(profile)
                self.assertEqual(1, code)
                self.assertIn("WordPress debug capture missing", text)

    def test_summary_rejects_unreviewed_diagnostics_even_without_report_errors(self):
        self.fixture()
        self.alter("integration-0", lambda data: data["debug"].update(unreviewed_lines=1))
        code, _, text = self.summarize()
        self.assertEqual(1, code)
        self.assertIn("WordPress debug capture missing or needs review", text)

    def test_nested_junit_suites_are_not_double_counted(self):
        path = self.directory / "junit.xml"
        path.write_text('<testsuites><testsuite tests="4"><testsuite tests="4">'
                        '<testcase assertions="3"/><testcase assertions="1"><failure/></testcase>'
                        '<testcase assertions="0"><error/></testcase><testcase assertions="0"><skipped/></testcase>'
                        '</testsuite></testsuite></testsuites>', encoding="utf-8")
        self.assertEqual({"tests": 4, "passed": 1, "failures": 1, "errors": 1, "skipped": 1, "assertions": 4}, REPORT.junit_counts(path))

    def test_empty_or_external_entity_junit_is_not_accepted(self):
        path = self.directory / "junit.xml"
        for xml in ('<testsuites/>', '<!DOCTYPE testsuites SYSTEM "file:///etc/passwd"><testsuites/>'):
            path.write_text(xml, encoding="utf-8")
            with self.assertRaises(ValueError):
                REPORT.junit_counts(path)

    def test_record_preserves_failure_when_junit_is_unavailable(self):
        with patch.dict(os.environ, {"CI_STEPS": json.dumps({"tests": {"outcome": "failure"}}),
                                   "CI_JOB_STATUS": "failure", "CI_WORDPRESS": "6.8", "CI_PHP": "8.2", "CI_MODE": "single"}):
            REPORT.record("integration", self.directory)
        result = REPORT.load_json(self.directory / "result.json")
        self.assertEqual("failure", result["checks"]["tests"])
        self.assertNotIn("tests", result)
        self.assertTrue(result["report_errors"])
        self.assertTrue(any("WordPress debug capture unavailable" in error for error in result["report_errors"]))


if __name__ == "__main__":
    unittest.main()
