#!/usr/bin/env python3
"""Create dependency-free CI reports; never change the outcome of a check."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import xml.etree.ElementTree as ET


QUALITY_CHECKS = {
    "validate": "Composer metadata and lock file",
    "install": "Development dependency installation",
    "platform": "Actual PHP platform requirements",
    "audit": "Known development dependency vulnerabilities",
    "lint": "PHP syntax",
    "standards": "WordPress Coding Standards",
    "compatibility": "PHP compatibility rules",
    "metadata": "Plugin release metadata",
    "reporting_tests": "CI reporting regression tests",
    "package": "Installable plugin package",
    "package_upload": "Package upload",
}
MATRIX = [
    ("6.8", "8.2", "single"),
    ("latest", "8.2", "single"),
    ("latest", "8.3", "single"),
    ("latest", "8.4", "single"),
    ("latest", "8.5", "single"),
    ("latest", "8.4", "multisite"),
]
MATRICES = {
    "stable": MATRIX,
    "prerelease": [
        ("7.2-RC3", "8.2", "single"),
        ("7.2-RC3", "8.4", "single"),
        ("7.2-RC3", "8.6", "single"),
        ("7.2-RC3", "8.4", "multisite"),
    ],
}
STATUSES = {"success", "failure", "cancelled", "skipped"}


def load_json(path):
    if path.stat().st_size > 2_000_000:
        raise ValueError(f"Report is too large: {path.name}")
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def junit_counts(path):
    """Count testcase leaves once, even when JUnit suites are nested."""
    if path.stat().st_size > 5_000_000:
        raise ValueError("JUnit report is too large")
    xml = path.read_bytes()
    if b"<!DOCTYPE" in xml or b"<!ENTITY" in xml:
        raise ValueError("JUnit declarations are not supported")
    root = ET.fromstring(xml)
    if root.tag not in {"testsuites", "testsuite"}:
        raise ValueError("Unexpected JUnit root")
    cases = list(root.iter("testcase"))
    if not cases:
        raise ValueError("JUnit report contains no test cases")
    counts = dict(tests=len(cases), passed=0, failures=0, errors=0, skipped=0, assertions=0)
    for case in cases:
        assertions = int(case.get("assertions", "0"))
        if assertions < 0:
            raise ValueError("Negative JUnit assertion count")
        counts["assertions"] += assertions
        outcome = next((key for key, tag in (("errors", "error"), ("failures", "failure"),
                                            ("skipped", "skipped")) if case.find(tag) is not None), "passed")
        counts[outcome] += 1
    return counts


def record(kind, directory, profile="stable"):
    directory.mkdir(parents=True, exist_ok=True)
    steps = json.loads(os.environ.get("CI_STEPS", "{}"))
    report = {
        "schema": 1, "kind": kind, "profile": profile, "sha": os.environ.get("GITHUB_SHA", ""),
        "run_id": os.environ.get("GITHUB_RUN_ID", ""),
        "status": os.environ.get("CI_JOB_STATUS", "unknown"),
        "checks": {}, "report_errors": [],
    }
    check_ids = QUALITY_CHECKS if kind == "quality" else (
        ("install", "platform", "tests") if kind == "integration" else ("plugin_check",))
    report["checks"] = {key: steps.get(key, {}).get("outcome", "not run") for key in check_ids}
    if kind == "quality":
        if (directory / "composer-audit.json").exists():
            try:
                audit = load_json(directory / "composer-audit.json")
                advisories, abandoned = audit["advisories"], audit["abandoned"]
                groups = advisories.values() if isinstance(advisories, dict) else advisories
                report["audit"] = {"advisories": sum(len(group) for group in groups),
                                   "abandoned": len(abandoned)}
            except (ValueError, TypeError, KeyError) as error:
                report["report_errors"].append(f"Cannot read dependency audit: {error}")
        elif report["checks"]["audit"] == "success":
            report["report_errors"].append("Successful dependency audit has no JSON report")
        report["packages"] = [{"name": path.name, "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
                               "bytes": path.stat().st_size} for path in sorted(Path("dist").glob("*.zip"))]
        report["package_url"] = os.environ.get("CI_PACKAGE_URL", "")
        if report["checks"]["package"] == "success" and not report["packages"]:
            report["report_errors"].append("Successful package build has no ZIP")
    elif kind == "integration":
        report["matrix"] = [os.environ.get(f"CI_{key}", "") for key in ("WORDPRESS", "PHP", "MODE")]
        report["versions"] = {}
        for key in ("wordpress", "php"):
            path = directory / f"{key}-version.txt"
            report["versions"][key] = path.read_text(encoding="utf-8").strip() if path.exists() else None
        try:
            report["tests"] = junit_counts(directory / "junit.xml")
        except (OSError, ValueError, ET.ParseError) as error:
            report["report_errors"].append(f"JUnit results unavailable: {error}")
    else:
        path = Path(os.environ.get("RUNNER_TEMP", ".")) / "plugin-check-results.txt"
        if path.is_file():
            shutil.copyfile(path, directory / "plugin-check-results.txt")
    write_json(directory / "result.json", report)


def cell(value):
    """Render report values as text, rather than active Markdown/HTML."""
    value = "Not recorded" if value is None else str(value)
    for token, replacement in (("&", "&amp;"), ("<", "&lt;"), (">", "&gt;"),
                               ("|", "&#124;"), ("`", "&#96;"), ("[", "&#91;"),
                               ("]", "&#93;"), ("*", "&#42;"), ("_", "&#95;")):
        value = value.replace(token, replacement)
    return " ".join(value.split())


def status(value):
    return {"success": "✅ Passed", "failure": "❌ Failed", "cancelled": "⚪ Cancelled",
            "skipped": "⚪ Skipped", "not run": "⚪ Not run"}.get(value, "⚠️ Not reported")


def summarize(directory, needs, profile="stable"):
    matrix_entries = MATRICES[profile]
    stable = profile == "stable"
    kinds = {"quality", "integration", "plugin-check"} if stable else {"integration"}
    records, problems = {}, []
    for path in sorted((directory / "collected").rglob("result.json")):
        try:
            report = load_json(path)
            if (report["schema"] != 1 or report["kind"] not in kinds or report["profile"] != profile
                    or report["sha"] != os.environ.get("GITHUB_SHA", "")
                    or report["run_id"] != os.environ.get("GITHUB_RUN_ID", "")
                    or report["status"] not in STATUSES or not isinstance(report["checks"], dict)
                    or not isinstance(report["report_errors"], list)):
                raise ValueError("Invalid report identity or fields")
            key = tuple(report["matrix"]) if report["kind"] == "integration" else report["kind"]
            if key in records or (isinstance(key, tuple) and key not in matrix_entries):
                raise ValueError("Duplicate or unexpected report")
            records[key] = report
            problems.extend(str(error) for error in report["report_errors"])
        except (OSError, ValueError, TypeError, KeyError) as error:
            problems.append(f"Cannot use {path.parent.name}/result.json: {error}")
    expected = ["quality", *matrix_entries, "plugin-check"] if stable else matrix_entries
    for key in expected:
        if key not in records:
            problems.append(f"Missing report: {key}")
        elif records[key]["status"] != "success":
            problems.append(f"Check did not succeed: {key}")
    for key in kinds:
        if needs.get(key, {}).get("result") != "success":
            problems.append(f"GitHub job {key}: {needs.get(key, {}).get('result', 'not reported')}")
    quality, plugin = records.get("quality", {}), records.get("plugin-check", {})
    required_reports = ((quality, QUALITY_CHECKS), (plugin, ("plugin_check",))) if stable else ()
    for report, required in required_reports:
        for key in required:
            if report.get("checks", {}).get(key) != "success":
                problems.append(f"Required check did not succeed: {key}")
    totals = dict(tests=0, passed=0, failures=0, errors=0, skipped=0, assertions=0)
    rows, available = [], 0
    for matrix in matrix_entries:
        report = records.get(matrix, {})
        tests = report.get("tests")
        valid = (isinstance(tests, dict) and all(type(tests.get(key)) is int and tests[key] >= 0 for key in totals)
                 and tests["tests"] > 0 and tests["tests"] == sum(tests[key] for key in ("passed", "failures", "errors", "skipped")))
        if valid:
            available += 1
            for key in totals:
                totals[key] += tests[key]
            if tests["errors"] or tests["failures"]:
                problems.append(f"Unsuccessful test cases: {matrix}")
        else:
            problems.append(f"No valid test counts: {matrix}")
        for key in ("install", "platform", "tests"):
            if report.get("checks", {}).get(key) != "success":
                problems.append(f"Integration check {key} did not succeed: {matrix}")
        versions = report.get("versions", {})
        if not all(versions.get(key) for key in ("wordpress", "php")):
            problems.append(f"Installed versions not recorded: {matrix}")
        else:
            wordpress, php = versions["wordpress"], versions["php"]
            if (not isinstance(wordpress, str) or not isinstance(php, str)
                    or not php.startswith(matrix[1] + ".")
                    or (matrix[0] == "latest" and not re.fullmatch(r"\d+\.\d+(?:\.\d+)?", wordpress))
                    or (matrix[0] != "latest" and wordpress != matrix[0])):
                problems.append(f"Installed versions do not match the requested environment: {matrix}")
        environment = f"{cell(matrix[0])} / {cell(matrix[1])} / {cell(matrix[2])}"
        values = [environment, cell(versions.get("wordpress")), cell(versions.get("php")),
                  status(report.get("status"))]
        values += [str(tests[key]) if valid else "—" for key in ("passed", "failures", "errors", "skipped", "assertions")]
        rows.append("| " + " | ".join(values) + " |")
    success = not problems
    server = os.environ.get("GITHUB_SERVER_URL", "https://github.com")
    run_url = f"{server}/{os.environ.get('GITHUB_REPOSITORY', '')}/actions/runs/{os.environ.get('GITHUB_RUN_ID', '')}"
    title = "test and security overview" if stable else "pre-release compatibility overview"
    lines = [f"# Bulk Password Reset — {title}", "",
             "**✅ All checks passed.**" if success else "**❌ Checks failed or results are incomplete. See the details below.**", "",
             f"Revision: `{cell(os.environ.get('GITHUB_SHA', ''))}` · [Open run and job logs]({run_url})", "",
             "## Test environments", "", "Database: MySQL 8.0. Versions below are recorded from each installed environment.", "",
             "| Requested WordPress / PHP / mode | Installed WordPress | Installed PHP | Result | Passed | Failures | Errors | Skipped | Assertions |",
             "| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: |", *rows, "",
             f"Available results: **{available}/{len(matrix_entries)} environments**. Test executions across these environments: "
             f"**{totals['passed']} passed, {totals['failures']} failures, {totals['errors']} errors, "
             f"{totals['skipped']} skipped; {totals['assertions']} assertions**.", "",
             "The same suite runs in each environment; totals count repeated executions. Multisite-only cases are skipped on single sites.", ""]
    if stable:
        lines += ["## Quality and security checks", "", "| Check | Result |", "| --- | --- |"]
        for key, label in QUALITY_CHECKS.items():
            lines.append(f"| {label} | {status(quality.get('checks', {}).get(key))} |")
        lines += [f"| Official WordPress Plugin Check | {status(plugin.get('checks', {}).get('plugin_check'))} |", ""]
        if "audit" in quality:
            lines.append(f"Dependency audit: **{cell(quality['audit'].get('advisories'))} known advisories**, "
                         f"**{cell(quality['audit'].get('abandoned'))} abandoned packages**. This audits locked development dependencies.")
        else:
            lines.append("Dependency audit counts are unavailable; see its check result and job log.")
    else:
        lines += ["This run tests pre-release compatibility only. The separate **Quality and security** workflow "
                  "reports stable/minimum-version coverage, coding standards, dependency audit, packaging and Plugin Check. "
                  "Those checks are not repeated or certified by this overview.", "",
                  "RC results do not replace final WordPress release testing, manual admin checks or a compatibility declaration."]
    artifact = "ci-report" if stable else "prerelease-report"
    lines += ["", "These automated checks do not constitute a full security audit or WordPress.org approval.", "",
              "## Package and downloadable reports", "",
              f"Download **{artifact}** from this run's Artifacts section for this overview, combined JSON and "
              "per-environment JUnit XML" + (", dependency-audit JSON, and available Plugin Check output" if stable else "")
              + ". Reports are retained for 14 days.", ""]
    package_url = quality.get("package_url", "")
    if isinstance(package_url, str) and package_url.startswith(run_url + "/artifacts/") and package_url[len(run_url + "/artifacts/"):].isdigit():
        lines.append(f"[Download the installable plugin package]({package_url}) (GitHub sign-in may be required).")
    for package in quality.get("packages", []):
        lines += ["", f"Package: **{cell(package.get('name'))}** · {cell(package.get('bytes'))} bytes",
                  f"SHA256: `{cell(package.get('sha256'))}`"]
    lines += ["", "Build artifacts are for review. Use a package only after checking the relevant run's results. "
              "This report does not authorize a release or WordPress.org upload."]
    if problems:
        lines += ["", "## Failures and missing evidence", ""] + [f"- {cell(problem)}" for problem in problems]
    markdown = "\n".join(lines) + "\n"
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "overview.md").write_text(markdown, encoding="utf-8")
    write_json(directory / "overview.json", {"success": success, "profile": profile, "run_url": run_url,
               "sha": os.environ.get("GITHUB_SHA", ""), "environments_reported": available,
               "totals": totals, "problems": problems, "reports": list(records.values())})
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
            summary.write(markdown)
    print(markdown)
    return 0 if success else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("record", "summary"))
    parser.add_argument("kind", nargs="?", choices=("quality", "integration", "plugin-check"))
    parser.add_argument("--directory", type=Path, default=Path("reports"))
    parser.add_argument("--profile", choices=tuple(MATRICES), default="stable")
    args = parser.parse_args()
    if args.command == "record":
        if not args.kind:
            parser.error("record requires a report kind")
        record(args.kind, args.directory, args.profile)
        return 0
    return summarize(args.directory, json.loads(os.environ.get("CI_NEEDS", "{}")), args.profile)


if __name__ == "__main__":
    sys.exit(main())
