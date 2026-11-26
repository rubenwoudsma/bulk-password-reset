=== Bulk Password Reset ===
Contributors: rubenw
Tags: password reset, reset password, users, user role, bulk
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk reset WordPress user passwords by role or selection. Preview first, send secure reset links, or invalidate passwords. Always free.

== Description ==

Bulk Password Reset lets WordPress administrators reset passwords for multiple users at once. Select one or more user roles, including custom roles, or choose individual users on the Users screen. Review the preview and confirm before any emails are sent or passwords are changed.

Send secure WordPress password-reset links so users can choose their own passwords. When existing passwords must stop working immediately, choose immediate invalidation to replace eligible users' passwords, end their browser sessions and email reset links.

Every feature is free, with no paid edition or total user limit. Larger resets run in batches of up to 20 users per request; you choose when to continue. Your own account and privileged accounts are always protected. No account registration, telemetry or third-party service is required.

= Two reset modes =

* Send reset links: each user receives a WordPress single-use password-reset link. Existing passwords and browser sessions continue working until the user changes their password.
* Invalidate immediately: replace each eligible user's password with a unique, unknown random value, end their browser sessions, and email a link to choose a new password. Passwords are never emailed.

Immediate invalidation cannot be undone. If email fails, the password still changes. Test outgoing mail before using this mode. Application passwords and external identity-provider sessions are not revoked.

= Designed for careful administration =

* Role selection, including custom roles, or individually selected users.
* A preview and explicit confirmation before a reset starts.
* Batches of up to 20 selected users per request, with no total user limit or paid tier. Click Continue to run each next batch; no background worker or cron is required.
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

An unfinished reset expires after 48 hours and cannot then be resumed. The latest results retain up to 100 user IDs with failed resets and 100 with unknown outcomes for review. Completed and cancelled results keep their status after the resume deadline. Results are replaced by the next preview or deleted on uninstall; opening the chooser does not erase them. Existing log files from older plugin versions are not automatically removed; administrators should inspect and remove them. No data is sent to the plugin author.

== Installation ==

1. Back up your site and verify outgoing email on a staging site.
2. Install the plugin ZIP using Plugins > Add New Plugin > Upload Plugin, or replace the existing bulk-password-reset directory.
3. Activate the plugin and open Users > Bulk Password Reset.
4. Select roles and a reset mode, add an optional note, and review the preview.
5. Confirm and start. Use Continue for each remaining batch, or cancel remaining work.
6. Review the results on step 3. Choose Start a new reset to return to the chooser; the previous results remain available until you create another preview.

Requires WordPress 6.8 or newer and PHP 8.2 or newer.

== Screenshots ==

1. Select recipient roles, choose the reset mode, and add an optional email note.
2. Review candidate accounts and explicitly confirm before starting a reset.
3. Review the completed reset on step 3, then choose Start a new reset when ready.

== Frequently Asked Questions ==

= Is there a user limit or paid version? =

No. All features are free. The 20-candidate batch size bounds each request; it does not limit the total number of users in a job.

= Can I perform a mass password reset by user role? =

Yes. Select one or more roles, including custom roles, under Users > Bulk Password Reset, or select individual users on the Users screen and choose Preview password reset. Review the preview, choose a reset mode and confirm before processing. Your own account, administrators and other privileged accounts are excluded.

= Can I force users to choose a new password? =

Sending reset links leaves existing passwords working. Choose immediate invalidation when eligible users' existing passwords and browser sessions must stop working immediately. The plugin emails a link so each user can choose a new password. Verify outgoing mail first: invalidation still changes a password if sending the email fails. Application passwords and external identity-provider sessions are not revoked.

= Can I choose one shared password for everybody? =

No. Shared passwords, configurable reset keys and plaintext password emails from older versions have been removed.

= What does a successful email count mean? =

It means WordPress accepted the email for sending, not that it arrived. Check your mail provider for delivery problems. A subsequent WordPress password-reset request can supersede an earlier link.

= What if a request stops halfway through? =

Progress is saved before each account is processed. An account with an uncertain outcome is not automatically retried. Review that account separately. Closing the page stops further batches; you can return within 48 hours.

= What changes when upgrading from 1.x? =

The old settings, including stored shared passwords and custom keys, are deleted when an authorized administrator next opens WordPress administration or activates the plugin. New jobs start in link mode. The legacy plugin entry-point filename is retained so existing installations remain activated. Old translation files and the old reset handlers are replaced.

== Changelog ==

= 2.0.2 =
* Preserve usernames ending in a period when reset links are made clickable, in both reset modes.
* Enable and verify WordPress debug logging in CI, retain logs and fail reports on missing or unreviewed diagnostics.

= 2.0.1 =
* Keep completed, cancelled and expired reset results on step 3, with an explicit Start a new reset action and a link back to the previous results.
* Finish exact 20-user batch multiples without an empty Continue request, using bounded look-ahead without changing the next recipient.
* Use the plugin icon in the header and move the Ruben Woudsma logo into the Made by card; both images are bundled locally.
* Clarify selected users, recipients, accounts and administrator terminology, and refresh the translation template.
* Keep completed and cancelled statuses after the 48-hour resume deadline.
* Clarify the directory description, role-based reset FAQs and free feature set, and refresh the listing banner with a visual reset-link workflow.

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

= 2.0.2 =
Fixes automatically linked reset URLs for usernames ending in a period. No settings or saved reset jobs need to be migrated.

= 2.0.1 =
Improves completion screens, batch boundaries, branding and wording. No reset settings or stored job data need to be migrated.

= 2.0.0 =
Security rebuild replacing the vulnerable legacy reset path. Requires PHP 8.2 and WordPress 6.8. Old settings are removed; review both reset modes before use.
