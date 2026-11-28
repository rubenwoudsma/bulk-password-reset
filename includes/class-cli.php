<?php
/**
 * Optional WP-CLI adapter for guarded bulk password-reset workflows.
 *
 * @package BulkPasswordReset
 */

namespace BulkPasswordReset;

defined( 'ABSPATH' ) || exit;

/** Exposes preview, start, status, resume and cancel commands over WP-CLI. */
final class Cli {

	const EXIT_OK          = 0;
	const EXIT_ERROR       = 1;
	const EXIT_UNCONFIRMED = 2;
	const EXIT_CONFLICT    = 3;
	const EXIT_PARTIAL     = 4;

	/**
	 * Job coordinator.
	 *
	 * @var Jobs
	 */
	private $jobs;

	/**
	 * Recipient policy and reset service.
	 *
	 * @var Service
	 */
	private $service;

	/**
	 * Optional input/output adapter used for deterministic tests.
	 *
	 * @var callable|null
	 */
	private $io;

	/**
	 * Create the WP-CLI command adapter.
	 *
	 * @param Jobs          $jobs Service coordinating persisted jobs.
	 * @param Service       $service Password-reset policy.
	 * @param callable|null $io Optional test IO handler.
	 */
	public function __construct( $jobs, $service, $io = null ) {
		$this->jobs    = $jobs;
		$this->service = $service;
		$this->io      = $io;
	}

