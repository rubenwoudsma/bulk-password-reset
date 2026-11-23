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
		add_action( 'admin_post_bpr_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_bpr_process', array( $this, 'process' ) );
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
	 * Render the selection screen or active job.
	 *
	 * @return void
	 */
	public function render() {
		$this->authorize();
		$job = $this->jobs->current();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Password Reset', 'bulk-password-reset' ); ?></h1>
			<p><?php esc_html_e( 'Send secure password-reset links to users selected by role or from the Users screen. All features are free.', 'bulk-password-reset' ); ?></p>
			<p><?php esc_html_e( 'Your own account, administrators, and accounts that can manage settings or edit users are always excluded.', 'bulk-password-reset' ); ?></p>
			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Multisite: only super administrators can run this tool. Selection is limited to this site, but passwords and sessions are shared across the network.', 'bulk-password-reset' ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( $job && $job['expires'] > time() && get_current_user_id() !== $job['owner'] && in_array( $job['status'], array( 'ready', 'running' ), true ) ) {
				echo '<p>' . esc_html__( 'Another administrator has an active preview or job on this site. They must finish or cancel it, or let it expire after 48 hours.', 'bulk-password-reset' ) . '</p>';
			} elseif ( $job && $job['expires'] > time() && get_current_user_id() === $job['owner'] && in_array( $job['status'], array( 'ready', 'running' ), true ) ) {
				$this->render_job( $job );
			} else {
				if ( $job && get_current_user_id() === $job['owner'] ) {
					$this->summary( $job );
				}
				$this->render_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the initial role and reset-mode form.
	 *
	 * @return void
	 */
	private function render_form() {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="bpr_preview">
			<?php wp_nonce_field( 'bpr_preview' ); ?>
			<h2><?php esc_html_e( 'Choose recipients', 'bulk-password-reset' ); ?></h2>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'User roles', 'bulk-password-reset' ); ?></legend>
				<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
					<?php
					if ( 'administrator' === $slug ) {
						continue; }
					?>
					<p><label><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( translate_user_role( $name ) ); ?></label></p>
				<?php endforeach; ?>
			</fieldset>
			<h2><?php esc_html_e( 'Reset mode', 'bulk-password-reset' ); ?></h2>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Reset mode', 'bulk-password-reset' ); ?></legend>
				<p><label><input type="radio" name="mode" value="link" checked> <?php esc_html_e( 'Send reset links. Existing passwords and sessions keep working until users change their passwords.', 'bulk-password-reset' ); ?></label></p>
				<p><label><input type="radio" name="mode" value="invalidate"> <?php esc_html_e( 'Invalidate passwords and browser sessions immediately, then send reset links.', 'bulk-password-reset' ); ?></label></p>
			</fieldset>
			<p><label for="bpr-note"><?php esc_html_e( 'Optional plain-text note for the email', 'bulk-password-reset' ); ?></label></p>
			<textarea id="bpr-note" name="note" class="large-text" rows="4" maxlength="2000"></textarea>
			<?php submit_button( __( 'Preview recipients', 'bulk-password-reset' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render a preview, confirmation and batch controls.
	 *
	 * @param array $job Current job.
	 * @return void
	 */
	private function render_job( $job ) {
		$this->summary( $job );
		if ( 'ready' === $job['status'] ) {
			$service = new Service();
			$users   = $service->query( $job['selection'] )->get_results();
			?>
			<h2><?php esc_html_e( 'Recipient preview', 'bulk-password-reset' ); ?></h2>
			<p><?php esc_html_e( 'Showing the first 20 candidates. The candidate count includes protected accounts, which will be skipped. Roles and permissions are checked again during processing; new accounts registered after this preview are excluded.', 'bulk-password-reset' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Username', 'bulk-password-reset' ); ?></th><th scope="col"><?php esc_html_e( 'Eligibility', 'bulk-password-reset' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<tr><td><?php echo esc_html( $user->user_login ); ?></td><td><?php echo $service->can_target( $user ) ? esc_html__( 'Eligible, subject to WordPress reset policy', 'bulk-password-reset' ) : esc_html__( 'Protected; will be skipped', 'bulk-password-reset' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}
		?>
		<p><strong><?php echo 'invalidate' === $job['mode'] ? esc_html__( 'Mode: invalidate passwords and browser sessions, then email reset links.', 'bulk-password-reset' ) : esc_html__( 'Mode: email reset links; existing passwords remain valid.', 'bulk-password-reset' ); ?></strong></p>
		<?php if ( 'invalidate' === $job['mode'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Immediate invalidation cannot be undone. If email fails, the password still changes. Verify your site can send mail before starting. Application passwords and external sign-in providers are not revoked.', 'bulk-password-reset' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $job['note'] ) : ?>
			<p><?php esc_html_e( 'Email note:', 'bulk-password-reset' ); ?> <?php echo esc_html( $job['note'] ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Each request processes up to 20 candidates. Use Continue for the next batch; no work runs in the background. You can resume this job for 48 hours.', 'bulk-password-reset' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="bpr_process">
			<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
			<?php wp_nonce_field( 'bpr_process_' . $job['id'] ); ?>
			<?php if ( 'ready' === $job['status'] ) : ?>
				<p><label><input type="checkbox" name="confirm" value="1"> <?php esc_html_e( 'I have reviewed the recipients and reset mode, and confirm this bulk reset.', 'bulk-password-reset' ); ?></label></p>
			<?php endif; ?>
			<?php submit_button( 'ready' === $job['status'] ? __( 'Start bulk reset', 'bulk-password-reset' ) : __( 'Continue next batch', 'bulk-password-reset' ), 'primary', 'submit', false ); ?>
			<?php submit_button( __( 'Cancel remaining work', 'bulk-password-reset' ), 'secondary', 'cancel', false ); ?>
		</form>
		<?php
	}

	/**
	 * Report aggregate results without storing reset credentials.
	 *
	 * @param array $job Current job.
	 * @return void
	 */
	private function summary( $job ) {
		$labels = array(
			'ready'     => __( 'Awaiting confirmation', 'bulk-password-reset' ),
			'running'   => __( 'In progress', 'bulk-password-reset' ),
			'complete'  => __( 'Complete', 'bulk-password-reset' ),
			'cancelled' => __( 'Cancelled', 'bulk-password-reset' ),
		);
		echo '<h2>' . esc_html( $job['expires'] <= time() ? __( 'Expired', 'bulk-password-reset' ) : $labels[ $job['status'] ] ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: candidates, 2: sent, 3: failed, 4: skipped, 5: interrupted, 6: invalidated. */
				__( 'Candidates at preview: %1$d. Emails accepted for sending: %2$d. Failed: %3$d. Skipped: %4$d. Interrupted / outcome unknown: %5$d. Passwords invalidated: %6$d.', 'bulk-password-reset' ),
				$job['total'],
				$job['sent'],
				$job['failed'],
				$job['skipped'],
				$job['uncertain'] + ( $job['in_flight'] ? 1 : 0 ),
				$job['invalidated']
			)
		) . '</p>';
		echo '<p>' . esc_html__( 'Accepted for sending does not guarantee delivery. Interrupted accounts are not retried automatically; review them separately. No passwords or reset links are stored in this report.', 'bulk-password-reset' ) . '</p>';
		$review_ids = array_unique( array_merge( $job['failed_ids'], $job['uncertain_ids'], $job['in_flight'] ? array( $job['in_flight'] ) : array() ) );
		if ( $review_ids ) {
			echo '<p><strong>' . esc_html__( 'Accounts to review (up to 100 failures and 100 interrupted outcomes):', 'bulk-password-reset' ) . '</strong></p><ul>';
			foreach ( $review_ids as $review_id ) {
				$user = get_user_by( 'id', $review_id );
				echo '<li><a href="' . esc_url( get_edit_user_link( $review_id ) ) . '">' . esc_html( $user ? $user->user_login : (string) $review_id ) . '</a> (' . esc_html( (string) $review_id ) . ')</li>';
			}
			echo '</ul>';
		}
	}
}
