<?php
/**
 * Plugin Name:       Site Migrator
 * Description:       Migrates text and images from external (non-WordPress) sites into pages or posts. Adds a screen under Tools → Site Migrator and the WP-CLI command "wp migrate-site".
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your agency
 * License:           GPL-2.0-or-later
 * Text Domain:       site-migrator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SITE_MIGRATOR_VERSION', '1.3.0' );
define( 'SITE_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SITE_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Loads translations based on the user/site language.
 * Any Spanish variant (es_MX, es_AR, es_CO…) falls back to the es_ES translation.
 */
function site_migrator_load_textdomain() {
	$locale = determine_locale();
	$dir    = SITE_MIGRATOR_PATH . 'languages/';
	$file   = $dir . 'site-migrator-' . $locale . '.mo';

	if ( ! file_exists( $file ) && 0 === strpos( $locale, 'es_' ) ) {
		$file = $dir . 'site-migrator-es_ES.mo';
	}
	if ( file_exists( $file ) ) {
		load_textdomain( 'site-migrator', $file );
	}
}
add_action( 'init', 'site_migrator_load_textdomain' );

require_once SITE_MIGRATOR_PATH . 'includes/class-site-migrator.php';

if ( is_admin() ) {
	require_once SITE_MIGRATOR_PATH . 'includes/class-site-migrator-admin.php';
	new Site_Migrator_Admin();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SITE_MIGRATOR_PATH . 'includes/class-site-migrator-cli.php';
	WP_CLI::add_command( 'migrate-site', 'Site_Migrator_CLI' );
}
