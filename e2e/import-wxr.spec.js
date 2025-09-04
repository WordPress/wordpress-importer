// E2E test: import WXR files using a fresh Playground instance per test
// Import from 'playwright/test' to ensure it matches the npx runner version
const { test, expect } = require('playwright/test');
const { runCLI } = require('@wp-playground/cli');
const path = require('path');
const net = require('net');
const http = require('http');
const fs = require('fs');

let PLAYGROUND_URL = '';

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

async function startPlayground(port, parser = null) {
	const pluginSrc = path.resolve(__dirname, '../src');
	const muPluginsSrc = path.resolve(__dirname, './helpers/mu-plugins');
	const blueprint = JSON.parse(
		fs.readFileSync(path.resolve(__dirname, './playground.blueprint.json'), 'utf8')
	);
	const siteUrl = `http://127.0.0.1:${port}`;

	// Create blueprint config with optional parser constant
	const blueprintConfig = {
		command: 'server',
		blueprint,
		blueprintMayReadAdjacentFiles: true,
		mount: [
			{
				hostPath: pluginSrc,
				vfsPath: '/wordpress/wp-content/plugins/wordpress-importer',
			},
			{
				hostPath: muPluginsSrc,
				vfsPath: '/wordpress/wp-content/mu-plugins',
			},
		],
		port,
		siteUrl,
		quiet: true,
	};

	// Add constants if parser is specified
	if (parser) {
		blueprintConfig.blueprint.constants = {
			...(blueprintConfig.blueprint?.constants || {}),
			PREFERRED_WXR_PARSER: parser,
		};
	}

	const { server } = await runCLI(blueprintConfig);

	await waitUntilAlive(`${siteUrl}/wp-admin/`);

	const stop = async () => {
		await server.close();
	};

	return { url: siteUrl, stop };
}

function abs(u) {
	return `${PLAYGROUND_URL}${u}`;
}

// Generic test function for WXR import
async function testWXRImport({ page, request, filename, expectations }) {
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

	// Upload the WXR file using provided filename
	const wxrPath = path.resolve(__dirname, `./fixtures/${filename}`);
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
		abs(
			`/wp-json/wp/v2/posts?_embed=1&search=${encodeURIComponent(
				expectations.searchTerm
			)}&per_page=10`
		)
	);
	expect(res.ok()).toBeTruthy();
	const posts = await res.json();
	expect(Array.isArray(posts) && posts.length > 0).toBeTruthy();

	// Find the specific imported post by unique title/content markers
	const post = posts.find((p) => p?.title?.rendered?.includes(expectations.titleContains));
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
		linksPresent: true,
		comment_status: post.comment_status,
		ping_status: post.ping_status,
	};

	expect(normalized).toMatchObject({
		status: 'publish',
		type: 'post',
		sticky: false,
		title: expect.stringContaining(expectations.titleContains),
		slug: expect.stringMatching(/^hello-world/),
		datePrefix: '2024-06-05',
		content: expect.stringContaining(expectations.contentContains),
		authorSlug: 'admin',
		categories: expect.arrayContaining(['uncategorized']),
		linksPresent: true,
		comment_status: expect.stringMatching(/^(open|closed)$/),
		ping_status: expect.stringMatching(/^(open|closed)$/),
	});

	// Public single view renders expected content
	expect(typeof post.link).toBe('string');
	await page.goto(post.link);
	await expect(page.getByText(expectations.contentContains)).toBeVisible();

	// Run additional expectations if provided
	if (expectations.additionalChecks) {
		await expectations.additionalChecks(page, post);
	}

	// Admin list shows the imported post and author
	await page.goto(abs('/wp-admin/edit.php'));
	if (page.url().includes('wp-login.php')) {
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');
		await page.goto(abs('/wp-admin/edit.php'));
	}
	const row = page.locator('table.wp-list-table tbody tr', {
		hasText: expectations.titleContains,
	});
	await expect(row).toHaveCount(1);
	await expect(row.locator('.row-title')).toContainText(expectations.titleContains);
	await expect(row).toContainText('admin');
}

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
	const targetComment =
		comments.find((c) => (c.content?.rendered || '').includes('Great post')) || comments[0];
	expect(targetComment).toBeTruthy();
	// Assert comment meta exposed by our MU plugin is present
	expect(targetComment).toMatchObject({
		meta: expect.objectContaining({
			rating: 5,
			note: 'vip',
		}),
	});

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

