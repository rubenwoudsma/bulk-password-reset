<?php
/**
 * Presentation for the bulk password reset administration page.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Renders the reset workflow without performing any account changes. */
final class View {

	/**
	 * The current site's latest job, when available.
	 *
	 * @var array|null
	 */
	private $job;

	/**
	 * Whether to display the chooser while retaining the latest results.
	 *
	 * @var bool
	 */
	private $start_new;

	/**
	 * Whether the separate email preferences view was requested.
	 *
	 * @var bool
	 */
	private $email;

	/**
	 * Create the administration view.
	 *
	 * @param array|null $job Current job.
	 * @param bool       $start_new Whether the chooser was requested.
	 * @param bool       $email Whether email preferences were requested.
	 */
	public function __construct( $job, $start_new = false, $email = false ) {
		$this->job       = $job;
		$this->start_new = $start_new;
		$this->email     = $email;
	}

	/**
	 * Render the complete plugin page.
	 *
	 * @return void
	 */
	public function render() {
		$job         = $this->job;
		$active      = $job && $job['expires'] > time() && in_array( $job['status'], array( 'ready', 'running' ), true );
		$owned       = $job && get_current_user_id() === $job['owner'];
		$show_report = $owned && ! $active && ! $this->start_new;
		$step        = $active && $owned ? ( 'ready' === $job['status'] ? 2 : 3 ) : ( $show_report ? 3 : 1 );
		$icon        = plugins_url( 'assets/plugin-icon.png', dirname( __DIR__ ) . '/bulk_password_reset.php' );
		$logo        = plugins_url( 'assets/ruben-woudsma-logo.png', dirname( __DIR__ ) . '/bulk_password_reset.php' );
		?>
		<div class="wrap bpr-app">
			<header class="bpr-header">
				<div class="bpr-brand">
					<img class="bpr-plugin-icon" src="<?php echo esc_url( $icon ); ?>" width="64" height="64" alt="">
					<div>
						<p class="bpr-eyebrow"><?php esc_html_e( 'A WordPress tool by Ruben Woudsma', 'bulk-password-reset' ); ?></p>
						<h1><?php esc_html_e( 'Bulk Password Reset', 'bulk-password-reset' ); ?></h1>
						<p class="bpr-intro"><?php esc_html_e( 'A fresh start for your users. A clear, careful process for you.', 'bulk-password-reset' ); ?></p>
					</div>
				</div>
				<span class="bpr-free-badge"><span class="dashicons dashicons-heart" aria-hidden="true"></span><?php esc_html_e( 'Free. Every feature.', 'bulk-password-reset' ); ?></span>
			</header>

			<nav class="bpr-navigation" aria-label="<?php esc_attr_e( 'Bulk Password Reset sections', 'bulk-password-reset' ); ?>">
				<a href="<?php echo esc_url( Admin::url() ); ?>" <?php echo ! $this->email ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Bulk reset', 'bulk-password-reset' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'view', 'email', Admin::url() ) ); ?>" <?php echo $this->email ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Email settings', 'bulk-password-reset' ); ?></a>
			</nav>
			<?php
			if ( ! $this->email ) {
				$this->steps( $step ); }
			?>

			<?php if ( is_multisite() ) : ?>
				<div class="bpr-callout bpr-callout--warning"><span class="dashicons dashicons-networking" aria-hidden="true"></span><p><?php esc_html_e( 'Multisite: only super administrators can run this tool. Selection is limited to this site, but passwords and sessions are shared across the network.', 'bulk-password-reset' ); ?></p></div>
			<?php endif; ?>

