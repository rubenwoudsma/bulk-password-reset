<?php
/**
 * Localized plain-text and optional HTML reset mail through WordPress mail.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Renders escaped messages and scopes multipart state to its own mail. */
final class Email {

	/**
	 * Build a message without generating a key or changing any account or job.
	 * The caller supplies either a genuine core URL or a nonfunctional example.
	 *
	 * @param string $login Account login or a fictional preview login.
	 * @param string $mode Reset mode.
	 * @param string $note Plain-text administrator note.
	 * @param string $url Core reset URL or example URL.
	 * @param array  $settings Email settings snapshot.
	 * @param bool   $test Whether this is a nonfunctional example.
	 * @return array Subject, plain text and optional HTML.
	 */
	public static function message( $login, $mode, $note, $url, $settings, $test = false ) {
		$settings = Email_Settings::snapshot( $settings );
		$site     = sanitize_text_field( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
		$intro    = sprintf(
			/* translators: %s: site name. */
			__( 'The administrator of %s has requested that you choose a new password.', 'bulk-password-reset' ),
			$site
		);
		$warning = 'invalidate' === $mode ? __( 'Your previous password and browser sessions have been invalidated.', 'bulk-password-reset' ) : '';
		$account = sprintf(
			/* translators: %s: account login. */
			__( 'Username: %s', 'bulk-password-reset' ),
			$login
		);
		$help    = __( 'If the link has expired, request another using the Lost your password link on the login screen.', 'bulk-password-reset' );
		$example = $test ? __( 'This is a test email. Its example link cannot reset a password. No account has been changed.', 'bulk-password-reset' ) : '';
		$note    = sanitize_textarea_field( $note );
		$text    = '' !== $example ? $example . "\n\n" : '';
		$text   .= $intro . "\n\n";
		if ( '' !== $warning ) {
			$text .= $warning . "\n\n";
		}
		if ( '' !== $note ) {
			$text .= $note . "\n\n";
		}
		$text   .= $account . "\n\n";
		$text   .= __( 'Choose your new password using this single-use link:', 'bulk-password-reset' ) . "\n" . $url . "\n\n" . $help . "\n";
		$subject = $test
			? sprintf( /* translators: %s: site name. */ __( '[%s] Test password reset email', 'bulk-password-reset' ), $site )
			: sprintf( /* translators: %s: site name. */ __( '[%s] Choose a new password', 'bulk-password-reset' ), $site );
		$html    = '';
		if ( 'html' === $settings['format'] ) {
			$logo = Email_Settings::image( $settings['logo_id'] );
			ob_start();
			?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $subject ); ?></title></head>
<body style="margin:0;padding:0;background-color:#f3f6f8;color:#243746;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f6f8;"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #dce4ea;border-radius:8px;"><tr><td style="padding:28px;">
			<?php if ( $logo ) : ?>
<p style="margin:0 0 16px;"><img src="<?php echo esc_url( $logo['url'] ); ?>" width="<?php echo esc_attr( (string) $logo['width'] ); ?>" height="<?php echo esc_attr( (string) $logo['height'] ); ?>" alt="<?php echo esc_attr( $site ); ?>" style="display:block;max-width:100%;height:auto;border:0;"></p>
			<?php endif; ?>
<p style="margin:0 0 24px;font-size:16px;font-weight:bold;line-height:1.5;"><?php echo esc_html( $site ); ?></p>
<h1 style="margin:0 0 20px;font-size:26px;line-height:1.3;color:#14334b;"><?php esc_html_e( 'Choose a new password', 'bulk-password-reset' ); ?></h1>
			<?php if ( '' !== $example ) : ?>
<p style="padding:14px;background-color:#edf4f8;font-size:16px;line-height:1.6;"><?php echo esc_html( $example ); ?></p>
			<?php endif; ?>
<p style="font-size:16px;line-height:1.6;"><?php echo esc_html( $intro ); ?></p>
			<?php if ( '' !== $warning ) : ?>
<p style="padding:14px;background-color:#fff4dc;color:#634800;font-size:16px;line-height:1.6;"><?php echo esc_html( $warning ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $note ) : ?>
<p style="padding:14px;border-inline-start:3px solid #007398;background-color:#f3f6f8;font-size:16px;line-height:1.6;"><?php echo nl2br( esc_html( $note ) ); ?></p>
			<?php endif; ?>
<p style="font-size:16px;line-height:1.6;"><?php echo esc_html( $account ); ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td bgcolor="#006b91" style="border-radius:5px;padding:14px 20px;"><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;color:#ffffff;font-size:16px;font-weight:bold;text-decoration:underline;line-height:1.5;"><?php esc_html_e( 'Choose a new password', 'bulk-password-reset' ); ?></a></td></tr></table>
<p style="font-size:14px;line-height:1.6;"><?php esc_html_e( 'If the button does not work, copy this link into your browser:', 'bulk-password-reset' ); ?><br><a href="<?php echo esc_url( $url ); ?>" style="color:#006b91;overflow-wrap:anywhere;word-break:break-all;"><?php echo esc_html( $url ); ?></a></p>
<p style="font-size:14px;line-height:1.6;color:#485c6b;"><?php echo esc_html( $help ); ?></p>
</td></tr></table>
<p style="margin:20px 12px;font-size:12px;line-height:1.5;color:#485c6b;"><?php echo esc_html( $site ); ?></p>
</td></tr></table>
</body></html>
			<?php
			$html = ob_get_clean();
		}
		return array(
			'subject' => $subject,
			'text'    => $text,
			'html'    => $html,
		);
	}

