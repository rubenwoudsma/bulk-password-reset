<?php
/**
 * Resumable, operator-owned bulk jobs with bounded requests.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Stores progress without storing email addresses, passwords, or reset keys. */
final class Jobs {

	const OPTION     = 'bpr_job';
	const LOCK       = 'bpr_job_lock';
	const BATCH_SIZE = 20;
	const LIFETIME   = 2 * DAY_IN_SECONDS;

	/**
	 * Password-reset policy.
	 *
	 * @var Service
	 */
	private $service;

	/**
	 * Create the job coordinator.
	 *
	 * @param Service $service Password-reset policy.
	 */
	public function __construct( $service ) {
		$this->service = $service;
	}

	/**
	 * Return the current site's job only to an authorized operator.
	 *
	 * @return array|null
	 */
	public function current() {
		return Service::can_manage() ? get_option( self::OPTION, null ) : null;
	}

	/**
	 * Require a durable checkpoint before performing any external side effect.
	 *
	 * @param array $job Job state to persist.
	 * @return bool
	 */
	private function save( $job ) {
		return update_option( self::OPTION, $job, false ) || get_option( self::OPTION ) === $job;
	}

	/**
	 * Report a failed checkpoint, leaving any in-flight account for review.
	 *
	 * @return \WP_Error
	 */
	private function storage_error() {
		return new \WP_Error( 'storage', __( 'Progress could not be saved. Processing stopped; check the database before continuing.', 'bulk-password-reset' ) );
	}

