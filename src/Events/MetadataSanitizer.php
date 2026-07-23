<?php

namespace ArgentSentinel\WordPress\Events;

final class MetadataSanitizer {
	private const MAX_DEPTH        = 4;
	private const MAX_ITEMS        = 50;
	private const MAX_STRING_BYTES = 1024;
	private const MAX_TOTAL_BYTES  = 16384;

	/** @var int */
	private $items_remaining = self::MAX_ITEMS;

	/** @var int */
	private $bytes_remaining = self::MAX_TOTAL_BYTES;

	/**
	 * @param array<string|int,mixed> $metadata Untrusted structured metadata.
	 *
	 * @return array<string|int,mixed>
	 */
	public function sanitize( array $metadata ): array {
		$this->items_remaining = self::MAX_ITEMS;
		$this->bytes_remaining = self::MAX_TOTAL_BYTES;

		$sanitized = $this->sanitizeArray( $metadata, 0 );

		if ( $this->isAssociative( $sanitized ) ) {
			ksort( $sanitized, SORT_STRING );
		}

		return $this->enforceEncodedByteLimit( $sanitized );
	}

	/**
	 * @param array<string|int,mixed> $values Values to sanitize.
	 *
	 * @return array<string|int,mixed>
	 */
	private function sanitizeArray( array $values, int $depth ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return array();
		}

		$result = array();

		foreach ( $values as $key => $value ) {
			if ( $this->items_remaining <= 0 || $this->bytes_remaining <= 0 ) {
				break;
			}

			if ( ! is_int( $key ) && $this->isSensitiveKey( (string) $key ) ) {
				continue;
			}

			$normalized_key = is_int( $key ) ? $key : substr( (string) $key, 0, 64 );

			--$this->items_remaining;
			$this->bytes_remaining -= strlen( (string) $normalized_key );

			if ( is_array( $value ) ) {
				$value = $this->sanitizeArray( $value, $depth + 1 );

				if ( $this->isAssociative( $value ) ) {
					ksort( $value, SORT_STRING );
				}
			} elseif ( is_string( $value ) ) {
				$maximum = min( self::MAX_STRING_BYTES, max( 0, $this->bytes_remaining ) );
				$value   = $this->truncateUtf8( $this->validUtf8( $value ), $maximum );
				$this->bytes_remaining -= strlen( $value );
			} elseif ( is_float( $value ) && ! is_finite( $value ) ) {
				continue;
			} elseif ( ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) && null !== $value ) {
				continue;
			} else {
				$encoded = json_encode( $value );
				$this->bytes_remaining -= false === $encoded ? 0 : strlen( $encoded );
			}

			$result[ $normalized_key ] = $value;
		}

		return $result;
	}

	private function isSensitiveKey( string $key ): bool {
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		$key = preg_replace( '/[^a-z0-9]+/i', '_', (string) $key );

		return 1 === preg_match(
			'/(?:^|[_-])(?:password|passwd|password_hash|token|nonce|cookie|authorization|'
				. 'secret|credential(?:s)?|api[_-]?key|private[_-]?key|session(?:_id)?|'
				. '(?:user[_-]?)?email(?:[_-]address)?|(?:request[_-]?)?body|headers?|'
				. '(?:mail|email)[_-]subject)(?:$|[_-])/i',
			$key
		);
	}

	/**
	 * @param array<string|int,mixed> $values Values to inspect.
	 */
	private function isAssociative( array $values ): bool {
		$index = 0;

		foreach ( $values as $key => $unused ) {
			if ( $key !== $index ) {
				return true;
			}

			++$index;
		}

		return false;
	}

	private function validUtf8( string $value ): string {
		$encoded = json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( false === $encoded ) {
			return '';
		}

		$decoded = json_decode( $encoded, true );

		return is_string( $decoded ) ? $decoded : '';
	}

	private function truncateUtf8( string $value, int $maximum_bytes ): string {
		if ( strlen( $value ) <= $maximum_bytes ) {
			return $value;
		}

		$value = substr( $value, 0, $maximum_bytes );

		while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	/**
	 * JSON escaping can expand otherwise bounded strings, especially control
	 * characters. Remove deterministic trailing fields until the serialized
	 * representation is inside the hard event-metadata limit.
	 *
	 * @param array<string|int,mixed> $values Sanitized metadata.
	 *
	 * @return array<string|int,mixed>
	 */
	private function enforceEncodedByteLimit( array $values ): array {
		while ( array() !== $values ) {
			$encoded = json_encode(
				$values,
				JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
			);

			if ( false !== $encoded && strlen( $encoded ) <= self::MAX_TOTAL_BYTES ) {
				break;
			}

			array_pop( $values );
		}

		return $values;
	}
}
