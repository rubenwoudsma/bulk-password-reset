<?php
/**
 * Native WordPress administration screens and protected form handlers.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Connects the UI to the job service. */
final class Admin {

	/**
	 * Job storage and processing.
	 *
	 * @var Jobs
	 */
	private $jobs;

	/**
	 * Create the administration controller.
	 *
	 * @param Jobs $jobs Job service.
	 */
	public function __construct( $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * Register authenticated entry points; there are no public reset handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_bpr_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_bpr_process', array( $this, 'process' ) );
		add_action( 'admin_post_bpr_email_save', array( $this, 'email_save' ) );
		add_action( 'admin_post_bpr_email_test', array( $this, 'email_test' ) );
		add_action( 'load-users.php', array( $this, 'users_preview' ) );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'bulk_preview' ), 10, 3 );
	}

	/**
	 * Locate the administration screen.
	 *
	 * @return string Admin screen URL, retaining the legacy page slug.
	 */
	public static function url() {
		return admin_url( 'users.php?page=bulk_password_reset' );
	}

	/**
	 * Add the Users submenu for authorized operators.
	 *
	 * @return void
	 */
	public function menu() {
		if ( Service::can_manage() ) {
			add_users_page( __( 'Bulk Password Reset', 'bulk-password-reset' ), __( 'Bulk Password Reset', 'bulk-password-reset' ), 'manage_options', 'bulk_password_reset', array( $this, 'render' ) );
		}
	}

	/**
	 * Load local styles only for this plugin's own authorized admin page.
	 *
	 * @param string $hook_suffix Current administration screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'users_page_bulk_password_reset' !== $hook_suffix || ! Service::can_manage() ) {
			return;
		}
		wp_enqueue_style(
			'bulk-password-reset-admin',
			plugins_url( 'assets/admin.css', dirname( __DIR__ ) . '/bulk_password_reset.php' ),
			array(),
			BULK_PASSWORD_RESET_VERSION
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset selection on the authorized plugin screen.
		if ( isset( $_GET['view'] ) && 'email' === $_GET['view'] && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'bulk-password-reset-email', plugins_url( 'assets/email-settings.js', dirname( __DIR__ ) . '/bulk_password_reset.php' ), array( 'media-views' ), BULK_PASSWORD_RESET_VERSION, true );
			wp_localize_script(
				'bulk-password-reset-email',
				'bprEmailSettings',
				array(
					'title'  => __( 'Choose an email logo', 'bulk-password-reset' ),
					'button' => __( 'Use this image', 'bulk-password-reset' ),
					'alt'    => __( 'Selected email logo', 'bulk-password-reset' ),
				)
			);
		}
	}

	/**
	 * Offer the preview action on the Users screen.
	 *
	 * @param array $actions Existing Users actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		if ( Service::can_manage() ) {
			$actions['bpr_preview'] = __( 'Preview password reset', 'bulk-password-reset' );
		}
		return $actions;
	}

	/**
	 * Capture native Users submissions before core's GET cleanup strips the nonce.
	 * This endpoint can only create a preview; execution still requires a POST.
	 *
	 * @return void
	 */
	public function users_preview() {
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		if ( 'bpr_preview' !== $action ) {
			return;
		}
		$this->authorize();
		check_admin_referer( 'bulk-users' );
		$ids = isset( $_REQUEST['users'] ) && is_array( $_REQUEST['users'] ) ? map_deep( wp_unslash( $_REQUEST['users'] ), 'sanitize_text_field' ) : array();
		$this->check_result( $this->jobs->create( array(), $ids, 'link', '' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Users-table actions only create a preview; they never reset passwords.
	 * WordPress uses GET for this native table and supplies a bulk-users nonce.
	 *
	 * @param string $redirect Original redirect URL.
	 * @param string $action Chosen action.
	 * @param array  $ids Selected users.
	 * @return string
	 */
	public function bulk_preview( $redirect, $action, $ids ) {
		if ( 'bpr_preview' !== $action ) {
			return $redirect;
		}
		$this->authorize();
		check_admin_referer( 'bulk-users' );
		$this->check_result( $this->jobs->create( array(), $ids, 'link', '' ) );
		return self::url();
	}

	/**
	 * Enforce access independently of menu visibility.
	 *
	 * @return void
	 */
	private function authorize() {
		if ( ! Service::can_manage() ) {
			wp_die( esc_html__( 'You cannot perform bulk password resets.', 'bulk-password-reset' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Reject state-changing requests that do not use POST.
	 *
	 * @return void
	 */
	private function require_post() {
		$this->authorize();
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Submit this action using its form.', 'bulk-password-reset' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Display a service failure without exposing internal data.
	 *
	 * @param mixed $result Service result.
	 * @return void
	 */
	private function check_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				'',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Validate the selection form and create a preview.
	 *
	 * @return void
	 */
	public function preview() {
		$this->require_post();
		check_admin_referer( 'bpr_preview' );
		$roles = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? map_deep( wp_unslash( $_POST['roles'] ), 'sanitize_text_field' ) : array();
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || sanitize_key( $role ) !== $role ) {
				$this->check_result( new \WP_Error( 'selection', __( 'The role selection is invalid.', 'bulk-password-reset' ) ) );
			}
		}
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$note = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$this->check_result( $this->jobs->create( $roles, array(), $mode, $note ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Validate and process or cancel an owned job.
	 *
	 * @return void
	 */
	public function process() {
		$this->require_post();
		$id = isset( $_POST['job'] ) && is_string( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		check_admin_referer( 'bpr_process_' . $id );
		if ( isset( $_POST['cancel'] ) ) {
			$result = $this->jobs->cancel( $id );
		} else {
			$result = $this->jobs->process( $id, isset( $_POST['confirm'] ) && '1' === $_POST['confirm'] );
		}
		$this->check_result( $result );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Save site-local email preferences without touching accounts or jobs.
	 *
	 * @return void
	 */
	public function email_save() {
		$this->require_post();
		check_admin_referer( 'bpr_email_save' );
		$value = isset( $_POST['email'] ) && is_array( $_POST['email'] ) ? map_deep( wp_unslash( $_POST['email'] ), 'sanitize_text_field' ) : array();
		$this->check_result( Email_Settings::save( $value ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'view'         => 'email',
					'email_notice' => 'saved',
				),
				self::url()
			)
		);
		exit;
	}

	/**
	 * Send a sample only to the current operator, never to submitted addresses.
	 *
	 * @return void
	 */
	public function email_test() {
		$this->require_post();
		check_admin_referer( 'bpr_email_test' );
		$mode   = isset( $_POST['example_mode'] ) && is_string( $_POST['example_mode'] ) ? sanitize_key( wp_unslash( $_POST['example_mode'] ) ) : '';
		$result = Email::send_test( $mode );
		$this->check_result( $result );
		wp_safe_redirect(
			add_query_arg(
				array(
					'view'         => 'email',
					'email_notice' => $result ? 'sent' : 'failed',
				),
				self::url()
			)
		);
		exit;
	}

	/**
	 * Render the authorized administration screen.
	 *
	 * @return void
	 */
	public function render() {
		$this->authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; starting a preview still requires its authorized POST and nonce.
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		( new View( $this->jobs->current(), 'new' === $view, 'email' === $view ) )->render();
	}
}
