<?php

namespace ArgentSentinel\WordPress;

final class Autoloader {
	private const PREFIX = 'ArgentSentinel\\WordPress\\';

	/** @var string */
	private static $source_directory;

	public static function register( string $source_directory ): void {
		self::$source_directory = rtrim( $source_directory, '/\\' );
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative_name = substr( $class_name, strlen( self::PREFIX ) );
		$file          = self::$source_directory . '/' . str_replace( '\\', '/', $relative_name ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
