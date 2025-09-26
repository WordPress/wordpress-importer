#!/bin/bash

# Simple test to demonstrate URL remapping idempotency bug using two WXR files
set -e

# Load common utilities
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/test-utils.sh"

PARTIAL_FILE="/var/www/html/wp-content/plugins/wordpress-importer/e2e/fixtures/wxr-partial.xml"
COMPLETE_FILE="/var/www/html/wp-content/plugins/wordpress-importer/e2e/fixtures/wxr-complete.xml"

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}=== WordPress Importer Bug Demo ====${NC}"
echo -e "${YELLOW}========================================${NC}"
echo -e "${CYAN}This test demonstrates the idempotency bug using two WXR files:${NC}"
echo -e "${CYAN}1. Partial import (1 attachment only) - simulates interrupted import${NC}"
echo -e "${CYAN}2. Complete import (3 attachments + 3 posts) - simulates recovery${NC}"
echo

# Check prerequisites
check_prerequisites

# Copy test files
copy_test_file "../e2e/fixtures/wxr-partial.xml" "$PARTIAL_FILE"
copy_test_file "../e2e/fixtures/wxr-complete.xml" "$COMPLETE_FILE"

# Show what we're testing
echo -e "${BLUE}📄 Test Files:${NC}"
echo "  Partial WXR:  1 attachment (image-1) only"
echo "  Complete WXR: 3 attachments (image-1, image-2, image-3) + 3 posts"
echo "  URLs: https://yavuzceliker.github.io/sample-images/"
echo

# Reset database
reset_database
check_db_state "before any imports"

# ===== STEP 1: SIMULATED INTERRUPTED IMPORT =====
show_test_phase "1" "SIMULATED INTERRUPTED IMPORT" "Importing partial file (1 attachment only)"

show_import_progress "Interrupted Import" "Processing partial WXR - 1 attachment, 0 posts"
enable_attachment_fetching

echo -e "${CYAN}Starting partial import (simulating interrupted import)...${NC}"
import_output=$(wp_clean import $PARTIAL_FILE --authors=create 2>&1)

echo -e "${BLUE}📋 Import Process Details:${NC}"
echo "$import_output" | grep -E "(Processing|Media|Post|Imported|already exists)" | while read line; do
    if [[ $line == *"attachment"* ]]; then
        if [[ $line == *"Imported"* ]]; then
            show_item_processing "attachment" "1" "1" "image-1" "downloaded" "Simulating first attachment before interruption"
        fi
    fi
done

check_db_state "after partial import"

explain_state "Interrupted Import Simulation" "Only 1 attachment was imported. This simulates what happens when an import is interrupted after processing some attachments but before any posts."

# ===== STEP 2: RECOVERY IMPORT =====
show_test_phase "2" "RECOVERY IMPORT" "Importing complete file to test idempotency"

show_import_progress "Recovery Import" "Processing complete WXR - 3 attachments + 3 posts"

