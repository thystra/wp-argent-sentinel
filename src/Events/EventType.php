<?php

namespace ArgentSentinel\WordPress\Events;

final class EventType {
	public const COMMENT_MARKED_SPAM                 = 'comment_marked_spam';
	public const LOGIN_FAILED                        = 'login_failed';
	public const LOGIN_UNKNOWN_ACCOUNT               = 'login_unknown_account';
	public const REGISTRATION_ATTEMPT                = 'registration_attempt';
	public const REGISTRATION_REJECTED               = 'registration_rejected';
	public const USER_CREATED_UNVERIFIED             = 'user_created_unverified';
	public const VERIFICATION_EMAIL_SENT             = 'verification_email_sent';
	public const VERIFICATION_EMAIL_RESEND_REQUESTED = 'verification_email_resend_requested';
	public const VERIFICATION_EMAIL_RATE_LIMITED     = 'verification_email_rate_limited';
	public const EMAIL_VERIFIED                      = 'email_verified';
	public const VERIFICATION_FAILED                 = 'verification_failed';
	public const VERIFICATION_TOKEN_EXPIRED          = 'verification_token_expired';
	public const UNVERIFIED_LOGIN_BLOCKED            = 'unverified_login_blocked';
	public const UNVERIFIED_EMAIL_SUPPRESSED         = 'unverified_email_suppressed';
	public const UNVERIFIED_USER_DELETED             = 'unverified_user_deleted';
	public const UNVERIFIED_CLEANUP_FAILED           = 'unverified_cleanup_failed';
	public const REGISTRATION_MARKED_SUSPICIOUS      = 'registration_marked_suspicious';
	public const EXPORT_BATCH_CREATED                = 'export_batch_created';
	public const EXPORT_BATCH_FAILED                 = 'export_batch_failed';

	private function __construct() {
	}
}
