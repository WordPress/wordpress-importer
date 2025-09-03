// E2E test: import WXR files using a fresh Playground instance per test
// Import from 'playwright/test' to ensure it matches the npx runner version
const { test, expect } = require('playwright/test');
const { runCLI } = require('@wp-playground/cli');
const path = require('path');
const net = require('net');
const http = require('http');

let PLAYGROUND_URL = '';
let stopPlayground = async () => {};

function sleep(ms) {
	return new Promise((r) => setTimeout(r, ms));
}

async function waitUntilAlive(url, timeoutMs = 30000) {
	const end = Date.now() + timeoutMs;
	while (Date.now() < end) {
		try {
			await new Promise((resolve, reject) => {
				const req = http.request(url, { method: 'HEAD' }, (res) => {
					res.destroy();
					if (res.statusCode && res.statusCode < 500) resolve(true);
					else reject(new Error('Bad status'));
				});
				req.on('error', reject);
				req.end();
			});
			return true;
		} catch (_) {}
		await sleep(300);
	}
	throw new Error('Playground server did not become ready in time');
}

async function getAvailablePort() {
	return new Promise((resolve, reject) => {
		const srv = net.createServer();
		srv.unref();
		srv.on('error', reject);
		srv.listen(0, '127.0.0.1', () => {
			const { port } = srv.address();
			srv.close(() => resolve(port));
		});
	});
}

async function startPlayground(port) {
	const pluginSrc = path.resolve(__dirname, '../src');
	const blueprint = path.resolve(__dirname, '../playground.blueprint.json');
	const siteUrl = `http://127.0.0.1:${port}`;

	const { server } = await runCLI({
		command: 'server',
		blueprint,
		blueprintMayReadAdjacentFiles: true,
		mount: [
			{
				hostPath: pluginSrc,
				vfsPath: '/wordpress/wp-content/plugins/wordpress-importer',
			},
		],
		port,
		siteUrl,
		quiet: true,
	});

	await waitUntilAlive(`${siteUrl}/wp-admin/`);

	const stop = async () => {
		await server.close();
	};

	return { url: siteUrl, stop };
}

function abs(u) {
	return `${PLAYGROUND_URL}${u}`;
}

test.beforeEach(async () => {
	const port = await getAvailablePort();
	const server = await startPlayground(port);
	PLAYGROUND_URL = server.url;
	stopPlayground = server.stop;
});

test.afterEach(async () => {
	await stopPlayground();
});

test('imports a simple WXR file', async ({ page, request }) => {
	// Extra time for CI/Playground
	test.setTimeout(240000);
	// Navigate to wp-admin (Playground --login flag should pre-authenticate)
	await page.goto(abs('/wp-admin/'));

	// Ensure we are logged in; if redirected to login, perform a login with defaults.
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
	}

	// Go directly to the importer screen to avoid localization issues on the listing page.
	await page.goto(abs('/wp-admin/admin.php?import=wordpress'));

	// If redirected to login for any reason, log in and retry.
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
		await page.goto(abs('/wp-admin/admin.php?import=wordpress'));
	}

	// Upload the WXR file using existing fixture
	const wxrPath = path.resolve(__dirname, './fixtures/wxr-simple.xml');
	// WP uses id="upload" and name="import"; target either one.
	const fileInput = page.locator('#upload, input[type="file"][name="import"]');
	await fileInput.waitFor({ state: 'visible' });
	await fileInput.setInputFiles(wxrPath);

	await page.getByRole('button', { name: /Upload file and import/i }).click();
	// Submit the upload form which will lead to author mapping/options (step=1)
	await page.waitForURL('**/admin.php?import=wordpress&step=1**', {
		waitUntil: 'domcontentloaded',
	});

	// On step=1, proceed to step=2 (author mapping defaults to current user)
	await page.getByRole('button', { name: /^Submit$/i }).click();
	await page.waitForURL('**/admin.php?import=wordpress&step=2**', {
		waitUntil: 'domcontentloaded',
	});

	// Expect final success copy from import_end()
	await expect(page.locator('text=All done.')).toBeVisible();
	await expect(page.locator('a[href$="/wp-admin/"]')).toBeVisible();

	// Verify imported content thoroughly via REST API
	const res = await request.get(
		abs('/wp-json/wp/v2/posts?_embed=1&search=Road%20Not%20Taken&per_page=10')
	);
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
		categories: categories.map((t) => (t.slug || t.name || '').toString().toLowerCase()),
		linksPresent: [
			'https://playground.internal/path/one',
			'https://playground.internal/path-not-taken',
			'https://w.org',
		].every((href) => (post.content?.rendered || '').includes(`href="${href}"`)),
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
	await page.goto(abs('/wp-admin/edit.php'));
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
		await page.goto(abs('/wp-admin/edit.php'));
	}
	const row = page.locator('table.wp-list-table tbody tr', { hasText: 'Road Not Taken' });
	await expect(row).toHaveCount(1);
	await expect(row.locator('.row-title')).toContainText('Road Not Taken');
	await expect(row).toContainText('admin');
});

async function loginIfNeeded(page) {
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
	}
}

