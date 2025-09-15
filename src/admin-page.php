<?php
/**
 * WordPress Importer Results Administration Screen.
 *
 * @package WordPress_Importer
 * @subpackage Administration
 */

/** WordPress Administration Bootstrap */
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'Sorry, you are not allowed to access this page.', 'wordpress-importer' ), '', 403 );
}

$action = ! empty( $_REQUEST['action'] ) ? sanitize_text_field( $_REQUEST['action'] ) : '';

$tabs = array(
	/* translators: Tab heading for Import Results Status page. */
	''        => _x( 'Status', 'Import Results', 'wordpress-importer' ),
	/* translators: Tab heading for Import Results Details page. */
	'details' => _x( 'Details', 'Import Results', 'wordpress-importer' ),
);

/**
 * Filters the extra tabs for the Import Results navigation bar.
 *
 * Add a custom page to the Import Results screen, based on a tab slug and label.
 *
 * @since 0.9.0
 *
 * @param string[] $tabs An associative array of tab labels keyed by their slug.
 */
$tabs = apply_filters( 'wordpress_importer_navigation_tabs', $tabs );

$wrapper_classes = array(
	'import-results-tabs-wrapper',
	'hide-if-no-js',
	'tab-count-' . count( $tabs ),
);

$current_tab = ( isset( $_GET['tab'] ) ? $_GET['tab'] : '' );

$title = sprintf(
	// translators: %s: The currently displayed tab.
	__( 'Import Results - %s', 'wordpress-importer' ),
	( isset( $tabs[ $current_tab ] ) ? esc_html( $tabs[ $current_tab ] ) : esc_html( reset( $tabs ) ) )
);

// Set the page title for WordPress
$GLOBALS['title'] = __( 'Import Results', 'wordpress-importer' );
$GLOBALS['parent_file'] = 'tools.php';
$GLOBALS['submenu_file'] = 'wordpress-importer-results';

// Ensure screen options are properly set up
add_screen_option( 'layout_columns', array( 'max' => 2, 'default' => 2 ) );

$current_screen = get_current_screen();

// Debug current screen
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( 'Current screen ID: ' . $current_screen->id );
	error_log( 'Current screen base: ' . $current_screen->base );
	error_log( 'Current screen parent_base: ' . $current_screen->parent_base );
}

$current_screen->add_help_tab(
	array(
		'id'      => 'overview',
		'title'   => __( 'Overview' ),
		'content' =>
				'<p>' . __( 'This screen displays the results of your WordPress import operation.', 'wordpress-importer' ) . '</p>' .
				'<p>' . __( 'In the Status tab, you can see overall statistics about the import process, including success rates and any issues encountered.', 'wordpress-importer' ) . '</p>' .
				'<p>' . __( 'In the Details tab, you will find detailed information about each imported item, including posts, pages, comments, categories, and tags.', 'wordpress-importer' ) . '</p>',
	)
);

$current_screen->set_help_sidebar(
	'<p><strong>' . __( 'For more information:', 'wordpress-importer' ) . '</strong></p>' .
	'<p>' . __( '<a href="https://wordpress.org/documentation/article/importing-content/">Documentation on importing content</a>', 'wordpress-importer' ) . '</p>'
);

// Verify help tabs were added
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	$help_tabs = $current_screen->get_help_tabs();
	error_log( 'Help tabs count: ' . ( is_array( $help_tabs ) ? count( $help_tabs ) : 0 ) );
	if ( is_array( $help_tabs ) && !empty( $help_tabs ) && isset( $help_tabs[0]['id'] ) ) {
		error_log( 'First help tab ID: ' . $help_tabs[0]['id'] );
	}
}

require_once ABSPATH . 'wp-admin/admin-header.php';
?>

