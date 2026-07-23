<?php

namespace ArgentSentinel\WordPress\Abuse;

interface AccountLookup {
	public function findByLoginIdentifier( string $identifier ): ?AccountIdentity;
}
