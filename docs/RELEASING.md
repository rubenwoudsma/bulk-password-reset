# Maintainer release setup

## Current candidate — 25 November 2026

Version 2.0.0 was uploaded to the existing SVN repository in revision r3818946; contributor-only revision r3820830 corrected the readme username to `rubenw`. The Plugins team has confirmed re-listing, and the ordinary WordPress.org update offer from the authentic 1.3.3 metadata to 2.0.0 was independently verified on 25 November 2026. The offered ZIP matched all 11 reviewed release files. GitHub advisory PR [#11982](https://github.com/github/advisory-database/pull/11982) and the [reply to its curators](https://github.com/github/advisory-database/pull/11982#issuecomment-7156866989) remain separate from an accepted advisory correction.

Version 2.0.1 remains an **unpublished maintenance candidate**. Its completion-flow fixes, listing copy and directory artwork can be reviewed together without another version bump. Directory assets are not tied to a code version, but there is no need to amend the published 2.0.0 release for this work. Preparing or merging this PR does not authorize publication. Keep `WPORG_DEPLOY_ENABLED=false` until a specific subsequent upload is explicitly authorized; do not push a 2.0.1 tag or publish a GitHub Release yet.

## Review, reopening and advisory follow-up

The original closure notice was received and reviewed. The 2.0.0 security rebuild passed acceptance and CI, was submitted via SVN, and the maintainer replied in the original closure thread. The Plugins team subsequently accepted the reported remediation and re-listed the plugin. A successful update offer is confirmed; it does not establish that every existing site has completed its upgrade. Sites still require WordPress 6.8 and PHP 8.2 or later.

Track remaining Wordfence/CVE and GitHub curator decisions in [issue #6](https://github.com/rubenwoudsma/bulk-password-reset/issues/6). Preserve the reported affected range through 1.3.3 and first patched release 2.0.0. Submitted evidence or a posted comment is not provider acceptance, and the unpublished 2.0.1 candidate is not the first patched version.

## Configure GitHub once

Create an environment named `wordpress-org`. Add required maintainer reviewers and restrict deployment branches/tags appropriately for your repository plan.

Add these **environment secrets**:

* `SVN_USERNAME`: the WordPress.org account authorized to commit to this plugin.
* `SVN_PASSWORD`: that account's WordPress.org SVN password. Generate it using WordPress.org's current account security process; never put it in the repository.

The **environment variable** `WPORG_DEPLOY_ENABLED` controls actual SVN writes. Keep it `false` for preparation and while the maintainer's publication hold is in effect. Enable it only when a specific reviewed upload is authorized. Leaving it unset also disables actual uploads. A tagged run skips SVN cleanly while the gate is false; its GitHub Release can still publish. Manual previews never publish a GitHub Release or commit to SVN, including when this variable is true. GitHub will create a missing named environment automatically if referenced, so configure its protections explicitly rather than assuming the workflow enforces them by itself.

Actions are enabled. On 23 November 2026, master branch protection was configured to require a pull request, all eight quality/integration/Plugin Check jobs from the GitHub Actions app, an up-to-date branch, and resolved review conversations. The rule applies to administrators and blocks force pushes and deletion. It requires zero approving reviews so the sole maintainer can merge a passing PR without a second reviewer. If CI job names change, update the required checks to match. These are live repository settings, separate from workflow files.

Enable Dependabot updates and private vulnerability reporting for the repository's Security tab as appropriate.

The workflow source currently uses `master`, matching the existing repository. Update branch references and this document if the default branch changes.

## Automatic GitHub Releases on version tags

Pushing a numeric Git tag such as `2.0.0` starts **Release plugin**. All nine quality/security jobs must pass. The tag must match the plugin metadata, and its tested commit must belong to master. A preparation job downloads the exact `plugin-package` ZIP produced by CI, verifies every runtime file against the tagged source, and prepares release notes from that version's first `readme.txt` changelog entry.

The tag-only GitHub publishing job uses the automatically supplied `GITHUB_TOKEN` with `contents: write`; all other jobs keep read-only repository permissions. No personal access token or additional secret is needed, and the GitHub publishing job has no access to the SVN environment secrets. It checks that the remote tag still points to the tested commit, creates a draft, uploads **bulk-password-reset-VERSION.zip** and **SHA256SUMS.txt**, downloads and verifies both assets, and then publishes the release. An interrupted upload remains a draft. Reruns can resume drafts created by this workflow for the same commit. An existing published release must match the workflow's recorded commit and runtime; it is verified and left unchanged, even if a rebuilt ZIP has different archive timestamps. Unrelated drafts or mismatched published releases require manual review rather than being overwritten.

GitHub Release publishing and SVN uploading are separate jobs. `WPORG_DEPLOY_ENABLED=false` skips the SVN job successfully and does not block GitHub publication. A GitHub Release is public distribution on GitHub; it does not imply WordPress.org approval, reopening, or an advisory-provider decision. Only create the release tag when GitHub publication is intended.

## Preview release notes, package and SVN changes

Open **Actions → Release plugin → Run workflow**, select `master`, and enter the candidate version (currently `2.0.1`). The manual trigger is deliberately preview-only: it cannot be switched to a real upload with an input. No Git release tag or GitHub Release is created.

The workflow reruns all nine CI jobs and prepares a **github-release-candidate** artifact containing the tested ZIP, release notes (`notes.md`), checksum and source/file metadata (`release.json`). The run's Summary links the preparation results. You can inspect this candidate before creating a real tag.

The separate SVN preview verifies matching version metadata, builds the runtime ZIP, refuses an already-existing SVN release tag, and uses 10up's pinned deployment action with `dry-run: true`. It checks out the existing plugin repository and stages the exact planned replacement of `trunk`, the new version tag (currently `tags/2.0.1`), and directory assets, without making an SVN commit. This works with `WPORG_DEPLOY_ENABLED=false` and uses the existing `wordpress-org` environment; no additional secrets are needed.

When testing a workflow/reporting change on a preparation branch, a manual preview can also select that branch. Before staging, it must match `origin/master` exactly for every runtime file, packaging/metadata script and directory asset. This allows reviewing workflow changes while keeping the upload candidate equal to reviewed master. Live tagged uploads always require the tagged commit to belong to master. GitHub requires the workflow file to exist on its default branch for manual dispatch; see [GitHub's manual-run documentation](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).

The run's Summary includes an SVN preparation report. Download **wordpress-org-svn-report** for the package, `plan.json`, `summary.md`, SVN status/revision XML, `changes.txt` and `changes.patch`. The report verifies that SVN trunk and the new tag contain exactly the ZIP's files and hashes, including removal of legacy files, and that directory assets match the reviewed source. Artifacts expire after 14 days. A failed preparation publishes available diagnostics and fails the job.

The action requires both saved secrets to be present, but its preview uses public SVN reads. A successful preview does **not** prove that the password is valid or that the account has commit access. Actual write authentication is exercised by the later SVN commit. Secret values are never included in the report.

Review the proposed additions, replacements and deletions, complete the original closure requirements and staging mail checks, and explicitly authorize the actual upload before enabling writes. The runtime package stays unchanged during preparation.

## Upload a code release to SVN

1. Update the plugin header, `BULK_PASSWORD_RESET_VERSION`, `Stable tag`, and first changelog entry to the same numeric version.
2. Change `Tested up to` only after actually testing that WordPress series.
3. Run the local checks and integration tests; merge through a green pull request.
4. Review a successful manual SVN preview, confirm the upload is authorized, and set `WPORG_DEPLOY_ENABLED=true` in the `wordpress-org` environment.
5. Create and push a numeric Git tag from the reviewed master commit:

```sh
git tag -a 2.0.1 -m "Bulk Password Reset 2.0.1"
git push origin 2.0.1
```

The release workflow reruns all nine CI checks on that tag and publishes the GitHub Release as described above. If the SVN gate is enabled and any configured environment approvals are satisfied, it also verifies metadata, checks that the commit belongs to master, refuses an existing SVN release tag, and deploys the generated runtime tree using 10up's action. Runtime files are built using the same allowlist as the installable ZIP. There are no Composer dependencies in the plugin ZIP. The resulting report checks that the working copy matches the package and has no uncommitted changes. Keep the commit revision from the deployment log for the closure-thread reply.

Only one WordPress.org preparation, deployment or readme sync runs at a time. Releases are never automatically canceled midway through an SVN update. Listing assets come from `.wordpress-org`; development files and tests are excluded. Uploading while the listing is closed does not itself approve or reopen the listing. For an open plugin, an SVN release can be distributed to users; treat the actual upload as publication. Return the write gate to `false` after the intended upload if further writes should be disabled.

If a GitHub Release was published while SVN was disabled, enable the gate only when the SVN upload is authorized, then rerun the existing tagged workflow. Do not move or recreate its tag. The GitHub job verifies the existing published assets without replacing them; the SVN job can perform the previously skipped upload. A manual run is always a preview and cannot perform that actual upload.

If a tag build fails, fix the cause and rerun only when the tag still represents the intended release. Never replace an already published WordPress.org tag; make a new patch release instead. Published GitHub Release assets remain available beyond the 14-day retention of Actions artifacts.

## Readme-only updates

After a release already exists, merge the readme change to master. Start **Sync published readme** from Actions with its default dry run and review the diff. Run again with dry run disabled to publish.

The script updates only `trunk/readme.txt` and `tags/CURRENT-STABLE/readme.txt`. Both remote files must already name the same stable version as the local readme. It does not create tags, switch stable versions, alter code or upload assets. It skips empty commits. Current master contains the unpublished 2.0.1 candidate while WordPress.org serves 2.0.0, so an ordinary master-based readme sync must reject that mismatch. Keep the listing and banner changes with the next authorized release instead of using this workflow to publish the candidate early.

## Inspiration and source documentation

* [Joost de Valk: GitHub Actions to keep your WordPress plugin healthy](https://joost.blog/github-actions-wordpress/)
* [Joost de Valk: Preview and sync your plugin readme with GitHub Actions](https://joost.blog/plugin-readme-actions/)
* [Plugin Page Preview action](https://github.com/jdevalk/plugin-page-preview)
* [10up WordPress deployment action](https://github.com/10up/action-wordpress-plugin-deploy)
* [Official WordPress Plugin Check action](https://github.com/WordPress/plugin-check-action)
