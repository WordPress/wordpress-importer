#!/bin/bash

# PHPUnit-style test runner using WordPress environment
# This script mimics the PHPUnit test but runs directly in WordPress

set -e

# Load common utilities
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/test-utils.sh"

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}=== PHPUnit-Style Idempotency Test ===${NC}"
echo -e "${YELLOW}========================================${NC}"
echo "This test mimics the PHPUnit test but runs directly in WordPress"
echo

# Check prerequisites
check_prerequisites

# Enable attachment fetching
enable_attachment_fetching

# Test data files (using PHPUnit test data)
PARTIAL_FILE="/var/www/html/wp-content/plugins/wordpress-importer/phpunit/data/idempotency-partial.xml"
COMPLETE_FILE="/var/www/html/wp-content/plugins/wordpress-importer/phpunit/data/idempotency-complete.xml"

echo -e "${BLUE}📄 Test Data:${NC}"
echo "  Partial: phpunit/data/idempotency-partial.xml (1 attachment)"
echo "  Complete: phpunit/data/idempotency-complete.xml (3 attachments + 3 posts)"
echo

# Reset database
reset_database
check_db_state "before test"

echo -e "${YELLOW}=== Test: test_idempotent_import_with_existing_attachments ===${NC}"
echo

# Step 1: Import partial file (simulates interrupted import)
echo -e "${CYAN}Step 1: Import partial file (1 attachment, 0 posts)${NC}"
import_output=$(wp_clean import "$PARTIAL_FILE" --authors=create 2>&1)

# Verify partial state
attachments_after_partial=$(wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'inherit'));
echo count(\$attachments);
")

posts_after_partial=$(wp_clean eval "
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'post_status' => 'any'));
echo count(\$posts);
")

echo "After partial import:"
echo "  Attachments: $attachments_after_partial (expected: 1)"
echo "  Posts: $posts_after_partial (expected: 0)"

if [ "$attachments_after_partial" = "1" ] && [ "$posts_after_partial" = "0" ]; then
    echo -e "${GREEN}✓ Partial import state correct${NC}"
else
    echo -e "${RED}✗ Partial import state incorrect${NC}"
    exit 1
fi

# Check first attachment title
first_attachment_title=$(wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => 1, 'post_status' => 'inherit'));
if (!empty(\$attachments)) {
    echo \$attachments[0]->post_title;
}
")

echo "  First attachment title: '$first_attachment_title' (expected: 'image-1')"

if [ "$first_attachment_title" = "image-1" ]; then
    echo -e "${GREEN}✓ First attachment correct${NC}"
else
    echo -e "${RED}✗ First attachment incorrect${NC}"
    exit 1
fi

echo

# Step 2: Import complete file
echo -e "${CYAN}Step 2: Import complete file (3 attachments + 3 posts)${NC}"
import_output=$(wp_clean import "$COMPLETE_FILE" --authors=create 2>&1)

# Verify final state
attachments_after_complete=$(wp_clean eval "
\$attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1, 'post_status' => 'inherit'));
echo count(\$attachments);
")

posts_after_complete=$(wp_clean eval "
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'post_status' => 'any'));
echo count(\$posts);
")

echo "After complete import:"
echo "  Attachments: $attachments_after_complete (expected: 3)"
echo "  Posts: $posts_after_complete (expected: 3)"

if [ "$attachments_after_complete" = "3" ] && [ "$posts_after_complete" = "3" ]; then
    echo -e "${GREEN}✓ Complete import state correct${NC}"
else
    echo -e "${RED}✗ Complete import state incorrect${NC}"
    exit 1
fi

echo

# Step 3: Critical test - URL remapping verification
echo -e "${CYAN}Step 3: URL remapping verification (the critical test)${NC}"

# Check each post for proper URL remapping
test_passed=true

for i in 1 2 3; do
    echo "Testing Post $i:"

    # Get post content
    post_content=$(wp_clean eval "
    \$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC'));
    if (isset(\$posts[$((i-1))])) {
        echo \$posts[$((i-1))]->post_content;
    }
    ")

    # Check for external URLs (should be 0)
    external_count=$(echo "$post_content" | grep -c "yavuzceliker.github.io" || echo "0")
    local_count=$(echo "$post_content" | grep -c "wp-content/uploads/" || echo "0")

    echo "  External URLs: $external_count (expected: 0)"
    echo "  Local URLs: $local_count (expected: 1+)"

    if [ "$external_count" = "0" ] && [ "$local_count" -gt "0" ]; then
        echo -e "  ${GREEN}✓ Post $i URLs properly remapped${NC}"
    else
        echo -e "  ${RED}✗ Post $i URLs NOT remapped (idempotency bug!)${NC}"
        test_passed=false
    fi
    echo
done

# Specific test for Post 1 (the critical case)
echo -e "${CYAN}Critical Test: Post 1 URL remapping${NC}"
echo "Post 1 references attachment 1 (which existed before complete import)"
echo "This is where the idempotency bug occurred."

post_1_content=$(wp_clean eval "
\$posts = get_posts(array('post_type' => 'post', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC'));
if (!empty(\$posts)) {
    echo \$posts[0]->post_title . '|' . \$posts[0]->post_content;
}
")

post_1_title=$(echo "$post_1_content" | cut -d'|' -f1)
post_1_body=$(echo "$post_1_content" | cut -d'|' -f2-)

echo "Post 1 title: '$post_1_title' (expected: 'Post 1 with Image 1')"

if [ "$post_1_title" = "Post 1 with Image 1" ]; then
    echo -e "${GREEN}✓ Post 1 title correct${NC}"
else
    echo -e "${RED}✗ Post 1 title incorrect${NC}"
    exit 1
fi

# Extract image URLs from post content
image_urls=$(echo "$post_1_body" | grep -o 'src="[^"]*"' | sed 's/src="//g' | sed 's/"//g')

echo "Image URLs in Post 1:"
while IFS= read -r url; do
    if [ -n "$url" ]; then
        echo "  $url"
        if echo "$url" | grep -q "yavuzceliker.github.io"; then
            echo -e "    ${RED}✗ EXTERNAL URL (idempotency bug!)${NC}"
            test_passed=false
        elif echo "$url" | grep -q "wp-content/uploads/"; then
            echo -e "    ${GREEN}✓ LOCAL URL (properly remapped)${NC}"
        fi
    fi
done <<< "$image_urls"

echo

# Final result
if [ "$test_passed" = true ]; then
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}✅ PHPUnit-style test PASSED${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo
    echo -e "${GREEN}The idempotency bug is FIXED:${NC}"
    echo "  • All posts have properly remapped URLs"
    echo "  • Post 1 (referencing existing attachment) works correctly"
    echo "  • No external URLs remain in post content"
    echo
    echo "This test verifies the same functionality as:"
    echo "  phpunit --group idempotency"
    exit 0
else
    echo -e "${RED}========================================${NC}"
    echo -e "${RED}❌ PHPUnit-style test FAILED${NC}"
    echo -e "${RED}========================================${NC}"
    echo
    echo -e "${RED}The idempotency bug is PRESENT:${NC}"
    echo "  • Some posts have broken external URLs"
    echo "  • URL remapping failed for existing attachments"
    echo
    echo "The fix needs to be applied to resolve this issue."
    exit 1
fi