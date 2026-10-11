<?php

namespace LMFP;

defined( 'ABSPATH' ) || exit;

class Proxy {
	private static $local_url;
	private static $local_full_url;
	private static $local_path;
	private static $local_dir;
	private static $remote_url;
	private static $local_first;
	private static $method;
	private static $decisions = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_answer_test' ), 0 );

		if ( ! Settings::is_active() ) {
			return;
		}

		$mode = Settings::get( 'mode' );

		if ( 'fallback' !== $mode ) {
			add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 99 );
			add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'rewrite_image_src' ), 99 );
			add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'rewrite_srcset' ), 99 );
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
		}

		if ( 'rewrite' !== $mode ) {
			add_action( 'init', array( __CLASS__, 'maybe_serve_missing' ), 0 );
			add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_production_host' ) );
		}
	}

	private static function setup() {
		if ( null !== self::$local_url ) {
			return;
		}

		$local = Settings::local_uploads();

		self::$local_full_url = $local['url'];
		self::$local_url      = preg_replace( '#^https?:#i', '', $local['url'] );
		self::$local_path     = untrailingslashit( (string) wp_parse_url( $local['url'], PHP_URL_PATH ) );
		self::$local_dir      = $local['dir'];
		self::$remote_url     = Settings::production_uploads_url();
		self::$local_first    = (bool) Settings::get( 'local_first' );
		self::$method         = Settings::get( 'fallback_method' );
	}

	public static function rewrite_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		self::setup();

		$bare = preg_replace( '#^https?:#i', '', $url );
		if ( 0 !== stripos( $bare, self::$local_url . '/' ) ) {
			return $url;
		}

		$path = substr( $bare, strlen( self::$local_url ) );
		return self::should_proxy( $path ) ? self::$remote_url . $path : $url;
	}

	public static function rewrite_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$image[0] = self::rewrite_url( $image[0] );
		}
		return $image;
	}

	public static function rewrite_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( isset( $source['url'] ) ) {
				$sources[ $width ]['url'] = self::rewrite_url( $source['url'] );
			}
		}
		return $sources;
	}

	public static function start_buffer() {
		ob_start( array( __CLASS__, 'rewrite_html' ) );
	}

	public static function rewrite_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		self::setup();

		$host_path = ltrim( self::$local_url, '/' );

		$plain = '#(?:https?:)?//' . preg_quote( $host_path, '#' ) . '(/[^\s"\'<>()\\\\]+)#i';
		$html  = preg_replace_callback(
			$plain,
			function ( $match ) {
				return self::should_proxy( $match[1] ) ? self::$remote_url . $match[1] : $match[0];
			},
			$html
		);

		$escaped = str_replace( '/', '\/', $host_path );
		$json    = '#(?:https?:)?\\\\/\\\\/' . preg_quote( $escaped, '#' ) . '((?:\\\\/[^\s"\'<>()\\\\]+)+)#i';
		$html    = preg_replace_callback(
			$json,
			function ( $match ) {
				$path = str_replace( '\/', '/', $match[1] );
				return self::should_proxy( $path ) ? str_replace( '/', '\/', self::$remote_url . $path ) : $match[0];
			},
			$html
		);

		return $html;
	}

	private static function should_proxy( $path ) {
		$parts = preg_split( '/[?#&]/', $path );
		$file  = rawurldecode( $parts[0] );

		if ( isset( self::$decisions[ $file ] ) ) {
			return self::$decisions[ $file ];
		}

		$proxy = '' !== self::$remote_url && false === strpos( $file, '..' );
		if ( $proxy && self::$local_first ) {
			$proxy = ! file_exists( self::$local_dir . $file );
		}

		$proxy = (bool) apply_filters( 'lmfp_should_proxy', $proxy, $file );

		self::$decisions[ $file ] = $proxy;
		return $proxy;
	}

	private static function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}

	public static function maybe_serve_missing() {
		self::setup();

		$path = self::request_path();
		if ( '' === $path || 0 !== strpos( $path, self::$local_path . '/' ) ) {
			return;
		}

		$rel  = substr( $path, strlen( self::$local_path ) );
		$file = rawurldecode( $rel );
		if ( false !== strpos( $file, '..' ) || false !== strpos( $file, "\0" ) || is_file( self::$local_dir . $file ) ) {
			return;
		}

		if ( '' === self::$remote_url || ! apply_filters( 'lmfp_should_proxy', true, $file ) ) {
			return;
		}

		$remote = self::$remote_url . $rel;

		$retry = isset( $_GET['lmfp'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only loop guard on a public file request.

		if ( 'download' === self::$method && ! $retry && self::download( $remote, $file ) ) {
			wp_safe_redirect( self::$local_full_url . $rel . '?lmfp=1', 302, 'Load Media from Production' );
			exit;
		}

		wp_safe_redirect( $remote, 302, 'Load Media from Production' );
		exit;
	}

	private static function download( $remote, $file ) {
		$type = wp_check_filetype( $file );
		if ( empty( $type['type'] ) ) {
			return false;
		}

		$target = self::$local_dir . $file;
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}

		$response = wp_remote_get(
			$remote,
			array(
				'timeout'  => 30,
				'stream'   => true,
				'filename' => $target,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $target );
			return false;
		}

		return true;
	}

	public static function allow_production_host( $hosts ) {
		self::setup();
		$host = wp_parse_url( self::$remote_url, PHP_URL_HOST );
		if ( $host ) {
			$hosts[] = $host;
		}
		return $hosts;
	}

	public static function maybe_answer_test() {
		$path = self::request_path();
		if ( ! preg_match( '#/lmfp-test-([A-Za-z0-9]{12})\.png$#', $path, $match ) ) {
			return;
		}

		$token = get_transient( 'lmfp_test_token' );
		if ( ! is_string( $token ) || ! hash_equals( $token, $match[1] ) ) {
			return;
		}

		status_header( 200 );
		header( 'X-LMFP-Test: reached' );
		exit;
	}

	public static function run_test() {
		$local = Settings::local_uploads();
		$token = wp_generate_password( 12, false );
		set_transient( 'lmfp_test_token', $token, 5 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			$local['url'] . '/lmfp-test-' . $token . '.png',
			array(
				'timeout'     => 10,
				'redirection' => 0,

				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests, applied as core does.
			)
		);

		delete_transient( 'lmfp_test_token' );

		if ( is_wp_error( $response ) ) {
			return array(
				'result' => 'error',
				'detail' => $response->get_error_message(),
			);
		}
		if ( 'reached' === wp_remote_retrieve_header( $response, 'x-lmfp-test' ) ) {
			return array(
				'result' => 'reached',
				'detail' => '',
			);
		}
		return array(
			'result' => 'server',
			'detail' => (string) wp_remote_retrieve_response_code( $response ),
		);
	}
}
