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

### Bumping minimum PHP version

WordPress Core has a PHPUnit workflow that clones this repository. Bumping the minimum required PHP version
in this repository may break that workflow, see https://github.com/WordPress/wordpress-importer/pull/196.
Whenever you are updating the minimum PHP version, adjust the Core workflows accordingly, see
https://core.trac.wordpress.org/ticket/63983 as a reference of how to do that.

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