<div class="import-results-wrap" data-wp-interactive="wordpress-importer/results" data-wp-init="callbacks.init">
	<div class="import-results-header">
		<div class="import-results-title-section">
			<h1>
				<?php _e( 'Import Results', 'wordpress-importer' ); ?>
			</h1>
		</div>

		<nav class="<?php echo implode( ' ', $wrapper_classes ); ?>" aria-label="<?php esc_attr_e( 'Secondary menu' ); ?>">
			<?php
			foreach ( $tabs as $slug => $label ) {
				printf(
					'<a href="%s" class="import-results-tab %s" data-wp-on--click="actions.switchTab" data-wp-context=\'{"tab": "%s"}\'>%s</a>',
					esc_url(
						add_query_arg(
							array(
								'tab' => $slug,
							),
							admin_url( 'admin.php?page=wordpress-importer-results' )
						)
					),
					( $current_tab === $slug ? 'active' : '' ),
					esc_attr( $slug ),
					esc_html( $label )
				);
			}
			?>
		</nav>
	</div>

	<hr class="wp-header-end">

	<?php if ( isset( $_GET['tab'] ) && ! empty( $_GET['tab'] ) ) : ?>
		<?php
		/**
		 * Fires when outputting the content of a custom Import Results tab.
		 *
		 * This action fires right after the Import Results header.
		 *
		 * @since 0.9.0
		 *
		 * @param string $tab The slug of the tab that was requested.
		 */
		do_action( 'wordpress_importer_results_tab_content', $_GET['tab'] );
		?>
	<?php else : ?>
		<?php
		wp_admin_notice(
			__( 'The Import Results screen requires JavaScript.', 'wordpress-importer' ),
			array(
				'type'               => 'error',
				'additional_classes' => array( 'hide-if-js' ),
			)
		);
		?>

		<div class="import-results-body import-results-status-tab hide-if-no-js" data-wp-bind--hidden="state.currentTab !== 'status'">
			<div class="import-status-success hide" data-wp-bind--hidden="!context.importComplete || context.hasErrors">
				<p class="icon">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				</p>

				<p class="encouragement">
					<?php _e( 'Import Complete!', 'wordpress-importer' ); ?>
				</p>

				<p data-wp-text="context.successMessage">
					<?php _e( 'Your content has been successfully imported.', 'wordpress-importer' ); ?>
				</p>
			</div>

			<div class="import-status-has-results">
				<h2>
					<?php _e( 'Import Status', 'wordpress-importer' ); ?>
				</h2>

				<p><?php _e( 'Here you can see the results of your WordPress import operation.', 'wordpress-importer' ); ?></p>

				<div class="import-results-stats" data-wp-bind--hidden="!context.stats">
					<div class="stats-grid">
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.posts"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Posts', 'wordpress-importer' ); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.pages"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Pages', 'wordpress-importer' ); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.comments"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Comments', 'wordpress-importer' ); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.categories"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Categories', 'wordpress-importer' ); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.tags"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Tags', 'wordpress-importer' ); ?></span>
						</div>
						<div class="stat-item">
							<span class="stat-number" data-wp-text="context.stats.media"><?php _e( '0', 'wordpress-importer' ); ?></span>
							<span class="stat-label"><?php _e( 'Media', 'wordpress-importer' ); ?></span>
						</div>
					</div>
				</div>

				<div class="import-results-issues-wrapper" id="import-results-issues-errors" data-wp-bind--hidden="!context.hasErrors">
					<h3 class="import-issue-count-title">
						<span class="issue-count" data-wp-text="context.errorCount"><?php _e( '0', 'wordpress-importer' ); ?></span>
						<?php _e( 'errors encountered', 'wordpress-importer' ); ?>
					</h3>

					<p><?php _e( 'The following errors were encountered during the import process:', 'wordpress-importer' ); ?></p>

					<div id="import-results-errors" class="import-results-accordion issues">
						<div class="accordion-item">
							<h4 class="import-results-accordion-heading">
								<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
									<span class="title">Failed to import media file: image.jpg</span>
									<span class="badge error">Error</span>
									<span class="icon"></span>
								</button>
							</h4>
							<div class="import-results-accordion-panel" style="display: none;">
								<div>The media file could not be downloaded from the remote server. The file may not exist or the server may be unreachable.</div>
							</div>
						</div>
						<div class="accordion-item">
							<h4 class="import-results-accordion-heading">
								<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
									<span class="title">Duplicate post detected: "Sample Post"</span>
									<span class="badge error">Error</span>
									<span class="icon"></span>
								</button>
							</h4>
							<div class="import-results-accordion-panel" style="display: none;">
								<div>A post with the same title and content already exists. The duplicate post was skipped during import.</div>
							</div>
						</div>
					</div>
				</div>

				<div class="import-results-issues-wrapper" id="import-results-issues-warnings" data-wp-bind--hidden="!context.hasWarnings">
					<h3 class="import-issue-count-title">
						<span class="issue-count" data-wp-text="context.warningCount"><?php _e( '0', 'wordpress-importer' ); ?></span>
						<?php _e( 'warnings', 'wordpress-importer' ); ?>
					</h3>

					<p><?php _e( 'The following warnings were generated during the import process:', 'wordpress-importer' ); ?></p>

					<div id="import-results-warnings" class="import-results-accordion issues">
						<div class="accordion-item">
							<h4 class="import-results-accordion-heading">
								<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
									<span class="title">Missing featured image for post: "Welcome Post"</span>
									<span class="badge warning">Warning</span>
									<span class="icon"></span>
								</button>
							</h4>
							<div class="import-results-accordion-panel" style="display: none;">
								<div>The featured image reference could not be resolved. The post was imported without a featured image.</div>
							</div>
						</div>
						<div class="accordion-item">
							<h4 class="import-results-accordion-heading">
								<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
									<span class="title">Category mapping not found for: "Old Category"</span>
									<span class="badge warning">Warning</span>
									<span class="icon"></span>
								</button>
							</h4>
							<div class="import-results-accordion-panel" style="display: none;">
								<div>The category "Old Category" was not found and was created automatically during import.</div>
							</div>
						</div>
						<div class="accordion-item">
							<h4 class="import-results-accordion-heading">
								<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
									<span class="title">Author mapping incomplete</span>
									<span class="badge warning">Warning</span>
									<span class="icon"></span>
								</button>
							</h4>
							<div class="import-results-accordion-panel" style="display: none;">
								<div>Some posts were assigned to the current user because the original author could not be mapped.</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="import-results-view-more">
				<button type="button" class="button import-results-view-success" aria-expanded="false" data-wp-on--click="actions.toggleSuccessItems">
					<?php _e( 'Successfully imported items', 'wordpress-importer' ); ?>
					<span class="icon"></span>
				</button>
			</div>

			<div class="import-results-issues-wrapper" id="import-results-issues-success" data-wp-bind--hidden="!state.showSuccessItems">
				<h3 class="import-issue-count-title">
					<span class="issue-count" data-wp-text="state.successCount"><?php _e( '0', 'wordpress-importer' ); ?></span>
					<?php _e( 'items imported successfully', 'wordpress-importer' ); ?>
				</h3>

				<div id="import-results-success" class="import-results-accordion issues" data-wp-bind--hidden="!state.successItems.length">
					<!-- Sample success items for display -->
					<div class="accordion-item">
						<h4 class="import-results-accordion-heading">
							<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
								<span class="title">Post imported: "Getting Started with WordPress"</span>
								<span class="badge success">Success</span>
								<span class="icon"></span>
							</button>
						</h4>
						<div class="import-results-accordion-panel" style="display: none;">
							<div>Successfully imported post with 3 comments and featured image.</div>
						</div>
					</div>
					<div class="accordion-item">
						<h4 class="import-results-accordion-heading">
							<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
								<span class="title">Page imported: "About Us"</span>
								<span class="badge success">Success</span>
								<span class="icon"></span>
							</button>
						</h4>
						<div class="import-results-accordion-panel" style="display: none;">
							<div>Successfully imported page with custom fields and media attachments.</div>
						</div>
					</div>
					<div class="accordion-item">
						<h4 class="import-results-accordion-heading">
							<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
								<span class="title">Category imported: "Technology"</span>
								<span class="badge success">Success</span>
								<span class="icon"></span>
							</button>
						</h4>
						<div class="import-results-accordion-panel" style="display: none;">
							<div>Successfully imported category with description and meta data.</div>
						</div>
					</div>
					<div class="accordion-item">
						<h4 class="import-results-accordion-heading">
							<button aria-expanded="false" class="import-results-accordion-trigger" type="button" data-wp-on--click="actions.toggleAccordion">
								<span class="title">Media imported: "header-banner.png"</span>
								<span class="badge success">Success</span>
								<span class="icon"></span>
							</button>
						</h4>
						<div class="import-results-accordion-panel" style="display: none;">
							<div>Successfully downloaded and imported media file (2.3MB).</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="import-results-body import-results-details-tab hide-if-no-js" data-wp-bind--hidden="context.currentTab !== 'details'" style="display: none;">
			<h2><?php _e( 'Import Details', 'wordpress-importer' ); ?></h2>
			<p><?php _e( 'Detailed information about the import process will be displayed here.', 'wordpress-importer' ); ?></p>

			<div class="import-details-content" data-wp-text="context.detailsContent">
				<?php _e( 'Loading detailed import information...', 'wordpress-importer' ); ?>
			</div>
		</div>
	<?php endif; ?>
</div>

<?php
// Ensure the interactive script is loaded
wp_enqueue_script_module( '@wordpress/interactivity' );
wp_enqueue_script_module( '@wordpress-importer/results' );

/**
 * Initialize the WordPress Interactivity API context
 */
wp_interactivity_config( 'wordpress-importer/results', array(
	'apiEndpoint' => rest_url( 'wordpress-importer/v1/state' ),
	'nonce'       => wp_create_nonce( 'wp_rest' ),
) );

// Also add as JavaScript global for compatibility
wp_add_inline_script(
	'@wordpress-importer/results',
	'window.wpInteractivityConfig = window.wpInteractivityConfig || {};
	window.wpInteractivityConfig["wordpress-importer/results"] = ' . wp_json_encode( array(
		'apiEndpoint' => rest_url( 'wordpress-importer/v1/state' ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
	) ) . ';',
	'before'
);

require_once ABSPATH . 'wp-admin/admin-footer.php';