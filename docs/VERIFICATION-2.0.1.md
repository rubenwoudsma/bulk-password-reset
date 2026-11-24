# Verification of the 2.0.1 maintenance candidate

Verified locally on 24 November 2026 using disposable WordPress installations and captured test mail. No production site or recipient was accessed.

## Completion and batch regressions

The added regressions reproduced the 2.0.0 faults before the runtime changes: exact 20/40-user batches needed an empty continuation, completed results returned to step 1, and terminal statuses became Expired after the resume deadline. The corrected tests pass.

The suite now has 49 test executions per environment, including five boundary cases (1, 19, 20, 21 and 40 selected users), a look-ahead recipient whose password, reset key, browser session and mailbox must remain unchanged until Continue, and result navigation/retention tests. Existing authorization, CSRF, per-user policy, durable checkpoints, interruption, locking, multisite, single-use keys and 105-user resume tests remain covered.

| Local environment | Passed | Expected skips | Assertions |
| --- | ---: | ---: | ---: |
| WordPress 6.8 / PHP 8.2.36 / single site | 47 | 2 | 581 |
| WordPress 7.1.5 / PHP 8.4.28 / single site | 47 | 2 | 581 |
| WordPress 7.1.5 / PHP 8.4.28 / multisite | 49 | 0 | 588 |

Single-site skips are the two multisite-only policy tests. Local databases use the official SQLite integration; GitHub Actions repeats the suite on MySQL 8.0 and the supported PHP matrix.

## Browser acceptance

Seventeen Chromium checks passed against the built 2.0.1 candidate, including:

- Both bundled brand images in their new locations, and styles restricted to the plugin page.
- Preview and confirmation with no premature mail or password change.
- A small reset remaining on step 3, with the chooser opened explicitly and previous results retained until a new preview.
- WordPress recipient recovery and actual login with the chosen password in both reset modes; link mode preserves the old password until recovery, and immediate mode revokes the existing editor session.
- The native Users bulk preview and cancellation.
- Mobile/tablet layouts at 390 and 768 pixels without horizontal overflow.
- A 40-recipient job completing in exactly two real form submissions, with no empty third continuation and no replay on reload.
- Five scoped WCAG 2.1 A/AA scans (desktop selection, preview, completion, running state and mobile selection) reporting zero violations.
- No automatic author-website requests or unexpected JavaScript errors. Chromium recorded the previously observed skipped WordPress admin view-transition warning during rapid navigation.

The directory screenshots and mobile image were refreshed from this installation. Automated accessibility scans supplement visual inspection and do not constitute a full audit.

## Packaging and translations

PHP syntax, WordPress Coding Standards, PHP compatibility rules, Composer validation, release metadata and ZIP generation passed locally. Reporting/release tests passed locally (34 passes and the expected Windows symlink skip); Linux CI runs all 35.

The plugin icon is included in the runtime ZIP and the release verifier's expected file list. The metadata consistently names 2.0.1. The POT was regenerated, and the contributor username remains `rubenw`. Unchanged strings retain their identifiers; changed phrases require corresponding updates to community translations. No Dutch translation was replaced, approved or published by this work.

## Publication controls

This is a pull-request candidate. No Git/SVN tag or GitHub Release is created, no existing release asset is replaced, and `WPORG_DEPLOY_ENABLED` remains false. WordPress.org review of the uploaded 2.0.0 version remains separate. Official Plugin Check and the complete hosted MySQL matrix are verified through this PR's Actions checks before handoff.
