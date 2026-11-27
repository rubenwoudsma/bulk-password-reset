<?php
/**
 * Email preferences, nonfunctional previews and operator-only test mail.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Renders preferences separately from the recipient/reset workflow. */
final class Email_View {

	/**
	 * Render authorized settings. Direct callers receive no private content.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Service::can_manage() ) {
			return;
		}
		$settings  = Email_Settings::get();
		$image     = Email_Settings::image( $settings['logo_id'] );
		$site_logo = 'site' === $settings['logo'] ? Email_Settings::image( absint( get_theme_mod( 'custom_logo' ) ) ) : null;
		$this->notice();
		if ( 'html' === $settings['format'] && 'site' === $settings['logo'] && ! $site_logo ) {
			?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No compatible public PNG, JPEG or GIF site logo is currently configured. Emails will show the site name as text instead.', 'bulk-password-reset' ); ?></p></div>
			<?php
		}
		?>
		<section class="bpr-card bpr-card-body">
			<h2><?php esc_html_e( 'Reset email settings', 'bulk-password-reset' ); ?></h2>
			<p><?php esc_html_e( 'Choose how this site\'s reset emails look. Changes apply to new bulk-reset previews; an existing reset keeps its confirmed email settings.', 'bulk-password-reset' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="bpr-email-form">
				<input type="hidden" name="action" value="bpr_email_save">
				<?php wp_nonce_field( 'bpr_email_save' ); ?>
				<fieldset><legend><?php esc_html_e( 'Email format', 'bulk-password-reset' ); ?></legend>
					<label><input type="radio" name="email[format]" value="plain" <?php checked( $settings['format'], 'plain' ); ?>> <?php esc_html_e( 'Plain text (default)', 'bulk-password-reset' ); ?></label>
					<label><input type="radio" name="email[format]" value="html" <?php checked( $settings['format'], 'html' ); ?>> <?php esc_html_e( 'HTML with a reset button', 'bulk-password-reset' ); ?></label>
				</fieldset>
				<div><label for="bpr-email-logo"><?php esc_html_e( 'Logo in HTML emails', 'bulk-password-reset' ); ?></label>
					<select name="email[logo]" id="bpr-email-logo" aria-describedby="bpr-logo-help">
						<option value="none" <?php selected( $settings['logo'], 'none' ); ?>><?php esc_html_e( 'No logo', 'bulk-password-reset' ); ?></option>
						<option value="site" <?php selected( $settings['logo'], 'site' ); ?>><?php esc_html_e( 'Use the site logo', 'bulk-password-reset' ); ?></option>
						<?php if ( current_user_can( 'upload_files' ) || 'custom' === $settings['logo'] ) : ?>
							<option value="custom" <?php selected( $settings['logo'], 'custom' ); ?>><?php esc_html_e( 'Use a selected Media Library image', 'bulk-password-reset' ); ?></option>
						<?php endif; ?>
					</select>
					<p id="bpr-logo-help" class="bpr-help"><?php esc_html_e( 'The site logo is configured in your theme\'s Site Editor or Customizer. Choose a public PNG, JPEG or GIF. If an image is unavailable, emails still show the site name.', 'bulk-password-reset' ); ?></p>
				</div>
				<div>
					<input type="hidden" name="email[logo_id]" id="bpr-email-logo-id" value="<?php echo esc_attr( (string) $settings['logo_id'] ); ?>">
					<div id="bpr-email-logo-preview" aria-live="polite">
					<?php
					if ( $image ) :
						?>
						<img src="<?php echo esc_url( $image['url'] ); ?>" width="<?php echo esc_attr( (string) $image['width'] ); ?>" height="<?php echo esc_attr( (string) $image['height'] ); ?>" alt="<?php esc_attr_e( 'Selected email logo', 'bulk-password-reset' ); ?>"><?php endif; ?></div>
					<?php if ( current_user_can( 'upload_files' ) ) : ?>
						<button type="button" class="button" id="bpr-email-choose-logo"><?php esc_html_e( 'Choose image', 'bulk-password-reset' ); ?></button>
						<button type="button" class="button" id="bpr-email-clear-logo"><?php esc_html_e( 'Remove selected image', 'bulk-password-reset' ); ?></button>
					<?php endif; ?>
				</div>
				<?php submit_button( __( 'Save email settings', 'bulk-password-reset' ), 'primary bpr-button', 'submit', false ); ?>
			</form>
		</section>
		<section class="bpr-card bpr-card-body">
			<h2><?php esc_html_e( 'Preview your saved email settings', 'bulk-password-reset' ); ?></h2>
			<p><?php esc_html_e( 'Save settings to refresh these examples. They use a fictional username and a nonfunctional link; no password, session, reset key or bulk-job report is changed.', 'bulk-password-reset' ); ?></p>
			<?php
			foreach ( array(
				'link'       => __( 'Send reset links', 'bulk-password-reset' ),
				'invalidate' => __( 'Invalidate immediately', 'bulk-password-reset' ),
			) as $mode => $label ) :
				?>
					<?php $message = Email::example( $mode ); ?>
				<details class="bpr-email-example" <?php echo 'link' === $mode ? 'open' : ''; ?>>
					<summary><?php echo esc_html( $label ); ?></summary>
					<p><strong><?php esc_html_e( 'Subject:', 'bulk-password-reset' ); ?></strong> <?php echo esc_html( $message['subject'] ); ?></p>
					<?php if ( $message['html'] ) : ?>
						<iframe class="bpr-email-preview" title="<?php echo esc_attr( $label ); ?>" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr( $message['html'] ); ?>"></iframe>
						<details><summary><?php esc_html_e( 'Plain-text alternative', 'bulk-password-reset' ); ?></summary><pre class="bpr-email-text"><?php echo esc_html( $message['text'] ); ?></pre></details>
					<?php else : ?>
						<pre class="bpr-email-text"><?php echo esc_html( $message['text'] ); ?></pre>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</section>
		<section class="bpr-card bpr-card-body">
			<h2><?php esc_html_e( 'Send yourself a test email', 'bulk-password-reset' ); ?></h2>
			<p><?php esc_html_e( 'Send a nonfunctional example using the saved settings to your WordPress profile email only. No recipient account is reset.', 'bulk-password-reset' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="bpr-email-form">
				<input type="hidden" name="action" value="bpr_email_test">
				<?php wp_nonce_field( 'bpr_email_test' ); ?>
				<p><strong><?php esc_html_e( 'Test recipient:', 'bulk-password-reset' ); ?></strong> <?php echo esc_html( wp_get_current_user()->user_email ); ?></p>
				<label for="bpr-email-example-mode"><?php esc_html_e( 'Example reset mode', 'bulk-password-reset' ); ?></label>
				<select id="bpr-email-example-mode" name="example_mode"><option value="link"><?php esc_html_e( 'Send reset links', 'bulk-password-reset' ); ?></option><option value="invalidate"><?php esc_html_e( 'Invalidate immediately', 'bulk-password-reset' ); ?></option></select>
				<?php submit_button( __( 'Send test email to my profile address', 'bulk-password-reset' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="bpr-help"><?php esc_html_e( 'Your existing WordPress mail provider is used. HTML includes a plain-text alternative with the standard PHPMailer transport; API mail providers control their own alternatives. Acceptance for sending does not guarantee delivery. Check your inbox and spam folder.', 'bulk-password-reset' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Show only fixed notices; the request never supplies notice content.
	 *
	 * @return void
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed, read-only notice after a protected POST; not a source of email or account state.
		$notice = isset( $_GET['email_notice'] ) && is_string( $_GET['email_notice'] ) ? sanitize_key( wp_unslash( $_GET['email_notice'] ) ) : '';
		$labels = array(
			'saved'  => __( 'Email settings saved. Review the examples below.', 'bulk-password-reset' ),
			'sent'   => __( 'Test email accepted for sending. Check your inbox and spam folder; no account was reset.', 'bulk-password-reset' ),
			'failed' => __( 'The test email was not accepted for sending. Check your site\'s mail configuration; no account was reset.', 'bulk-password-reset' ),
		);
		if ( isset( $labels[ $notice ] ) ) {
			?>
			<div class="notice <?php echo 'failed' === $notice ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $labels[ $notice ] ); ?></p></div>
			<?php
		}
	}
}
