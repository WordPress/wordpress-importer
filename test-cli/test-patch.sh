#!/bin/bash

# Complete test script for the idempotency bug fix
# This script:
# 1. Tests WITHOUT the patch to confirm the bug
# 2. Applies the patch
# 3. Tests WITH the patch to verify the fix
# 4. Can optionally revert the patch

set -e

# Load common utilities
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/test-utils.sh"

# Configuration
PATCH_FILE="$SCRIPT_DIR/fix-idempotency-git.patch"
# Auto-detect plugin directory relative to script location
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SIMPLE_TEST="$SCRIPT_DIR/simple-test.sh"

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}=== WordPress Importer Patch Test ===${NC}"
echo -e "${YELLOW}========================================${NC}"
echo

# Check prerequisites
check_prerequisites

if [ ! -f "$PATCH_FILE" ]; then
    echo -e "${RED}Error: Patch file not found: $PATCH_FILE${NC}"
    echo "Please ensure fix-idempotency-git.patch exists"
    exit 1
fi

# Function to run the test and check results
run_test() {
    local label="$1"
    echo -e "${CYAN}Running test: $label${NC}"

    # Run the test and capture exit code
    if bash "$SIMPLE_TEST" > /tmp/test_output_$$.txt 2>&1; then
        echo -e "${GREEN}✅ Test passed - No bug detected${NC}"
        return 0
    else
        echo -e "${RED}❌ Test failed - Bug detected${NC}"
        return 1
    fi
}

# Function to apply the patch
apply_patch() {
    echo -e "${CYAN}Applying patch...${NC}"
    cd "$PLUGIN_DIR"

    # Apply the patch using git apply
    if git apply "$PATCH_FILE" 2>&1 | grep -v "warning:"; then
        echo -e "${GREEN}✓ Patch applied successfully${NC}"
        return 0
    else
        echo -e "${RED}✗ Failed to apply patch${NC}"
        return 1
    fi
}

# Function to revert the patch
revert_patch() {
    echo -e "${CYAN}Reverting patch...${NC}"
    cd "$PLUGIN_DIR"

    # Revert using git checkout
    if git checkout src/class-wp-import.php 2>&1 | grep -v "Updated"; then
        echo -e "${GREEN}✓ Patch reverted successfully${NC}"
        return 0
    else
        echo -e "${YELLOW}⚠️  Could not revert patch${NC}"
        return 1
    fi
}

# Main test execution
main() {
    echo -e "${BLUE}📊 Test Plan:${NC}"
    echo "  1. Test WITHOUT patch (expect failure - bug present)"
    echo "  2. Apply the patch"
    echo "  3. Test WITH patch (expect success - bug fixed)"
    echo

    # Step 1: Test WITHOUT patch
    echo
    echo -e "${YELLOW}=== STEP 1: Testing WITHOUT patch ===${NC}"
    local before_result=0
    if run_test "WITHOUT patch"; then
        echo -e "${YELLOW}⚠️  Unexpected: Test passed without patch${NC}"
        echo "The bug may already be fixed or not reproducing"
        before_result=1
    else
        echo -e "${GREEN}✓ Expected: Bug confirmed (URLs not remapped)${NC}"
        before_result=0
    fi

    # Step 2: Apply the patch
    echo
    echo -e "${YELLOW}=== STEP 2: Applying patch ===${NC}"
    if ! apply_patch; then
        echo -e "${RED}Failed to apply patch. Aborting.${NC}"
        exit 1
    fi

    # Step 3: Test WITH patch
    echo
    echo -e "${YELLOW}=== STEP 3: Testing WITH patch ===${NC}"
    local after_result=0
    if run_test "WITH patch"; then
        echo -e "${GREEN}✓ Success: Bug fixed! URLs properly remapped${NC}"
        after_result=1
    else
        echo -e "${RED}✗ Failed: Bug still present after patch${NC}"
        after_result=0
    fi

    # Final results
    echo
    echo -e "${YELLOW}========================================${NC}"
    echo -e "${YELLOW}=== FINAL RESULTS ===${NC}"
    echo -e "${YELLOW}========================================${NC}"

    if [ $before_result -eq 0 ] && [ $after_result -eq 1 ]; then
        echo -e "${GREEN}🎉 SUCCESS: The patch fixes the idempotency bug!${NC}"
        echo
        echo -e "${GREEN}The fix works by:${NC}"
        echo "  • Populating url_remap for existing attachments"
        echo "  • Ensuring backfill_attachment_urls() can remap all URLs"
        echo "  • Making imports truly idempotent"

        echo
        echo -e "${CYAN}Do you want to keep the patch applied? (y/n)${NC}"
        read -r keep_patch
        if [ "$keep_patch" != "y" ] && [ "$keep_patch" != "Y" ]; then
            revert_patch
        else
            echo -e "${GREEN}✓ Patch kept in place${NC}"
        fi
        return 0
    elif [ $before_result -eq 1 ]; then
        echo -e "${YELLOW}⚠️  INCONCLUSIVE: Bug not reproduced${NC}"
        return 2
    else
        echo -e "${RED}❌ FAILED: Patch did not fix the bug${NC}"
        return 1
    fi
}

# Run the test
main "$@"