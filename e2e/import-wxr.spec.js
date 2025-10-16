// E2E test: import WXR files using a fresh Playground instance per test
// Import from 'playwright/test' to ensure it matches the npx runner version
const { test, expect } = require('playwright/test');
const { runCLI } = require('@wp-playground/cli');
const path = require('path');
const net = require('net');
const http = require('http');
const fs = require('fs');

// Define available parsers
const PARSERS = process.env.PARSER ? [process.env.PARSER] : ['simplexml', 'xml', 'regex', 'xmlprocessor'];
let PLAYGROUND_URL = '';
// Run tests for each parser
PARSERS.forEach((parser) => {
	test.describe(`WXR Import with ${parser} parser`, () => {
		test(`imports a simple WXR file using ${parser} parser`, async ({ page, request }) => {
			await withPlaygroundServer(
				async () => {
					// Run the import
					await runWxrImport(page, 'wxr-simple.xml');

					// Get posts (edit context to access raw block markup) and find the imported one
					const posts = await getPostsEdit(page, 'Road Not Taken');
					expect(posts.length).toBeGreaterThan(0);
					const post = findPostByTitle(posts, 'The Road Not Taken');

					// Verify post data
					const normalized = normalizePostData(post);
					expect(normalized).toMatchObject({
						status: 'publish',
						type: 'post',
						sticky: false,
						title: expect.stringContaining('The Road Not Taken'),
						slug: expect.stringMatching(/^hello-world/),
						datePrefix: '2024-06-05',
						authorSlug: 'admin',
						categories: expect.arrayContaining(['uncategorized']),
						comment_status: expect.stringMatching(/^(open|closed)$/),
						ping_status: expect.stringMatching(/^(open|closed)$/),
					});

					// Compare raw block markup with tolerant normalization (<br> vs <br />, minor whitespace)
					const simpleExpected = `<!-- wp:paragraph -->
<p>Two roads diverged in a yellow wood,<br>And sorry I could not travel both</p>
<!-- /wp:paragraph -->

<!-- Test if self-closing blocks remain self-closing after URL rewriting. -->
<!-- wp:navigation-link {"url":"${PLAYGROUND_URL}/one"} /-->

<!-- wp:paragraph -->
<p>
<a href="${PLAYGROUND_URL}/one">One</a> seemed great, but <a href="https://playground.internal/path-not-taken">the other</a> seemed great too.
There was also a <a href="https://w.org">third</a> option, but it was not as great.

${PLAYGROUND_URL.slice('http://'.length)}/one was the best choice.
https://playground.internal/path-not-taken was the second best choice.
</p>
<!-- /wp:paragraph -->`;
					expect(normalizeBlockMarkup(normalized.rawContent)).toContain(
						normalizeBlockMarkup(simpleExpected)
					);

					// Verify frontend rendering
					await goToPostFrontend(page, post);
					await expect(
						page.getByText('Two roads diverged in a yellow wood')
					).toBeVisible();
					await expect(page.getByRole('link', { name: 'One' })).toBeVisible();
					await expect(page.locator('a[href="https://w.org"]')).toBeVisible();
				},
				{ parser }
			);
		});

		test(`imports a base URL rewriting WXR file using ${parser} parser`, async ({
			page,
			request,
		}) => {
			await withPlaygroundServer(
				async () => {
					// Run the import
					await runWxrImport(page, 'wxr-base-url-rewriting.xml');

					// Get posts and find the imported one
					const posts = await getPostsEdit(page, 'Road Not Taken');
					expect(posts.length).toBeGreaterThan(0);
					const post = findPostByTitle(posts, 'The Road Not Taken');

					// Verify post data
					const normalized = normalizePostData(post);
					expect(normalized).toMatchObject({
						status: 'publish',
						type: 'post',
						sticky: false,
						title: expect.stringContaining('The Road Not Taken'),
						slug: expect.stringMatching(/^hello-world/),
						datePrefix: '2024-06-05',
						authorSlug: 'admin',
						categories: expect.arrayContaining(['uncategorized']),
						comment_status: expect.stringMatching(/^(open|closed)$/),
						ping_status: expect.stringMatching(/^(open|closed)$/),
					});

					// Compare raw block markup with tolerant normalization (<br> vs <br />, minor whitespace)
					const baseUrlExpected = `<!-- wp:paragraph -->
<p>
    <!-- Rewrites URLs that match the base URL -->
    URLs to rewrite:

    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}
    ${PLAYGROUND_URL}/
    <a href=\"${PLAYGROUND_URL}/wp-content/image.png\">Test</a>

    <!-- Correctly ignores URLs that are similar to the base URL but do not match it -->
    This isn't migrated: https://🚀-science.comcast/science <br>
    Or this: super-🚀-science.com/science
</p>
<!-- /wp:paragraph -->

<!-- wp:image {"alt":"${PLAYGROUND_URL}/wp-content/image.png","notUrl":"/science/wp-content/image.png","url":"/wp-content/image.png"} -->
<img src="${PLAYGROUND_URL}/wp-content/image.png">
<!-- /wp:image -->`;
					expect(normalizeBlockMarkup(normalized.rawContent)).toContain(
						normalizeBlockMarkup(baseUrlExpected)
					);

					// Verify frontend rendering
					await goToPostFrontend(page, post);
					await expect(page.getByText('URLs to rewrite')).toBeVisible();
				},
				{ parser }
			);
		});

		test(`imports a large 10MB WXR file successfully`, async ({ page }) => {
			await withPlaygroundServer(async () => {
				test.setTimeout(600000);
				await goToImporter(page);
				const wxrPath = path.resolve(__dirname, '../phpunit/data/10MB.xml');
				const fileInput = page.locator('#upload, input[type="file"][name="import"]');
				await fileInput.waitFor({ state: 'visible' });
				await fileInput.setInputFiles(wxrPath);
				await page.getByRole('button', { name: /Upload file and import/i }).click();
				await page.waitForURL('**/admin.php?import=wordpress&step=1**', {
					waitUntil: 'domcontentloaded',
					timeout: 120000,
				});
				await page.getByRole('button', { name: /^Submit$/i }).click();
				await page.waitForURL('**/admin.php?import=wordpress&step=2**', {
					waitUntil: 'domcontentloaded',
					timeout: 300000,
				});
				await expect(page.locator('text=All done. Have fun!')).toBeVisible();
				await expect(
					page.locator(
						'text=Remember to update the passwords and roles of imported users.'
					)
				).toBeVisible();
				await expect(page.locator('a[href$="/wp-admin/"]')).toBeVisible();
			});
		});

		test.describe('Comprehensive WXR import', () => {
			test('imports with explicit author mapping to admin', async ({ page, request }) => {
				await withPlaygroundServer(
					async () => {
						test.setTimeout(300000);
						await performImport(page, { mapAuthorsToAdmin: true });
						await verifyImportedData(page, request, { expectAuthorSlug: 'admin' });
					},
					{ parser }
				);
			});

			test('imports with default author mapping (current user)', async ({
				page,
				request,
			}) => {
				await withPlaygroundServer(
					async () => {
						if (parser === 'regex') {
							test.skip('WP_Regex_Parser has troubles with mapping authors');
							return;
						}
						test.setTimeout(300000);
						await performImport(page, { mapAuthorsToAdmin: false });
						await verifyImportedData(page, request, { expectAuthorSlug: 'alice' });
					},
					{ parser }
				);
			});
		});
	});
});

