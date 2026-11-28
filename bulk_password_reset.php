<?php
/**
 * Plugin Name: Bulk Password Reset
 * Plugin URI: https://github.com/rubenwoudsma/bulk-password-reset
 * Description: Bulk reset WordPress user passwords by role or selection. Preview first, send secure reset links, or invalidate passwords. Always free.
 * Version: 2.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Ruben Woudsma
 * Author URI: https://rubenwoudsma.nl/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bulk-password-reset
 * Domain Path: /languages
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

define( 'BULK_PASSWORD_RESET_VERSION', '2.1.0' );

require_once __DIR__ . '/includes/class-email-settings.php';
require_once __DIR__ . '/includes/class-email.php';
require_once __DIR__ . '/includes/class-email-view.php';
require_once __DIR__ . '/includes/class-service.php';
require_once __DIR__ . '/includes/class-jobs.php';
require_once __DIR__ . '/includes/class-view.php';
require_once __DIR__ . '/includes/class-admin.php';

/**
 * Remove obsolete settings, including any stored shared password or reset key.
 * Old email templates and reset preferences are deliberately not imported.
 *
 * @return void
 */
function remove_legacy_settings() {
	if ( Service::can_manage() && false !== get_option( 'bpr_options', false ) ) {
		delete_option( 'bpr_options' );
	}
}

add_action( 'admin_init', __NAMESPACE__ . '\\remove_legacy_settings' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\remove_legacy_settings' );

( new Admin( new Jobs( new Service() ) ) )->register();

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/includes/class-cli.php';
	Cli::register( new Jobs( new Service() ), new Service() );
}
