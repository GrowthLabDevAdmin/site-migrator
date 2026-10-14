<?php
/**
 * Screen under Tools → Site Migrator and AJAX endpoints.
 * Migration runs one page per request and one image per request to avoid timeouts.
 */

defined( 'ABSPATH' ) || exit;

class Site_Migrator_Admin {

	const SLUG   = 'site-migrator';
	const OPTION = 'site_migrator_last_options';
	const NONCE  = 'site_migrator';

	private $hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		foreach ( array( 'collect', 'preview', 'import_page', 'import_image' ) as $action ) {
			add_action( 'wp_ajax_sm_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	public function menu() {
		$this->hook = add_management_page( 'Site Migrator', 'Site Migrator', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'site-migrator', SITE_MIGRATOR_URL . 'assets/admin.css', array(), SITE_MIGRATOR_VERSION );
		wp_enqueue_script( 'site-migrator', SITE_MIGRATOR_URL . 'assets/admin.js', array(), SITE_MIGRATOR_VERSION, true );
		wp_localize_script( 'site-migrator', 'SiteMigrator', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => $this->js_strings(),
		) );
	}

	/** Strings used by admin.js. Placeholders: %s (in order) or %1$s, %2$s… (by position). */
	private function js_strings() {
		return array(
			/* translators: %s: technical error message. */
			'noResponse'      => __( 'No response from the server (%s).', 'site-migrator' ),
			/* translators: %s: HTTP status code. */
			'invalidResponse' => __( 'Invalid server response (HTTP %s). It may be a timeout or a PHP error; check the server log.', 'site-migrator' ),
			'unknownError'    => __( 'Unknown error.', 'site-migrator' ),
			'urlCountOne'     => __( '1 URL in the list', 'site-migrator' ),
			/* translators: %s: number of URLs. */
			'urlCountMany'    => __( '%s URLs in the list', 'site-migrator' ),
			'noUrls'          => __( 'No URLs found with those filters. Check the sitemap or the include and exclude expressions.', 'site-migrator' ),
			/* translators: %s: parent post ID. */
			'childOf'         => __( '(child of post ID %s)', 'site-migrator' ),
			'empty'           => __( 'Empty', 'site-migrator' ),
			'none'            => __( 'None', 'site-migrator' ),
			/* translators: %s: image alt text. */
			'alt'             => __( '(alt: %s)', 'site-migrator' ),
			'noAlt'           => __( '(no alt)', 'site-migrator' ),
			'cssBackground'   => __( '(CSS background)', 'site-migrator' ),
			/* translators: %s: number of URLs. */
			'confirmPublish'  => __( '%s URLs will be migrated and published immediately. Continue?', 'site-migrator' ),
			/* translators: %s: number of URLs. */
			'confirm'         => __( '%s URLs will be migrated. Continue?', 'site-migrator' ),
			/* translators: 1: current number, 2: total, 3: URL. */
			'migrating'       => __( 'Migrating %1$s of %2$s: %3$s', 'site-migrator' ),
			/* translators: %s: error message. */
			'imageError'      => __( 'Image: %s', 'site-migrator' ),
			/* translators: %s: error message. */
			'featuredError'   => __( 'Featured image: %s', 'site-migrator' ),
			/* translators: 1: images imported, 2: total images. */
			'imagesOf'        => __( '%1$s of %2$s', 'site-migrator' ),
			/* translators: %s: number of failed images. */
			'imagesFailed'    => __( '(%s failed)', 'site-migrator' ),
			/* translators: 1: created, 2: updated, 3: skipped, 4: errors. */
			'summary'         => __( '%1$s created, %2$s updated, %3$s skipped, %4$s with errors.', 'site-migrator' ),
			/* translators: 1: processed URLs, 2: total URLs, 3: summary. */
			'stopped'         => __( 'Migration stopped after %1$s of %2$s URLs: %3$s', 'site-migrator' ),
			/* translators: %s: summary. */
			'finished'        => __( 'Migration finished: %s', 'site-migrator' ),
			'stopping'        => __( 'Stopping after the current image finishes…', 'site-migrator' ),
			/* translators: %s: number of URLs. */
			'confirmImages'   => __( '%s URLs will be scanned and their images uploaded to the Media Library. Continue?', 'site-migrator' ),
			/* translators: 1: pages processed, 2: images imported, 3: images failed, 4: pages with errors. */
			'summaryImages'   => __( '%1$s pages processed, %2$s images imported, %3$s failed, %4$s pages with errors.', 'site-migrator' ),
			'status'          => array(
				'created' => __( 'Created', 'site-migrator' ),
				'updated' => __( 'Updated', 'site-migrator' ),
				'skipped'   => __( 'Skipped', 'site-migrator' ),
				'processed' => __( 'Processed', 'site-migrator' ),
				'error'     => __( 'Error', 'site-migrator' ),
			),
			'csvHeaders'      => array(
				__( 'original_url', 'site-migrator' ),
				__( 'original_path', 'site-migrator' ),
				'post_id',
				__( 'new_path', 'site-migrator' ),
				__( 'status', 'site-migrator' ),
				__( 'images_ok', 'site-migrator' ),
				__( 'images_not_migrated', 'site-migrator' ),
				__( 'notes', 'site-migrator' ),
			),
			'csvFilename'     => __( 'site-migrator-report.csv', 'site-migrator' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX                                                               */
	/* ------------------------------------------------------------------ */

	/** Checks nonce and capabilities, and returns an engine configured with the posted options. */
	private function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'site-migrator' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Your user does not have permission to use this tool.', 'site-migrator' ) ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$raw = ( isset( $_POST['options'] ) && is_array( $_POST['options'] ) ) ? wp_unslash( $_POST['options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Site_Migrator::sanitize_options().
		return new Site_Migrator( $raw );
	}

	private function posted_url() {
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'message' => __( 'The URL is not valid.', 'site-migrator' ) ) );
		}
		return $url;
	}

	public function ajax_collect() {
		$migrator = $this->guard();
		try {
			$urls = $migrator->collect_urls();
			$save = $migrator->get_options();
			unset( $save['urls'] );
			update_option( self::OPTION, $save, false );
			wp_send_json_success( array( 'urls' => $urls, 'warnings' => $migrator->get_warnings() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_preview() {
		$migrator = $this->guard();
		$url      = $this->posted_url();
		try {
			$page              = $migrator->parse( $url );
			$page['html_safe'] = wp_kses_post( $page['content'] );
			wp_send_json_success( $page );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_page() {
		$migrator = $this->guard();
		$url      = $this->posted_url();
		try {
			$result              = $migrator->import_page( $url );
			$result['edit_link'] = $result['post_id'] ? get_edit_post_link( $result['post_id'], 'raw' ) : '';
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_image() {
		$migrator = $this->guard();

		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$src      = isset( $_POST['src'] ) ? esc_url_raw( wp_unslash( $_POST['src'] ) ) : '';
		$alt      = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$featured = ! empty( $_POST['featured'] ) && '1' === $_POST['featured'];
		// phpcs:enable

		// post_id = 0 means "images only": unattached upload to the Media Library.
		if ( $post_id && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The target post does not exist or cannot be edited.', 'site-migrator' ) ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Your user does not have permission to use this tool.', 'site-migrator' ) ), 403 );
		}
		$featured = $featured && $post_id;
		if ( ! preg_match( '#^https?://#i', $src ) ) {
			wp_send_json_error( array( 'message' => __( 'The image URL is not valid.', 'site-migrator' ) ) );
		}

		$att = $migrator->import_image( $post_id, $src, $alt, $featured );
		if ( is_wp_error( $att ) ) {
			wp_send_json_error( array( 'message' => $att->get_error_message() ) );
		}
		wp_send_json_success( array( 'attachment_id' => $att ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Screen                                                             */
	/* ------------------------------------------------------------------ */

	public function render() {
		$saved = get_option( self::OPTION, array() );
		$o     = wp_parse_args( is_array( $saved ) ? $saved : array(), Site_Migrator::defaults() );

		if ( ! $saved ) {
			if ( defined( 'WPSEO_VERSION' ) ) {
				$o['seo'] = 'yoast';
			} elseif ( class_exists( 'RankMath' ) ) {
				$o['seo'] = 'rankmath';
			}
		}

		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		$statuses = array(
			'draft'   => __( 'Draft', 'site-migrator' ),
			'pending' => __( 'Pending review', 'site-migrator' ),
			'private' => __( 'Private', 'site-migrator' ),
			'publish' => __( 'Published', 'site-migrator' ),
		);
		$modes = array(
			'all'     => __( 'Content and images', 'site-migrator' ),
			'content' => __( 'Content only', 'site-migrator' ),
			'images'  => __( 'Images only', 'site-migrator' ),
		);
		$seo = array(
			'none'     => __( 'Do not save', 'site-migrator' ),
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
		);
		?>
		<div class="wrap sm-wrap">
			<h1>Site Migrator</h1>
			<p class="sm-intro"><?php esc_html_e( 'Import text and images from an external site as pages or posts on this site. Check the preview before migrating, and migrate as drafts first.', 'site-migrator' ); ?></p>

			<form id="sm-form" onsubmit="return false;">
				<h2><?php esc_html_e( 'What to migrate', 'site-migrator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'site-migrator' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $modes as $value => $label ) : ?>
									<label><input type="radio" name="mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $o['mode'], $value ); ?>> <?php echo esc_html( $label ); ?></label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description" data-modes="images"><?php esc_html_e( 'Images are uploaded to the Media Library without creating or changing any page. Images already in the library are not duplicated.', 'site-migrator' ); ?></p>
						</td>
					</tr>
					<tr data-modes="content">
						<th scope="row"><label for="sm-content-images"><?php esc_html_e( 'Images inside the content', 'site-migrator' ); ?></label></th>
						<td>
							<select id="sm-content-images" name="content_images">
								<option value="remove" <?php selected( $o['content_images'], 'remove' ); ?>><?php esc_html_e( 'Remove them from the content', 'site-migrator' ); ?></option>
								<option value="keep" <?php selected( $o['content_images'], 'keep' ); ?>><?php esc_html_e( 'Keep them pointing to the original site', 'site-migrator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'If you keep them, they will break when the original site goes offline. Use it only if you will replace them later.', 'site-migrator' ); ?></p>
						</td>
					</tr>
					<tr data-modes="images">
						<th scope="row"><label for="sm-image-scope"><?php esc_html_e( 'Which images', 'site-migrator' ); ?></label></th>
						<td>
							<select id="sm-image-scope" name="image_scope">
								<option value="container" <?php selected( $o['image_scope'], 'container' ); ?>><?php esc_html_e( 'Only those inside the content container', 'site-migrator' ); ?></option>
								<option value="page" <?php selected( $o['image_scope'], 'page' ); ?>><?php esc_html_e( 'All images on the page', 'site-migrator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'With "All images on the page" the content container is ignored, but "Remove elements" still applies: use it to leave out logos and icons.', 'site-migrator' ); ?></p>
						</td>
					</tr>
					<tr data-modes="images">
						<th scope="row"><?php esc_html_e( 'Background images', 'site-migrator' ); ?></th>
						<td>
							<label><input type="checkbox" name="backgrounds" value="1" <?php checked( $o['backgrounds'] ); ?>>
								<?php esc_html_e( 'Also import background images defined in CSS', 'site-migrator' ); ?>
							</label>
							<p class="description">
								<?php
								/* translators: 1: style attribute, 2: <style> tag. */
								printf( esc_html__( 'Scans %1$s attributes and lazy-load attributes such as data-bg. With "All images on the page" it also scans %2$s tags; those tags are not affected by "Remove elements". CSS loaded from external files is not scanned.', 'site-migrator' ), '<code>style</code>', '<code>&lt;style&gt;</code>' );
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Source', 'site-migrator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sm-sitemap"><?php esc_html_e( 'Sitemap', 'site-migrator' ); ?></label></th>
						<td>
							<input type="url" id="sm-sitemap" name="sitemap" class="regular-text code" value="<?php echo esc_attr( $o['sitemap'] ); ?>" placeholder="https://old-site.com/sitemap.xml">
							<p class="description"><?php esc_html_e( 'Sitemap indexes are supported. If you enter URLs in the list below, the list is used instead.', 'site-migrator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sm-urls"><?php esc_html_e( 'URL list', 'site-migrator' ); ?></label></th>
						<td>
							<textarea id="sm-urls" name="urls" rows="4" class="large-text code" placeholder="<?php esc_attr_e( 'One URL per line', 'site-migrator' ); ?>"></textarea>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Content', 'site-migrator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sm-selector"><?php esc_html_e( 'Content container', 'site-migrator' ); ?></label></th>
						<td>
							<input type="text" id="sm-selector" name="selector" class="regular-text code" value="<?php echo esc_attr( $o['selector'] ); ?>">
							<p class="description">
								<?php
								/* translators: %s: example CSS selectors. */
								printf( esc_html__( 'CSS selector of the element that wraps only the content, without menu or footer. Examples: %s.', 'site-migrator' ), '<code>main</code>, <code>#content</code>, <code>.entry-content</code>' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sm-exclude-selector"><?php esc_html_e( 'Remove elements', 'site-migrator' ); ?></label></th>
						<td>
							<input type="text" id="sm-exclude-selector" name="exclude_selector" class="regular-text code" value="<?php echo esc_attr( $o['exclude_selector'] ); ?>" placeholder=".breadcrumbs, .share, .cta">
							<p class="description"><?php esc_html_e( 'Selectors of elements you do not want to migrate, separated by commas.', 'site-migrator' ); ?></p>
						</td>
					</tr>
				</table>

				<details class="sm-advanced">
					<summary><?php esc_html_e( 'Advanced options', 'site-migrator' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sm-include-urls"><?php esc_html_e( 'Only URLs containing', 'site-migrator' ); ?></label></th>
							<td>
								<input type="text" id="sm-include-urls" name="include_urls" class="regular-text code" value="<?php echo esc_attr( $o['include_urls'] ); ?>" placeholder="/blog/">
								<p class="description"><?php esc_html_e( 'Regular expression. Useful for migrating the blog separately.', 'site-migrator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sm-exclude-urls"><?php esc_html_e( 'Skip URLs containing', 'site-migrator' ); ?></label></th>
							<td>
								<input type="text" id="sm-exclude-urls" name="exclude_urls" class="regular-text code" value="<?php echo esc_attr( $o['exclude_urls'] ); ?>" placeholder="/blog/|/tag/|/category/">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sm-xpath"><?php esc_html_e( 'Container XPath', 'site-migrator' ); ?></label></th>
							<td>
								<input type="text" id="sm-xpath" name="xpath" class="regular-text code" value="<?php echo esc_attr( $o['xpath'] ); ?>">
								<p class="description"><?php esc_html_e( 'Only if the CSS selector is not enough. When filled in, it replaces the selector.', 'site-migrator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cleanup', 'site-migrator' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="keep_span" value="1" <?php checked( $o['keep_span'] ); ?>>
										<?php
										/* translators: %s: the <span> tag. */
										printf( esc_html__( 'Keep %s tags', 'site-migrator' ), '<code>&lt;span&gt;</code>' );
										?>
									</label><br>
									<label><input type="checkbox" name="keep_h1" value="1" <?php checked( $o['keep_h1'] ); ?>> <?php esc_html_e( 'Keep the H1 inside the content (in addition to using it as the title)', 'site-migrator' ); ?></label><br>
									<label><input type="checkbox" name="keep_absolute_links" value="1" <?php checked( $o['keep_absolute_links'] ); ?>> <?php esc_html_e( 'Do not convert internal links to relative paths', 'site-migrator' ); ?></label>
								</fieldset>
							</td>
						</tr>
					</table>
				</details>

				<div data-modes="all content">
				<h2><?php esc_html_e( 'Destination', 'site-migrator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sm-post-type"><?php esc_html_e( 'Create as', 'site-migrator' ); ?></label></th>
						<td>
							<select id="sm-post-type" name="post_type">
								<?php foreach ( $types as $type ) : ?>
									<option value="<?php echo esc_attr( $type->name ); ?>" <?php selected( $o['post_type'], $type->name ); ?>><?php echo esc_html( $type->labels->singular_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sm-status"><?php esc_html_e( 'Status', 'site-migrator' ); ?></label></th>
						<td>
							<select id="sm-status" name="status">
								<?php foreach ( $statuses as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $o['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sm-seo"><?php esc_html_e( 'Title and meta description', 'site-migrator' ); ?></label></th>
						<td>
							<select id="sm-seo" name="seo">
								<?php foreach ( $seo as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $o['seo'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Copies the original title and meta description to the selected SEO plugin.', 'site-migrator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other options', 'site-migrator' ); ?></th>
						<td>
							<fieldset>
								<label data-modes="all"><input type="checkbox" name="featured" value="1" <?php checked( $o['featured'] ); ?>> <?php esc_html_e( 'Use the Open Graph image (og:image) as the featured image', 'site-migrator' ); ?><br></label>
								<label><input type="checkbox" name="update" value="1" <?php checked( $o['update'] ); ?>> <?php esc_html_e( 'Update already migrated (otherwise they are skipped)', 'site-migrator' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				</div>
			</form>

			<div class="sm-actions">
				<button type="button" id="sm-collect" class="button button-primary"><?php esc_html_e( 'Get URLs', 'site-migrator' ); ?></button>
				<span class="spinner"></span>
			</div>
			<div id="sm-collect-msg"></div>

			<div id="sm-step-urls" class="sm-step" hidden>
				<h2><?php esc_html_e( 'URLs to migrate', 'site-migrator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'You can remove or add URLs before migrating, one per line. Parent pages are processed before their children.', 'site-migrator' ); ?></p>
				<p id="sm-url-count" class="sm-count"></p>
				<textarea id="sm-queue" rows="10" class="large-text code" aria-label="<?php esc_attr_e( 'URLs to migrate', 'site-migrator' ); ?>"></textarea>

				<h2><?php esc_html_e( 'Preview', 'site-migrator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Check that only the content is extracted. If you change the selector or the options, generate the preview again; there is no need to get the URLs again.', 'site-migrator' ); ?></p>
				<div class="sm-actions">
					<select id="sm-preview-url" aria-label="<?php esc_attr_e( 'URL to preview', 'site-migrator' ); ?>"></select>
					<button type="button" id="sm-preview-btn" class="button"><?php esc_html_e( 'Generate preview', 'site-migrator' ); ?></button>
					<span class="spinner"></span>
				</div>
				<div id="sm-preview-msg"></div>

				<div id="sm-preview" class="sm-preview" hidden>
					<dl class="sm-meta">
						<dt><?php esc_html_e( 'Title', 'site-migrator' ); ?></dt><dd id="sm-pv-title"></dd>
						<dt><?php esc_html_e( 'Slug', 'site-migrator' ); ?></dt><dd id="sm-pv-slug"></dd>
						<dt><?php esc_html_e( 'Meta description', 'site-migrator' ); ?></dt><dd id="sm-pv-desc"></dd>
						<dt><?php esc_html_e( 'Images', 'site-migrator' ); ?></dt><dd id="sm-pv-images"></dd>
					</dl>
					<div id="sm-pv-warnings"></div>
					<div class="sm-tabs" role="group" aria-label="<?php esc_attr_e( 'Preview format', 'site-migrator' ); ?>">
						<button type="button" class="button sm-tab" data-target="sm-pv-rendered" aria-pressed="true"><?php esc_html_e( 'Rendered', 'site-migrator' ); ?></button>
						<button type="button" class="button sm-tab" data-target="sm-pv-code" aria-pressed="false">HTML</button>
					</div>
					<div id="sm-pv-rendered" class="sm-rendered"></div>
					<pre id="sm-pv-code" class="sm-code" hidden></pre>
				</div>
			</div>

			<div id="sm-step-run" class="sm-step" hidden>
				<h2><?php esc_html_e( 'Migrate', 'site-migrator' ); ?></h2>
				<div class="sm-actions">
					<button type="button" id="sm-start" class="button button-primary"><?php esc_html_e( 'Start migration', 'site-migrator' ); ?></button>
					<button type="button" id="sm-stop" class="button" disabled><?php esc_html_e( 'Stop', 'site-migrator' ); ?></button>
					<button type="button" id="sm-csv" class="button" hidden><?php esc_html_e( 'Download CSV report', 'site-migrator' ); ?></button>
				</div>
				<progress id="sm-progress" value="0" max="1" hidden></progress>
				<p id="sm-run-status" aria-live="polite"></p>
				<table id="sm-log" class="widefat striped" hidden>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Original URL', 'site-migrator' ); ?></th>
							<th><?php esc_html_e( 'Status', 'site-migrator' ); ?></th>
							<th><?php esc_html_e( 'New page', 'site-migrator' ); ?></th>
							<th><?php esc_html_e( 'Images', 'site-migrator' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
