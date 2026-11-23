# Security and modernization review

Prepared 23 November 2026. This is an engineering review and tested remediation candidate, not an independent penetration-test certificate or an advisory-provider approval.

## Baselines examined

* GitHub master at `e98356c620d2bf256c42392de23b44a876663c68`: header reports `1.1.0.` and readme contains WordPress 2.8-era requirements.
* WordPress.org SVN `tags/1.3.3/bulk_password_reset.php`: includes the later Users bulk-action implementation that does not exist in the GitHub baseline.
* [Wordfence advisory](https://www.wordfence.com/threat-intel/vulnerabilities/wordpress-plugins/bulk-password-reset/bulk-password-reset-133-authenticated-subscriber-arbitrary-password-reset), [GHSA-c66v-c2xg-68hr](https://github.com/advisories/GHSA-c66v-c2xg-68hr), and the current [WordPress.org listing](https://wordpress.org/plugins/bulk-password-reset/).

The published advisory describes account takeover through unauthorized password resets. The 1.3.3 source confirms a direct password-reset path without authorization/nonce checks. Its advisory wording also mentions email-address changes; this review did not independently reproduce that separate assertion. The replacement never changes account email addresses.

## Findings and resulting behavior

| Finding in legacy sources | Replacement |
| --- | --- |
| `load-edit.php` and `load-users.php` reach `bpr_cust_bulk_action()`, which accepts request user IDs and calls `reset_password()` without capability or nonce enforcement | Legacy handler and action are removed. The replacement Users-screen handler requires authorization and a core nonce and only prepares a preview. It runs before WordPress's redirect cleanup strips GET nonces; a native bulk-action filter is also provided. Actual reset work uses authenticated POST handlers, job-specific nonces, and explicit confirmation. |
| Password-changing helper has no independent permission check | Operator access and per-target `edit_user` checks are repeated inside the reset service. Protected accounts are rejected even if selected or given an ordinary second role. |
| Role values interpolated into custom SQL | Role slugs must exist in WordPress. `WP_User_Query` performs role selection. The numeric cursor bounds use `$wpdb->prepare()` and a temporary hook scoped to one query instance. |
| Configurable shared plaintext passwords and fixed reset keys | Removed. Link keys come from `get_password_reset_key()`. Invalidation uses an independent 64-character random password per account; it is never returned, logged or emailed. |
| Copied historical reset-key logic writes keys directly to the users table | Core generates and stores the key; WordPress's own reset screen controls hashing, expiration and single-use consumption. |
| Unescaped option output and unchecked reset-defaults form | Old settings UI is removed. All displayed dynamic values are escaped, administrator notes are plain text, and mutation forms require nonces and POST. |
| Mail failure terminates execution or only the last result is reported | Each account contributes a result. Immediate invalidation followed by mail failure is explicitly reported as a failure with an invalidation count. Success means accepted for sending, not delivered. |
| Unbounded synchronous resets and replay hazards | Work is bounded to 20 candidates per request, serialized through an atomic option lock, and checkpointed before side effects. Interrupted accounts are not replayed automatically. |
| Global upload-directory setup, obsolete UI assets, PHP 4 constructor and deprecated user APIs | Removed. Native admin controls, scoped hooks, modern metadata, coherent text domain, explicit GPL license, and uninstall cleanup replace the old scaffolding. |

## Deliberate behavior changes

Privileged accounts and the initiating administrator are always excluded. There is no option to set a shared password, configure a reset key, email a password, or silently disable notifications. Immediate invalidation remains available as a clearly labeled opt-in mode.

Multisite requires a super administrator and site membership for targets. This intentionally imposes a stricter boundary than ordinary single-site access because WordPress stores passwords at network level.

Jobs are owned by an administrator and site, expire after 48 hours, and require fresh authorization to resume. One job can be active per site. The job option is not autoloaded. It contains role/user selection, a plain-text note, progress, aggregate counts, and bounded failed/interrupted account IDs. It contains no reset links or plaintext credentials. Only the latest report is retained, until replaced or uninstalled; expiration stops execution but does not itself erase the report.

## Limits that require operational understanding

* Sending a link does not force a password change. Immediate invalidation does.
* Invalidation changes the password before generating the new key and sending mail. Mail or key-generation failure after this point cannot undo it. A changed reset policy between preflight and key generation can also produce this partial result.
* Browser sessions are explicitly destroyed in immediate mode. WordPress application passwords, API credentials managed by other plugins, and external identity-provider sessions are not revoked.
* A new reset request invalidates an earlier reset link according to core behavior. Email delivery cannot be guaranteed by `wp_mail()`.
* Role selection is a live query bounded to account IDs existing at preview. It is not a frozen roster. Protected-account and policy checks are repeated during processing.
* A process killed without PHP shutdown can leave a lock. The plugin refuses to steal it automatically; see the documented recovery procedure. No exactly-once claim is made across database or process failures: ambiguous outcomes are reported for review and not retried automatically.
* Locks serialize this plugin's jobs on a site. Another site on a multisite network, another plugin, or a concurrent WordPress recovery request can independently affect a shared account.
* Existing public log files, backups containing historical settings, already compromised accounts, and leaked old shared passwords are outside this code update's automatic cleanup. Review those during incident response.

## Release status

The candidate replaces the source-confirmed vulnerable path and has security regression coverage. Local verification and the link to GitHub Actions results are recorded in `docs/VERIFICATION.md`. Production mail delivery, Plugins-team reopening, and advisory-provider acceptance remain separate release steps. Do not relabel the advisory as fixed solely because automated tests pass.
