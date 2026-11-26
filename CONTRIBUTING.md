# Development and verification

Runtime PHP is in `bulk_password_reset.php`, `includes/`, and `uninstall.php`. Development dependencies are never shipped. Keep the entry-point filename stable for existing installations.

## Local checks

Use PHP 8.2+ with mysqli, mbstring, DOM/XML and zip, plus Composer 2:

```sh
composer install
composer validate --strict
composer lint
composer standards
composer compatibility
composer audit --locked
composer metadata
composer package
```

The package script includes only explicitly listed runtime files, the plugin readme, license, and translations. It fails on inconsistent release metadata. Build output is `dist/bulk-password-reset-VERSION.zip`.

WordPress Coding Standards applies to all shipped PHP. A single filename exception preserves the historical `bulk_password_reset.php` activation basename. Tests and build scripts use ordinary PHP style and are syntax-checked separately. The PHP tools in `scripts/` have file DocBlocks describing their purpose, usage, outputs and package boundary; they do not need WordPress plugin activation headers. PHPCompatibilityWP supplements syntax tests; it is not proof of compatibility with every future PHP version.

Composer resolves development dependencies for PHP 8.2.0 through `config.platform.php`, including when updating the lock file on a newer interpreter. Keep that target aligned with the plugin's minimum supported PHP version. CI also runs `composer check-platform-reqs` against each actual interpreter, so the resolution target does not replace runtime verification.

PHPUnit remains on the PHP 8.2-compatible 11.x line. Dependabot still proposes compatible minor/patch updates, while PHPUnit major upgrades require a deliberate compatibility review. Do not raise the plugin's PHP minimum just to accommodate a test-tool update. CI installs WP-CLI 2.12.0 from its official release asset with a fixed SHA256 and bounded download retries; update both version and checksum when changing that tool.

## Integration tests

Tests run against **real WordPress**, using a disposable installed site rather than mocked WordPress functions. They create and delete users and change their passwords. Never point them at a production site.

1. Create a fresh WordPress database and installation using WP-CLI or your local environment.
2. Add `define( 'BPR_TESTING', true );` to that disposable site's `wp-config.php`. The test bootstrap refuses to run without this marker.
3. Set `BPR_WP_ROOT` to the absolute WordPress directory and run `composer test`.

```sh
export BPR_WP_ROOT=/tmp/bpr-wordpress
composer test
```

The test suite intercepts WordPress mail in memory. Its fixtures use `.test` addresses. It covers permission denial, per-user policy, privileged accounts, CSRF protection, POST-only mutation, job ownership, confirmation, reset-key hashing/single use/expiry, both modes, sessions, mail failures, durable progress, interrupted work, locks, cancellation, role changes, custom roles, and more than 100 users. Multisite-specific tests run in the multisite matrix entry.

Both CI matrices explicitly set `WP_DEBUG=true`, `WP_DEBUG_DISPLAY=false` and an absolute `WP_DEBUG_LOG` outside the WordPress installation and packaged plugin, in the environment's `reports/` directory. The PHPUnit bootstrap records the actual PHP/WordPress logging settings and writes one probe. The collector requires that proof, preserves the raw log/settings and fails on missing capture or any other nonempty log line; it does not suppress core, dependency or fixture warnings. Review unexpected lines before changing any classification. PHPUnit separately reports diagnostics intercepted by its error handler and fails on warnings, notices and deprecations. This only configures disposable CI sites, not installed plugins or production logging.

CI installs WordPress with MySQL 8.0. The matrix covers minimum WordPress 6.8/PHP 8.2, latest WordPress on PHP 8.2–8.5, and latest WordPress multisite on PHP 8.4. Inspect each run's generated report for what actually ran; a workflow definition alone is not a completed GitHub run. [Verification and reports](docs/VERIFICATION.md) describes the evidence and manual acceptance process without requiring a new document per release.

## WordPress pre-release testing

The separate **WordPress pre-release compatibility** workflow runs the same suite on **WordPress 7.2-RC3**, using PHP **8.2, 8.4 and 8.6** on single sites and PHP **8.4** on multisite. It runs on pull requests, master pushes, weekly and manually. `latest` in the stable matrix continues to mean the latest stable WordPress; it is not an RC selector. The shared `.github/actions/wordpress-tests` action keeps installation, safety markers, pinned WP-CLI, mail interception, test execution and evidence collection consistent between workflows.

These RC jobs are independent of the release workflow and are not added to required branch checks by this change. Failures still fail their jobs and overview; there is no `continue-on-error`. The **Pre-release results overview** and **prerelease-report** artifact show only that run's compatibility results. They do not certify the separate quality/security checks, manual acceptance or compatibility with a future final release. Both collectors verify requested versus installed WordPress/PHP versions and reject reports from another profile or run.