# Query existing attachments before import
echo -e "${CYAN}Checking existing attachments before recovery import...${NC}"
attachments_before=$(wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
foreach (\$attachments as \$att) {
    echo \$att->ID . ':' . \$att->post_title . PHP_EOL;
}
")

posts_before=$(wp_quiet post list --post_type=post --format=count)

echo -e "${CYAN}Starting complete import (recovery attempt)...${NC}"
import_output=$(wp_clean import $COMPLETE_FILE --authors=create 2>&1)

# Query attachments after import
attachments_after=$(wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
foreach (\$attachments as \$att) {
    echo \$att->ID . ':' . \$att->post_title . PHP_EOL;
}
")

posts_after=$(wp_quiet post list --post_type=post --format=count)

echo -e "${BLUE}📋 Import Process Details:${NC}"

# Show what actually happened by comparing before/after
attachment_count=0
echo "Expected attachments: image-1, image-2, image-3"

for expected_name in "image-1" "image-2" "image-3"; do
    ((attachment_count++))

    # Check if this attachment existed before the import
    existed_before=false
    if echo "$attachments_before" | grep -q ":$expected_name"; then
        existed_before=true
    fi

    # Check if this attachment exists after the import
    exists_after=false
    if echo "$attachments_after" | grep -q ":$expected_name"; then
        exists_after=true
    fi

    if [ "$existed_before" = true ] && [ "$exists_after" = true ]; then
        show_item_processing "attachment" "$attachment_count" "3" "$expected_name" "skipped" "❌ Already exists, url_remap NOT populated!"
    elif [ "$existed_before" = false ] && [ "$exists_after" = true ]; then
        show_item_processing "attachment" "$attachment_count" "3" "$expected_name" "downloaded" "✅ New attachment, url_remap populated"
    elif [ "$exists_after" = false ]; then
        show_item_processing "attachment" "$attachment_count" "3" "$expected_name" "failed" "❌ Import failed"
    fi
done

# Show posts that were created
posts_created=$((posts_after - posts_before))
echo
echo "Posts created during this import: $posts_created"

if [ $posts_created -gt 0 ]; then
    wp_clean eval "
    \$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
    \$count = 0;
    foreach (\$posts as \$post) {
        \$count++;
        echo 'Post ' . \$count . ': ' . \$post->post_title . ' (ID: ' . \$post->ID . ')' . PHP_EOL;
    }
    " | while read -r line; do
        if [[ $line == Post* ]]; then
            post_num=$(echo "$line" | sed 's/Post \([0-9]*\):.*/\1/')
            show_item_processing "post" "$post_num" "3" "$line" "created" "References attachment $post_num"
        fi
    done
fi

check_db_state "after complete import"

# ===== STEP 3: URL REMAPPING ANALYSIS =====
show_test_phase "3" "URL REMAPPING ANALYSIS" "Checking which posts have broken vs working URLs"

# Check URL remapping for each post individually
echo -e "${BLUE}📊 Individual Post Analysis:${NC}"

# Get all posts and analyze their content
wp_clean eval "
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
\$bug_detected = false;

foreach (\$posts as \$i => \$post) {
    \$post_num = \$i + 1;
    \$has_original = strpos(\$post->post_content, 'yavuzceliker.github.io') !== false;
    \$has_local = strpos(\$post->post_content, 'wp-content/uploads/') !== false;

    echo 'Post ' . \$post_num . ': ' . \$post->post_title . PHP_EOL;

    if (\$has_original) {
        echo '  ❌ Contains broken URLs (external links still present)' . PHP_EOL;
        \$bug_detected = true;
    } elseif (\$has_local) {
        echo '  ✅ URLs properly remapped to local files' . PHP_EOL;
    } else {
        echo '  ⚠️  No attachment URLs found' . PHP_EOL;
    }
}

echo PHP_EOL . 'SUMMARY:' . PHP_EOL;
if (\$bug_detected) {
    echo '🔴 IDEMPOTENCY BUG DETECTED' . PHP_EOL;
    echo 'Some posts reference skipped attachments and have broken URLs.' . PHP_EOL;
} else {
    echo '✅ All URLs properly remapped - no idempotency issues.' . PHP_EOL;
}
"

# ===== STEP 4: PARENT RELATIONSHIP ANALYSIS =====
show_test_phase "4" "PARENT RELATIONSHIP ANALYSIS" "Checking attachment-post relationships in Media Library"

echo -e "${BLUE}📊 Attachment Parent Relationships:${NC}"
wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'inherit', 'orderby' => 'ID', 'order' => 'ASC'));
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'));
\$relationship_issues = 0;

