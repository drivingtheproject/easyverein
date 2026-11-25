<?php
/**
 * Uninstall: remove data only if the administrator opted in.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'evmf_token_maintenance' );

$evmf_settings = get_option( 'evmf_settings', array() );
if ( empty( $evmf_settings['delete_on_uninstall'] ) ) {
	return;
}

foreach ( array( 'evmf_settings', 'evmf_token', 'evmf_token_status', 'evmf_token_refresh_lock', 'evmf_schema_version', 'evmf_log_file_key' ) as $evmf_option ) {
	delete_option( $evmf_option );
}

// Delete the log directory.
$evmf_uploads = wp_upload_dir( null, false );
$evmf_dir     = trailingslashit( $evmf_uploads['basedir'] ) . 'evmf-logs';
if ( is_dir( $evmf_dir ) ) {
	foreach ( (array) scandir( $evmf_dir ) as $evmf_file ) {
		if ( is_file( $evmf_dir . '/' . $evmf_file ) ) {
			wp_delete_file( $evmf_dir . '/' . $evmf_file );
		}
	}
	@rmdir( $evmf_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