To move to the next RC, update `.github/workflows/prerelease.yml` and the `prerelease` matrix in `.github/scripts/ci-report.py` together, then run the reporting tests and both workflows. Retest the final WordPress release when available. Keep the minimum-version coverage and PHP 8.2 Composer resolution target. Review PHP 8.6 tooling support without raising the plugin's minimum PHP requirement or ignoring platform checks.

Use only a disposable localhost site for manual RC testing. For example, on an existing disposable installation: `wp core update --version=7.2-RC3 --path=/absolute/path/to/test-site`, followed by `wp core update-db` and the usual marked-site integration tests. The [official RC announcement](https://wordpress.org/news/2026/11/wordpress-7-2-release-candidate-3/) also describes WordPress Beta Tester. RC tests do not verify external mail delivery or replace browser checks. [Issue #14](https://github.com/rubenwoudsma/bulk-password-reset/issues/14) tracks those checks, the core-upgrade scenario, development-note triage and the later compatibility declaration. This CI change does not change `Tested up to`, publish a version or enable SVN uploads.

## GitHub Actions results overview

Open a **Quality and security** run in GitHub Actions and select **Summary**. The **CI results overview** job publishes one overview after the other jobs finish, including failed runs where reporting can still execute. It lists the six test environments, installed WordPress/PHP versions, passed/failed/error/skipped test executions, assertions, coding standards, PHP compatibility, dependency audit, release metadata, and official Plugin Check result. Totals count executions across the matrix, rather than unique tests.

Download **ci-report** from the run's **Artifacts** section for `overview.md`, `overview.json`, the per-environment PHPUnit JUnit XML, Composer audit JSON, and available Plugin Check output. The overview links to the installable **plugin-package** artifact and records its ZIP checksum. Artifacts require GitHub sign-in and expire after 14 days. A generated package does not imply that checks passed or that WordPress.org approved or published it.

The stable and pre-release overviews also show verified debug capture and unreviewed log-line counts for each environment. Each integration artifact includes `wordpress-debug.log` and `wordpress-debug.json` when the bootstrap ran; early setup failures retain whatever evidence is available and cannot be reported as a clean log. These files use synthetic CI data, not live-site logs.

Reporting uses Python 3's standard library, available on the Ubuntu CI runner, with no Python packages to install. Run its regression checks locally with `python3 -m unittest discover -s tests/reporting -v`. The collector rejects missing, duplicate, or foreign-run reports and distinguishes skipped checks and unavailable test results from passes. It also checks GitHub's job outcomes independently of the report data and fails the overview job when evidence is incomplete or checks failed. Original check failures remain failures; no `continue-on-error` is used. A cancelled run, unavailable runner, or checkout failure can prevent a report from being published; the run and job logs remain authoritative.

## Manual acceptance checks

On staging, test the complete admin forms and Users bulk action, mail delivery to a controlled address, the recipient's reset link, and browser-session revocation. Include a custom role, an account with multiple roles, and a multisite network if applicable. Screen-reader labels and native WordPress controls are used; a formal accessibility audit has not been performed.

The recipient count is a preview of candidates. Role membership is evaluated on each page, with an upper account-ID bound captured at preview. Previously existing users can enter or leave selected roles; users created later are excluded. This avoids loading all matching account IDs into memory.

## Interrupted jobs and locks

Progress is checkpointed before each account. If a request is interrupted after that checkpoint, the account's outcome is uncertain and it is not replayed. The next batch records its ID for review. The latest job retains up to 100 failed IDs and 100 uncertain IDs; aggregate counts continue without that reporting bound.

A unique database option serializes mutation requests. Normal completion, exceptions, and ordinary PHP fatal shutdown release the lock. A forcibly killed PHP process can leave a lock. **Only after verifying that no reset request remains running**, an administrator can clear that site's lock using:

```sh
wp option delete bpr_job_lock --url=https://the-affected-site.example
```

The plugin never automatically steals a lock from a possibly active sender. Completed resets cannot be rolled back. A mail failure after immediate invalidation leaves the password changed; use the account's normal WordPress recovery flow after fixing mail.

## Translations

Use consistent user-facing terms: **user** for a WordPress user (Dutch: gebruiker), **account** for credentials and access (gebruikersaccount), **recipient** for someone selected to receive a reset email (ontvanger), and **administrator** for the person operating the tool (beheerder). Preview counts include protected users, so label them as selected users rather than promising a recipient count. Keep `owner` as an internal job field; on screen, say the administrator who started the reset.

Preserve unchanged gettext strings and add translator context where necessary. Changed 2.0.1 phrases need corresponding translation updates on translate.wordpress.org; regenerating the POT does not approve or publish Dutch translations.

The text domain is `bulk-password-reset`. New translations can be managed through WordPress.org. To regenerate the source catalog, run `wp i18n make-pot . languages/bulk-password-reset.pot --domain=bulk-password-reset --exclude=vendor,tests,scripts,dist,build`. Legacy underscored-domain catalogs were removed because they describe a different interface.