test.describe('General tests', () => {
	test(`URLs are not rewritten when the checkbox is unchecked`, async ({ page, request }) => {
		// Run the import
		await withPlaygroundServer(async () => {
			await runWxrImport(page, 'wxr-simple.xml', { rewriteUrls: false });

			// Get posts (edit context to access raw block markup) and find the imported one
			const posts = await getPostsEdit(page, 'Road Not Taken');
			expect(posts.length).toBeGreaterThan(0);
			const post = findPostByTitle(posts, 'The Road Not Taken');

			// Verify post data
			const normalized = normalizePostData(post);
			expect(normalized).toMatchObject({
				status: 'publish',
				type: 'post',
				sticky: false,
				title: expect.stringContaining('The Road Not Taken'),
				slug: expect.stringMatching(/^hello-world/),
				datePrefix: '2024-06-05',
				authorSlug: 'admin',
				categories: expect.arrayContaining(['uncategorized']),
				comment_status: expect.stringMatching(/^(open|closed)$/),
				ping_status: expect.stringMatching(/^(open|closed)$/),
			});

			// Compare raw block markup with tolerant normalization (<br> vs <br />, minor whitespace)
			const simpleExpected = `<!-- wp:paragraph -->
<p>Two roads diverged in a yellow wood,<br>And sorry I could not travel both</p>
<!-- /wp:paragraph -->

<!-- Test if self-closing blocks remain self-closing after URL rewriting. -->
<!-- wp:navigation-link {\"url\":\"https://playground.internal/path/one\"} /-->

<!-- wp:paragraph -->
<p>
<a href="https://playground.internal/path/one">One</a> seemed great, but <a href="https://playground.internal/path-not-taken">the other</a> seemed great too.
There was also a <a href="https://w.org">third</a> option, but it was not as great.

playground.internal/path/one was the best choice.
https://playground.internal/path-not-taken was the second best choice.
</p>
<!-- /wp:paragraph -->`;
			expect(normalizeBlockMarkup(normalized.rawContent)).toContain(
				normalizeBlockMarkup(simpleExpected)
			);

			// Verify frontend rendering
			await goToPostFrontend(page, post);
			await expect(page.getByText('Two roads diverged in a yellow wood')).toBeVisible();
			await expect(page.getByRole('link', { name: 'One' })).toBeVisible();
			await expect(page.locator('a[href="https://w.org"]')).toBeVisible();
		});
	});

	test.only('imports a11y-unit-test-data.xml with media assets downloaded', async ({ page }) => {
		await withPlaygroundServer(async () => {
			test.setTimeout(600000); // 10 minutes for large file with media downloads

			// Expected media files based on the a11y-unit-test-data.xml content
			const expectedMediaFiles = [
				'2008/06/canola2.jpg',
				'2008/06/100_5478.jpg',
				'2008/06/100_5540.jpg',
				'2008/06/cep00032.jpg',
				'2008/06/dcp_2082.jpg',
				'2008/06/dsc03149.jpg',
				'2008/06/dsc04563.jpg',
				'2008/06/dsc09114.jpg',
				'2008/06/dsc20050727_091048_222.jpg',
				'2008/06/dsc20050813_115856_52.jpg',
				'2008/06/dsc20050102_192118_51.jpg',
				'2008/06/dsc20051220_160808_102.jpg',
				'2008/06/dsc02085.jpg',
				'2008/06/dsc20051220_173257_119.jpg',
				'2008/06/dscn3316.jpg',
				'2008/06/michelle_049.jpg',
				'2008/06/windmill.jpg',
				'2008/06/img_0513-1.jpg',
				'2008/06/img_0747.jpg',
				'2008/06/img_0767.jpg',
				'2008/06/img_8399.jpg',
				'2008/06/dsc20050604_133440_34211.jpg',
				'2014/01/spectacles.gif',
			];

			// Navigate to importer
			await goToImporter(page);

			// Upload the a11y test data file
			const wxrPath = path.resolve(__dirname, './fixtures/a11y-unit-test-data.xml');
			const fileInput = page.locator('#upload, input[type="file"][name="import"]');
			await fileInput.waitFor({ state: 'visible' });
			await fileInput.setInputFiles(wxrPath);

			// Click upload button
			await page.getByRole('button', { name: /Upload file and import/i }).click();

			// Wait for author mapping screen
			await page.waitForURL('**/admin.php?import=wordpress&step=1**', {
				waitUntil: 'domcontentloaded',
				timeout: 120000,
			});

			// Enable attachment downloads
			const attachmentsCheckbox = page.locator('input[name="fetch_attachments"]');
			if (await attachmentsCheckbox.count()) {
				await attachmentsCheckbox.check();
			}

			// Submit to start import
			await page.getByRole('button', { name: /^Submit$/i }).click();

			// Wait for import to complete
			await page.waitForURL('**/admin.php?import=wordpress&step=2**', {
				waitUntil: 'domcontentloaded',
				timeout: 300000, // 5 minutes for import with media downloads
			});

			// Verify import success
			await expect(page.locator('text=All done.')).toBeVisible();

			// Go to media library
			await page.goto(abs('/wp-admin/upload.php'));
			await loginIfNeeded(page);

			// Switch to grid view if not already
			const gridViewButton = page.locator('a[href*="mode=grid"]');
			if (await gridViewButton.count()) {
				await gridViewButton.click();
				await page.waitForURL('**/upload.php?mode=grid**');
			}

			// Wait for media items to load
			await page.waitForSelector('.attachment', { timeout: 30000 });

			// Verify we have media items
			const mediaItems = page.locator('.attachment');
			const mediaCount = await mediaItems.count();
			console.log(`Found ${mediaCount} media items in library`);
			expect(mediaCount).toBeGreaterThanOrEqual(expectedMediaFiles.length - 1); // At least most files

			// Verify specific images are visible in the gallery
			// Check for a few key images by their alt text
			await expect(page.locator('img[alt="canola"]')).toBeVisible();
			await expect(page.locator('img[alt="Bell on Wharf"]')).toBeVisible();
			await expect(page.locator('img[alt="Golden Gate Bridge"]')).toBeVisible();
			await expect(page.locator('img[alt="Boardwalk"]')).toBeVisible();

			// Take screenshot of the media gallery for visual verification
			await page.screenshot({
				path: path.join(
					__dirname,
					'import-wxr.spec.js-snapshots',
					'a11y-media-gallery.png'
				),
				fullPage: true,
			});

			// Check file system for uploaded files using WordPress REST API
			// First get all media items via REST API
			await page.goto(abs('/wp-admin/')); // Ensure we're authenticated
			const mediaResponse = await page.request.get(
				abs('/wp-json/wp/v2/media?per_page=100&context=edit'),
				{
					headers: {
						'X-WP-Nonce': await page.evaluate(() => {
							return window.wpApiSettings?.nonce || '';
						}),
					},
				}
			);

			expect(mediaResponse.ok()).toBeTruthy();
			const mediaData = await mediaResponse.json();

			// Verify each expected file has a corresponding media item
			const uploadedFiles = mediaData
				.map((item) => {
					const sourceUrl = item.source_url || '';
					const match = sourceUrl.match(/uploads\/(.+)$/);
					return match ? match[1] : '';
				})
				.filter(Boolean);

			console.log(`Uploaded files found: ${uploadedFiles.length}`);
			console.log('Sample uploaded files:', uploadedFiles.slice(0, 5));

			// Check that most expected files were uploaded (allow for some failures)
			let matchedFiles = 0;
			for (const expectedFile of expectedMediaFiles) {
				if (
					uploadedFiles.some((uploaded) =>
						uploaded.includes(expectedFile.split('/').pop())
					)
				) {
					matchedFiles++;
				}
			}

			console.log(
				`Matched ${matchedFiles} out of ${expectedMediaFiles.length} expected files`
			);
			expect(matchedFiles).toBeGreaterThanOrEqual(expectedMediaFiles.length * 0.8); // At least 80% success

			// Verify images load properly by checking a few
			const firstMediaItem = mediaItems.first();
			await firstMediaItem.click();

			// Wait for media modal to open
			await page.waitForSelector('.media-modal', { timeout: 10000 });

			// Check that attachment details are visible
			await expect(page.locator('.attachment-details')).toBeVisible();
			await expect(page.locator('.details-image, .details-media')).toBeVisible();

			// Close modal
			await page.keyboard.press('Escape');
			await page.waitForSelector('.media-modal', { state: 'hidden' });

			// Additional check: Go to a post with images to verify they display
			const postsResponse = await page.request.get(
				abs('/wp-json/wp/v2/posts?per_page=10&search=Image%20Alignment')
			);
			const posts = await postsResponse.json();

			if (posts.length > 0) {
				// Visit the first image post
				await page.goto(posts[0].link);

				// Check that images in content are loading
				const contentImages = page.locator('.entry-content img, article img');
				if ((await contentImages.count()) > 0) {
					// Wait for at least one image to be fully loaded
					await expect(contentImages.first()).toBeVisible();

					// Verify image has proper src attribute
					const imgSrc = await contentImages.first().getAttribute('src');
					expect(imgSrc).toBeTruthy();
					expect(imgSrc).toContain('wp-content/uploads');
				}
			}
		});
	});
});

