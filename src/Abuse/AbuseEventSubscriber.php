<?php

namespace ArgentSentinel\WordPress\Abuse;

use ArgentSentinel\WordPress\Events\EventSink;
use ArgentSentinel\WordPress\Events\EventType;
use ArgentSentinel\WordPress\Events\Severity;
use ArgentSentinel\WordPress\Network\IpAddress;
use ArgentSentinel\WordPress\Privacy\EmailIdentity;

final class AbuseEventSubscriber {
	private const UNVERIFIED_ERROR_CODE = 'argent_sentinel_unverified';

	/** @var EventSink */
	private $events;

	/** @var AccountLookup */
	private $accounts;

	/** @var EmailIdentity */
	private $email_identity;

	/** @var bool */
	private $reporting_failure = false;

	public function __construct( EventSink $events, AccountLookup $accounts, EmailIdentity $email_identity ) {
		$this->events         = $events;
		$this->accounts       = $accounts;
		$this->email_identity = $email_identity;
	}

	public function register(): void {
		add_action( 'wp_insert_comment', array( $this, 'onCommentInserted' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'onCommentStatusTransition' ), 10, 3 );
		add_action( 'wp_login_failed', array( $this, 'onLoginFailed' ), 10, 2 );
	}

	/**
	 * Captures comments that are already spam when first inserted.
	 *
	 * @param mixed $comment_id Numeric comment ID.
	 * @param mixed $comment    WP_Comment object.
	 */
	public function onCommentInserted( $comment_id, $comment ): void {
		try {
			if (
				! is_object( $comment )
				|| ! isset( $comment->comment_approved )
				|| 'spam' !== (string) $comment->comment_approved
			) {
				return;
			}

			$this->recordSpamComment( 'new', $comment, (int) $comment_id );
		} catch ( \Throwable $throwable ) {
			$this->reportFailure( EventType::COMMENT_MARKED_SPAM );
		}
	}

	/**
	 * @param mixed $new_status New comment status.
	 * @param mixed $old_status Previous comment status.
	 * @param mixed $comment    WP_Comment object.
	 */
	public function onCommentStatusTransition( $new_status, $old_status, $comment ): void {
		try {
			$new_status = (string) $new_status;
			$old_status = (string) $old_status;

			if ( 'spam' !== $new_status || 'spam' === $old_status || ! is_object( $comment ) ) {
				return;
			}

			$this->recordSpamComment( $old_status, $comment );
		} catch ( \Throwable $throwable ) {
			$this->reportFailure( EventType::COMMENT_MARKED_SPAM );
		}
	}

	/**
	 * @param mixed $comment WP_Comment-compatible object.
	 */
	private function recordSpamComment(
		string $old_status,
		$comment,
		?int $comment_id = null
	): void {
		$source_ip = isset( $comment->comment_author_IP )
			? IpAddress::normalize( (string) $comment->comment_author_IP )
			: null;
		$metadata  = array(
			'comment_id'       => null !== $comment_id
				? $comment_id
				: ( isset( $comment->comment_ID ) ? (int) $comment->comment_ID : null ),
			'comment_type'     => isset( $comment->comment_type ) ? (string) $comment->comment_type : 'comment',
			'previous_status'  => $old_status,
			'source_ip_valid'  => null !== $source_ip,
		);
		$attributes = array(
			'source_ip'         => $source_ip,
			'wordpress_user_id' => isset( $comment->user_id ) ? (int) $comment->user_id : null,
			'user_agent'        => null,
			'request_method'    => null,
			'request_path'      => null,
			'metadata'          => $metadata,
		);

		$this->recordFailOpen(
			EventType::COMMENT_MARKED_SPAM,
			Severity::WARNING,
			'detected',
			$attributes
		);
	}

	/**
	 * @param mixed $submitted_identifier Submitted username or email address.
	 * @param mixed $error                WP_Error-compatible authentication error.
	 */
	public function onLoginFailed( $submitted_identifier, $error = null ): void {
		try {
			$this->recordLoginFailure( (string) $submitted_identifier, $error );
		} catch ( \Throwable $throwable ) {
			$this->reportFailure( EventType::LOGIN_FAILED );
		}
	}

