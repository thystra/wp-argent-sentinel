<?php

namespace ArgentSentinel\WordPress\Abuse;

use ArgentSentinel\WordPress\Privacy\EmailIdentity;

final class WordPressAccountLookup implements AccountLookup {
	public function findByLoginIdentifier( string $identifier ): ?AccountIdentity {
		$user = get_user_by( 'login', $identifier );

		if ( false === $user && EmailIdentity::looksLikeEmail( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}

		if ( false === $user || ! isset( $user->ID, $user->user_login, $user->user_email ) ) {
			return null;
		}

		return new AccountIdentity(
			(int) $user->ID,
			(string) $user->user_login,
			(string) $user->user_email
		);
	}
}
