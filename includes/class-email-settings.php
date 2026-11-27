<?php
/**
 * Site-local email preferences and validated Media Library branding.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Keeps email preferences separate from recipient and password operations. */
final class Email_Settings {

	const OPTION = 'bpr_email_settings';

	/**
	 * Defaults also used for jobs created before email settings existed.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'format'  => 'plain',
			'logo'    => 'none',
			'logo_id' => 0,
		);
	}

	/**
	 * Read only recognized settings; invalid stored values fall back safely.
	 *
	 * @return array
	 */
	public static function get() {
		return self::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Normalize settings from storage or a job snapshot, without side effects.
	 *
	 * @param mixed $value Possible settings.
	 * @return array
	 */
	public static function normalize( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array(
			'format'  => isset( $value['format'] ) && 'html' === $value['format'] ? 'html' : 'plain',
			'logo'    => isset( $value['logo'] ) && in_array( $value['logo'], array( 'site', 'custom' ), true ) ? $value['logo'] : 'none',
			'logo_id' => isset( $value['logo_id'] ) && is_scalar( $value['logo_id'] ) && ctype_digit( (string) $value['logo_id'] ) ? absint( $value['logo_id'] ) : 0,
		);
	}

	/**
	 * Save preferences only for an operator authorized to run bulk resets.
	 * The HTTP adapter must additionally enforce POST and its nonce.
	 *
	 * @param array $value Submitted, unslashed preferences.
	 * @return array|\WP_Error
	 */
	public static function save( $value ) {
		if ( ! Service::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'You cannot change password-reset email settings.', 'bulk-password-reset' ) );
		}
		if ( ! is_array( $value ) || ! isset( $value['format'], $value['logo'] )
			|| ! in_array( $value['format'], array( 'plain', 'html' ), true )
			|| ! in_array( $value['logo'], array( 'none', 'site', 'custom' ), true ) ) {
			return new \WP_Error( 'settings', __( 'Choose a valid email format and logo option.', 'bulk-password-reset' ) );
		}
		$settings = self::normalize( $value );
		if ( 'custom' === $settings['logo'] && ! self::image( $settings['logo_id'] ) ) {
			return new \WP_Error( 'logo', __( 'Choose a public PNG, JPEG or GIF image from this site\'s Media Library.', 'bulk-password-reset' ) );
		}
		if ( 'custom' !== $settings['logo'] ) {
			$settings['logo_id'] = 0;
		}
		if ( ! update_option( self::OPTION, $settings, false ) && get_option( self::OPTION ) !== $settings ) {
			return new \WP_Error( 'storage', __( 'Email settings could not be saved. Please try again.', 'bulk-password-reset' ) );
		}
		return $settings;
	}

	/**
	 * Resolve the site logo now, so future jobs retain the confirmed image ID.
	 * A deleted or no-longer-public image is omitted when rendering.
	 *
	 * @param array|null $settings Explicit settings, or the saved preferences.
	 * @return array
	 */
	public static function snapshot( $settings = null ) {
		$settings = self::normalize( null === $settings ? self::get() : $settings );
		$id       = 'site' === $settings['logo'] ? absint( get_theme_mod( 'custom_logo' ) ) : $settings['logo_id'];
		if ( 'plain' === $settings['format'] || 'none' === $settings['logo'] || ! self::image( $id ) ) {
			$settings['logo']    = 'none';
			$settings['logo_id'] = 0;
		} else {
			$settings['logo']    = 'custom';
			$settings['logo_id'] = $id;
		}
		return $settings;
	}

	/**
	 * Resolve a public, portable image without fetching remote content.
	 * Never accept an arbitrary URL, SVG, private attachment or other-site ID.
	 *
	 * @param int $id Attachment ID in the current site.
	 * @return array|null URL and constrained presentation dimensions.
	 */
	public static function image( $id ) {
		$post = get_post( $id );
		if ( ! $id || ! $post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status
			|| ! in_array( get_post_mime_type( $id ), array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return null;
		}
		if ( $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || ! is_post_publicly_viewable( $parent ) || '' !== $parent->post_password ) {
				return null;
			}
		}
		$image = wp_get_attachment_image_src( $id, 'medium' );
		if ( ! $image || ! $image[1] || ! $image[2] || ! is_string( $image[0] ) ) {
			return null;
		}
		$raw = $image[0];
		if ( str_starts_with( $raw, '/' ) && ! str_starts_with( $raw, '//' ) ) {
			$raw = home_url( $raw );
		}
		$url    = esc_url_raw( $raw, array( 'http', 'https' ) );
		$scheme = $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : null;
		$host   = $url ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		if ( ! $url || ! is_string( $host ) || '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		$scale = min( 1, 180 / $image[1], 72 / $image[2] );
		return array(
			'url'    => $url,
			'width'  => max( 1, (int) round( $image[1] * $scale ) ),
			'height' => max( 1, (int) round( $image[2] * $scale ) ),
		);
	}
}
