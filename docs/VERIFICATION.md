# Verification and reports

Verification runs throughout development and before publication. This guide describes the checks and where their results live; it is not a manually maintained report for one release. Release changes belong in the `readme.txt` changelog and [GitHub Releases](https://github.com/rubenwoudsma/bulk-password-reset/releases). Older verification notes remain available in Git history; a new Markdown file is not required for each version.

## Results from each Actions run

Open a [Quality and security](https://github.com/rubenwoudsma/bulk-password-reset/actions/workflows/ci.yml) or [Release plugin](https://github.com/rubenwoudsma/bulk-password-reset/actions/workflows/release.yml) run and select **Summary**. The **CI results overview** job combines fresh results for that run's source commit:

- Six real WordPress/MySQL environments, with installed WordPress/PHP versions, passed/failed/error/skipped executions and assertions. Totals count the suite's repeated executions across environments.
- PHP syntax, WordPress Coding Standards, PHP compatibility, release metadata, package validation and reporting-tool regression tests.
- Locked development-dependency audit and the official WordPress Plugin Check result. These complement security review; they do not establish the absence of vulnerabilities.
- The installable package name, checksum and artifact link. Missing reports or failed jobs are not silently counted as a pass.

Download **ci-report** for `overview.md`, `overview.json`, per-environment JUnit XML, dependency-audit JSON and available Plugin Check output. Actions artifacts are retained for **14 days**; save any evidence needed for longer-term review before it expires. The run links and release source commit identify which checks were performed.

Stable and pre-release integration jobs enable WordPress debug logging with hidden display and retain `wordpress-debug.log` plus `wordpress-debug.json`. The bootstrap records the settings in the actual PHPUnit process and writes one logging probe. The overview shows capture status and unreviewed log-line counts per environment. Missing proof or any nonempty line other than the exact probe fails the reporting check; inspect raw logs for context rather than treating line counts as distinct errors. PHPUnit also reports diagnostics handled by its own error handler and fails on warnings, notices and deprecations. Logs are outside the plugin package, and collection still runs after setup/test failure where the runner permits it.

A manual release preview also produces **github-release-candidate** (ZIP, notes, checksum and source/file manifest) and **wordpress-org-svn-report** (planned trees, hashes and SVN diff). A preview never publishes or commits. A version-tag run can publish after its checks pass and the relevant release controls allow it; see [the release guide](RELEASING.md).

The published GitHub Release keeps its changelog notes, plugin ZIP and checksum beyond the Actions artifact retention period. Repository-only documentation or fixture cleanup does not require a new plugin release.

## Pre-release compatibility results

The [WordPress pre-release compatibility](../.github/workflows/prerelease.yml) workflow has its own **Pre-release results overview** and **prerelease-report** artifact. It runs the existing integration/security suite on four explicit RC/PHP environments, including PHP 8.6 and multisite. The same installer/test action is used by stable CI. The report records requested and installed versions, checks their agreement, validates run/profile identity and reports failures or missing evidence as failures. It does not repeat or claim a pass for coding standards, dependency audit, packaging or Plugin Check; inspect **Quality and security** for those results.

Pre-release checks are separate from the release prerequisites and do not change required branch checks. A passing RC suite establishes the automated behavior tested on that RC, not a completed manual acceptance review or compatibility with the eventual final release. See [the development guide](../CONTRIBUTING.md#wordpress-pre-release-testing) and [issue #14](https://github.com/rubenwoudsma/bulk-password-reset/issues/14) for the remaining release-readiness work. These checks have no deployment secrets or publication steps.

## Tests and sample data

`tests/SecurityTest.php` exercises real WordPress behavior on a disposable installation, including authorization, nonces, per-target protections, reset links, both reset modes, sessions, mail failures, durable checkpoints, concurrency, cancellation, multisite and resumable batches. Boundary cases include 1, 19, 20, 21 and 40 users and an untouched look-ahead recipient.

Reset-link regressions include a username ending in a period, both reset modes and core's automatic linkification. They check network-aware URLs, exact username/key preservation, password/session behavior and a working single-use core reset.

`tests/reporting/` tests the reporting and release tools themselves. Its temporary sample repositories, versions, test counts and mocked API responses are **test fixtures**, not historical reports or live publication results. They stay independent of the plugin version; changing every sample number for each release would not refresh the actual reports. See [the fixture guide](../tests/reporting/README.md) for coverage and the local command.

The current test matrix is defined in [the CI workflow](../.github/workflows/ci.yml). [CONTRIBUTING.md](../CONTRIBUTING.md) describes local checks and disposable WordPress setup. A workflow definition or checked-in fixture is not evidence that a particular run passed; inspect that run's generated results.

## Manual acceptance before a runtime release

Use a disposable localhost/staging installation and controlled test recipients:

1. Exercise the role selector and native Users bulk action. Preview and an unconfirmed submission must not send mail or change passwords.
2. Complete both reset modes, open the captured reset link, choose a password and verify recipient login. Immediate invalidation must end an existing browser session.
3. Check a small job stays on the completed step, **Start a new reset** opens the chooser, and previous results remain accessible until replaced.
4. Check exact 20- and 40-user batches finish without an empty continuation, reload does not replay mail, and a look-ahead recipient is untouched until its batch runs.
5. Upgrade the previous published ZIP to the candidate without reactivation while a job is in progress. Confirm activation, saved progress and continuation without duplicate messages.
6. Review desktop/mobile layouts, keyboard use, labels, focus, branding, scoped assets and browser/PHP errors. Scoped accessibility scans supplement manual inspection; they are not a full accessibility audit.
7. Separately verify delivery to a controlled inbox through the site's mail provider. Local capture verifies generated messages and reset links, not external delivery.

Record the tested commit/package, environments, outcomes and any expected warnings in the release review or tracking issue. Clean up synthetic users, captured reset links and any test server created for the run. Do not use production users or private sites without explicit authorization.

## Verify publication

After an authorized release, record the actual committed SVN revision, verify the public runtime files and directory assets against the reviewed source, and compare every unpacked file in both published ZIPs. ZIP timestamps/metadata may differ between builds, so compare content as well as each archive's own checksum. Preserve existing release tags and assets.

Restore `WPORG_DEPLOY_ENABLED=false` after the upload and read it back. Check the listing/download separately from the ordinary WordPress.org update API: a live download does not establish an update offer. Use the original `bulk-password-reset/bulk_password_reset.php` basename and authentic installed metadata, confirm older supported installations receive the expected package, and confirm the published version is recognized as current. Account for minimum WordPress/PHP requirements, site caches and WordPress.org's [release cooldown](https://make.wordpress.org/plugins/2026/09/09/automated-security-review-for-plugin-releases/). Keep any unconfirmed distribution check open in the tracker; another SVN upload is not needed merely to recheck an offer.