	/**
	 * Build sample data for preview or test mail. Never request a real reset key.
	 *
	 * @param string $mode Mode to illustrate.
	 * @return array
	 */
	public static function example( $mode = 'link' ) {
		return self::message(
			'example-user.',
			'invalidate' === $mode ? 'invalidate' : 'link',
			__( 'Example note from your administrator.', 'bulk-password-reset' ),
			'https://example.invalid/password-reset-example',
			Email_Settings::get(),
			true
		);
	}

	/**
	 * Send through wp_mail, preserving the site's configured sender/transport.
	 * Native PHPMailer gets a plain alternative. API mail adapters that bypass
	 * PHPMailer receive standard HTML/Content-Type and own MIME composition.
	 *
	 * @param string $to Single recipient.
	 * @param array  $message Rendered message.
	 * @return bool Accepted for sending, not proof of delivery.
	 */
	public static function send( $to, $message ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer exposes its plain alternative as AltBody.
		if ( '' === $message['html'] ) {
			return wp_mail( $to, $message['subject'], $message['text'] );
		}
		$token   = wp_generate_uuid4();
		$touched = array();
		$prepare = static function ( $mailer ) use ( $token, $message, &$touched ) {
			foreach ( $mailer->getCustomHeaders() as $header ) {
				if ( 'X-BPR-Email' === $header[0] && hash_equals( $token, $header[1] ) ) {
					// A temporary matching marker, removed before sending; no tracking.
					$mailer->clearCustomHeader( 'X-BPR-Email' );
					$touched[]       = array( $mailer, $mailer->AltBody );
					$mailer->AltBody = $message['text'];
					break;
				}
			}
		};
		add_action( 'phpmailer_init', $prepare, PHP_INT_MAX );
		try {
			return wp_mail( $to, $message['subject'], $message['html'], array( 'Content-Type: text/html; charset=UTF-8', 'X-BPR-Email: ' . $token ) );
		} finally {
			remove_action( 'phpmailer_init', $prepare, PHP_INT_MAX );
			foreach ( $touched as list( $mailer, $previous ) ) {
				if ( $mailer->AltBody === $message['text'] ) {
					$mailer->AltBody = $previous;
				}
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Send a nonfunctional example only to the authorized operator's profile email.
	 *
	 * @param string $mode Mode to illustrate.
	 * @return bool|\WP_Error
	 */
	public static function send_test( $mode ) {
		if ( ! Service::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'You cannot send password-reset test emails.', 'bulk-password-reset' ) );
		}
		$user = wp_get_current_user();
		if ( ! in_array( $mode, array( 'link', 'invalidate' ), true ) || ! is_email( $user->user_email ) ) {
			return new \WP_Error( 'test', __( 'Choose a valid example mode and check your profile email address.', 'bulk-password-reset' ) );
		}
		return self::send( $user->user_email, self::example( $mode ) );
	}
}