			<div class="bpr-layout">
				<main class="bpr-main" aria-label="<?php esc_attr_e( 'Password reset workflow', 'bulk-password-reset' ); ?>">
					<?php
					if ( $this->email ) {
						( new Email_View() )->render();
					} elseif ( $active && ! $owned ) {
						?>
							<section class="bpr-card bpr-card-body"><h2><?php esc_html_e( 'A reset is already in progress', 'bulk-password-reset' ); ?></h2><p><?php esc_html_e( 'Another administrator has an active password reset on this site. They must finish or cancel it, or let it expire after 48 hours.', 'bulk-password-reset' ); ?></p></section>
						<?php
					} elseif ( $active && $owned ) {
						$this->job( $job );
					} elseif ( $show_report ) {
						$this->summary( $job );
						?>
						<div class="bpr-result-actions">
							<a class="button button-primary bpr-button" href="<?php echo esc_url( add_query_arg( 'view', 'new', Admin::url() ) ); ?>"><?php esc_html_e( 'Start a new reset', 'bulk-password-reset' ); ?></a>
							<p class="bpr-help"><?php esc_html_e( 'These results remain available until you create a new preview.', 'bulk-password-reset' ); ?></p>
						</div>
						<?php
					} else {
						if ( $owned ) {
							?>
							<p class="bpr-help"><a href="<?php echo esc_url( Admin::url() ); ?>"><?php esc_html_e( 'View previous reset results', 'bulk-password-reset' ); ?></a>. <?php esc_html_e( 'Creating a new preview replaces the previous results.', 'bulk-password-reset' ); ?></p>
							<?php
						}
						$this->form();
					}
					?>
				</main>
				<aside class="bpr-sidebar" aria-label="<?php esc_attr_e( 'Reset guidance and author', 'bulk-password-reset' ); ?>">
					<section class="bpr-card bpr-card-body bpr-safety">
						<span class="bpr-aside-icon dashicons dashicons-shield-alt" aria-hidden="true"></span>
						<h2><?php esc_html_e( 'Care built in', 'bulk-password-reset' ); ?></h2>
						<p><?php esc_html_e( 'You stay in control at every step.', 'bulk-password-reset' ); ?></p>
						<ul class="bpr-check-list">
							<li><?php esc_html_e( 'Preview before you send or change anything.', 'bulk-password-reset' ); ?></li>
							<li><?php esc_html_e( 'Your own account and privileged accounts are always protected.', 'bulk-password-reset' ); ?></li>
							<li><?php esc_html_e( 'Users choose their own password through a secure reset link.', 'bulk-password-reset' ); ?></li>
						</ul>
						<details class="bpr-details"><summary><?php esc_html_e( 'Which accounts are protected?', 'bulk-password-reset' ); ?></summary><p><?php esc_html_e( 'Your own account, administrators, and accounts that can manage settings or edit users are always excluded.', 'bulk-password-reset' ); ?></p></details>
					</section>
					<section class="bpr-card bpr-card-body bpr-author">
						<div class="bpr-author-heading">
							<img class="bpr-author-logo" src="<?php echo esc_url( $logo ); ?>" width="56" height="56" alt="">
							<div><p class="bpr-eyebrow"><?php esc_html_e( 'Made by', 'bulk-password-reset' ); ?></p><h2>Ruben Woudsma</h2></div>
						</div>
						<p><?php esc_html_e( 'Useful tools, shared freely. This plugin is open source, with no paid tier or total user limit.', 'bulk-password-reset' ); ?></p>
						<a class="bpr-author-link" href="https://rubenwoudsma.nl/" target="_blank" rel="noopener noreferrer">rubenwoudsma.nl <span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'bulk-password-reset' ); ?></span></a>
					</section>
				</aside>
			</div>
			<footer class="bpr-footer"><?php echo esc_html( 'Bulk Password Reset ' . BULK_PASSWORD_RESET_VERSION ); ?><span aria-hidden="true"> · </span><?php esc_html_e( 'Open source. No tracking.', 'bulk-password-reset' ); ?></footer>
		</div>
		<?php
	}

	/**
	 * Show the three steps with an accessible current-step label.
	 *
	 * @param int $current Current step number.
	 * @return void
	 */
	private function steps( $current ) {
		$labels = array(
			1 => __( 'Choose recipients', 'bulk-password-reset' ),
			2 => __( 'Review & confirm', 'bulk-password-reset' ),
			3 => __( 'Send & review results', 'bulk-password-reset' ),
		);
		?>
		<ol class="bpr-steps" aria-label="<?php esc_attr_e( 'Reset workflow steps', 'bulk-password-reset' ); ?>">
			<?php foreach ( $labels as $number => $label ) : ?>
				<li class="<?php echo $number === $current ? 'is-current' : ( $number < $current ? 'is-done' : '' ); ?>" aria-current="<?php echo $number === $current ? 'step' : 'false'; ?>">
					<span class="bpr-step-number" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span><span><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Render the recipient and reset-mode form.
	 *
	 * @return void
	 */
	private function form() {
		?>
		<form class="bpr-card bpr-reset-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="bpr_preview">
			<?php wp_nonce_field( 'bpr_preview' ); ?>
			<section class="bpr-form-section">
				<div class="bpr-section-heading"><span class="bpr-section-number" aria-hidden="true">01</span><h2><?php esc_html_e( 'Choose recipients', 'bulk-password-reset' ); ?></h2></div>
				<p class="bpr-section-description"><?php esc_html_e( 'Select one or more user roles. Each recipient is checked before processing.', 'bulk-password-reset' ); ?></p>
				<fieldset class="bpr-roles">
					<legend class="screen-reader-text"><?php esc_html_e( 'User roles', 'bulk-password-reset' ); ?></legend>
					<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
						<?php
						if ( 'administrator' === $slug ) {
							continue; }
						?>
						<label class="bpr-role"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>"><span><?php echo esc_html( translate_user_role( $name ) ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="bpr-help"><?php esc_html_e( 'Want to choose individual recipients?', 'bulk-password-reset' ); ?> <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Select them on the Users screen.', 'bulk-password-reset' ); ?></a></p>
			</section>
			<section class="bpr-form-section">
				<div class="bpr-section-heading"><span class="bpr-section-number" aria-hidden="true">02</span><h2><?php esc_html_e( 'Choose how to reset', 'bulk-password-reset' ); ?></h2></div>
				<fieldset class="bpr-modes">
					<legend class="screen-reader-text"><?php esc_html_e( 'Reset mode', 'bulk-password-reset' ); ?></legend>
					<label class="bpr-mode">
						<input type="radio" name="mode" value="link" checked>
						<span class="bpr-mode-content"><span class="bpr-mode-title"><?php esc_html_e( 'Send reset links', 'bulk-password-reset' ); ?> <span class="bpr-recommended"><?php esc_html_e( 'Recommended', 'bulk-password-reset' ); ?></span></span><span class="bpr-mode-description"><?php esc_html_e( 'Email each recipient a secure link to choose a new password. Existing passwords and browser sessions keep working until they change it.', 'bulk-password-reset' ); ?></span></span>
					</label>
					<label class="bpr-mode bpr-mode--immediate">
						<input type="radio" name="mode" value="invalidate">
						<span class="bpr-mode-content"><span class="bpr-mode-title"><?php esc_html_e( 'Invalidate immediately', 'bulk-password-reset' ); ?></span><span class="bpr-mode-description"><?php esc_html_e( 'Stop current passwords and browser sessions from working, then email reset links. Choose this when access must end straight away.', 'bulk-password-reset' ); ?></span></span>
					</label>
				</fieldset>
			</section>
			<section class="bpr-form-section">
				<div class="bpr-section-heading"><span class="bpr-section-number" aria-hidden="true">03</span><h2><label for="bpr-note"><?php esc_html_e( 'Add a personal note', 'bulk-password-reset' ); ?></label> <span class="bpr-optional"><?php esc_html_e( 'Optional', 'bulk-password-reset' ); ?></span></h2></div>
				<p id="bpr-note-help" class="bpr-section-description"><?php esc_html_e( 'Add context for your recipients. This plain-text note appears in their reset email.', 'bulk-password-reset' ); ?></p>
				<textarea id="bpr-note" name="note" rows="4" maxlength="2000" aria-describedby="bpr-note-help"></textarea>
			</section>
			<div class="bpr-form-actions">
				<p><?php esc_html_e( 'You’ll review the recipients before anything is sent or changed.', 'bulk-password-reset' ); ?></p>
				<?php submit_button( __( 'Preview recipients', 'bulk-password-reset' ), 'primary bpr-button', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render a preview or the next-batch controls for an active job.
	 *
	 * @param array $job Current job.
	 * @return void
	 */
	private function job( $job ) {
		$this->summary( $job );
		?>
		<section class="bpr-card">
			<div class="bpr-card-body">
				<?php if ( 'ready' === $job['status'] ) : ?>
					<h2><?php esc_html_e( 'Recipient preview', 'bulk-password-reset' ); ?></h2>
					<p class="bpr-section-description"><?php esc_html_e( 'Showing the first 20 selected users. The preview count includes protected accounts, which will be skipped. Roles and permissions are checked again before sending; users registered after the preview are excluded.', 'bulk-password-reset' ); ?></p>
					<?php $this->recipients( $job ); ?>
				<?php else : ?>
					<h2><?php esc_html_e( 'Ready for the next batch', 'bulk-password-reset' ); ?></h2>
				<?php endif; ?>
				<div class="bpr-mode-summary"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><strong><?php echo 'invalidate' === $job['mode'] ? esc_html__( 'Invalidate passwords and browser sessions, then send reset links', 'bulk-password-reset' ) : esc_html__( 'Send reset links; existing passwords remain valid', 'bulk-password-reset' ); ?></strong></div>
				<p class="bpr-help"><?php echo isset( $job['email']['format'] ) && 'html' === $job['email']['format'] ? esc_html__( 'Email format: HTML with a reset button. The confirmed logo is used when available.', 'bulk-password-reset' ) : esc_html__( 'Email format: plain text.', 'bulk-password-reset' ); ?></p>
				<?php $email_logo = isset( $job['email']['logo_id'] ) ? Email_Settings::image( $job['email']['logo_id'] ) : null; ?>
				<?php if ( $email_logo ) : ?>
					<p><img src="<?php echo esc_url( $email_logo['url'] ); ?>" width="<?php echo esc_attr( (string) $email_logo['width'] ); ?>" height="<?php echo esc_attr( (string) $email_logo['height'] ); ?>" alt="<?php esc_attr_e( 'Email logo', 'bulk-password-reset' ); ?>"></p>
				<?php endif; ?>
				<?php if ( 'invalidate' === $job['mode'] ) : ?>
					<div class="bpr-callout bpr-callout--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p><?php esc_html_e( 'Immediate invalidation cannot be undone. If email fails, the password still changes. Verify your site can send mail before starting. Application passwords and external sign-in providers are not revoked.', 'bulk-password-reset' ); ?></p></div>
				<?php endif; ?>
				<?php if ( $job['note'] ) : ?>
					<div class="bpr-note-preview"><h3><?php esc_html_e( 'Your email note', 'bulk-password-reset' ); ?></h3><p><?php echo esc_html( $job['note'] ); ?></p></div>
				<?php endif; ?>
				<p class="bpr-help"><?php esc_html_e( 'Each request processes up to 20 selected users. Further batches run when you click Continue. You can resume this reset within 48 hours.', 'bulk-password-reset' ); ?></p>
			</div>
			<form class="bpr-confirm-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="bpr_process">
				<input type="hidden" name="job" value="<?php echo esc_attr( $job['id'] ); ?>">
				<?php wp_nonce_field( 'bpr_process_' . $job['id'] ); ?>
				<?php if ( 'ready' === $job['status'] ) : ?>
					<label class="bpr-confirm"><input type="checkbox" name="confirm" value="1"><span><?php esc_html_e( 'I have reviewed the recipients and reset mode, and confirm this bulk reset.', 'bulk-password-reset' ); ?></span></label>
				<?php endif; ?>
				<div class="bpr-job-actions">
					<?php submit_button( 'ready' === $job['status'] ? __( 'Start bulk reset', 'bulk-password-reset' ) : __( 'Continue next batch', 'bulk-password-reset' ), 'primary bpr-button', 'submit', false ); ?>
					<?php submit_button( __( 'Cancel remaining work', 'bulk-password-reset' ), 'secondary bpr-button-secondary', 'cancel', false ); ?>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Display the first page of candidates with protected-account labels.
	 *
	 * @param array $job Current job.
	 * @return void
	 */
	private function recipients( $job ) {
		$service = new Service();
		$users   = $service->query( $job['selection'] )->get_results();
		?>
		<table class="widefat bpr-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Username', 'bulk-password-reset' ); ?></th><th scope="col"><?php esc_html_e( 'Eligibility', 'bulk-password-reset' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $users ) : ?>
				<tr><td colspan="2"><?php esc_html_e( 'No accounts match this selection.', 'bulk-password-reset' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $users as $user ) : ?>
				<tr><td><?php echo esc_html( $user->user_login ); ?></td><td><?php echo $service->can_target( $user ) ? esc_html__( 'Eligible, subject to WordPress reset policy', 'bulk-password-reset' ) : esc_html__( 'Protected; will be skipped', 'bulk-password-reset' ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show honest progress and accounts requiring individual review.
	 *
	 * @param array $job Current job.
	 * @return void
	 */
	private function summary( $job ) {
		$labels               = array(
			'ready'     => __( 'Awaiting confirmation', 'bulk-password-reset' ),
			'running'   => __( 'In progress', 'bulk-password-reset' ),
			'complete'  => __( 'Complete', 'bulk-password-reset' ),
			'cancelled' => __( 'Cancelled', 'bulk-password-reset' ),
		);
		$stats                = array(
			'total'       => __( 'Selected users at preview', 'bulk-password-reset' ),
			'sent'        => __( 'Emails accepted', 'bulk-password-reset' ),
			'failed'      => __( 'Failed', 'bulk-password-reset' ),
			'skipped'     => __( 'Skipped', 'bulk-password-reset' ),
			'uncertain'   => __( 'Outcome unknown', 'bulk-password-reset' ),
			'invalidated' => __( 'Passwords invalidated', 'bulk-password-reset' ),
		);
		$counts               = $job;
		$expired              = $job['expires'] <= time() && in_array( $job['status'], array( 'ready', 'running' ), true );
		$counts['uncertain'] += $job['in_flight'] ? 1 : 0;
		$review_ids           = array_unique( array_merge( $job['failed_ids'], $job['uncertain_ids'], $job['in_flight'] ? array( $job['in_flight'] ) : array() ) );
		if ( 'ready' === $job['status'] && $job['expires'] > time() ) {
			?>
			<section class="bpr-card bpr-preview-overview">
				<div><h2><?php esc_html_e( 'Awaiting confirmation', 'bulk-password-reset' ); ?></h2><p><?php esc_html_e( 'Nothing has been sent or changed. Review the recipients and confirm to begin.', 'bulk-password-reset' ); ?></p></div>
				<div class="bpr-preview-count"><strong><?php echo esc_html( number_format_i18n( $job['total'] ) ); ?></strong><span><?php esc_html_e( 'Selected users at preview', 'bulk-password-reset' ); ?></span></div>
			</section>
			<?php
			return;
		}
		?>
		<section class="bpr-card bpr-report">
			<div class="bpr-report-heading"><h2><?php echo esc_html( $expired ? __( 'Expired', 'bulk-password-reset' ) : $labels[ $job['status'] ] ); ?></h2><span class="bpr-status-dot" aria-hidden="true"></span></div>
			<dl class="bpr-stats">
				<?php foreach ( $stats as $key => $label ) : ?>
					<div class="bpr-stat" data-bpr-stat="<?php echo esc_attr( $key ); ?>"><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
			<div class="bpr-report-footnote"><p><?php esc_html_e( 'Accepted for sending does not guarantee delivery. Users with an unknown outcome are not retried automatically; review them separately. No passwords or reset links are stored in this report.', 'bulk-password-reset' ); ?></p></div>
			<?php if ( $review_ids ) : ?>
				<div class="bpr-review-accounts"><h3><?php esc_html_e( 'Users to review', 'bulk-password-reset' ); ?></h3><p><?php esc_html_e( 'Up to 100 users with failed resets and 100 users with unknown outcomes are listed here.', 'bulk-password-reset' ); ?></p><ul>
					<?php
					$review_users = get_users(
						array(
							'blog_id' => 0,
							'include' => $review_ids,
							'fields'  => array( 'ID', 'user_login' ),
						)
					);
					$users_by_id  = array_column( $review_users, null, 'ID' );
					foreach ( $review_ids as $review_id ) :
						$user      = isset( $users_by_id[ $review_id ] ) ? $users_by_id[ $review_id ] : null;
						$label     = $user ? $user->user_login : (string) $review_id;
						$edit_link = get_edit_user_link( $review_id );
						?>
						<li>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $label ); ?>
							<?php endif; ?>
							(<?php echo esc_html( (string) $review_id ); ?>)
						</li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>
		</section>
		<?php
	}
}
