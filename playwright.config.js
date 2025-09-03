// Playwright configuration for running E2E tests against WordPress Playground
// Uses Playground CLI to serve WordPress with this plugin auto-mounted.

/* eslint-disable */
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: 'e2e',
	timeout: 120000,
	fullyParallel: false,
	reporter: [['list']],
	use: {
		baseURL: 'http://127.0.0.1:9400',
		headless: false,
	},
	webServer: {
		command: 'npx -y @wp-playground/cli@latest server --mount=./src:/wordpress/wp-content/plugins/wordpress-importer --blueprint=playground.blueprint.json --blueprint-may-read-adjacent-files --port 9400 --site-url=http://127.0.0.1:9400',
		port: 9400,
		reuseExistingServer: !process.env.CI,
		timeout: 180000,
	},
});


