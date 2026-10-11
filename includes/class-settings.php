<?php

namespace LMFP;

defined( 'ABSPATH' ) || exit;

class Settings {
	const OPTION = 'lmfp_settings';

	public static function defaults() {
		return array(
			'enabled'          => false,
			'production_url'   => '',
			'uploads_url'      => '',
			'mode'             => 'both',
			'local_first'      => true,
			'fallback_method'  => 'redirect',
			'environment'      => 'auto',
			'site_fingerprint' => '',
		);
	}

	public static function all() {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		if ( self::url_from_constant() ) {
			$settings['enabled']        = true;
			$settings['production_url'] = self::origin( LMFP_PRODUCTION_URL );
		}
		if ( defined( 'LMFP_UPLOADS_URL' ) && '' !== (string) LMFP_UPLOADS_URL ) {
			$settings['uploads_url'] = untrailingslashit( esc_url_raw( LMFP_UPLOADS_URL ) );
		}

		return $settings;
	}

	public static function get( $key ) {
		$settings = self::all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public static function url_from_constant() {
		return defined( 'LMFP_PRODUCTION_URL' ) && '' !== (string) LMFP_PRODUCTION_URL;
	}

	public static function origin( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( esc_url_raw( $url ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	public static function fingerprint() {
		return md5( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$clean['enabled']        = ! empty( $input['enabled'] );
		$clean['production_url'] = isset( $input['production_url'] ) ? self::origin( $input['production_url'] ) : '';
		$clean['local_first']    = ! empty( $input['local_first'] );

		if ( isset( $input['uploads_url'] ) && '' !== trim( $input['uploads_url'] ) ) {
			$clean['uploads_url'] = untrailingslashit( esc_url_raw( trim( $input['uploads_url'] ) ) );
		}
		if ( isset( $input['mode'] ) && in_array( $input['mode'], array( 'both', 'rewrite', 'fallback' ), true ) ) {
			$clean['mode'] = $input['mode'];
		}
		if ( isset( $input['fallback_method'] ) && 'download' === $input['fallback_method'] ) {
			$clean['fallback_method'] = 'download';
		}
		if ( isset( $input['environment'] ) && Environments::exists( $input['environment'] ) ) {
			$clean['environment'] = $input['environment'];
		}

		$clean['site_fingerprint'] = self::fingerprint();

		return $clean;
	}

	public static function status() {
		$settings = self::all();

		if ( defined( 'LMFP_DISABLE' ) && LMFP_DISABLE ) {
			return 'disabled_constant';
		}
		if ( ! $settings['enabled'] ) {
			return 'disabled';
		}
		if ( '' === $settings['production_url'] ) {
			return 'no_url';
		}
		if ( self::is_production() ) {
			return 'production';
		}
		if ( wp_parse_url( $settings['production_url'], PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return 'same_site';
		}
		if ( ! self::url_from_constant() && self::fingerprint() !== $settings['site_fingerprint'] ) {
			return 'site_changed';
		}

		return 'active';
	}

	public static function is_active() {
		return 'active' === self::status();
	}

	public static function is_production() {
		$explicit = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );
		return $explicit && 'production' === wp_get_environment_type();
	}

	public static function local_uploads() {
		$uploads = wp_upload_dir( null, false );

		return array(
			'url' => untrailingslashit( $uploads['baseurl'] ),
			'dir' => untrailingslashit( $uploads['basedir'] ),
		);
	}

	public static function production_uploads_url( $origin = null ) {
		$settings = self::all();
		if ( null === $origin ) {
			if ( '' !== $settings['uploads_url'] ) {
				return $settings['uploads_url'];
			}
			$origin = $settings['production_url'];
		}
		if ( '' === $origin ) {
			return '';
		}

		$local = self::local_uploads();
		return $origin . (string) wp_parse_url( $local['url'], PHP_URL_PATH );
	}
}
