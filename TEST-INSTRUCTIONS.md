# WordPress Importer Idempotency Bug - Test Instructions

This document provides step-by-step instructions for testing the WordPress Importer idempotency bug fix.

**Status: FIXED** ✅ - The bug has been fixed and the tests now verify the fix works.

## Prerequisites

1. **Docker Environment**: WordPress development environment running
   ```bash
   docker compose up -d
   ```

2. **Verify Environment**: Check that containers are running
   ```bash
   docker compose ps
   # Should show wp_site and wp_db containers as "Up"
   ```

3. **Access WordPress**: Verify WordPress is accessible
   ```bash
   curl -s -o /dev/null -w "%{http_code}" http://localhost:8880
   # Should return 200
   ```

## Quick Test (Recommended)

### 1. Simple Bug Demonstration & Fix Verification

The fastest way to see the bug fix in action:

```bash
# Navigate to the test directory
cd wordpress/wp-content/plugins/wordpress-importer/test-cli

# Run the simple test (demonstrates the fix)
bash simple-test.sh
```

**Expected Result**: ✅ All URLs properly remapped - no idempotency issues.

This test:
1. Imports 1 attachment (simulates interrupted import)
2. Imports 3 attachments + 3 posts (simulates recovery)
3. Verifies all URLs are correctly remapped to local files

## Testing the Patch

### 2. Patch Application Test

To test applying the fix as a patch:

```bash
# Navigate to the test directory
cd wordpress/wp-content/plugins/wordpress-importer/test-cli

# Run the patch test
bash test-patch.sh
```

This test:
1. Tests WITHOUT the patch (should fail - bug present)
2. Applies the git patch
3. Tests WITH the patch (should pass - bug fixed)
4. Asks if you want to keep the patch applied

## Test Files

### Essential Files

The testing system uses these files:

- **test-cli/simple-test.sh**: Main test demonstrating the bug/fix
- **test-cli/test-patch.sh**: Tests applying the patch
- **test-cli/test-utils.sh**: Common utilities for test scripts
- **test-cli/fix-idempotency-git.patch**: Git patch with the fix

### Test Data

- **e2e/fixtures/wxr-partial.xml**: Simulates interrupted import (1 attachment)
- **e2e/fixtures/wxr-complete.xml**: Complete import file (3 attachments + 3 posts)

## Understanding the Output

### Before Fix (Bug Present)
```
❌ Post 1: URLs NOT remapped (references skipped attachment)
✅ Posts 2-3: URLs properly remapped (reference new attachments)

🔴 IDEMPOTENCY BUG CONFIRMED
```

### After Fix (Bug Fixed)
```
✅ Post 1: URLs properly remapped to local files
✅ Post 2: URLs properly remapped to local files
✅ Post 3: URLs properly remapped to local files

✅ All URLs properly remapped - no idempotency issues.
```

## How the Tests Work

### 1. Two-File Approach

Instead of killing processes, we use two separate WXR files:

- **Partial Import**: Contains only the first attachment (simulates interruption)
- **Complete Import**: Contains all attachments + posts (simulates recovery)

### 2. Database State Checking

Tests verify:
- Which attachments were downloaded vs skipped
- Which post URLs were remapped vs broken
- Database state before/after each import

### 3. URL Analysis

The tests check post content for:
- **External URLs**: `https://yavuzceliker.github.io/sample-images/...` (broken)
- **Local URLs**: `http://localhost:8880/wp-content/uploads/...` (working)

## Troubleshooting

### Container Issues
```bash
# If containers aren't running
docker compose up -d

# Check logs if issues persist
docker compose logs
```

### Permission Issues
```bash
# Make scripts executable
chmod +x test-cli/*.sh
```

### Import Failures
```bash
# Check WordPress logs
docker compose exec wordpress tail -f /var/log/apache2/error.log
```

## Manual Testing

If you want to test manually:

1. Run the partial import: `wp import e2e/fixtures/wxr-partial.xml --authors=create`
2. Run the complete import: `wp import e2e/fixtures/wxr-complete.xml --authors=create`
3. Check post content for URL mappings
4. Verify attachment files exist in uploads directory

## Conclusion

The simplified testing approach using shell scripts provides reliable, repeatable tests that clearly demonstrate:

1. **The bug**: When it occurs and why
2. **The fix**: How it resolves the issue
3. **Verification**: That imports are now truly idempotent

All tests should pass with the fix applied, showing that WordPress imports now work correctly even after interruptions.

## PHPUnit Testing

We also provide professional PHPUnit tests that are **ready for CI/CD and WordPress.org integration**.

### PHPUnit Test Files

- **`phpunit/tests/idempotency.php`** - Main PHPUnit test suite
- **`phpunit/data/idempotency-partial.xml`** - Test data (partial import)
- **`phpunit/data/idempotency-complete.xml`** - Test data (complete import)

### Running PHPUnit Tests

**In WordPress Core Test Environment:**
```bash
# Setup WordPress test environment first
composer install
phpunit --group idempotency
```

**Alternative: PHPUnit-Style Shell Test:**
```bash
cd test-cli
bash run-phpunit-style-test.sh
```

This shell script provides **identical functionality** to the PHPUnit test but runs in our current Docker environment.

### PHPUnit vs Shell Script Testing

Both approaches test the same functionality:

| Test Approach | Environment | Setup | Coverage |
|---------------|-------------|-------|----------|
| **PHPUnit** | WordPress Test Suite | Complex | ✅ Complete |
| **Shell Scripts** | Live WordPress | None | ✅ Complete |

### For Pull Requests

Include **both testing approaches** in your PR:

1. **PHPUnit tests** - For WordPress core integration
2. **Shell scripts** - For development and demonstration

This provides maximum compatibility and testing coverage.

### PHPUnit Test Details

The PHPUnit test performs these exact steps:

1. **Setup**: Clean database, enable attachment fetching
2. **Partial Import**: Import 1 attachment (simulates interruption)
3. **Complete Import**: Import 3 attachments + 3 posts (simulates recovery)
4. **Verification**: Assert all URLs are properly remapped (the critical test)
5. **Bug Detection**: Specifically test Post 1 (referencing existing attachment)

**Expected Results:**
- ❌ **Without fix**: Test fails (external URLs remain)
- ✅ **With fix**: Test passes (all URLs remapped)