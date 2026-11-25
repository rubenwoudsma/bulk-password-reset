# Security policy

## Supported versions

Security fixes target the [latest published release](https://github.com/rubenwoudsma/bulk-password-reset/releases/latest). Upgrade older installations to that release; fixes are not backported to older versions.

| Version | Security maintenance |
| --- | --- |
| Latest published release | Supported |
| Earlier releases | Upgrade to the latest release |
| Historical 1.x releases | Unsupported; versions through 1.3.3 are affected by CVE-2026-14873 |

Version **2.0.0** is the first patched release for the reported CVE-2026-14873 / [GHSA-c66v-c2xg-68hr](https://github.com/advisories/GHSA-c66v-c2xg-68hr) issue. The published [2.0.1 maintenance release](https://github.com/rubenwoudsma/bulk-password-reset/releases/tag/2.0.1) retains that remediation and adds completion-flow and batch-boundary fixes. Do not change the first patched version to 2.0.1. The WordPress Plugins team accepted the reported remediation and re-listed the plugin; advisory-provider corrections remain a separate process tracked in [issue #6](https://github.com/rubenwoudsma/bulk-password-reset/issues/6).

## Reporting a vulnerability

Report newly discovered vulnerabilities privately using GitHub's **Security → Report a vulnerability** when enabled. If it is unavailable, use the maintainer contact route linked on the author's website and request a private channel. Do not post reset keys, credentials, personal account information, or exploit details in a public issue.

Include the plugin, WordPress and PHP versions, single-site/multisite configuration, required attacker permissions, expected and observed behavior, and a minimal reproduction on a disposable site.

## Security boundaries and verification

WordPress plugins run inside the same trusted PHP process. This plugin cannot protect a site from a malicious administrator or another plugin that deliberately overrides capabilities, mail routing, reset policies, or core password functions. Site owners remain responsible for HTTPS and email delivery.

See [the security review](docs/SECURITY-REVIEW.md) for the remediation boundary and known operational limitations, and [verification and reports](docs/VERIFICATION.md) for the recurring checks. Passing tests, re-listing or a prior review does not guarantee the absence of other security issues.