	/**
	 * @param mixed $error WP_Error-compatible authentication error.
	 */
	private function recordLoginFailure( string $submitted_identifier, $error ): void {
		$identifier = $this->normalizeLoginIdentifier( $submitted_identifier );
		$codes      = $this->errorCodes( $error );

		if ( in_array( self::UNVERIFIED_ERROR_CODE, $codes, true ) ) {
			return;
		}

		$is_email         = EmailIdentity::looksLikeEmail( $identifier );
		$explicit_unknown = in_array( 'invalid_username', $codes, true ) || in_array( 'invalid_email', $codes, true );
		$account          = null;
		$lookup_failed    = false;

		if ( '' !== $identifier && ! $explicit_unknown ) {
			try {
				$account = $this->accounts->findByLoginIdentifier( $identifier );
			} catch ( \Throwable $throwable ) {
				$lookup_failed = true;
			}
		}

		$is_unknown = '' !== $identifier
			&& ( $explicit_unknown || ( ! $lookup_failed && null === $account ) );
		$event_type       = $is_unknown ? EventType::LOGIN_UNKNOWN_ACCOUNT : EventType::LOGIN_FAILED;
		$metadata         = array(
			'account_resolution' => $this->accountResolution(
				$identifier,
				$account,
				$explicit_unknown,
				$lookup_failed
			),
			'identifier_kind'   => $is_email ? 'email' : ( '' === $identifier ? 'empty' : 'username' ),
			'reason_category'   => $this->reasonCategory( $codes, $is_unknown ),
		);
		$attributes       = array(
			'metadata' => $metadata,
		);

		if ( null !== $account && ! $is_unknown ) {
			$attributes['wordpress_user_id'] = $account->userId();

			if ( ! EmailIdentity::looksLikeEmail( $account->username() ) ) {
				$attributes['username'] = $account->username();
			}

			if ( $is_email ) {
				$attributes['email'] = $account->email();
			}
		} elseif ( $is_email ) {
			$attributes['email'] = $identifier;
		} elseif ( '' !== $identifier ) {
			$metadata['login_identifier'] = $this->email_identity->identifyOpaqueValue( $identifier );
			$attributes['metadata']       = $metadata;
		}

		$this->recordFailOpen(
			$event_type,
			Severity::WARNING,
			'denied',
			$attributes
		);
	}

	/**
	 * @param array<string,mixed> $attributes Event-specific attributes.
	 */
	private function recordFailOpen( string $event_type, string $severity, string $outcome, array $attributes ): void {
		$recorded = false;

		try {
			$recorded = $this->events->record( $event_type, $severity, $outcome, $attributes );
		} catch ( \Throwable $throwable ) {
			$recorded = false;
		}

		if ( ! $recorded ) {
			$this->reportFailure( $event_type );
		}
	}

	private function reportFailure( string $event_type ): void {
		if ( ! $this->reporting_failure && function_exists( 'do_action' ) ) {
			$this->reporting_failure = true;

			try {
				do_action( 'argent_sentinel_event_recording_failed', $event_type );
			} catch ( \Throwable $throwable ) {
				// Event recording must never interrupt the WordPress request.
			} finally {
				$this->reporting_failure = false;
			}
		}
	}

	private function normalizeLoginIdentifier( string $identifier ): string {
		if ( function_exists( 'wp_unslash' ) ) {
			$identifier = wp_unslash( $identifier );
		}

		$identifier = trim( $identifier );
		$identifier = preg_replace( '/[\x00-\x1F\x7F]/', '', $identifier );

		return substr( (string) $identifier, 0, 320 );
	}

	/**
	 * @param mixed $error WP_Error-compatible value.
	 *
	 * @return array<int,string>
	 */
	private function errorCodes( $error ): array {
		if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_codes' ) ) {
			return array();
		}

		$codes = $error->get_error_codes();

		if ( ! is_array( $codes ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $code ): string {
						return substr( preg_replace( '/[^a-z0-9_-]/i', '', (string) $code ), 0, 64 );
					},
					$codes
				)
			)
		);
	}

	/**
	 * @param array<int,string> $codes Authentication error codes.
	 */
	private function reasonCategory( array $codes, bool $is_unknown ): string {
		if ( $is_unknown ) {
			return 'unknown_account';
		}

		if ( in_array( 'incorrect_password', $codes, true ) ) {
			return 'invalid_credentials';
		}

		if ( in_array( 'empty_username', $codes, true ) || in_array( 'empty_password', $codes, true ) ) {
			return 'malformed_request';
		}

		return 'authentication_failed';
	}

	private function accountResolution(
		string $identifier,
		?AccountIdentity $account,
		bool $explicit_unknown,
		bool $lookup_failed
	): string {
		if ( '' === $identifier ) {
			return 'not_applicable';
		}

		if ( $lookup_failed ) {
			return 'unavailable';
		}

		if ( $explicit_unknown || null === $account ) {
			return 'not_found';
		}

		return 'found';
	}
}
