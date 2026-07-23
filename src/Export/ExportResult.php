<?php

namespace ArgentSentinel\WordPress\Export;

final class ExportResult {

	/** @var string */
	private $status;

	/** @var int */
	private $event_count;

	/** @var string|null */
	private $batch_uuid;

	/** @var string|null */
	private $path;

	/** @var string|null */
	private $error;

	private function __construct(
		string $status,
		int $event_count,
		?string $batch_uuid,
		?string $path,
		?string $error
	) {
		$this->status      = $status;
		$this->event_count = $event_count;
		$this->batch_uuid  = $batch_uuid;
		$this->path        = $path;
		$this->error       = $error;
	}

	public static function emptyQueue(): self {
		return new self( 'empty', 0, null, null, null );
	}

	public static function locked(): self {
		return new self( 'locked', 0, null, null, null );
	}

	public static function success( int $count, string $batch_uuid, string $path ): self {
		return new self( 'success', $count, $batch_uuid, $path, null );
	}

	public static function partial( int $count, string $batch_uuid, string $path, string $error ): self {
		return new self( 'partial', $count, $batch_uuid, $path, $error );
	}

	public static function failure( string $error ): self {
		return new self( 'failure', 0, null, null, $error );
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'status'      => $this->status,
			'event_count' => $this->event_count,
			'batch_uuid'  => $this->batch_uuid,
			'path'        => $this->path,
			'error'       => $this->error,
		);
	}

	public function isSuccess(): bool {
		return 'success' === $this->status || 'empty' === $this->status;
	}
}
