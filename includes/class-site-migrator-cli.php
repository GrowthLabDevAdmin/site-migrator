<?php
/**
 * WP-CLI command: wp migrate-site run
 */

defined( 'ABSPATH' ) || exit;

class Site_Migrator_CLI {

	/**
	 * Migrates pages from an external site into WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : What to migrate: all (content and images) | content (content only) | images (images only, uploaded to the Media Library without creating pages). Default: all
	 *
	 * [--content-images=<action>]
	 * : Content mode only. What to do with images inside the content: remove | keep (pointing to the original site). Default: remove
	 *
	 * [--image-scope=<scope>]
	 * : Images mode only. container (only images inside the content container) | page (every image on the page). Default: container
	 *
	 * [--skip-backgrounds]
	 * : Images mode only. Do not import background images found in style attributes and <style> tags.
	 *
	 * [--sitemap=<url>]
	 * : Sitemap URL (sitemap indexes are supported).
	 *
	 * [--urls=<file>]
	 * : Text file with one URL per line (alternative to the sitemap).
	 *
	 * [--selector=<css>]
	 * : CSS selector of the content container. Default: body
	 *
	 * [--xpath=<xpath>]
	 * : XPath of the container (replaces --selector).
	 *
	 * [--exclude-selector=<css>]
	 * : Elements to remove before extracting (e.g. ".breadcrumbs, .share").
	 *
	 * [--include-urls=<regex>]
	 * : Only process URLs that match (e.g. "/blog/").
	 *
	 * [--exclude-urls=<regex>]
	 * : Skip URLs that match (e.g. "/blog/|/tag/").
	 *
	 * [--post-type=<type>]
	 * : Target post type. Default: page
	 *
	 * [--status=<status>]
	 * : draft | pending | private | publish. Default: draft
	 *
	 * [--seo=<plugin>]
	 * : Save the original title and meta description: yoast | rankmath | none. Default: none
	 *
	 * [--limit=<n>]
	 * : Only process the first N URLs.
	 *
	 * [--delay=<ms>]
	 * : Pause between pages in milliseconds. Default: 500
	 *
	 * [--report=<file.csv>]
	 * : Writes a CSV mapping original URL → new path (basis for 301 redirects).
	 *
	 * [--keep-span]
	 * : Keep <span> tags (without attributes).
	 *
	 * [--keep-h1]
	 * : Do not remove the first H1 from the content.
	 *
	 * [--keep-absolute-links]
	 * : Do not convert internal links to relative paths.
	 *
	 * [--featured]
	 * : Use og:image as the featured image.
	 *
	 * [--update]
	 * : Overwrite URLs that were already migrated.
	 *
	 * [--dry-run]
	 * : Write nothing; show what would be extracted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="main" --dry-run --limit=3
	 *     wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="#content" --exclude-urls="/blog/" --seo=yoast --report=redirects.csv --user=1
	 *     wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --mode=images --image-scope=page --exclude-selector="header, footer" --user=1
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc ) {
		$opts = array();
		foreach ( $assoc as $k => $v ) {
			$opts[ str_replace( '-', '_', $k ) ] = $v;
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc, 'skip-backgrounds', false ) ) {
			$opts['backgrounds'] = false;
		}

		if ( ! empty( $assoc['urls'] ) ) {
			if ( ! is_readable( $assoc['urls'] ) ) {
				/* translators: %s: file path. */
				WP_CLI::error( sprintf( __( 'Cannot read %s', 'site-migrator' ), $assoc['urls'] ) );
			}
			$opts['urls'] = file_get_contents( $assoc['urls'] );
		}

		$dry    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		$limit  = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0;
		$delay  = isset( $assoc['delay'] ) ? (int) $assoc['delay'] : 500;
		$report = ( ! $dry && ! empty( $assoc['report'] ) ) ? $assoc['report'] : '';

		$migrator = new Site_Migrator( $opts );

		try {
			$urls = $migrator->collect_urls();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		foreach ( $migrator->get_warnings() as $w ) {
			WP_CLI::warning( $w );
		}
		if ( $limit ) {
			$urls = array_slice( $urls, 0, $limit );
		}
		if ( ! $urls ) {
			WP_CLI::error( __( 'No URLs found to process.', 'site-migrator' ) );
		}

		$labels = array(
			'created' => __( 'Created', 'site-migrator' ),
			'updated' => __( 'Updated', 'site-migrator' ),
			'skipped'   => __( 'Skipped', 'site-migrator' ),
			'processed' => __( 'Processed', 'site-migrator' ),
		);
		$images_mode = 'images' === $migrator->get_options()['mode'];

		$fh = null;
		if ( $report ) {
			$fh = fopen( $report, 'w' );
			fputcsv( $fh, $this->csv_headers() );
		}

		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'processed' => 0, 'error' => 0, 'img_ok' => 0, 'img_error' => 0 );
		$total = count( $urls );

		/* translators: %d: number of URLs. */
		$intro = sprintf( __( 'URLs to process: %d', 'site-migrator' ), $total );
		WP_CLI::log( $dry ? $intro . ' ' . __( '(dry run: nothing will be written)', 'site-migrator' ) : $intro );

		foreach ( $urls as $i => $url ) {
			WP_CLI::log( sprintf( "\n[%d/%d] %s", $i + 1, $total, $url ) );

			try {
				if ( $dry ) {
					$this->print_preview( $migrator->parse( $url ) );
				} else {
					$r      = $migrator->import_page( $url );
					$failed = array();

					foreach ( $r['images'] as $img ) {
						$res = $migrator->import_image( $r['post_id'], $img['src'], $img['alt'] );
						if ( is_wp_error( $res ) ) {
							$failed[] = $img['src'];
							/* translators: 1: image URL, 2: error message. */
							WP_CLI::warning( '  ' . sprintf( __( 'Image %1$s: %2$s', 'site-migrator' ), $img['src'], $res->get_error_message() ) );
						}
					}
					if ( $r['featured'] ) {
						$res = $migrator->import_image( $r['post_id'], $r['featured'], $r['title'], true );
						if ( is_wp_error( $res ) ) {
							/* translators: %s: error message. */
							WP_CLI::warning( '  ' . sprintf( __( 'Featured image: %s', 'site-migrator' ), $res->get_error_message() ) );
						}
					}
					foreach ( $r['warnings'] as $w ) {
						WP_CLI::warning( '  ' . $w );
					}

					$stats[ $r['status'] ]++;
					$stats['img_ok']    += count( $r['images'] ) - count( $failed );
					$stats['img_error'] += count( $failed );

					if ( $images_mode ) {
						/* translators: 1: images imported, 2: images failed. */
						WP_CLI::log( '  ' . sprintf( __( 'Images: %1$d ok, %2$d failed', 'site-migrator' ), count( $r['images'] ) - count( $failed ), count( $failed ) ) );
					} else {
						WP_CLI::log( '  ' . sprintf(
							/* translators: 1: status, 2: post ID, 3: new path, 4: images imported, 5: images failed. */
							__( '%1$s: ID %2$d → %3$s | images: %4$d ok, %5$d failed', 'site-migrator' ),
							$labels[ $r['status'] ],
							$r['post_id'],
							$r['new_path'],
							count( $r['images'] ) - count( $failed ),
							count( $failed )
						) );
					}

					if ( $fh ) {
						fputcsv( $fh, array( $url, $r['old_path'], $r['post_id'], $r['new_path'], $labels[ $r['status'] ], count( $r['images'] ) - count( $failed ), implode( ' ', $failed ), implode( ' | ', $r['warnings'] ) ) );
					}
				}
			} catch ( Exception $e ) {
				$stats['error']++;
				/* translators: %s: error message. */
				WP_CLI::warning( '  ' . sprintf( __( 'Error: %s', 'site-migrator' ), $e->getMessage() ) );
				if ( $fh ) {
					fputcsv( $fh, array( $url, '', '', '', __( 'Error', 'site-migrator' ), '', '', $e->getMessage() ) );
				}
			}

			if ( $delay ) {
				usleep( $delay * 1000 );
			}
		}

		if ( $fh ) {
			fclose( $fh );
			/* translators: %s: file path. */
			WP_CLI::log( "\n" . sprintf( __( 'Report saved to: %s', 'site-migrator' ), $report ) );
		}

		if ( ! $dry && $images_mode ) {
			WP_CLI::success( sprintf(
				/* translators: 1: pages processed, 2: errors, 3: images imported, 4: images failed. */
				__( 'Pages processed: %1$d | Errors: %2$d | Images: %3$d ok, %4$d failed', 'site-migrator' ),
				$stats['processed'], $stats['error'], $stats['img_ok'], $stats['img_error']
			) );
		} elseif ( ! $dry ) {
			WP_CLI::success( sprintf(
				/* translators: 1: created, 2: updated, 3: skipped, 4: errors, 5: images imported, 6: images failed. */
				__( 'Created: %1$d | Updated: %2$d | Skipped: %3$d | Errors: %4$d | Images: %5$d ok, %6$d failed', 'site-migrator' ),
				$stats['created'], $stats['updated'], $stats['skipped'], $stats['error'], $stats['img_ok'], $stats['img_error']
			) );
		}
	}

	private function csv_headers() {
		return array(
			__( 'original_url', 'site-migrator' ),
			__( 'original_path', 'site-migrator' ),
			'post_id',
			__( 'new_path', 'site-migrator' ),
			__( 'status', 'site-migrator' ),
			__( 'images_ok', 'site-migrator' ),
			__( 'images_not_migrated', 'site-migrator' ),
			__( 'notes', 'site-migrator' ),
		);
	}

	private function print_preview( $p ) {
		/* translators: %s: post title. */
		WP_CLI::log( '  ' . sprintf( __( 'Title: %s', 'site-migrator' ), $p['title'] ) );
		$slug = $p['parent_id']
			/* translators: 1: slug, 2: parent post ID. */
			? sprintf( __( 'Slug: %1$s (child of post ID %2$d)', 'site-migrator' ), $p['slug'], $p['parent_id'] )
			/* translators: %s: slug. */
			: sprintf( __( 'Slug: %s', 'site-migrator' ), $p['slug'] );
		WP_CLI::log( '  ' . $slug );
		/* translators: %s: meta description. */
		WP_CLI::log( '  ' . sprintf( __( 'Meta description: %s', 'site-migrator' ), $p['meta']['description'] ? $p['meta']['description'] : __( '(empty)', 'site-migrator' ) ) );
		/* translators: %d: number of images. */
		WP_CLI::log( '  ' . sprintf( __( 'Images: %d', 'site-migrator' ), count( $p['images'] ) ) );
		foreach ( $p['images'] as $img ) {
			$is_bg = isset( $img['type'] ) && 'background' === $img['type'];
			WP_CLI::log( '    - ' . $img['src'] . ( $is_bg ? ' ' . __( '(CSS background)', 'site-migrator' ) : '' ) );
		}
		foreach ( $p['warnings'] as $w ) {
			WP_CLI::warning( '  ' . $w );
		}
		WP_CLI::log( '  --- ' . __( 'Content (first 1500 characters)', 'site-migrator' ) . " ---\n" . mb_substr( $p['content'], 0, 1500 ) . "\n  ---" );
	}
}
