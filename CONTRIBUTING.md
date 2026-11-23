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

WordPress Coding Standards applies to all shipped PHP. A single filename exception preserves the historical `bulk_password_reset.php` activation basename. Tests and build scripts use ordinary PHP style and are syntax-checked separately. PHPCompatibilityWP supplements syntax tests; it is not proof of compatibility with every future PHP version.

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

CI installs WordPress with MySQL 8.0. The matrix covers minimum WordPress 6.8/PHP 8.2, latest WordPress on PHP 8.2–8.5, and latest WordPress multisite on PHP 8.4. The local verification report records what actually ran locally; a workflow definition alone is not a completed GitHub run.

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

The text domain is `bulk-password-reset`. New translations can be managed through WordPress.org. To regenerate the source catalog, run `wp i18n make-pot . languages/bulk-password-reset.pot --domain=bulk-password-reset --exclude=vendor,tests,scripts,dist,build`. Legacy underscored-domain catalogs were removed because they describe a different interface.
