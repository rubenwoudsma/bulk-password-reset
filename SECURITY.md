# Security policy

Treat historical versions through 1.3.3 as affected by the published CVE-2026-14873 advisory. This repository contains a 2.0.0 remediation candidate; external advisory and WordPress.org status only change after their respective review processes.

Report newly discovered vulnerabilities privately using GitHub's **Security → Report a vulnerability** when enabled. If it is unavailable, use the maintainer contact route linked on the author's website and request a private channel. Do not post reset keys, credentials, personal account information, or exploit details in a public issue.

Include the plugin, WordPress and PHP versions, single-site/multisite configuration, required attacker permissions, expected and observed behavior, and a minimal reproduction on a disposable site.

WordPress plugins run inside the same trusted PHP process. This plugin cannot protect a site from a malicious administrator or another plugin that deliberately overrides capabilities, mail routing, reset policies, or core password functions. Site owners remain responsible for HTTPS and email delivery.

See [the security review](docs/SECURITY-REVIEW.md) for the remediation boundary and known operational limitations.
