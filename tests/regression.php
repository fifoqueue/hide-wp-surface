<?php

declare(strict_types=1);

// Run with: php tests/regression.php
error_reporting( E_ALL );
set_error_handler( static function ( int $severity, string $message ): never {
	throw new RuntimeException( $message );
} );

$mode = $argv[1] ?? '';
$root = sys_get_temp_dir() . '/hide-wp-regression-' . bin2hex( random_bytes( 8 ) );
mkdir( $root );
define( 'ABSPATH', $root . '/' );
define( 'HIDE_WP_MARKER_DIR', $root . '/custom-runtime' );

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['options'][ $key ] ?? $default;
}
function add_option( string $key, mixed $value, mixed ...$unused ): bool {
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function update_option( string $key, mixed $value, mixed ...$unused ): bool {
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function delete_option( string $key ): bool {
	unset( $GLOBALS['options'][ $key ] );
	return true;
}
function delete_site_transient( string $key ): bool { return true; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function is_multisite(): bool { return false; }
function add_action( mixed ...$args ): void {}
function plugin_basename( string $file ): string { return 'hide-wp-surface/' . basename( $file ); }
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://example.test/wp-content/plugins/hide-wp-surface/'; }

$failures = 0;
function check( bool $passed, string $label ): void {
	if ( ! $passed ) {
		++$GLOBALS['failures'];
		fwrite( STDERR, "FAIL: $label\n" );
	}
}

try {
	if ( in_array( $mode, array( 'uninstall', 'unsupported' ), true ) ) {
		mkdir( HIDE_WP_MARKER_DIR );
		$fixtureMarker = HIDE_WP_MARKER_DIR . '/paths-enabled-' . str_repeat( 'a', 64 ) . '.php';
		touch( $fixtureMarker );
		if ( 'uninstall' === $mode ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
			copy( dirname( __DIR__ ) . '/uninstall.php', $root . '/uninstall.php' );
			require $root . '/uninstall.php';
		} else {
			$GLOBALS['wp_version'] = '6.9';
			copy( dirname( __DIR__ ) . '/hide-wp.php', $root . '/hide-wp.php' );
			require $root . '/hide-wp.php';
		}
		check( ! is_file( $fixtureMarker ), "$mode removes custom runtime markers" );
	} else {
		define( 'HIDE_WP_DIR', dirname( __DIR__ ) . '/' );
		require HIDE_WP_DIR . 'src/Settings.php';
		require HIDE_WP_DIR . 'src/Marker.php';
		require HIDE_WP_DIR . 'src/PathMapper.php';
		require HIDE_WP_DIR . 'src/UrlRewriter.php';
		$GLOBALS['options'] = array(
			'siteurl' => 'https://wp-login.php',
			'home' => 'https://wp-login.php',
			HideWp\Settings::ALIAS_TOKEN_OPTION => str_repeat( 'a', 48 ),
		);
		$mapper = new HideWp\PathMapper( new HideWp\Settings() );
		foreach ( array(
			'https://wp-login.php/wp-login.php?next=/wp-login.php#form' => 'https://wp-login.php/login?next=/wp-login.php#form',
			'//wp-login.php/wp-login.php' => '//wp-login.php/login',
			'/wp-login.php' => '/login',
			'https://external.test/wp-login.php' => 'https://external.test/wp-login.php',
			'https://wp-login.php/wp-login.php-extra' => 'https://wp-login.php/wp-login.php-extra',
		) as $input => $expected ) {
			check( $mapper->rewriteUrl( $input, false, true ) === $expected, "URL: $input" );
		}
		$GLOBALS['options']['siteurl'] = 'https://example.test:8443/site';
		$GLOBALS['options']['home'] = 'https://example.test:8443/site';
		foreach ( array(
			'//example.test/site/wp-login.php' => '//example.test/site/wp-login.php',
			'//example.test:8443/site/wp-login.php' => '//example.test:8443/site/login',
			'https://example.test:8443/site/wp-login.php?x=1#form' => 'https://example.test:8443/site/login?x=1#form',
		) as $input => $expected ) {
			check( $mapper->rewriteUrl( $input, false, true ) === $expected, "URL: $input" );
		}
		$rewriter = new HideWp\UrlRewriter( $mapper );
		try {
			check( false === $rewriter->rewriteSrcsetSources( false ), 'disabled srcset stays disabled' );
		} catch ( TypeError $error ) {
			check( false, 'disabled srcset: ' . $error->getMessage() );
		}
		check( array() === $rewriter->rewriteSrcsetSources( array() ), 'empty srcset stays empty' );
		check( false === $rewriter->rewriteImageSource( false ), 'missing image stays false' );
		$GLOBALS['options']['siteurl'] = 'https://example.test';
		$GLOBALS['options']['home'] = 'https://example.test';
		$settings = new HideWp\Settings();
		$hash = $settings->configurationHash();
		check( $settings->markPathsVerified( $hash ) && HideWp\Marker::enable( $hash ), 'activate test aliases' );
		$source = array( 640 => array( 'url' => 'https://example.test/wp-content/a.jpg', 'descriptor' => 'w', 'value' => 640 ) );
		$expected = $source;
		$expected[640]['url'] = 'https://example.test/assets/a.jpg';
		check( $rewriter->rewriteSrcsetSources( $source ) === $expected, 'active srcset rewrites URL, preserves metadata' );
	}
} finally {
	// Only remove fixtures created in this unique test directory.
	foreach ( glob( HIDE_WP_MARKER_DIR . '/*' ) ?: array() as $file ) { unlink( $file ); }
	if ( is_file( HIDE_WP_MARKER_DIR . '/.htaccess' ) ) { unlink( HIDE_WP_MARKER_DIR . '/.htaccess' ); }
	if ( is_dir( HIDE_WP_MARKER_DIR ) ) { rmdir( HIDE_WP_MARKER_DIR ); }
	if ( is_file( ABSPATH . '.hide-wp-recovery' ) ) { unlink( ABSPATH . '.hide-wp-recovery' ); }
	foreach ( array( 'hide-wp.php', 'uninstall.php' ) as $file ) {
		if ( is_file( $root . '/' . $file ) ) { unlink( $root . '/' . $file ); }
	}
	rmdir( $root );
}

if ( 0 === $failures ) { echo 'PASS: ' . ( '' === $mode ? 'URLs and image filters' : $mode . ' marker cleanup' ) . PHP_EOL; }
exit( 0 === $failures ? 0 : 1 );