	/**
	 * Serialize all job mutations using the unique option-name database index.
	 * Never steal a lock from a worker that might still be sending email.
	 *
	 * @param callable $callback Job operation.
	 * @return mixed|\WP_Error
	 */
	private function locked( $callback ) {
		if ( ! Service::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'You cannot perform bulk password resets.', 'bulk-password-reset' ) );
		}
		$token = wp_generate_uuid4();
		if ( ! add_option( self::LOCK, $token, '', false ) ) {
			return new \WP_Error( 'locked', __( 'Another bulk-reset request is running. Wait for it to finish before continuing.', 'bulk-password-reset' ) );
		}
		$release = static function () use ( $token ) {
			if ( get_option( self::LOCK ) === $token ) {
				delete_option( self::LOCK );
			}
		};
		// Also release on PHP fatal errors and normal request shutdown.
		register_shutdown_function( $release );
		try {
			return $callback();
		} finally {
			$release();
		}
	}

	/**
	 * Save a preview without sending email or changing any password.
	 *
	 * @param array  $roles Selected roles.
	 * @param array  $ids Selected users.
	 * @param string $mode Reset mode.
	 * @param string $note Plain-text note.
	 * @return array|\WP_Error
	 */
	public function create( $roles, $ids, $mode, $note ) {
		return $this->locked(
			function () use ( $roles, $ids, $mode, $note ) {
				$old = $this->current();
				if ( $old && $old['expires'] > time() && in_array( $old['status'], array( 'ready', 'running' ), true ) ) {
					return new \WP_Error( 'active', __( 'Finish or cancel the current reset before starting another.', 'bulk-password-reset' ) );
				}
				if ( ! in_array( $mode, array( 'link', 'invalidate' ), true ) || strlen( $note ) > 4000 ) {
					return new \WP_Error( 'settings', __( 'Choose a valid mode and keep the note under 4,000 bytes.', 'bulk-password-reset' ) );
				}
				$selection = $this->service->selection( $roles, $ids );
				if ( is_wp_error( $selection ) ) {
					return $selection;
				}
				$query = $this->service->query( $selection, 0, 1, true );
				$job   = array(
					'id'            => wp_generate_uuid4(),
					'owner'         => get_current_user_id(),
					'blog_id'       => get_current_blog_id(),
					'selection'     => $selection,
					'mode'          => $mode,
					'note'          => sanitize_textarea_field( $note ),
					'email'         => Email_Settings::snapshot(),
					'status'        => 'ready',
					'cursor'        => 0,
					'in_flight'     => 0,
					'total'         => (int) $query->get_total(),
					'created'       => time(),
					'expires'       => time() + self::LIFETIME,
					'sent'          => 0,
					'failed'        => 0,
					'failed_ids'    => array(),
					'skipped'       => 0,
					'uncertain'     => 0,
					'uncertain_ids' => array(),
					'invalidated'   => 0,
				);
				return $this->save( $job ) ? $job : $this->storage_error();
			}
		);
	}

	/**
	 * Check ownership, site, job identity and lifetime after acquiring the lock.
	 *
	 * @param string $id Expected job UUID.
	 * @return array|\WP_Error
	 */
	private function owned( $id ) {
		$job = $this->current();
		if ( ! $job || ! hash_equals( $job['id'], $id ) || get_current_user_id() !== $job['owner']
			|| get_current_blog_id() !== $job['blog_id'] || $job['expires'] <= time() ) {
			return new \WP_Error( 'job', __( 'This reset is unavailable, has expired, or was started by another administrator.', 'bulk-password-reset' ) );
		}
		return $job;
	}

	/**
	 * Process one page. Checkpoint before side effects to avoid replaying a reset
	 * after a timeout. An interrupted account is reported as uncertain, not retried.
	 *
	 * @param string $id Expected job UUID.
	 * @param bool   $confirmed Explicit confirmation for a new job.
	 * @return array|\WP_Error
	 */
	public function process( $id, $confirmed = false ) {
		return $this->locked(
			function () use ( $id, $confirmed ) {
				$job = $this->owned( $id );
				if ( is_wp_error( $job ) || ! in_array( $job['status'], array( 'ready', 'running' ), true ) ) {
					return $job;
				}
				if ( 'ready' === $job['status'] && ! $confirmed ) {
					return new \WP_Error( 'confirmation', __( 'Confirm the preview before starting the reset.', 'bulk-password-reset' ) );
				}
				$job['status'] = 'running';
				if ( $job['in_flight'] ) {
					++$job['uncertain'];
					if ( count( $job['uncertain_ids'] ) < 100 ) {
						$job['uncertain_ids'][] = $job['in_flight'];
					}
					$job['in_flight'] = 0;
				}
				// Read one extra user to detect completion at exact batch boundaries.
				// The look-ahead user is never processed or checkpointed in this request.
				$users    = $this->service->query( $job['selection'], $job['cursor'], self::BATCH_SIZE + 1 )->get_results();
				$has_more = count( $users ) > self::BATCH_SIZE;
				foreach ( array_slice( $users, 0, self::BATCH_SIZE ) as $user ) {
					if ( ! Service::can_manage() ) {
						break;
					}
					$job['cursor']    = $user->ID;
					$job['in_flight'] = $user->ID;
					if ( ! $this->save( $job ) ) {
						return $this->storage_error();
					}
					// Existing pre-feature jobs retain their original plain-text format.
					$email  = isset( $job['email'] ) ? $job['email'] : Email_Settings::defaults();
					$result = $this->service->reset_user( $user->ID, $job['mode'], $job['note'], $email );
					++$job[ $result['status'] ];
					if ( 'failed' === $result['status'] && count( $job['failed_ids'] ) < 100 ) {
						$job['failed_ids'][] = $user->ID;
					}
					if ( $result['invalidated'] ) {
						++$job['invalidated'];
					}
					$job['in_flight'] = 0;
					if ( ! $this->save( $job ) ) {
						return $this->storage_error();
					}
				}
				if ( ! $has_more && 0 === $job['in_flight'] && Service::can_manage() ) {
					$job['status'] = 'complete';
				}
				return $this->save( $job ) ? $job : $this->storage_error();
			}
		);
	}

	/**
	 * Stop future work; completed resets cannot be undone.
	 *
	 * @param string $id Expected job UUID.
	 * @return array|\WP_Error
	 */
	public function cancel( $id ) {
		return $this->locked(
			function () use ( $id ) {
				$job = $this->owned( $id );
				if ( ! is_wp_error( $job ) ) {
					$job['status'] = 'cancelled';
					if ( ! $this->save( $job ) ) {
						return $this->storage_error();
					}
				}
				return $job;
			}
		);
	}
}
