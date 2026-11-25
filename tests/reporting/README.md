# Reporting and release-tool tests

These files contain regression tests, not saved reports. Each test creates temporary sample inputs and checks the tools in `.github/scripts/`:

| Test module | Coverage |
| --- | --- |
| `test_ci_report.py` | Fresh run identity, matrix aggregation, failures, missing evidence, JUnit parsing and native Actions summaries |
| `test_github_release.py` | Changelog extraction, exact package verification, checksums, tag identity, draft recovery and publication boundaries |
| `test_svn_report.py` | ZIP/trunk/tag/asset parity, path validation, read-only preview evidence and failed/incomplete upload reporting |

The fixed **9.8.7** version is deliberately fictional. Sample versions, users, file contents, run IDs and test totals are controlled test data; they do not describe the installed plugin or need updating for every release. GitHub and SVN operations are mocked here, and temporary files are cleaned up after each test.

Run the suite from the repository root:

```sh
python3 -m unittest discover -s tests/reporting -v
```

CI runs it in the **Standards, metadata and package** job. Actual results are generated anew for each workflow commit and appear in its **Summary** and **ci-report** artifact. Release/SVN reports derive their version and file hashes from that run's reviewed metadata and package. See [verification and reports](../../docs/VERIFICATION.md); changing a fixture version does not update or republish a release.
