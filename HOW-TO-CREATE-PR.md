# How to Create a Pull Request for WordPress Importer

## Current Situation

You have a local clone of the WordPress Importer repository with:
- HEAD detached at tag 0.9.2
- Bug fix implemented in `src/class-wp-import.php`
- Comprehensive tests and documentation ready
- Remote pointing to the official WordPress/wordpress-importer repository

## Steps to Create Your Pull Request

### 1. Fork the Repository on GitHub

1. Go to https://github.com/WordPress/wordpress-importer
2. Click the "Fork" button (top right) to create your own copy
3. This creates: `https://github.com/YOUR_USERNAME/wordpress-importer`

### 2. Add Your Fork as a Remote

```bash
# Add your fork as a remote (replace YOUR_USERNAME with your GitHub username)
git remote add myfork https://github.com/YOUR_USERNAME/wordpress-importer.git
```

### 3. Create a Feature Branch

```bash
# First, get on master branch
git checkout master

# Pull latest changes from upstream
git pull origin master

# Create and checkout new branch for the fix
git checkout -b fix/idempotency-url-remap-bug
```

### 4. Stage and Commit Your Changes

```bash
# Add all your new files and modifications
git add src/class-wp-import.php
git add IDEMPOTENCY-BUG.md
git add TEST-INSTRUCTIONS.md
git add PHPUNIT-INSTRUCTIONS.md
git add e2e/fixtures/wxr-complete.xml
git add e2e/fixtures/wxr-partial.xml
git add phpunit/data/idempotency-complete.xml
git add phpunit/data/idempotency-partial.xml
git add phpunit/tests/idempotency.php
git add test-cli/

# Create a detailed commit
git commit -m "Fix idempotency bug: URL remapping for existing attachments

When importing a WXR file after an interrupted import, existing attachments
were not added to the url_remap array, causing broken URLs in post content.

This fix ensures that when an attachment already exists (detected by
post_exists()), the url_remap array is properly populated with the
mapping from the original URL to the local URL.

Changes:
- Modified process_posts() in class-wp-import.php to populate url_remap
  for existing attachments
- Added comprehensive PHPUnit tests for idempotency scenarios
- Added shell script tests for development verification
- Added test data files simulating interrupted imports

Fixes: WordPress/wordpress-importer#[ISSUE_NUMBER]"
```

### 5. Push to Your Fork

```bash
git push myfork fix/idempotency-url-remap-bug
```

### 6. Create the Pull Request

1. Go to `https://github.com/YOUR_USERNAME/wordpress-importer`
2. You'll see a banner: "fix/idempotency-url-remap-bug had recent pushes"
3. Click "Compare & pull request"
4. Fill in the PR template (see below)

## Pull Request Template

Use this template for your PR description:

```markdown
## Description

Fixes an idempotency bug where URL remapping fails for existing attachments during resumed imports.

## The Problem

When an import is interrupted and resumed:
1. Existing attachments are skipped (correctly)
2. BUT their URLs are not added to the url_remap array (bug)
3. Result: Posts contain broken external URLs instead of local URLs

### Example of the Bug

```html
<!-- BROKEN: External URL remains after resumed import -->
<img src="https://yavuzceliker.github.io/sample-images/image-178.jpg" />

