<?php
/**
 * Remove plugin-owned data on uninstall, including network-activated sites.
 *
 * @package BulkPasswordReset
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	$bulk_password_reset_offset = 0;
	do {
		$bulk_password_reset_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $bulk_password_reset_offset,
			)
		);
		foreach ( $bulk_password_reset_sites as $bulk_password_reset_site ) {
			switch_to_blog( $bulk_password_reset_site );
			delete_option( 'bpr_job' );
			delete_option( 'bpr_job_lock' );
			delete_option( 'bpr_options' );
			restore_current_blog();
		}
		$bulk_password_reset_offset += 100;
		$bulk_password_reset_count   = count( $bulk_password_reset_sites );
	} while ( 100 === $bulk_password_reset_count );
} else {
	delete_option( 'bpr_job' );
	delete_option( 'bpr_job_lock' );
	delete_option( 'bpr_options' );
}