// Helpers

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

async function withPlaygroundServer(fn, { parser } = {}) {
	const server = await startPlayground(parser);
	try {
		PLAYGROUND_URL = server.url;
		await fn();
	} finally {
		await server.stop();
	}
}

async function startPlayground(parser = null) {
	const pluginSrc = path.resolve(__dirname, '../src');
	const muPluginsSrc = path.resolve(__dirname, './helpers/mu-plugins');
	const blueprint = JSON.parse(
		fs.readFileSync(path.resolve(__dirname, './playground.blueprint.json'), 'utf8')
	);
	const port = await getAvailablePort();
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

	const { server, playground } = await runCLI(blueprintConfig);

	await waitUntilAlive(`${siteUrl}/wp-admin/`);

	const stop = async () => {
		try {
			await server[Symbol.asyncDispose]();
		} catch (e) {
			console.error(e);
		}
	};

	return { url: siteUrl, stop };
}

function abs(u) {
	return `${PLAYGROUND_URL}${u}`;
}

// Helper: Run WXR import process
async function runWxrImport(page, filename, { rewriteUrls = true } = {}) {
	// Extra time for CI/Playground
	test.setTimeout(240000);

	// Navigate to wp-admin and ensure login
	await page.goto(abs('/wp-admin/'));
	await loginIfNeeded(page);

	// Go directly to the importer screen
	await page.goto(abs('/wp-admin/admin.php?import=wordpress'));
	await loginIfNeeded(page);

	// Upload the WXR file
	const wxrPath = path.resolve(__dirname, `./fixtures/${filename}`);
	const fileInput = page.locator('#upload, input[type="file"][name="import"]');
	await fileInput.waitFor({ state: 'visible' });
	await fileInput.setInputFiles(wxrPath);

	await page.getByRole('button', { name: /Upload file and import/i }).click();

	// Submit the upload form (step=1: author mapping)
	await page.waitForURL('**/admin.php?import=wordpress&step=1**', {
		waitUntil: 'domcontentloaded',
	});

	if (rewriteUrls) {
		await page.check('#rewrite-urls');
	} else {
		await page.uncheck('#rewrite-urls');
	}

	// Proceed to step=2 (author mapping defaults to current user)
	await page.getByRole('button', { name: /^Submit$/i }).click();
	await page.waitForURL('**/admin.php?import=wordpress&step=2**', {
		waitUntil: 'domcontentloaded',
	});

	// Verify import success
	await expect(page.locator('text=All done.')).toBeVisible();
	await expect(page.locator('a[href$="/wp-admin/"]')).toBeVisible();
}