<!-- EXPECTED: Should be remapped to local URL -->
<img src="http://localhost:8080/wp-content/uploads/2024/01/image-178.jpg" />
```

## The Solution

Modified `process_posts()` in `class-wp-import.php` to populate the `url_remap` array even for existing attachments, ensuring URLs are properly remapped during `backfill_attachment_urls()`.

### Code Changes

Added URL remapping logic after line 756 when an attachment already exists:

```php
// Fix for idempotency bug: populate url_remap for existing attachments
if ( 'attachment' == $post['post_type'] && ! empty( $post['attachment_url'] ) ) {
    $existing_url = wp_get_attachment_url( $post_exists );
    if ( $existing_url ) {
        // Map remote URL to local URL
        $remote_url = $post['attachment_url'];

        // Handle resized image URLs...
        // [code continues]
    }
}
```

## Testing

### Automated Tests Included

- ✅ **PHPUnit Test Suite** (`phpunit/tests/idempotency.php`)
  - Tests partial import → complete import → URL verification
  - Compatible with WordPress core testing infrastructure
  - Ready for CI/CD integration

- ✅ **Shell Script Tests** (`test-cli/`)
  - `simple-test.sh` - Demonstrates the bug and verifies the fix
  - `test-patch.sh` - Tests applying the patch
  - `run-phpunit-style-test.sh` - PHPUnit-equivalent test

- ✅ **Test Data Files**
  - `phpunit/data/idempotency-partial.xml` - 1 attachment (simulates interruption)
  - `phpunit/data/idempotency-complete.xml` - 3 attachments + 3 posts (full import)

### Test Results

**Before Fix:**
```
❌ Post 1: URLs NOT remapped (references skipped attachment)
✅ Posts 2-3: URLs properly remapped (reference new attachments)
🔴 IDEMPOTENCY BUG CONFIRMED
```

**After Fix:**
```
✅ Post 1: URLs properly remapped to local files
✅ Post 2: URLs properly remapped to local files
✅ Post 3: URLs properly remapped to local files
✅ All URLs properly remapped - no idempotency issues
```

## How to Test Locally

1. **Quick Test:**
   ```bash
   cd test-cli
   bash simple-test.sh
   ```

2. **PHPUnit Test (requires WordPress test environment):**
   ```bash
   phpunit --group idempotency
   ```

3. **Test the Patch:**
   ```bash
   cd test-cli
   bash test-patch.sh
   ```

## Files Changed

- `src/class-wp-import.php` - Core bug fix
- `phpunit/tests/idempotency.php` - PHPUnit test suite
- `phpunit/data/*.xml` - Test data files
- `test-cli/` - Shell script tests
- `*.md` - Documentation

## Impact

This fix ensures WordPress imports are truly idempotent, preventing broken media URLs when imports are interrupted and resumed - a common scenario in production environments.

Fixes #[ISSUE_NUMBER] (if there's an existing issue)
```

## Alternative: Creating an Issue with Patch

If you prefer not to fork, you can:

1. **Create an Issue**
   - Go to https://github.com/WordPress/wordpress-importer/issues
   - Click "New issue"
   - Describe the bug with test results

2. **Attach Your Patch**
   - Include `test-cli/fix-idempotency-git.patch` in the issue
   - Share test results showing the fix works

3. **Provide Documentation**
   - Link to test scripts and documentation
   - Include PHPUnit test code

## Important Notes

### WordPress Development Process

- WordPress core uses SVN, but the Importer plugin uses GitHub
- Pull requests should follow WordPress coding standards
- Include tests whenever possible
- Be responsive to code review feedback

### Before Submitting

- [ ] Test your fix thoroughly
- [ ] Ensure all tests pass
- [ ] Follow WordPress coding standards
- [ ] Write clear commit messages
- [ ] Update documentation if needed

### Getting Help

- Check existing issues: https://github.com/WordPress/wordpress-importer/issues
- Review other PRs: https://github.com/WordPress/wordpress-importer/pulls
- WordPress Make blog: https://make.wordpress.org/

## Quick Command Summary

```bash
# 1. Fork on GitHub (via web interface)

# 2. Add your fork
git remote add myfork https://github.com/YOUR_USERNAME/wordpress-importer.git

# 3. Create branch
git checkout master
git pull origin master
git checkout -b fix/idempotency-url-remap-bug

# 4. Commit changes
git add .
git commit -m "Fix idempotency bug: URL remapping for existing attachments"

# 5. Push
git push myfork fix/idempotency-url-remap-bug

# 6. Create PR on GitHub (via web interface)
```

## Your Current Status

Based on `git status`:
- Modified: `src/class-wp-import.php` (the fix)
- Untracked: Test files, documentation, PHPUnit tests

All these files are ready to be committed and included in your PR!