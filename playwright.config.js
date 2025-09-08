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
		// Base URL is provided per-test by the Playground fixture
		headless: true,
	},
	projects: [
		{
			name: 'chrome',
			use: {
				channel: 'chrome',
			},
		},
	],
	// Server is started per-test via @wp-playground/cli runCLI()
});
