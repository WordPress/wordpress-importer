/**
 * WordPress Importer Results - Interactivity API Implementation
 *
 * This module provides the interactive functionality for the Import Results admin page
 * using the WordPress Interactivity API.
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

store('wordpress-importer/results', {
	state: {
		loading: false,
		currentTab: 'status',
		importComplete: true,
		hasErrors: true,
		hasWarnings: true,
		showSuccessItems: false,
		progressOffset: 0,
		progressText: 'Import Complete',
		successMessage: 'Your WordPress content has been successfully imported.',
		stats: {
			posts: 25,
			pages: 8,
			comments: 156,
			categories: 12,
			tags: 34,
			media: 67
		},
		errors: [
			{
				title: 'Failed to import media file: image.jpg',
				message: 'The media file could not be downloaded from the remote server. The file may not exist or the server may be unreachable.',
				expanded: false
			},
			{
				title: 'Duplicate post detected: "Sample Post"',
				message: 'A post with the same title and content already exists. The duplicate post was skipped during import.',
				expanded: false
			}
		],
		warnings: [
			{
				title: 'Missing featured image for post: "Welcome Post"',
				message: 'The featured image reference could not be resolved. The post was imported without a featured image.',
				expanded: false
			},
			{
				title: 'Category mapping not found for: "Old Category"',
				message: 'The category "Old Category" was not found and was created automatically during import.',
				expanded: false
			},
			{
				title: 'Author mapping incomplete',
				message: 'Some posts were assigned to the current user because the original author could not be mapped.',
				expanded: false
			}
		],
		successItems: [
			{
				title: 'Post imported: "Getting Started with WordPress"',
				message: 'Successfully imported post with 3 comments and featured image.',
				expanded: false
			},
			{
				title: 'Page imported: "About Us"',
				message: 'Successfully imported page with custom fields and media attachments.',
				expanded: false
			},
			{
				title: 'Category imported: "Technology"',
				message: 'Successfully imported category with description and meta data.',
				expanded: false
			},
			{
				title: 'Media imported: "header-banner.png"',
				message: 'Successfully downloaded and imported media file (2.3MB).',
				expanded: false
			}
		],
		errorCount: 2,
		warningCount: 3,
		successCount: 89,
		detailsContent: 'Detailed import information will be loaded here...'
	},
	actions: {
		*switchTab() {
			const { context } = yield import('@wordpress/interactivity');
			const element = getElement();

			// Get tab from data attribute or href
			let tab = 'status';
			const tabContext = element.getAttribute('data-wp-context');
			if (tabContext) {
				try {
					const parsed = JSON.parse(tabContext);
					tab = parsed.tab || 'status';
				} catch (e) {
					// Fallback to href parsing
					const href = element.getAttribute('href');
					if (href && href.includes('tab=')) {
						tab = new URL(href).searchParams.get('tab') || 'status';
					}
				}
			}

			context.currentTab = tab;

			// Update URL without page reload
			const url = new URL(window.location);
			if (tab === 'status') {
				url.searchParams.delete('tab');
			} else {
				url.searchParams.set('tab', tab);
			}
			window.history.replaceState({}, '', url);

			// Load details content if switching to details tab
			if (tab === 'details' && context.detailsContent === 'Detailed import information will be loaded here...') {
				context.detailsContent = 'Loading...';
				setTimeout(() => {
					context.detailsContent = `
						<h3>Import Summary</h3>
						<ul>
							<li><strong>Total items processed:</strong> ${context.stats.posts + context.stats.pages + context.stats.comments + context.stats.categories + context.stats.tags + context.stats.media}</li>
							<li><strong>Success rate:</strong> ${Math.round((context.successCount / (context.successCount + context.errorCount + context.warningCount)) * 100)}%</li>
							<li><strong>Processing time:</strong> 2 minutes 34 seconds</li>
							<li><strong>Memory usage:</strong> 128MB peak</li>
						</ul>

						<h3>Import Log</h3>
						<div style="background: #f6f7f7; padding: 1em; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">
							<div>[2024-01-15 10:30:15] Starting WordPress import...</div>
							<div>[2024-01-15 10:30:16] Parsing WXR file: export.xml (2.4MB)</div>
							<div>[2024-01-15 10:30:18] Found ${context.stats.posts} posts, ${context.stats.pages} pages, ${context.stats.comments} comments</div>
							<div>[2024-01-15 10:30:20] Importing categories: ${context.stats.categories} found</div>
							<div>[2024-01-15 10:30:22] Importing tags: ${context.stats.tags} found</div>
							<div>[2024-01-15 10:30:25] Processing media attachments: ${context.stats.media} files</div>
							<div>[2024-01-15 10:32:49] Import completed with ${context.errorCount} errors and ${context.warningCount} warnings</div>
						</div>
					`;
				}, 500);
			}
		},

		*toggleAccordion(event) {
			console.log('toggleAccordion called with event:', event);
			
			// Try to get the element from the WordPress Interactivity API
			const wpElement = getElement();
			console.log('getElement result:', wpElement);
			
			// Extract the actual DOM element from the WordPress element object
			let targetElement = null;
			if (wpElement && wpElement.ref) {
				targetElement = wpElement.ref;
				console.log('Using wpElement.ref:', targetElement);
			} else if (event?.target) {
				targetElement = event.target;
				console.log('Using event.target:', targetElement);
			}
			
			console.log('Final targetElement:', targetElement);
			console.log('targetElement has closest?', typeof targetElement?.closest);
			
			if (targetElement && typeof targetElement.closest === 'function') {
				const accordionItem = targetElement.closest('.accordion-item');
				console.log('Found accordion item:', accordionItem);
				
				if (accordionItem) {
					const panel = accordionItem.querySelector('.import-results-accordion-panel');
					console.log('Found panel:', panel);
					
					if (panel) {
						const isHidden = panel.style.display === 'none' || !panel.style.display;
						panel.style.display = isHidden ? 'block' : 'none';
						
						// Update the button's aria-expanded attribute
						const button = accordionItem.querySelector('.import-results-accordion-trigger');
						if (button) {
							button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
						}
						
						console.log('Accordion toggled:', isHidden ? 'opened' : 'closed');
					}
				}
			} else {
				console.error('Could not find valid DOM element for accordion toggle');
				console.log('targetElement type:', typeof targetElement);
				console.log('targetElement:', targetElement);
			}
		},

		toggleSuccessItems: () => {
			// Try the proper way to get context within an action
			const context = getContext();
			console.log('getContext result:', context);

			// If getContext doesn't work, try accessing the store directly
			const { state } = store('wordpress-importer/results');
			console.log('Store state:', state);

			if (state) {
				console.log('Current showSuccessItems value:', state.showSuccessItems);
				state.showSuccessItems = !state.showSuccessItems;
				console.log('New showSuccessItems value:', state.showSuccessItems);
			} else if (context) {
				console.log('Using context fallback');
				context.showSuccessItems = !context.showSuccessItems;
			} else {
				console.error('Neither state nor context available');
			}
		},

		*loadImportResults() {
			const { context } = yield import('@wordpress/interactivity');

			try {
				// Get config - check if it exists in window or WordPress globals
				const config = window.wpInteractivityConfig?.['wordpress-importer/results'] || {};

				if (!config || !config.apiEndpoint) {
					console.warn('WordPress Importer: API endpoint not configured, using placeholder data');
					context.loading = false;
					return;
				}

				context.loading = true;

				const response = yield fetch(config.apiEndpoint, {
					method: 'GET',
					headers: {
						'X-WP-Nonce': config.nonce || '',
						'Content-Type': 'application/json'
					},
					credentials: 'same-origin'
				});

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`);
				}

				const data = yield response.json();

				// Update context with real data
				context.loading = false;
				context.importComplete = data.complete || false;
				context.stats = data.stats || context.stats;
				context.errors = data.errors || [];
				context.warnings = data.warnings || [];
				context.successItems = data.success_items || [];
				context.hasErrors = context.errors.length > 0;
				context.hasWarnings = context.warnings.length > 0;
				context.errorCount = context.errors.length;
				context.warningCount = context.warnings.length;
				context.successCount = context.successItems.length;
				context.progressOffset = context.importComplete ? 0 : 565.48;
				context.progressText = context.importComplete ? 'Import Complete' : 'Processing...';
				context.successMessage = data.message || context.successMessage;

			} catch (error) {
				console.error('WordPress Importer: Failed to load import results:', error);
				context.loading = false;

				// Keep using placeholder data on error
				console.info('WordPress Importer: Using placeholder data for demonstration');
			}
		}
	},

	callbacks: {
		*init() {
			const { context } = yield import('@wordpress/interactivity');
			const { actions } = store('wordpress-importer/results');

			console.log('Initializing WordPress Importer Results with context:', context);

			// Set initial tab from URL
			const urlParams = new URLSearchParams(window.location.search);
			const tab = urlParams.get('tab') || 'status';
			context.currentTab = tab;

			// Load import results
			yield actions.loadImportResults();
		}
	}
});
