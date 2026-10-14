<?php
/**
 * On uninstall only the saved settings are removed.
 * Migrated content and its meta (_sm_source_url, _sm_source_path) are kept on purpose.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'site_migrator_last_options' );
