# Contributing to WordPress Importer

Thanks for your interest in contributing to the **WordPress Importer** plugin.
This guide covers the essentials for new contributors, especially around testing, CI, and releasing.

## Development Setup

* Minimum supported PHP version: **7.2**.
* Use Composer for dev dependencies:

  ```bash
  composer install --dev
  npm install
  ```

## Testing

The project has two main test layers:

### Unit Tests

The setup is tricky and not well documented. It requires a specific PHPUnit version and the right
local WordPress setup. Until that documentation is in place, rely on the PHPUnit checks reported by
GitHub CI.

### End-to-End (E2E) Tests

* Implemented using **Playwright**.
* These simulate real imports of WXR files into a temporary WordPress instance.
* Run locally:

```bash
composer e2e
```

## Common gotchas

### Raising the minimum required version of PHP

In most cases, the plugin should aim to support the same versions of PHP as the current version of WordPress. However, there may be some scenarios where continuing to support an older version may be appropriate. Before raising the minimum version of PHP in the plugin, create an issue or pull request to start a discussion around the change. https://github.com/WordPress/wordpress-importer/pull/196 is an example of such a pull request.

#### Related required changes in WordPress Core

In the past, the [wordpress-develop repository](https://github.com/wordpress/wordpress-develop) has included the unit tests for this plugin. Running the PHPUnit test suite required this repository to be cloned into the `tests/phpunit/data/plugins` directory.

This practice was discontinued during the WP 6.8 release cycle in favor of strictly running the Importer plugin's unit tests within this repository.

- [The pull request that synced test changes from WordPress/wordpress-develop to this repository before removing the tests from WP core itself (#181)](https://github.com/WordPress/wordpress-importer/pull/181)
- [Related WordPress/wordpress-develop changeset (r59769)](https://core.trac.wordpress.org/changeset/59769)
- [Related Core Trac ticket (42668)](https://core.trac.wordpress.org/ticket/42668)

Though more recent branches of WordPress do not clone this repository when running PHPUnit tests, old ones continue to do so. Bumping the minimum required version of PHP for the plugin in this repository will likely break the test workflows for those older branches that supported the version of PHP being dropped.

When updating the minimum required version of PHP in this repository, please create a corresponding ticket on [Trac](https://core.trac.wordpress.org/) to coordinate updating these older branches around the same time. The change will need to be:

- Committed to the latest branch that supported the highest version of PHP being dropped first.
- [Backported to each affected branch](https://make.wordpress.org/core/handbook/best-practices/backporting-commits/) from that initial commit making any adjustments to the logic if necessary.

As an example, consider a scenario where the minimum required version of PHP for the plugin is raised from 7.2 to 7.4.
- The 6.7 branch of WordPress is the most recent one that supports PHP 7.3 and still runs the Importer unit tests as a part of the Core test suite. 5.0 is the oldest.
- The 4.9 branch is the oldest one supporting PHP 7.2 (which is also being dropped here).
- Prepare a commit similar to [Core r60748](https://core.trac.wordpress.org/changeset/60748) to pin a specific version of the plugin when running `env:install` on PHP <= 7.3.
- Commit the change to the 6.7 branch.
- Find the last time this change was made. In this case, [Core r60748](https://core.trac.wordpress.org/changeset/60748) was to the 6.5 branch.
- Create a new pull request that adjusts the logic in that branch to detect multiple version ranges because PHP <= 7.1 will still require the previously pinned version.
- Backport all the way to the 5.0 branch.
- Update the 4.9 branch to pin a different version for PHP 7.2 and PHP <= 7.1.

Other helpful links:
- [A Trac ticket to address a change in the plugin's support policy in Core](https://core.trac.wordpress.org/ticket/63983)
- [PHP Compatibilty and WordPress Versions](https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/)

### CI jobs seem stuck

Sometimes you may submit a PR and notice the required checks are stuck:

<img width="830" height="350" alt="475983719-91a009b7-11fc-451c-9f03-58db7a3c0c1d" src="https://github.com/user-attachments/assets/ba07ab06-7304-4772-95e5-d73453b0e84c" />

This is because some workflows are scheduled to run both on PRs and on the main repository branch as a cron job. GitHub deactivates
cronjobs for repositories without recent activity. To get those checks going again, a repository maintainer must re-enable the workflow
in GitHub UI:

<img width="833" height="209" alt="475984763-ab8ea42d-0b01-47ae-981c-5f0f1bb00ff5" src="https://github.com/user-attachments/assets/aa587be3-8339-4491-9da5-6f48eb147259" />

## Releasing

A new version of the plugin is automatically submitted to the WordPress.org plugin directory
whenever you **push a new tag**. Once that happens, one of WordPress.org admins must approve
the plugin release in the plugin directory.

Here's a release checklist to go through on every release:

* Before the release
  * Bump the version numbers in:
      * `wordpress-importer.php`
      * `src/wordpress-importer.php`
      * `src/readme.txt`
  * Confirm the metadata in `src/wordpress-importer.php` is still up to date
     * `Requires at least:`
     * `Requires PHP:`
  * Update the changelog in `src/readme.txt`
* Create a new tag, push it to the repo
* Create a new release on GitHub
