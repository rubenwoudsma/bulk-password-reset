# Bulk Password Reset

A free WordPress plugin for bulk password resets by role or selected users. No premium edition, user cap, runtime Composer dependency, telemetry, or external account is required.

Download the [latest stable GitHub Release](https://github.com/rubenwoudsma/bulk-password-reset/releases/latest), including release notes, the installable ZIP and checksum, or install from [WordPress.org](https://wordpress.org/plugins/bulk-password-reset/). See the [security policy](SECURITY.md) for supported versions and the historical vulnerability boundary.

## What administrators can do

Open **Users → Bulk Password Reset**, select roles, choose a reset mode, and preview the candidates. Alternatively, select accounts on the Users screen and choose **Preview password reset**.

The responsive admin screen uses a three-step workflow, clear reset-mode cards, and a separate progress report. Ruben Woudsma's logo and author link appear only on this plugin's screen. The logo and styles are bundled locally; displaying the page makes no request to the author's website and adds no credits to public-facing pages or user emails.

Confirm the preview to begin. Each request handles up to 20 candidates. Use **Continue next batch** until the job finishes; there is no total user limit. Progress is saved, and the originating administrator can resume for 48 hours or cancel remaining work. No background worker or cron is required.

| Mode | Existing password | Browser sessions | Email |
| --- | --- | --- | --- |
| Send reset links (default) | Remains valid until the user resets it | Left intact by this plugin | WordPress single-use reset link |
| Invalidate immediately | Replaced with a unique unknown random password | Explicitly revoked | Link to choose a new password |

Immediate invalidation cannot be reversed, including if email fails. Application passwords and external sign-in providers are unaffected. Core or another plugin may apply additional policies when the recipient completes a reset.

The operator needs both `manage_options` and `edit_users`. Every target also requires `edit_user`. Own accounts, administrators, super administrators, and accounts with `manage_options` or `edit_users` are always protected, including accounts with multiple roles.

On multisite, only super administrators may operate the plugin. Selection is site-scoped; password and browser-session changes affect the shared account throughout the network. There is no network-wide recipient selector.

## Requirements and installation

WordPress 6.8+, PHP 8.2+, and working WordPress email. Upload the installable ZIP through Plugins, or replace `wp-content/plugins/bulk-password-reset`. The existing `bulk_password_reset.php` filename is retained to preserve the plugin's activation identity.

Before enabling immediate invalidation, test mail and both modes on a staging copy. The old `bpr_options` settings, including shared passwords and custom reset keys, are discarded on the next authorized admin initialization or activation. Legacy files in uploads or logs are not automatically deleted.

## Maintenance automation

| Workflow | Trigger | Purpose |
| --- | --- | --- |
| Quality and security | Pull requests, master pushes, weekly, manual, release prerequisite | PHP lint, WordPress coding standards, PHP compatibility, dependency audit, metadata consistency, package build, real WordPress integration tests, official Plugin Check |
| WordPress pre-release compatibility | Pull requests, master pushes, weekly, manual | Security/integration suite against an explicitly pinned WordPress RC, including PHP 8.6 and multisite, with its own results overview; independent of release publication |
| Dependency updates | Weekly through Dependabot | Composer and pinned GitHub Actions updates |
| Plugin readme preview | Public pull requests | Joost de Valk's listing preview and preflight comment; no PR checkout or code execution |
| Release plugin | Numeric `MAJOR.MINOR.PATCH` Git tag; manual preview | Reruns CI and verifies reviewed source. Tags publish a GitHub Release with changelog notes, the tested plugin ZIP and checksum. SVN uploads run only when enabled; manual runs preview both destinations. |
| Sync published readme | Manual, master only; dry run by default | Updates only the readme in trunk and the existing stable tag; refuses missing or mismatched stable releases |

All referenced actions are pinned to commit SHAs, with Dependabot tracking updates. General CI has read-only repository permissions and no deployment secrets. The readme-preview workflow has narrowly scoped comment permission and intentionally never checks out pull-request code. No Node build is needed for this PHP-only plugin.

The GitHub Release publishing job uses the built-in Actions token with repository-content write permission. SVN upload credentials and its enable setting are handled separately: a disabled SVN upload is skipped successfully. Only push a version tag when public GitHub publication is intended.

See [release setup](docs/RELEASING.md), [development and tests](CONTRIBUTING.md), [verification and reports](docs/VERIFICATION.md), and the [security review](docs/SECURITY-REVIEW.md).

## License

GPL-2.0-or-later. Original plugin by Ruben Woudsma; historical inspiration included Joost de Valk and Pressography. The WordPress.org banner illustrates the current reset-link workflow; its design brief is in `docs/banner-design.txt`. The plugin icon source is in `docs/icon-source.svg`; author-logo attribution is in `assets/README.md`. See `LICENSE` and the historical attribution in Git history.