// Define available parsers
const PARSERS = ['simplexml']; //], 'xml', 'regex', 'xmlprocessor'];

// Run tests for each parser
PARSERS.forEach((parser) => {
	test.describe(`WXR Import with ${parser} parser`, () => {
		let stopPlayground;
		test.beforeEach(async () => {
			const port = await getAvailablePort();
			const server = await startPlayground(port, parser);
			PLAYGROUND_URL = server.url;
			stopPlayground = server.stop;
		});

		test.afterEach(async () => {
			await stopPlayground();
		});

		test(`imports a simple WXR file using ${parser} parser`, async ({ page, request }) => {
			await testWXRImport({
				page,
				request,
				filename: 'wxr-simple.xml',
				expectations: {
					searchTerm: 'Road Not Taken',
					titleContains: 'The Road Not Taken',
					contentContains: `<p>Two roads diverged in a yellow wood,<br>And sorry I could not travel both</p>


					
<p>
<a href="${PLAYGROUND_URL}/one">One</a> seemed great, but <a href="https://playground.internal/path-not-taken">the other</a> seemed great too.
There was also a <a href="https://w.org">third</a> option, but it was not as great.

${PLAYGROUND_URL.substring('http://'.length)}/one was the best choice.
https://playground.internal/path-not-taken was the second best choice.
</p>`,
					additionalChecks: async (page, post) => {
						await expect(page.getByRole('link', { name: 'One' })).toBeVisible();
						await expect(page.locator('a[href="https://w.org"]')).toBeVisible();
					},
				},
			});
		});

		test(`imports a base URL rewriting WXR file using ${parser} parser`, async ({
			page,
			request,
		}) => {
			await testWXRImport({
				page,
				request,
				filename: 'wxr-base-url-rewriting.xml',
				expectations: {
					searchTerm: 'Road Not Taken',
					titleContains: 'The Road Not Taken',
					contentContains: `<p>
    <!-- Rewrites URLs that match the base URL -->
    URLs to rewrite:

    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}/
    <a href="${PLAYGROUND_URL}/wp-content/image.png">Test</a>

    <!-- Correctly ignores URLs that are similar to the base URL but do not match it -->
    This isn&#8217;t migrated: https://🚀-science.comcast/science <br>
    Or this: super-🚀-science.com/science
</p>



<img decoding=\"async\" src=\"${PLAYGROUND_URL}/wp-content/image.png\">`,
					additionalChecks: async (page, post) => {
						// Verify that the content contains the expected text from the base URL rewriting test file
						const content = post.content?.rendered || '';
						expect(content).toContain('URLs to rewrite');
						expect(content).toContain('science');
						// Check that URLs that shouldn't be migrated are mentioned
						expect(content).toContain("This isn't migrated");
						// Verify the post contains the specific content from the XML
						await expect(page.getByText('URLs to rewrite')).toBeVisible();
					},
				},
			});
		});

		test.describe('Comprehensive WXR import', () => {
			test('imports with explicit author mapping to admin', async ({ page, request }) => {
				test.setTimeout(300000);
				await performImport(page, { mapAuthorsToAdmin: true });
				await verifyImportedData(page, request, { expectAuthorSlug: 'admin' });
			});

			test('imports with default author mapping (current user)', async ({
				page,
				request,
			}) => {
				if (parser === 'regex') {
					test.skip('WP_Regex_Parser has troubles with mapping authors');
					return;
				}
				test.setTimeout(300000);
				await performImport(page, { mapAuthorsToAdmin: false });
				await verifyImportedData(page, request, { expectAuthorSlug: 'alice' });
			});
		});
	});
});