	/**
	 * Register all bulk-password-reset subcommands with WP-CLI.
	 *
	 * @param Jobs    $jobs Service coordinating persisted jobs.
	 * @param Service $service Password-reset policy.
	 * @return void
	 */
	public static function register( $jobs, $service ) {
		$cli = new self( $jobs, $service );
		\WP_CLI::add_command(
			'bulk-password-reset preview',
			array( $cli, 'preview' ),
			array(
				'shortdesc' => 'Preview eligible and protected accounts without creating or changing a job.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'roles',
						'description' => 'Comma-separated role slugs to inspect.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'users',
						'description' => 'Comma-separated numeric user IDs to inspect.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'mode',
						'description' => 'Reset mode to describe: link or invalidate.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: human or json.',
						'optional'    => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'bulk-password-reset start',
			array( $cli, 'start' ),
			array(
				'shortdesc' => 'Create and run a confirmed bulk password-reset job in bounded batches.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'roles',
						'description' => 'Comma-separated role slugs to target.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'users',
						'description' => 'Comma-separated numeric user IDs to target.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'mode',
						'description' => 'Required reset mode: link or invalidate.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'note',
						'description' => 'Optional plain-text administrator note for reset emails.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'max-batches',
						'description' => 'Optional maximum number of 20-user batches to run now.',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'yes',
						'description' => 'Confirm starting the bulk reset without an interactive prompt.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: human or json.',
						'optional'    => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'bulk-password-reset status',
			array( $cli, 'status' ),
			array(
				'shortdesc' => 'Show authorized progress and outcome counts for the current site.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: human or json.',
						'optional'    => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'bulk-password-reset resume',
			array( $cli, 'resume' ),
			array(
				'shortdesc' => 'Continue an owned, unexpired bulk password-reset job from its saved checkpoint.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'job-id',
						'description' => 'UUID of the job to resume.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'max-batches',
						'description' => 'Optional maximum number of 20-user batches to run now.',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'yes',
						'description' => 'Confirm starting if the owned job is still awaiting initial confirmation.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: human or json.',
						'optional'    => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'bulk-password-reset cancel',
			array( $cli, 'cancel' ),
			array(
				'shortdesc' => 'Cancel remaining batches of an owned bulk password-reset job.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'job-id',
						'description' => 'UUID of the job to cancel.',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'yes',
						'description' => 'Confirm cancellation without an interactive prompt.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: human or json.',
						'optional'    => true,
					),
				),
			)
		);
	}

	/**
	 * Inspect a candidate selection without writing job, lock or account state.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Associative command options.
	 * @return void
	 */
	public function preview( $args = array(), $assoc_args = array() ) {
		$format = $this->parse_format( $assoc_args );
		$this->check_keys( $args, 0, $assoc_args, array( 'roles', 'users', 'mode', 'format' ) );
		$this->require_operator();

		$mode = isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'link';
		if ( ! is_string( $mode ) || ! in_array( $mode, array( 'link', 'invalidate' ), true ) ) {
			$this->fail( __( 'Choose a valid reset mode: link or invalidate.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		$parsed  = $this->parse_selectors( $assoc_args );
		$summary = $this->service->preview( $parsed['roles'], $parsed['ids'] );
		if ( is_wp_error( $summary ) ) {
			$this->fail_error( $summary );
			return;
		}

		$this->warn_context( $mode );
		$payload = array(
			'blog_id'      => $summary['blog_id'],
			'site_url'     => home_url(),
			'operator_id'  => get_current_user_id(),
			'mode'         => $mode,
			'email_format' => $summary['email']['format'],
			'email_logo'   => (int) $summary['email']['logo_id'],
			'total'        => $summary['total'],
			'sample'       => $summary['sample'],
		);
		if ( 'json' === $format ) {
			$this->line( (string) wp_json_encode( $payload ) );
			$this->halt( self::EXIT_OK );
			return;
		}

		$operator = wp_get_current_user();
		$this->line(
			sprintf(
				/* translators: 1: site URL, 2: blog ID. */
				__( 'Site: %1$s (blog %2$d)', 'bulk-password-reset' ),
				$payload['site_url'],
				$payload['blog_id']
			)
		);
		$this->line(
			sprintf(
				/* translators: 1: administrator login, 2: administrator user ID. */
				__( 'Administrator: %1$s (%2$d)', 'bulk-password-reset' ),
				$operator->user_login,
				$payload['operator_id']
			)
		);
		$this->line(
			'invalidate' === $mode
				? __( 'Mode: invalidate — stop current passwords and browser sessions, then email reset links.', 'bulk-password-reset' )
				: __( 'Mode: link — email reset links while keeping existing passwords and sessions valid.', 'bulk-password-reset' )
		);
		$this->line(
			'html' === $payload['email_format']
				? __( 'Email format: HTML with plain-text alternative (when using standard PHPMailer).', 'bulk-password-reset' )
				: __( 'Email format: plain text.', 'bulk-password-reset' )
		);
		$this->line(
			sprintf(
				/* translators: %d: candidate count at preview. */
				__( 'Selected users at preview: %d', 'bulk-password-reset' ),
				$payload['total']
			)
		);
		$this->line( __( 'Read-only preview: no job, lock, password, session or reset key was changed. Roles and permissions are rechecked during processing; later registrations are excluded.', 'bulk-password-reset' ) );
		if ( empty( $payload['sample'] ) ) {
			$this->line( __( 'No accounts match this selection.', 'bulk-password-reset' ) );
		} else {
			$this->line( __( 'Sample accounts (up to 20):', 'bulk-password-reset' ) );
			foreach ( $payload['sample'] as $row ) {
				$this->line( sprintf( '  #%1$d %2$s — %3$s', $row['id'], $row['login'], $row['eligibility'] ) );
			}
		}
		$this->halt( self::EXIT_OK );
	}

	/**
	 * Create and process a confirmed bulk password-reset job.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Associative command options.
	 * @return void
	 */
	public function start( $args = array(), $assoc_args = array() ) {
		$format = $this->parse_format( $assoc_args );
		$this->check_keys( $args, 0, $assoc_args, array( 'roles', 'users', 'mode', 'note', 'max-batches', 'yes', 'format' ) );
		$this->require_operator();

		$mode = isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : '';
		if ( ! is_string( $mode ) || ! in_array( $mode, array( 'link', 'invalidate' ), true ) ) {
			$this->fail( __( 'Specify an explicit --mode=link or --mode=invalidate.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		$note = isset( $assoc_args['note'] ) ? $assoc_args['note'] : '';
		if ( ! is_string( $note ) || strlen( $note ) > 4000 ) {
			$this->fail( __( 'Choose a valid mode and keep the note under 4,000 bytes.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		$max_batches = $this->parse_max_batches( $assoc_args );
		$parsed      = $this->parse_selectors( $assoc_args );
		$preview     = $this->service->preview( $parsed['roles'], $parsed['ids'] );
		if ( is_wp_error( $preview ) ) {
			$this->fail_error( $preview );
			return;
		}

		if ( false !== get_option( Jobs::LOCK, false ) ) {
			$this->fail( __( 'Another bulk-reset request is running. Wait for it to finish before continuing.', 'bulk-password-reset' ), self::EXIT_CONFLICT );
			return;
		}
		$existing = $this->jobs->current();
		if ( $existing && $existing['expires'] > time() && in_array( $existing['status'], array( 'ready', 'running' ), true ) ) {
			$this->fail( __( 'Finish or cancel the current reset before starting another.', 'bulk-password-reset' ), self::EXIT_CONFLICT );
			return;
		}

		$this->warn_context( $mode );
		$this->confirm_action(
			sprintf(
				/* translators: 1: candidate user count, 2: site URL, 3: reset mode. */
				__( 'Start bulk password reset for %1$d selected user(s) on %2$s in %3$s mode?', 'bulk-password-reset' ),
				$preview['total'],
				home_url(),
				$mode
			),
			! empty( $assoc_args['yes'] ),
			$format
		);

		$job = $this->jobs->create( $parsed['roles'], $parsed['ids'], $mode, $note );
		if ( is_wp_error( $job ) ) {
			$this->fail_error( $job );
			return;
		}
		$this->run_batches( $job['id'], true, $max_batches, $format );
	}

	/**
	 * Report authorized job status for the current operator and site.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Associative command options.
	 * @return void
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		$format = $this->parse_format( $assoc_args );
		$this->check_keys( $args, 0, $assoc_args, array( 'format' ) );
		$this->require_operator();

		$job    = $this->jobs->current();
		$owned  = $job && get_current_user_id() === $job['owner'] && get_current_blog_id() === $job['blog_id'];
		$active = $job && $job['expires'] > time() && in_array( $job['status'], array( 'ready', 'running' ), true );

		if ( $owned ) {
			$this->render_job_report( $job, $format );
			$this->halt( self::EXIT_OK );
			return;
		}
		if ( $active ) {
			if ( 'json' === $format ) {
				$this->line(
					(string) wp_json_encode(
						array(
							'status'   => 'active_other_admin',
							'blog_id'  => get_current_blog_id(),
							'site_url' => home_url(),
							'expires'  => (int) $job['expires'],
						)
					)
				);
			} else {
				$this->line( __( 'Another administrator has an active password reset on this site. They must finish or cancel it, or let it expire after 48 hours.', 'bulk-password-reset' ) );
			}
			$this->halt( self::EXIT_OK );
			return;
		}

		if ( 'json' === $format ) {
			$this->line(
				(string) wp_json_encode(
					array(
						'status'   => 'none',
						'blog_id'  => get_current_blog_id(),
						'site_url' => home_url(),
					)
				)
			);
		} else {
			$this->line( __( 'No bulk password-reset job is stored for your account on this site.', 'bulk-password-reset' ) );
		}
		$this->halt( self::EXIT_OK );
	}

	/**
	 * Resume an owned, unexpired job from its saved checkpoint.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Associative command options.
	 * @return void
	 */
	public function resume( $args = array(), $assoc_args = array() ) {
		$format = $this->parse_format( $assoc_args );
		$this->check_keys( $args, 1, $assoc_args, array( 'max-batches', 'yes', 'format' ) );
		$this->require_operator();

		$job_id = isset( $args[0] ) && is_string( $args[0] ) ? trim( $args[0] ) : '';
		if ( '' === $job_id ) {
			$this->fail( __( 'Specify the job ID to resume.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		$max_batches = $this->parse_max_batches( $assoc_args );
		$confirmed   = ! empty( $assoc_args['yes'] );

		$current = $this->jobs->current();
		if ( $current && hash_equals( $current['id'], $job_id ) && get_current_user_id() === $current['owner']
			&& get_current_blog_id() === $current['blog_id'] && false === get_option( Jobs::LOCK, false ) ) {
			if ( in_array( $current['status'], array( 'complete', 'cancelled' ), true ) ) {
				$this->fail( __( 'This reset is already complete or cancelled and cannot be resumed.', 'bulk-password-reset' ), self::EXIT_ERROR );
				return;
			}
			if ( $current['expires'] <= time() ) {
				$this->fail( __( 'This reset is unavailable, has expired, or was started by another administrator.', 'bulk-password-reset' ), self::EXIT_ERROR );
				return;
			}
			$this->warn_context( $current['mode'] );
			if ( 'ready' === $current['status'] ) {
				$this->confirm_action(
					sprintf(
						/* translators: 1: job UUID, 2: site URL, 3: reset mode. */
						__( 'Confirm and start pending job %1$s on %2$s in %3$s mode?', 'bulk-password-reset' ),
						$current['id'],
						home_url(),
						$current['mode']
					),
					$confirmed,
					$format
				);
				$confirmed = true;
			}
		}

		$this->run_batches( $job_id, $confirmed, $max_batches, $format );
	}

	/**
	 * Cancel remaining work on an owned, unexpired job.
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Associative command options.
	 * @return void
	 */
	public function cancel( $args = array(), $assoc_args = array() ) {
		$format = $this->parse_format( $assoc_args );
		$this->check_keys( $args, 1, $assoc_args, array( 'yes', 'format' ) );
		$this->require_operator();

		$job_id = isset( $args[0] ) && is_string( $args[0] ) ? trim( $args[0] ) : '';
		if ( '' === $job_id ) {
			$this->fail( __( 'Specify the job ID to cancel.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		if ( false !== get_option( Jobs::LOCK, false ) ) {
			$this->fail( __( 'Another bulk-reset request is running. Wait for it to finish before continuing.', 'bulk-password-reset' ), self::EXIT_CONFLICT );
			return;
		}

		$current = $this->jobs->current();
		if ( ! $current || ! hash_equals( $current['id'], $job_id ) || get_current_user_id() !== $current['owner']
			|| get_current_blog_id() !== $current['blog_id'] || $current['expires'] <= time() ) {
			$this->fail( __( 'This reset is unavailable, has expired, or was started by another administrator.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}
		if ( in_array( $current['status'], array( 'complete', 'cancelled' ), true ) ) {
			$this->fail( __( 'This reset is already complete or cancelled.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return;
		}

		$this->warn( __( 'Cancelling stops future batches only. Already-accepted emails and completed password or session invalidations cannot be undone.', 'bulk-password-reset' ) );
		$this->confirm_action(
			sprintf(
				/* translators: %s: job UUID. */
				__( 'Cancel remaining batches for job %s?', 'bulk-password-reset' ),
				$job_id
			),
			! empty( $assoc_args['yes'] ),
			$format
		);

		$cancelled = $this->jobs->cancel( $job_id );
		if ( is_wp_error( $cancelled ) ) {
			$this->fail_error( $cancelled );
			return;
		}
		$this->render_job_report( $cancelled, $format );
		if ( 'human' === $format ) {
			$this->done( __( 'Remaining batches cancelled.', 'bulk-password-reset' ) );
		}
		$this->halt( self::EXIT_OK );
	}

	/**
	 * Process bounded 20-user batches sequentially and emit the final report.
	 *
	 * @param string   $job_id Expected job UUID.
	 * @param bool     $confirm_first Whether the initial ready state is confirmed.
	 * @param int|null $max_batches Optional batch limit.
	 * @param string   $format Output format.
	 * @return void
	 */
	private function run_batches( $job_id, $confirm_first, $max_batches, $format ) {
		$batch = 1;
		$job   = null;
		while ( null === $max_batches || $batch <= $max_batches ) {
			$job = $this->jobs->process( $job_id, 1 === $batch && $confirm_first );
			if ( is_wp_error( $job ) ) {
				$this->fail_error( $job );
				return;
			}
			if ( 'human' === $format ) {
				$this->line(
					sprintf(
						/* translators: 1: batch number, 2: sent count, 3: failed count, 4: skipped count, 5: uncertain count, 6: job status. */
						__( 'Batch %1$d: %2$d accepted, %3$d failed, %4$d skipped, %5$d unknown (%6$s).', 'bulk-password-reset' ),
						$batch,
						$job['sent'],
						$job['failed'],
						$job['skipped'],
						$job['uncertain'],
						$job['status']
					)
				);
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			if ( 'running' !== $job['status'] ) {
				break;
			}
			++$batch;
		}

		$this->render_job_report( $job, $format );
		$partial = ( $job['failed'] + $job['uncertain'] + ( $job['in_flight'] ? 1 : 0 ) ) > 0;
		if ( $partial ) {
			$this->warn( __( 'One or more accounts failed or had an unknown outcome. Review them individually; accepted emails do not guarantee inbox delivery.', 'bulk-password-reset' ) );
			$this->halt( self::EXIT_PARTIAL );
			return;
		}
		if ( 'human' === $format ) {
			if ( 'complete' === $job['status'] ) {
				$this->done( __( 'Bulk password reset finished. Accepted for sending does not guarantee inbox delivery.', 'bulk-password-reset' ) );
			} else {
				$this->done(
					sprintf(
						/* translators: %s: job UUID. */
						__( 'Batch limit reached. Resume within 48 hours using: wp bulk-password-reset resume %s', 'bulk-password-reset' ),
						$job['id']
					)
				);
			}
		}
		$this->halt( self::EXIT_OK );
	}

	/**
	 * Format and output a sanitized job summary without credentials or addresses.
	 *
	 * @param array  $job Stored job array.
	 * @param string $format Output format.
	 * @return void
	 */
	private function render_job_report( $job, $format ) {
		$expired       = $job['expires'] <= time() && in_array( $job['status'], array( 'ready', 'running' ), true );
		$status        = $expired ? 'expired' : $job['status'];
		$email         = isset( $job['email'] ) ? Email_Settings::normalize( $job['email'] ) : Email_Settings::defaults();
		$uncertain     = (int) $job['uncertain'] + ( $job['in_flight'] ? 1 : 0 );
		$uncertain_ids = $job['uncertain_ids'];
		if ( $job['in_flight'] && count( $uncertain_ids ) < 100 && ! in_array( $job['in_flight'], $uncertain_ids, true ) ) {
			$uncertain_ids[] = (int) $job['in_flight'];
		}
		$report = array(
			'id'            => $job['id'],
			'status'        => $status,
			'blog_id'       => (int) $job['blog_id'],
			'site_url'      => home_url(),
			'owner'         => (int) $job['owner'],
			'mode'          => $job['mode'],
			'has_note'      => '' !== $job['note'],
			'email_format'  => $email['format'],
			'email_logo'    => (int) $email['logo_id'],
			'created'       => (int) $job['created'],
			'expires'       => (int) $job['expires'],
			'total'         => (int) $job['total'],
			'sent'          => (int) $job['sent'],
			'failed'        => (int) $job['failed'],
			'skipped'       => (int) $job['skipped'],
			'uncertain'     => $uncertain,
			'invalidated'   => (int) $job['invalidated'],
			'failed_ids'    => array_values( array_map( 'intval', $job['failed_ids'] ) ),
			'uncertain_ids' => array_values( array_map( 'intval', $uncertain_ids ) ),
		);

		if ( 'json' === $format ) {
			$this->line( (string) wp_json_encode( $report ) );
			return;
		}

		$this->line( sprintf( 'Job ID: %s', $report['id'] ) );
		$this->line( sprintf( 'Status: %s', $report['status'] ) );
		$this->line( sprintf( 'Site: %1$s (blog %2$d)', $report['site_url'], $report['blog_id'] ) );
		$this->line( sprintf( 'Mode: %1$s | Email format: %2$s', $report['mode'], $report['email_format'] ) );
		$this->line(
			sprintf(
				/* translators: 1: selected count, 2: sent count, 3: failed count, 4: skipped count, 5: uncertain count, 6: invalidated count. */
				__( 'Counts — selected: %1$d, accepted: %2$d, failed: %3$d, skipped: %4$d, unknown: %5$d, invalidated: %6$d', 'bulk-password-reset' ),
				$report['total'],
				$report['sent'],
				$report['failed'],
				$report['skipped'],
				$report['uncertain'],
				$report['invalidated']
			)
		);
		if ( ! empty( $report['failed_ids'] ) ) {
			$this->line( sprintf( 'Failed user IDs (up to 100): %s', implode( ', ', $report['failed_ids'] ) ) );
		}
		if ( ! empty( $report['uncertain_ids'] ) ) {
			$this->line( sprintf( 'Unknown-outcome user IDs (up to 100): %s', implode( ', ', $report['uncertain_ids'] ) ) );
		}
	}

	/**
	 * Enforce an explicit, authorized WordPress operator even on shell access.
	 *
	 * @return void
	 */
	private function require_operator() {
		if ( 0 === get_current_user_id() ) {
			$this->fail(
				is_multisite()
					? __( 'Specify an authorized super administrator with --user=<id|login> and target site with --url=<url>.', 'bulk-password-reset' )
					: __( 'Specify an authorized administrator with --user=<id|login>.', 'bulk-password-reset' ),
				self::EXIT_ERROR
			);
			return;
		}
		if ( ! Service::can_manage() ) {
			$this->fail( __( 'You cannot perform bulk password resets.', 'bulk-password-reset' ), self::EXIT_ERROR );
		}
	}

	/**
	 * Emit multisite and immediate-invalidation context warnings to stderr.
	 *
	 * @param string $mode Reset mode.
	 * @return void
	 */
	private function warn_context( $mode ) {
		if ( is_multisite() ) {
			$this->warn( __( 'Multisite: only super administrators can run this tool. Selection is limited to this site, but passwords and sessions are shared across the network.', 'bulk-password-reset' ) );
		}
		if ( 'invalidate' === $mode ) {
			$this->warn( __( 'Immediate invalidation cannot be undone. If email fails, the password still changes. Verify your site can send mail before starting. Application passwords and external sign-in providers are not revoked.', 'bulk-password-reset' ) );
		}
	}

	/**
	 * Require interactive confirmation or an explicit --yes flag.
	 *
	 * @param string $question Confirmation question.
	 * @param bool   $yes Whether --yes was supplied.
	 * @param string $format Output format.
	 * @return void
	 */
	private function confirm_action( $question, $yes, $format ) {
		if ( $yes ) {
			return;
		}
		if ( 'json' === $format ) {
			$this->fail( __( 'Pass --yes to confirm this action when using --format=json.', 'bulk-password-reset' ), self::EXIT_UNCONFIRMED );
			return;
		}
		$this->line( $question . ' [y/N]' );
		$answer = $this->read_input();
		if ( ! is_string( $answer ) || ! in_array( strtolower( trim( $answer ) ), array( 'y', 'yes' ), true ) ) {
			$this->fail( __( 'Action not confirmed. No changes were made.', 'bulk-password-reset' ), self::EXIT_UNCONFIRMED );
		}
	}

	/**
	 * Parse the output format option before running further checks.
	 *
	 * @param array $assoc_args Associative options.
	 * @return string Either human or json.
	 */
	private function parse_format( $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'human';
		if ( ! is_string( $format ) || ! in_array( $format, array( 'human', 'json' ), true ) ) {
			$this->fail( __( 'Choose a valid output format: human or json.', 'bulk-password-reset' ), self::EXIT_ERROR );
		}
		return $format;
	}

	/**
	 * Reject unexpected positional arguments or unknown associative flags.
	 *
	 * @param array $args Positional arguments.
	 * @param int   $max_args Maximum allowed positional arguments.
	 * @param array $assoc_args Associative options.
	 * @param array $allowed_assoc Allowed option keys.
	 * @return void
	 */
	private function check_keys( $args, $max_args, $assoc_args, $allowed_assoc ) {
		if ( count( $args ) > $max_args || array_diff( array_keys( $assoc_args ), $allowed_assoc ) ) {
			$this->fail( __( 'Unexpected command arguments or options.', 'bulk-password-reset' ), self::EXIT_ERROR );
		}
	}

	/**
	 * Parse comma-separated role slugs and user IDs without accepting empty lists.
	 *
	 * @param array $assoc_args Associative options.
	 * @return array Parsed roles and IDs arrays.
	 */
	private function parse_selectors( $assoc_args ) {
		$roles = array();
		$ids   = array();
		if ( array_key_exists( 'roles', $assoc_args ) ) {
			if ( ! is_string( $assoc_args['roles'] ) || '' === trim( $assoc_args['roles'] ) ) {
				$this->fail( __( 'The role selection is invalid.', 'bulk-password-reset' ), self::EXIT_ERROR );
				return array(
					'roles' => array(),
					'ids'   => array(),
				);
			}
			$roles = array_map( 'trim', explode( ',', $assoc_args['roles'] ) );
		}
		if ( array_key_exists( 'users', $assoc_args ) ) {
			if ( ! is_string( $assoc_args['users'] ) || '' === trim( $assoc_args['users'] ) ) {
				$this->fail( __( 'The recipient selection is invalid.', 'bulk-password-reset' ), self::EXIT_ERROR );
				return array(
					'roles' => array(),
					'ids'   => array(),
				);
			}
			$ids = array_map( 'trim', explode( ',', $assoc_args['users'] ) );
		}
		return array(
			'roles' => $roles,
			'ids'   => $ids,
		);
	}

	/**
	 * Validate the optional positive --max-batches integer option.
	 *
	 * @param array $assoc_args Associative options.
	 * @return int|null Maximum batches, or null for all remaining batches.
	 */
	private function parse_max_batches( $assoc_args ) {
		if ( ! array_key_exists( 'max-batches', $assoc_args ) ) {
			return null;
		}
		$raw = $assoc_args['max-batches'];
		if ( ! is_scalar( $raw ) || ! ctype_digit( (string) $raw ) || (int) $raw < 1 ) {
			$this->fail( __( 'Specify --max-batches as a positive integer.', 'bulk-password-reset' ), self::EXIT_ERROR );
			return null;
		}
		return (int) $raw;
	}

	/**
	 * Map a service or job WP_Error to its documented CLI exit code.
	 *
	 * @param \WP_Error $error Service or job error.
	 * @return void
	 */
	private function fail_error( $error ) {
		$code = $error->get_error_code();
		$exit = self::EXIT_ERROR;
		if ( in_array( $code, array( 'locked', 'active' ), true ) ) {
			$exit = self::EXIT_CONFLICT;
		} elseif ( 'confirmation' === $code ) {
			$exit = self::EXIT_UNCONFIRMED;
		}
		$this->fail( $error->get_error_message(), $exit );
	}

	/**
	 * Write an informational line to stdout.
	 *
	 * @param string $message Plain-text line.
	 * @return void
	 */
	private function line( $message ) {
		if ( null !== $this->io ) {
			call_user_func( $this->io, 'line', $message );
			return;
		}
		\WP_CLI::line( $message );
	}

	/**
	 * Write a warning message to stderr.
	 *
	 * @param string $message Plain-text warning.
	 * @return void
	 */
	private function warn( $message ) {
		if ( null !== $this->io ) {
			call_user_func( $this->io, 'warning', $message );
			return;
		}
		\WP_CLI::warning( $message );
	}

	/**
	 * Write a completion message to stdout.
	 *
	 * @param string $message Plain-text success message.
	 * @return void
	 */
	private function done( $message ) {
		if ( null !== $this->io ) {
			call_user_func( $this->io, 'success', $message );
			return;
		}
		\WP_CLI::success( $message );
	}

	/**
	 * Read one confirmation line from standard input or the test IO adapter.
	 *
	 * @return string|false Input line or false on EOF.
	 */
	private function read_input() {
		if ( null !== $this->io ) {
			return call_user_func( $this->io, 'input', '' );
		}
		return defined( 'STDIN' ) ? fgets( \STDIN ) : false;
	}

	/**
	 * Emit an error to stderr and terminate with a specific status code.
	 *
	 * @param string $message Plain-text error message.
	 * @param int    $code Process exit code.
	 * @return void
	 */
	private function fail( $message, $code ) {
		if ( null !== $this->io ) {
			call_user_func( $this->io, 'error', $message );
		} else {
			\WP_CLI::error( $message, false );
		}
		$this->halt( $code );
	}

	/**
	 * Terminate command execution with the documented exit code.
	 *
	 * @param int $code Process exit code.
	 * @return void
	 */
	private function halt( $code ) {
		if ( null !== $this->io ) {
			call_user_func( $this->io, 'halt', (int) $code );
			return;
		}
		\WP_CLI::halt( (int) $code );
	}
}
