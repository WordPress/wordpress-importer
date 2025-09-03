// E2E test: import a simple WXR file using the WordPress Importer UI
const { test, expect } = require('@playwright/test');
const path = require('path');

test('imports a simple WXR file', async ({ page }) => {
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
});


