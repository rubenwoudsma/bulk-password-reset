# Verification of the 2.0.0 candidate

Verified locally on 23 November 2026. All environments were disposable installations. No production users were changed and no email was sent to real recipients.

## Real WordPress integration tests

| Environment | Result | Assertions |
| --- | --- | --- |
| WordPress 6.8 / PHP 8.2.36 / single site | 37 passed, 2 multisite-only tests skipped | 367 |
| WordPress 7.1.5 / PHP 8.4.28 / single site | 37 passed, 2 multisite-only tests skipped | 367 |
| WordPress 7.1.5 / PHP 8.4.28 / multisite | All 39 passed | 374 |

These runs used PHPUnit 11.5.58 and the official SQLite Database Integration plugin 3.1.3. They exercised the actual WordPress user, capability, option, session, mail-filter and password-reset functions. WordPress functions were not replaced with test doubles. Mail was intercepted through `pre_wp_mail` and retained only in the disposable test environment.

The GitHub matrix repeats tests with MySQL 8.0 and PHP 8.2–8.5. Hosted results, including the additional PHP 8.3/8.5 combinations, are available in [pull request #1's checks](https://github.com/rubenwoudsma/bulk-password-reset/pull/1/checks). The local SQLite runs complement that separate MySQL matrix.

Coverage includes the source-confirmed missing-authorization path, lower-privilege denial, per-target edit capabilities, multi-role privileged accounts, reset policy denials/errors, confirmation, nonces, POST requirements, job ownership, expiry, concurrency guards, cancellation, reset-key hashing/single use/expiry, both reset modes, session destruction, failed email, failed checkpoints, uncertain outcomes, custom roles, role changes, and a 105-account batch job without duplicate email.

## Browser acceptance

Chromium 151.0.7922.34 exercised WordPress 7.1.5/PHP 8.4.28 through the actual HTTP forms:

1. Administrator login and selection-screen rendering.
2. Three-account role preview with no outgoing email or reset.
3. Rejection of an unconfirmed start.
4. Confirmed reset-link job, finishing with exactly three captured messages.
5. Recipient opening an emailed link and completing the core WordPress password-change form.
6. Immediate invalidation ending an editor's already-open browser session.
7. Native Users-table bulk action opening a one-account preview without performing a reset, followed by cancellation.
8. A 390-pixel mobile layout with no horizontal overflow.
9. Local CSS and logo loading on the plugin screen, with no plugin stylesheet loaded on the main Dashboard.
10. A 768-pixel tablet layout with no horizontal overflow.
11. No automatic requests to the author's website while rendering or using the page.
12. Axe-core 4.14.1 WCAG 2.1 A/AA scans of desktop selection, desktop preview, and mobile selection, with zero reported violations.

All twelve checks passed. There were no unexpected JavaScript errors. Chromium reported one skipped WordPress admin view transition during rapid automated navigation; that browser warning was recorded separately. The plugin ships no JavaScript. Automated accessibility scans supplement manual review and do not constitute a full accessibility audit.

Browser testing found and corrected a real integration issue: WordPress strips the Users form's GET nonce before invoking the default bulk-action filter. The replacement captures the preview earlier with capability and nonce enforcement intact, and still requires a separate confirmed POST before executing resets.

The two WordPress.org screenshots in `.wordpress-org` and `docs/mobile-admin.png` came from these real screens. They contain disposable demonstration accounts.

## Other checks

* All first-party PHP files passed syntax checks.
* WordPress Coding Standards 3.4.1 passed for every shipped PHP file, with only the documented historical filename exception configured.
* PHPCompatibilityWP checks passed for PHP 8.2 and later according to that tool's available rules.
* Official Plugin Check 2.2.0 passed against the plugin extracted from the installable ZIP, without reported findings.
* `composer validate --strict` passed; the online locked-dependency audit returned no advisories or abandoned packages.
* Release metadata passed, including matching PHP/readme requirements, version, version constant, stable tag, and first changelog entry.
* A deliberately mismatched release tag was correctly rejected.
* Actionlint 1.7.12 validated all four workflows. The readme-sync script passed Bash syntax validation.
* Git's whitespace check passed for tracked changes.
* The runtime ZIP contains only six runtime PHP files, the local stylesheet and author logo, readme, license, and the generated translation catalog. Development dependencies, tests, workflows, screenshots, and source documentation are excluded from the installation package.

## Outstanding external validation and release steps

Review the GitHub MySQL/PHP matrix and Plugin Check results in the pull request. Confirm delivery through a real staging mail provider and review translations as needed. The readme-preview PR comment, WordPress.org deployment, remote readme sync, Plugins-team reopening, and advisory-provider acceptance have not been performed. No repository secrets or live-site credentials were requested or configured; the push and pull request use the maintainer's existing local GitHub sign-in.

See `RELEASING.md` for the one-time repository/environment setup and controlled publication procedure. This is a tested staging candidate; it is not a claim that the historical public advisory has been closed.
