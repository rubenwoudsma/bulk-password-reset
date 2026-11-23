# Maintainer release setup

## First: review and reopening

As checked on 23 November 2026, WordPress.org lists Bulk Password Reset 1.3.3 as temporarily closed pending review. Wordfence lists CVE-2026-14873 as affecting versions through 1.3.3 and having no known patch. This working copy does not change those external records.

1. Review the security rebuild and test the ZIP on a staging site with real mail delivery.
2. Review the `modernize/security-and-maintenance` pull request in the existing GitHub repository. Code review and GitHub checks precede the separate WordPress.org release process; opening a pull request does not publish a plugin release.
3. Wait for the full GitHub CI matrix and Plugin Check and complete manual acceptance checks. For the first PR, preview the local plugin folder at [Plugin Page Preview](https://readme.joost.blog/). Automatic preview comments begin after the new workflow exists on master: GitHub runs `pull_request_target` workflows from the base repository's default branch. See [GitHub's event documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request_target).
4. Supply the reviewed changes and remediation explanation to the WordPress.org Plugins team through the existing closure/review conversation. Coordinate with them before publication. Ask the vulnerability providers to review the eventual published fixed version after release; do not claim their approval in advance.
5. Merge the approved changes into `master`.

## Configure GitHub once

Create an environment named `wordpress-org`. Add required maintainer reviewers and restrict deployment branches/tags appropriately for your repository plan.

Add these **environment secrets**:

* `SVN_USERNAME`: the WordPress.org account authorized to commit to this plugin.
* `SVN_PASSWORD`: that account's WordPress.org SVN password. Generate it using WordPress.org's current account security process; never put it in the repository.

Set the **environment variable** `WPORG_DEPLOY_ENABLED` to `true` only when the Plugins team permits publication. Leaving it unset blocks publication. GitHub will create a missing named environment automatically if referenced, so configure its protections explicitly rather than assuming the workflow enforces them by itself.

Actions are enabled. On 23 November 2026, master branch protection was configured to require a pull request, all eight quality/integration/Plugin Check jobs from the GitHub Actions app, an up-to-date branch, and resolved review conversations. The rule applies to administrators and blocks force pushes and deletion. It requires zero approving reviews so the sole maintainer can merge a passing PR without a second reviewer. If CI job names change, update the required checks to match. These are live repository settings, separate from workflow files.

Enable Dependabot updates and private vulnerability reporting for the repository's Security tab as appropriate.

The workflow source currently uses `master`, matching the existing repository. Update branch references and this document if the default branch changes.

## Publish a code release

1. Update the plugin header, `BULK_PASSWORD_RESET_VERSION`, `Stable tag`, and first changelog entry to the same numeric version.
2. Change `Tested up to` only after actually testing that WordPress series.
3. Run the local checks and integration tests; merge through a green pull request.
4. Create and push a numeric Git tag from the reviewed master commit:

```sh
git tag -a 2.0.0 -m "Bulk Password Reset 2.0.0"
git push origin 2.0.0
```

The release workflow reruns all CI checks on that tag. After checks pass and environment approvals are satisfied, it verifies metadata, checks that the commit belongs to master, refuses an existing SVN release tag, and deploys the generated runtime tree using 10up's action. Runtime files are built using the same allowlist as the installable ZIP. There are no Composer dependencies in the plugin ZIP.

Only one WordPress.org deployment or readme sync runs at a time. Releases are never automatically canceled midway through an SVN update. Listing assets come from `.wordpress-org`; development files and tests are excluded.

If a tag build fails, fix the cause and rerun only when the tag still represents the intended release. Never replace an already published WordPress.org tag; make a new patch release instead. This workflow publishes to WordPress.org, not a separate GitHub Release page; the tested ZIP is also retained as a GitHub Actions artifact.

## Readme-only updates

After a release already exists, merge the readme change to master. Start **Sync published readme** from Actions with its default dry run and review the diff. Run again with dry run disabled to publish.

The script updates only `trunk/readme.txt` and `tags/CURRENT-STABLE/readme.txt`. Both remote files must already name the same stable version as the local readme. It does not create tags, switch stable versions, alter code or upload assets. It skips empty commits. The first 2.0.0 readme sync will intentionally fail while WordPress.org still advertises the historical stable version; publish the reviewed code release first.

## Inspiration and source documentation

* [Joost de Valk: GitHub Actions to keep your WordPress plugin healthy](https://joost.blog/github-actions-wordpress/)
* [Joost de Valk: Preview and sync your plugin readme with GitHub Actions](https://joost.blog/plugin-readme-actions/)
* [Plugin Page Preview action](https://github.com/jdevalk/plugin-page-preview)
* [10up WordPress deployment action](https://github.com/10up/action-wordpress-plugin-deploy)
* [Official WordPress Plugin Check action](https://github.com/WordPress/plugin-check-action)
