// E2E test: import a simple WXR file using the WordPress Importer UI
const { test, expect } = require('@playwright/test');
const path = require('path');

test('imports a simple WXR file', async ({ page, request }) => {
	// Extra time for CI/Playground
	test.setTimeout(240000);
	// Navigate to wp-admin (Playground --login flag should pre-authenticate)
	await page.goto('/wp-admin/');

	// Ensure we are logged in; if redirected to login, perform a login with defaults.
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
	}

	// Go directly to the importer screen to avoid localization issues on the listing page.
	await page.goto('/wp-admin/admin.php?import=wordpress');

	// If redirected to login for any reason, log in and retry.
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
		await page.goto('/wp-admin/admin.php?import=wordpress');
	}

	// Upload the WXR file using existing fixture
	const wxrPath = path.resolve(__dirname, '../phpunit/data/wxr-simple.xml');
	// WP uses id="upload" and name="import"; target either one.
	const fileInput = page.locator('#upload, input[type="file"][name="import"]');
	await fileInput.waitFor({ state: 'visible' });
	await fileInput.setInputFiles(wxrPath);

	await page.getByRole('button', { name: /Upload file and import/i }).click()
	// Submit the upload form which will lead to author mapping/options (step=1)
	await page.waitForURL('**/admin.php?import=wordpress&step=1**', { waitUntil: 'domcontentloaded' });

	// On step=1, proceed to step=2 (author mapping defaults to current user)
	await page.getByRole('button', { name: /^Submit$/i }).click();
	await page.waitForURL('**/admin.php?import=wordpress&step=2**', { waitUntil: 'domcontentloaded' });

	// Expect final success copy from import_end()
	await expect(page.locator('text=All done.')).toBeVisible();
	await expect(page.locator('a[href$="/wp-admin/"]')).toBeVisible();

	// Verify imported content thoroughly via REST API
	const res = await request.get('/wp-json/wp/v2/posts?_embed=1&search=Road%20Not%20Taken&per_page=10');
	expect(res.ok()).toBeTruthy();
	const posts = await res.json();
	expect(Array.isArray(posts) && posts.length > 0).toBeTruthy();

	// Find the specific imported post by unique title/content markers
	const post = posts.find(
		(p) =>
			p?.title?.rendered?.includes('The Road Not Taken') &&
			p?.title?.rendered?.includes('Robert Frost') &&
			p?.content?.rendered?.includes('Two roads diverged in a yellow wood')
	);
	expect(post, 'Imported post not found by title/content').toBeTruthy();

	// Single-statement comparison with normalized snapshot
	const author = post?._embedded?.author?.[0];
	const embeddedTerms = (post?._embedded?.['wp:term'] || []).flat().filter(Boolean);
	const categories = embeddedTerms.filter((t) => t.taxonomy === 'category');
	const normalized = {
		status: post.status,
		type: post.type,
		sticky: !!post.sticky,
		title: post.title?.rendered || '',
		slug: post.slug || '',
		datePrefix: (post.date_gmt || '').slice(0, 10),
		content: post.content?.rendered || '',
		authorSlug: author?.slug,
		categories: categories
			.map((t) => (t.slug || t.name || '').toString().toLowerCase()),
		linksPresent: ['https://playground.internal/path/one', 'https://playground.internal/path-not-taken', 'https://w.org']
			.every((href) => (post.content?.rendered || '').includes(`href="${href}"`)),
		comment_status: post.comment_status,
		ping_status: post.ping_status,
	};

	expect(normalized).toMatchObject({
		status: 'publish',
		type: 'post',
		sticky: false,
		title: expect.stringContaining('The Road Not Taken'),
		slug: expect.stringMatching(/^hello-world/),
		datePrefix: '2024-06-05',
		content: expect.stringContaining('Two roads diverged in a yellow wood'),
		authorSlug: 'admin',
		categories: expect.arrayContaining(['uncategorized']),
		linksPresent: true,
		comment_status: expect.stringMatching(/^(open|closed)$/),
		ping_status: expect.stringMatching(/^(open|closed)$/),
	});

	// Public single view renders expected content
	expect(typeof post.link).toBe('string');
	await page.goto(post.link);
	await expect(page.getByText('Two roads diverged in a yellow wood')).toBeVisible();
	await expect(page.getByRole('link', { name: 'One' })).toBeVisible();
	await expect(page.locator('a[href="https://w.org"]')).toBeVisible();

	// Admin list shows the imported post and author
	await page.goto('/wp-admin/edit.php');
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
		await page.goto('/wp-admin/edit.php');
	}
	const row = page.locator('table.wp-list-table tbody tr', { hasText: 'Road Not Taken' });
	await expect(row).toHaveCount(1);
	await expect(row.locator('.row-title')).toContainText('Road Not Taken');
	await expect(row).toContainText('admin');
});