foreach (\$attachments as \$attachment) {
    \$parent_id = \$attachment->post_parent;
    echo 'Attachment: ' . \$attachment->post_title . PHP_EOL;

    if (\$parent_id == 0) {
        echo '  ❌ Status: (Unattached) - Missing parent relationship' . PHP_EOL;
        \$relationship_issues++;
    } else {
        \$parent_post = get_post(\$parent_id);
        if (\$parent_post) {
            echo '  ✅ Uploaded to: ' . \$parent_post->post_title . ' (ID: ' . \$parent_id . ')' . PHP_EOL;
        } else {
            echo '  ⚠️  Parent ID ' . \$parent_id . ' exists but post not found' . PHP_EOL;
            \$relationship_issues++;
        }
    }
}

echo PHP_EOL . 'PARENT RELATIONSHIP SUMMARY:' . PHP_EOL;
if (\$relationship_issues > 0) {
    echo '🔴 RELATIONSHIP ISSUES DETECTED' . PHP_EOL;
    echo 'Some attachments are unattached or have invalid parent references.' . PHP_EOL;
} else {
    echo '✅ All attachments properly linked to their parent posts.' . PHP_EOL;
}
"

# Show specific URL examples
echo
echo -e "${BLUE}📋 URL Examples:${NC}"
wp_clean eval "
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => 3, 'orderby' => 'ID', 'order' => 'ASC'));

foreach (\$posts as \$i => \$post) {
    \$post_num = \$i + 1;
    echo 'Post ' . \$post_num . ' URLs:' . PHP_EOL;

    // Find URLs in content
    preg_match_all('/https:\/\/yavuzceliker\.github\.io[^\"]*/', \$post->post_content, \$original_matches);
    preg_match_all('/[^\"]*wp-content\/uploads\/[^\"]*/', \$post->post_content, \$local_matches);

    if (!empty(\$original_matches[0])) {
        foreach (\$original_matches[0] as \$url) {
            echo '  ❌ BROKEN: ' . \$url . PHP_EOL;
        }
    }

    if (!empty(\$local_matches[0])) {
        foreach (\$local_matches[0] as \$url) {
            echo '  ✅ WORKING: ' . \$url . PHP_EOL;
        }
    }

    echo PHP_EOL;
}
"

# ===== FINAL RESULTS =====
show_test_phase "4" "FINAL RESULTS" "Understanding the idempotency bug"

# Check if bug was detected
url_check_result=0
if check_url_remapping_detailed "final analysis"; then
    url_check_result=1
fi

echo
if [ "$url_check_result" -eq 0 ]; then
    echo -e "${RED}🔴 IDEMPOTENCY BUG CONFIRMED${NC}"
    echo
    echo -e "${YELLOW}💡 What happened:${NC}"
    echo "  1. ✅ Partial import: Attachment 1 downloaded successfully"
    echo "  2. ⚠️  Complete import: Attachment 1 skipped (already exists)"
    echo "  3. ❌ Attachment 1: url_remap array NOT populated"
    echo "  4. ✅ Attachments 2-3: Downloaded fresh, url_remap populated"
    echo "  5. ✅ All 3 posts: Created successfully"
    echo "  6. ❌ Post 1: URLs NOT remapped (references skipped attachment)"
    echo "  7. ✅ Posts 2-3: URLs properly remapped (reference new attachments)"
    echo
    echo -e "${YELLOW}🔧 Technical cause:${NC}"
    echo "  - File: src/class-wp-import.php (around line 751)"
    echo "  - When attachments already exist, process_attachment() is skipped"
    echo "  - This means url_remap array is never populated for existing attachments"
    echo "  - backfill_attachment_urls() has no mappings for those attachments"
    echo
    echo -e "${RED}This bug affects WordPress sites when imports are interrupted and resumed.${NC}"
    exit 1
else
    echo -e "${GREEN}✅ No idempotency bug detected${NC}"
    echo -e "${GREEN}All URLs were properly remapped to local files.${NC}"
    echo -e "${GREEN}The WordPress Importer handled the scenario correctly.${NC}"
    exit 0
fi