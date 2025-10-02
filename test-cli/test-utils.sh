#!/bin/bash

# Common utilities for WordPress Importer test scripts
# Source this file in other test scripts: source test-utils.sh

# Configuration
WP_CLI="docker compose exec wordpress wp --allow-root"
CONTAINER_NAME="wp_site"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Function to run WP-CLI commands and filter deprecation warnings
wp_clean() {
    ${WP_CLI} "$@" 2>&1 | grep -v "Deprecated:" || true
}

# Quiet version that also suppresses other warnings
wp_quiet() {
    ${WP_CLI} "$@" 2>/dev/null || ${WP_CLI} "$@" 2>&1 | grep -v "Deprecated:" || true
}

# Function to check database state
check_db_state() {
    local label="$1"
    echo -e "${BLUE}Database state $label:${NC}"

    local posts=$(wp_quiet post list --post_type=post --format=count)
    local attachments=$(wp_quiet post list --post_type=attachment --format=count)

    echo "  Posts: $posts"
    echo "  Attachments: $attachments"
}

# Function to show import progress with detailed status
show_import_progress() {
    local phase="$1"
    local description="$2"
    echo -e "${CYAN}⏱️  $phase: $description${NC}"
    echo "   Started at: $(date '+%H:%M:%S')"
}

# Function to show a clear kill marker
show_kill_marker() {
    local reason="$1"
    local items_processed="$2"
    echo
    echo -e "${RED}🔴 KILLING IMPORT NOW!${NC}"
    echo -e "   ${RED}Reason: $reason${NC}"
    echo -e "   ${RED}Items processed: $items_processed${NC}"
    echo -e "   ${RED}Time: $(date '+%H:%M:%S')${NC}"
    echo
}

# Function to explain what a state means
explain_state() {
    local context="$1"
    local explanation="$2"
    echo -e "${BLUE}💡 WHAT THIS MEANS:${NC}"
    echo -e "   ${BLUE}$explanation${NC}"
    echo
}

# Function to show detailed import item processing
show_item_processing() {
    local item_type="$1"  # "attachment" or "post"
    local item_number="$2"
    local total="$3"
    local item_name="$4"
    local status="$5"     # "downloaded", "created", "skipped", "failed"
    local extra_info="$6"

    local icon=""
    local color=""
    case "$status" in
        "downloaded"|"created")
            icon="✓"
            color="${GREEN}"
            ;;
        "skipped")
            icon="⚠️ "
            color="${YELLOW}"
            ;;
        "failed")
            icon="❌"
            color="${RED}"
            ;;
    esac

    local item_type_cap="$(echo "${item_type:0:1}" | tr '[:lower:]' '[:upper:]')$(echo "${item_type:1}")"
    local status_upper=$(echo "$status" | tr '[:lower:]' '[:upper:]')
    echo -e "${color}${icon} ${item_type_cap} $item_number/$total: $item_name - ${status_upper}${NC}"
    if [ -n "$extra_info" ]; then
        echo -e "    ${color}$extra_info${NC}"
    fi
}

# Function to compare expected vs actual outcomes
compare_expected_actual() {
    local label="$1"
    local expected="$2"
    local actual="$3"

    echo -e "${BLUE}Expected vs Actual $label:${NC}"
    echo -e "  Expected: $expected"
    echo -e "  Actual:   $actual"

    if [ "$expected" = "$actual" ]; then
        echo -e "  ${GREEN}✓ MATCH${NC}"
        return 0
    else
        echo -e "  ${RED}✗ MISMATCH${NC}"
        return 1
    fi
}

# Function to show test phase header
show_test_phase() {
    local phase_number="$1"
    local phase_name="$2"
    local description="$3"

    echo
    echo -e "${YELLOW}=================================${NC}"
    echo -e "${YELLOW}=== STEP $phase_number: $phase_name ===${NC}"
    echo -e "${YELLOW}=================================${NC}"
    echo -e "${CYAN}$description${NC}"
    echo
}

# Function to check URL remapping in post content
check_url_remapping() {
    local label="$1"
    echo -e "${BLUE}URL remapping check $label:${NC}"

    local post_count=$(wp_quiet post list --post_type=post --format=count)
    if [ "$post_count" -eq 0 ]; then
        echo "  No posts found"
        return 1
    fi

    local post_content=$(wp_clean post list --post_type=post --field=post_content --format=csv | tail -n +2)

    # Check for original URLs (should be 0 after proper remapping)
    local original_urls=$(echo "$post_content" | grep -o "https://yavuzceliker.github.io/sample-images/[^\"]*" | wc -l | tr -d ' ')
    local local_urls=$(echo "$post_content" | grep -o "[^\"]*wp-content/uploads/[^\"]*" | wc -l | tr -d ' ')

    echo "  Original external URLs: $original_urls"
    echo "  Local URLs: $local_urls"

    if [ "$original_urls" -gt 0 ]; then
        echo -e "  ${RED}URLs NOT remapped${NC}"
        echo "  Sample unreplaced URLs:"
        echo "$post_content" | grep -o "https://yavuzceliker.github.io/sample-images/[^\"]*" | head -2 | sed 's/^/    /'
        return 1
    elif [ "$local_urls" -gt 0 ]; then
        echo -e "  ${GREEN}URLs properly remapped${NC}"
        return 0
    else
        echo -e "  ${YELLOW}No attachment URLs found${NC}"
        return 1
    fi
}