// Helper: Get posts via REST API
async function getPosts(request, searchTerm = '', perPage = 10) {
	const searchParam = searchTerm ? `&search=${encodeURIComponent(searchTerm)}` : '';
	const res = await request.get(
		abs(`/wp-json/wp/v2/posts?_embed=1${searchParam}&per_page=${perPage}`)
	);
	expect(res.ok()).toBeTruthy();
	const posts = await res.json();
	expect(Array.isArray(posts)).toBeTruthy();
	return posts;
}

// Helper: Get posts via REST API with context=edit (raw content)
async function getPostsEdit(page, searchTerm = '', perPage = 10) {
	// Ensure we're on admin to access a REST nonce
	await page.goto(abs('/wp-admin/'));
	await loginIfNeeded(page);

	// Try to read REST API nonce from the admin page
	const nonce = await page.evaluate(() => {
		return (
			(window && window.wpApiSettings && window.wpApiSettings.nonce) ||
			(document.querySelector('meta[name="_wpnonce"]') &&
				document.querySelector('meta[name="_wpnonce"]').getAttribute('content')) ||
			(document.querySelector('meta[name="x-wp-nonce"]') &&
				document.querySelector('meta[name="x-wp-nonce"]').getAttribute('content')) ||
			(document.querySelector('meta[name="wp-rest-nonce"]') &&
				document.querySelector('meta[name="wp-rest-nonce"]').getAttribute('content')) ||
			''
		);
	});

	const searchParam = searchTerm ? `&search=${encodeURIComponent(searchTerm)}` : '';
	const url = abs(`/wp-json/wp/v2/posts?_embed=1${searchParam}&per_page=${perPage}&context=edit`);
	const headers = nonce ? { 'X-WP-Nonce': nonce } : {};
	const res = await page.request.get(url, { headers });
	if (!res.ok()) {
		const bodyText = await res.text();
		throw new Error(`Failed to fetch posts with context=edit: ${res.status()} ${bodyText}`);
	}
	const posts = await res.json();
	expect(Array.isArray(posts)).toBeTruthy();
	return posts;
}