async function goToImporter(page) {
	await page.goto(abs('/wp-admin/'));
	await loginIfNeeded(page);
	await page.goto(abs('/wp-admin/admin.php?import=wordpress'));
	await loginIfNeeded(page);
}

async function performImport(page, { mapAuthorsToAdmin = false } = {}) {
	await goToImporter(page);
	const wxrPath = path.resolve(__dirname, './fixtures/wxr-comprehensive.xml');
	const fileInput = page.locator('#upload, input[type="file"][name="import"]');
	await fileInput.waitFor({ state: 'visible' });
	await fileInput.setInputFiles(wxrPath);

	await page.getByRole('button', { name: /Upload file and import/i }).click();
	await page.waitForURL('**/admin.php?import=wordpress&step=1**', {
		waitUntil: 'domcontentloaded',
	});

	// Do not fetch attachments to avoid network dependencies
	const attachmentsCheckbox = page.locator('input[name="fetch_attachments"]');
	if (await attachmentsCheckbox.count()) {
		await attachmentsCheckbox.uncheck().catch(() => {});
	}

	if (mapAuthorsToAdmin) {
		// Try to set each author mapping select to admin (ID 1)
		const selects = page.locator('select');
		const total = await selects.count();
		for (let i = 0; i < total; i++) {
			const sel = selects.nth(i);
			try {
				await sel.selectOption({ label: /admin/i });
			} catch (_) {
				try {
					await sel.selectOption('1');
				} catch (_) {}
			}
		}
	}

	await page.getByRole('button', { name: /^Submit$/i }).click();
	await page.waitForURL('**/admin.php?import=wordpress&step=2**', {
		waitUntil: 'domcontentloaded',
	});
	await expect(page.locator('text=All done.')).toBeVisible();
}

async function verifyImportedData(page, request, { expectAuthorSlug = 'admin' } = {}) {
	// Verify post
	const postRes = await request.get(
		abs('/wp-json/wp/v2/posts?_embed=1&search=Comprehensive%20Post&per_page=10')
	);
	expect(postRes.ok()).toBeTruthy();
	const posts = await postRes.json();
	expect(Array.isArray(posts) && posts.length > 0).toBeTruthy();
	const post = posts.find((p) => p.title?.rendered?.includes('Comprehensive Post')) || posts[0];
	const author = post?._embedded?.author?.[0];
	const terms = (post?._embedded?.['wp:term'] || []).flat().filter(Boolean);
	const categories = terms
		.filter((t) => t.taxonomy === 'category')
		.map((t) => (t.slug || '').toLowerCase());
	const tags = terms
		.filter((t) => t.taxonomy === 'post_tag')
		.map((t) => (t.slug || '').toLowerCase());

	const normalized = {
		status: post.status,
		type: post.type,
		sticky: !!post.sticky,
		title: post.title?.rendered || '',
		slug: post.slug || '',
		datePrefix: (post.date_gmt || '').slice(0, 10),
		content: post.content?.rendered || '',
		authorSlug: author?.slug,
		categories,
		tags,
		comment_status: post.comment_status,
		ping_status: post.ping_status,
	};

	expect(normalized).toMatchObject({
		status: 'publish',
		type: 'post',
		sticky: false,
		title: expect.stringContaining('Comprehensive Post'),
		slug: expect.stringMatching(/^comprehensive-post/),
		datePrefix: '2024-06-05',
		content: expect.stringContaining('This is a comprehensive post body'),
		authorSlug: expectAuthorSlug,
		categories: expect.arrayContaining(['news', 'updates']),
		tags: expect.arrayContaining(['t1', 't2']),
		comment_status: expect.stringMatching(/^(open|closed)$/),
		ping_status: expect.stringMatching(/^(open|closed)$/),
	});

	// Verify a comment exists for the post
	const commentsRes = await request.get(abs(`/wp-json/wp/v2/comments?post=${post.id}`));
	expect(commentsRes.ok()).toBeTruthy();
	const comments = await commentsRes.json();
	expect(comments.some((c) => (c.content?.rendered || '').includes('Great post'))).toBeTruthy();

	// Verify page exists
	const pageRes = await request.get(
		abs('/wp-json/wp/v2/pages?search=Comprehensive%20Page&per_page=10')
	);
	expect(pageRes.ok()).toBeTruthy();
	const pages = await pageRes.json();
	expect(pages.some((p) => p.title?.rendered?.includes('Comprehensive Page'))).toBeTruthy();

	// Public view spot-check
	await page.goto(post.link);
	await expect(page.getByText('comprehensive post body')).toBeVisible();
}

test.describe('Comprehensive WXR import', () => {
	test('imports with explicit author mapping to admin', async ({ page, request }) => {
		test.setTimeout(300000);
		await performImport(page, { mapAuthorsToAdmin: true });
		await verifyImportedData(page, request, { expectAuthorSlug: 'admin' });
	});

	test('imports with default author mapping (current user)', async ({ page, request }) => {
		test.setTimeout(300000);
		await performImport(page, { mapAuthorsToAdmin: false });
		await verifyImportedData(page, request, { expectAuthorSlug: 'alice' });
	});
});
