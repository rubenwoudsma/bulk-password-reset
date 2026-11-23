=== Bulk Password Reset ===
Contributors: rubenwoudsma
Tags: password reset, users, security, bulk
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview and reset passwords in bulk by role or selected users, with secure reset links and optional immediate invalidation. Entirely free.

== Description ==

Bulk Password Reset helps site administrators ask groups of users to choose new passwords. Select roles under Users > Bulk Password Reset, or select individual users on the Users screen and choose Preview password reset.

Review a preview before anything is sent or changed. Every feature is free, with no premium edition, account registration, telemetry, or third-party service requirement.

= Two reset modes =

* Send reset links: each user receives a WordPress single-use password-reset link. Existing passwords and browser sessions continue working until the user changes their password.
* Invalidate immediately: replace each eligible user's password with a unique, unknown random value, end their browser sessions, and email a link to choose a new password. Passwords are never emailed.

Immediate invalidation cannot be undone. If email fails, the password still changes. Test outgoing mail before using this mode. Application passwords and external identity-provider sessions are not revoked.

= Designed for careful administration =

* Role selection, including custom roles, or individually selected users.
* A preview and explicit confirmation before a reset starts.
* Batches of up to 20 candidates per request, with no total user limit or paid tier. Click Continue to run each next batch; no background worker or cron is required.
* Resume a job for 48 hours. One active job per site; only its originating administrator can run or cancel it.
* Optional plain-text email note and user-locale support.
* A light, responsive admin layout with clear steps, reset-mode cards, and progress reports.
* WordPress reset-key generation, hashing, expiration and single-use validation.
* Per-request operator permissions and per-account edit checks.
* Separate counts for emails accepted for sending, failures, protected accounts, interrupted outcomes and password invalidations.

Your own account, administrators, super administrators, and accounts that can manage settings or edit users are always excluded, even if they also hold a selected role. Use WordPress's individual recovery flow for these accounts.

Role-based selections are evaluated again during processing. Accounts registered after the preview are excluded; existing accounts whose roles change may enter or leave the selection. The preview count is a candidate count, not a promise of successful resets.

= Multisite =

Only super administrators can use this tool on multisite. Selection is limited to members of the current site. Passwords and browser sessions are shared across the network, so invalidation affects those accounts on every site. This is not a network-wide selection screen.

= Privacy =

WordPress sends mail to the account's existing email address through your site's mail configuration. The plugin stores one site's latest job, its administrator ID, selected roles or user IDs, optional note, progress and aggregate counts in a non-autoloaded database option. It does not store plaintext passwords, reset links, or reset keys in job data or public log files. WordPress stores the hashed reset key in its own user record.

Jobs expire after 48 hours and cannot then be resumed. The report also retains up to 100 failed and 100 interrupted account IDs for review. Expiration does not automatically erase the latest report; it is replaced by the next preview or deleted on uninstall. Existing log files from older plugin versions are not automatically removed; administrators should inspect and remove them. No data is sent to the plugin author.

== Installation ==

1. Back up your site and verify outgoing email on a staging site.
2. Install the plugin ZIP using Plugins > Add New Plugin > Upload Plugin, or replace the existing bulk-password-reset directory.
3. Activate the plugin and open Users > Bulk Password Reset.
4. Select roles and a reset mode, add an optional note, and review the preview.
5. Confirm and start. Use Continue for each remaining batch, or cancel remaining work.

Requires WordPress 6.8 or newer and PHP 8.2 or newer.

== Screenshots ==

1. Select recipient roles, choose the reset mode, and add an optional email note.
2. Review candidate accounts and explicitly confirm before starting a reset.

== Frequently Asked Questions ==

= Is there a user limit or paid version? =

No. All features are free. The 20-candidate batch size bounds each request; it does not limit the total number of users in a job.

= Does sending a link force a password change? =

No. Link mode leaves existing passwords working. Use immediate invalidation when the old password must stop working at once.

= Can I choose one shared password for everybody? =

No. Shared passwords, configurable reset keys and plaintext password emails from older versions have been removed.

= What does a successful email count mean? =

It means WordPress accepted the email for sending, not that it arrived. Check your mail provider for delivery problems. A subsequent WordPress password-reset request can supersede an earlier link.

= What if a request stops halfway through? =

Progress is saved before each account is processed. An account with an uncertain outcome is not automatically retried. Review that account separately. Closing the page stops further batches; you can return within 48 hours.

= What changes when upgrading from 1.x? =

The old settings, including stored shared passwords and custom keys, are deleted when an authorized administrator next opens WordPress administration or activates the plugin. New jobs start in link mode. The legacy plugin entry-point filename is retained so existing installations remain activated. Old translation files and the old reset handlers are replaced.

== Changelog ==

= 2.0.0 =
* Rebuild the plugin's reset flow with authorization, per-user permissions, nonces, confirmation, and protected accounts.
* Replace the vulnerable legacy bulk-action path associated with CVE-2026-14873.
* Use secure WordPress reset links; remove shared passwords, custom reset keys, and plaintext password emails.
* Add resumable batches, previews, explicit immediate invalidation, session revocation, and honest failure reporting.
* Add automated security regression tests, WordPress coding standards, release checks, and GitHub Actions workflows.
* Introduce a responsive admin interface with locally bundled Ruben Woudsma branding and an author website link.

= 1.3.3 =
* Last historical WordPress.org release. Do not use for new installations.

== Upgrade Notice ==

= 2.0.0 =
Security rebuild replacing the vulnerable legacy reset path. Requires PHP 8.2 and WordPress 6.8. Old settings are removed; review both reset modes before use.
