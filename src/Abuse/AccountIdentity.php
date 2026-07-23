<?php

namespace ArgentSentinel\WordPress\Abuse;

final class AccountIdentity {
	/** @var int */
	private $user_id;

	/** @var string */
	private $username;

	/** @var string */
	private $email;

	public function __construct( int $user_id, string $username, string $email ) {
		$this->user_id = $user_id;
		$this->username = $username;
		$this->email = $email;
	}

	public function userId(): int {
		return $this->user_id;
	}

	public function username(): string {
		return $this->username;
	}

	public function email(): string {
		return $this->email;
	}
}
