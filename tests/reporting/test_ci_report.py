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

    def fixture(self):
        base = {"schema": 1, "sha": "abc123", "run_id": "42", "status": "success", "report_errors": []}
        quality = dict(base, kind="quality", checks={key: "success" for key in REPORT.QUALITY_CHECKS},
                       audit={"advisories": 0, "abandoned": 0}, packages=[],
                       package_url="https://github.com/example/plugin/actions/runs/42/artifacts/7")
        REPORT.write_json(self.directory / "collected/quality/result.json", quality)
        REPORT.write_json(self.directory / "collected/plugin/result.json",
                          dict(base, kind="plugin-check", checks={"plugin_check": "success"}))
        for number, matrix in enumerate(REPORT.MATRIX):
            report = dict(base, kind="integration", matrix=matrix,
                          checks={key: "success" for key in ("install", "platform", "tests")},
                          versions={"wordpress": "6.8" if number == 0 else "7.1.5", "php": matrix[1] + ".1"},
                          tests={"tests": 3, "passed": 2, "failures": 0, "errors": 0, "skipped": 1, "assertions": 7})
            REPORT.write_json(self.directory / f"collected/integration-{number}/result.json", report)

    def alter(self, relative, change):
        path = self.directory / "collected" / relative / "result.json"
        data = REPORT.load_json(path)
        change(data)
        REPORT.write_json(path, data)

    def summarize(self):
        with contextlib.redirect_stdout(io.StringIO()):
            code = REPORT.summarize(self.directory, self.needs)
        return code, REPORT.load_json(self.directory / "overview.json"), (self.directory / "overview.md").read_text(encoding="utf-8")

    def test_complete_matrix_totals_and_native_summary(self):
        self.fixture()
        code, data, text = self.summarize()
        self.assertEqual(0, code)
        self.assertTrue(data["success"])
        self.assertEqual({"tests": 18, "passed": 12, "failures": 0, "errors": 0, "skipped": 6, "assertions": 42}, data["totals"])
        self.assertIn("6/6 environments", text)
        self.assertIn("artifacts/7", text)
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


if __name__ == "__main__":
    unittest.main()