# Enhanced version with more detailed analysis
check_url_remapping_detailed() {
    local label="$1"
    show_import_progress "URL Analysis" "Checking URL remapping $label"

    local post_count=$(wp_quiet post list --post_type=post --format=count)
    if [ "$post_count" -eq 0 ]; then
        echo "  No posts found to analyze"
        return 1
    fi

    local post_content=$(wp_clean post list --post_type=post --field=post_content --format=csv | tail -n +2)

    # Check for original URLs (should be 0 after proper remapping)
    local original_urls=$(echo "$post_content" | grep -o "https://yavuzceliker.github.io/sample-images/[^\"]*" | wc -l | tr -d ' ')
    local local_urls=$(echo "$post_content" | grep -o "[^\"]*wp-content/uploads/[^\"]*" | wc -l | tr -d ' ')

    echo
    echo -e "${BLUE}📊 URL Remapping Analysis:${NC}"
    echo "  Posts analyzed: $post_count"
    echo "  Original external URLs found: $original_urls"
    echo "  Local URLs found: $local_urls"

    if [ "$original_urls" -gt 0 ]; then
        echo -e "  ${RED}❌ ISSUE: URLs NOT properly remapped${NC}"
        echo
        echo -e "${RED}Sample unreplaced URLs:${NC}"
        echo "$post_content" | grep -o "https://yavuzceliker.github.io/sample-images/[^\"]*" | head -3 | sed 's/^/    /'

        explain_state "URL Remapping Failure" "Some URLs still point to external sources instead of local files. This indicates the idempotency bug where existing attachments don't populate the url_remap array."
        return 1
    elif [ "$local_urls" -gt 0 ]; then
        echo -e "  ${GREEN}✓ SUCCESS: URLs properly remapped to local files${NC}"
        echo
        echo -e "${GREEN}Sample local URLs:${NC}"
        echo "$post_content" | grep -o "[^\"]*wp-content/uploads/[^\"]*" | head -3 | sed 's/^/    /'
        return 0
    else
        echo -e "  ${YELLOW}⚠️  WARNING: No attachment URLs found in content${NC}"
        explain_state "No URLs Found" "Posts don't contain attachment references, which might indicate they weren't processed correctly."
        return 1
    fi
}

# Function to reset database
reset_database() {
    echo -e "${CYAN}Resetting database...${NC}"

    # Delete all posts (including attachments)
    local post_ids=$(wp_quiet post list --post_type=any --format=ids)
    if [ -n "$post_ids" ]; then
        wp_quiet post delete $post_ids --force 2>/dev/null || true
    fi

    # Also explicitly delete attachments to be sure
    local attachment_ids=$(wp_quiet post list --post_type=attachment --format=ids)
    if [ -n "$attachment_ids" ]; then
        wp_quiet post delete $attachment_ids --force 2>/dev/null || true
    fi

    # Delete all users except admin (ID 1)
    local user_ids=$(wp_quiet user list --field=ID --format=csv | grep -v "^1$")
    if [ -n "$user_ids" ]; then
        wp_quiet user delete $user_ids --yes 2>/dev/null || true
    fi

    echo "Database reset complete"
}

# Function to copy test file to container
copy_test_file() {
    local source_file="$1"
    local target_file="$2"
    docker cp "$source_file" "$CONTAINER_NAME:$target_file"
}

# Function to enable attachment fetching
enable_attachment_fetching() {
    wp_clean eval "
    \$mu_plugins_dir = WPMU_PLUGIN_DIR;
    if (!file_exists(\$mu_plugins_dir)) {
        wp_mkdir_p(\$mu_plugins_dir);
    }
    file_put_contents(\$mu_plugins_dir . '/enable-fetch-attachments.php', '<?php add_filter(\"import_allow_fetch_attachments\", \"__return_true\");');
    " 2>/dev/null
}

# Function to disable attachment fetching
disable_attachment_fetching() {
    wp_clean eval "
    \$mu_plugins_dir = WPMU_PLUGIN_DIR;
    if (!file_exists(\$mu_plugins_dir)) {
        wp_mkdir_p(\$mu_plugins_dir);
    }
    file_put_contents(\$mu_plugins_dir . '/disable-fetch-attachments.php', '<?php add_filter(\"import_allow_fetch_attachments\", \"__return_false\");');
    " 2>/dev/null
}

# Function to check if containers are running
check_prerequisites() {
    if ! docker ps | grep -q "$CONTAINER_NAME"; then
        echo -e "${RED}Error: WordPress container '$CONTAINER_NAME' not running${NC}"
        echo "Please start the development environment:"
        echo "  docker compose up -d"
        exit 1
    fi
}

# Function to show test success/failure
show_result() {
    local success="$1"
    local test_name="$2"

    if [ "$success" -eq 1 ]; then
        echo -e "${GREEN}✓ $test_name: PASS${NC}"
        return 0
    else
        echo -e "${RED}✗ $test_name: FAIL${NC}"
        return 1
    fi
}