// Helper: Find post by title
function findPostByTitle(posts, titleContains) {
	const post = posts.find((p) => p?.title?.rendered?.includes(titleContains));
	expect(post, `Post not found with title containing: ${titleContains}`).toBeTruthy();
	return post;
}

// Helper: Normalize post data for testing
function normalizePostData(post) {
	const author = post?._embedded?.author?.[0];
	const embeddedTerms = (post?._embedded?.['wp:term'] || []).flat().filter(Boolean);
	const categories = embeddedTerms.filter((t) => t.taxonomy === 'category');

	return {
		status: post.status,
		type: post.type,
		sticky: !!post.sticky,
		title: post.title?.rendered || '',
		slug: post.slug || '',
		datePrefix: (post.date_gmt || '').slice(0, 10),
		content: post.content?.rendered || '',
		rawContent: post.content?.raw ?? post.content?.rendered ?? '',
		authorSlug: author?.slug,
		categories: categories.map((t) => (t.slug || t.name || '').toString().toLowerCase()),
		comment_status: post.comment_status,
		ping_status: post.ping_status,
	};
}

// Helper: Normalize block markup for robust comparisons
// TODO: Do not use regexps. Actually parse the HTML.
function normalizeBlockMarkup(s) {
	return (
		String(s)
			// Normalize <br>, <br/> and <br /> to a single form
			.replace(/<br\s*\/?>(?=)|<br\s*\/?>(?!)/gi, '<br>')
			// Remove visible dot characters that may appear in debug renderings
			.replace(/\u00B7/g, '')
			// Trim trailing spaces on lines
			.replace(/[\t ]+$/gm, '')
			// Collapse multiple blank lines
			.replace(/\n{3,}/g, '\n\n')
	);
}

// Helper: Verify post in admin list
async function verifyPostInAdminList(page, titleContains) {
	await page.goto(abs('/wp-admin/edit.php'));
	await loginIfNeeded(page);

	const row = page.locator('table.wp-list-table tbody tr', {
		hasText: titleContains,
	});
	await expect(row).toHaveCount(1);
	await expect(row.locator('.row-title')).toContainText(titleContains);
	await expect(row).toContainText('admin');
}

// Helper: Navigate to post frontend
async function goToPostFrontend(page, post) {
	expect(typeof post.link).toBe('string');
	await page.goto(post.link);
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
