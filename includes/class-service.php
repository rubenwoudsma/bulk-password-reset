<?php
/**
 * Recipient policy and WordPress password-reset operations.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Handles selection and a single reset without accepting request data. */
final class Service {

	/**
	 * Whether the operator may use the plugin on this site.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_users' )
			&& ( ! is_multisite() || is_super_admin() );
	}

	/**
	 * Protect privileged accounts even when they also hold an ordinary role.
	 * Multisite passwords are global, so require site membership as well.
	 *
	 * @param \WP_User $user Target account.
	 * @return bool
	 */
	public function can_target( $user ) {
		return self::can_manage() && $user->exists()
			&& get_current_user_id() !== $user->ID
			&& current_user_can( 'edit_user', $user->ID )
			&& is_user_member_of_blog( $user->ID, get_current_blog_id() )
			&& ! is_super_admin( $user->ID )
			&& ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'edit_users' )
			&& empty( $user->allcaps['manage_options'] ) && empty( $user->allcaps['edit_users'] );
	}

	/**
	 * Validate a role selection or an explicit set of numeric account IDs.
	 *
	 * @param array $roles Role slugs.
	 * @param array $ids Account IDs.
	 * @return array|\WP_Error
	 */
	public function selection( $roles, $ids ) {
		if ( ! self::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'You cannot perform bulk password resets.', 'bulk-password-reset' ) );
		}
		if ( ( empty( $roles ) && empty( $ids ) ) || ( ! empty( $roles ) && ! empty( $ids ) ) ) {
			return new \WP_Error( 'selection', __( 'Select at least one user role or recipient.', 'bulk-password-reset' ) );
		}
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || ! isset( wp_roles()->roles[ $role ] ) ) {
				return new \WP_Error( 'selection', __( 'The role selection is invalid.', 'bulk-password-reset' ) );
			}
		}
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
				return new \WP_Error( 'selection', __( 'The recipient selection is invalid.', 'bulk-password-reset' ) );
			}
		}
		$highest = get_users(
			array(
				'blog_id' => get_current_blog_id(),
				'fields'  => 'ID',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);
		return array(
			'roles'  => array_values( array_unique( $roles ) ),
			'ids'    => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'max_id' => empty( $highest ) ? 0 : (int) $highest[0],
		);
	}

	/**
	 * Query a bounded page using an ID cursor rather than a growing SQL offset.
	 * Roles are checked afresh; accounts registered after preview are excluded.
	 *
	 * @param array $selection Validated selection.
	 * @param int   $after Last visited account ID.
	 * @param int   $number Page size.
	 * @param bool  $count Whether to calculate the candidate count.
	 * @return \WP_User_Query
	 */
	public function query( $selection, $after = 0, $number = 20, $count = false ) {
		$args = array(
			'blog_id'     => get_current_blog_id(),
			'number'      => $number,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => $count,
		);
		if ( $selection['ids'] ) {
			$args['include'] = $selection['ids'];
		} else {
			$args['role__in'] = $selection['roles'];
		}
		$query  = new \WP_User_Query();
		$bounds = static function ( $candidate ) use ( $query, $selection, $after ) {
			if ( $candidate === $query ) {
				global $wpdb;
				$candidate->query_where .= $wpdb->prepare(
					" AND {$wpdb->users}.ID > %d AND {$wpdb->users}.ID <= %d",
					$after,
					$selection['max_id']
				);
			}
		};
		add_action( 'pre_user_query', $bounds );
		try {
			$query->prepare_query( $args );
			$query->query();
		} finally {
			remove_action( 'pre_user_query', $bounds );
		}
		return $query;
	}

	/**
	 * Reset one eligible account; never return or store credentials or keys.
	 * A failed email in invalidate mode cannot undo the password change.
	 *
	 * @param int        $id Account ID.
	 * @param string     $mode Either link or invalidate.
	 * @param string     $note Plain-text administrator note.
	 * @param array|null $email_settings Confirmed preferences, or current settings.
	 * @return array Status and whether credentials were invalidated.
	 */
	public function reset_user( $id, $mode, $note = '', $email_settings = null ) {
		$result = array(
			'status'      => 'skipped',
			'invalidated' => false,
		);
		$user   = get_user_by( 'id', $id );
		if ( ! in_array( $mode, array( 'link', 'invalidate' ), true ) || ! $user || ! $this->can_target( $user ) ) {
			return $result;
		}
		$allowed = wp_is_password_reset_allowed_for_user( $user );
		if ( is_wp_error( $allowed ) || ! $allowed ) {
			return $result;
		}

		$switched = switch_to_user_locale( $user->ID );
		try {
			if ( 'invalidate' === $mode ) {
				wp_set_password( wp_generate_password( 64, true, true ), $user->ID );
				\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
				$result['invalidated'] = true;
			}
			// Generate after wp_set_password(), which clears existing reset keys.
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) {
				$result['status'] = 'failed';
				return $result;
			}
			// Keep login away from the end so automatic links retain a trailing period.
			$url              = network_site_url( 'wp-login.php?login=' . rawurlencode( $user->user_login ) . '&key=' . rawurlencode( $key ) . '&action=rp', 'login' );
			$message          = Email::message( $user->user_login, $mode, $note, $url, Email_Settings::snapshot( $email_settings ) );
			$sent             = Email::send( $user->user_email, $message );
			$result['status'] = $sent ? 'sent' : 'failed';
			return $result;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
