<?php

namespace LMFP;

defined( 'ABSPATH' ) || exit;

class Environments {
	public static function all() {
		return array(
			'auto'      => array(
				'label'  => __( 'Detect automatically', 'load-media-from-production' ),
				'server' => 'detect',
			),
			'local'     => array(
				'label'  => 'Local',
				'server' => 'detect',
				'where'  => __( 'In Local, open the site folder and edit conf/nginx/site.conf.hbs. Add the block inside the server { } block, then restart the site.', 'load-media-from-production' ),
			),
			'devkinsta' => array(
				'label'  => 'DevKinsta',
				'server' => 'nginx',
				'where'  => __( 'Add the block to your site\'s nginx config file in the DevKinsta folder in your user directory, then restart the site in DevKinsta.', 'load-media-from-production' ),
			),
			'ddev'      => array(
				'label'  => 'DDEV',
				'server' => 'detect',
				'where'  => __( 'Save the block as a file in your project\'s .ddev/nginx/ folder (for example .ddev/nginx/load-media-from-production.conf), then run ddev restart.', 'load-media-from-production' ),
			),
			'lando'     => array(
				'label'  => 'Lando',
				'server' => 'detect',
			),
			'laragon'   => array(
				'label'  => 'Laragon',
				'server' => 'detect',
			),
			'mamp'      => array(
				'label'  => 'MAMP',
				'server' => 'detect',
			),
			'xampp'     => array(
				'label'  => 'XAMPP',
				'server' => 'apache',
			),
			'wp-env'    => array(
				'label'  => 'wp-env',
				'server' => 'apache',
			),
			'valet'     => array(
				'label'  => 'Laravel Valet / Herd',
				'server' => 'router',
			),
			'docker'    => array(
				'label'  => __( 'Docker (other)', 'load-media-from-production' ),
				'server' => 'detect',
			),
			'other'     => array(
				'label'  => __( 'Other / not sure', 'load-media-from-production' ),
				'server' => 'detect',
			),
		);
	}

	public static function exists( $key ) {
		return is_string( $key ) && array_key_exists( $key, self::all() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $all['other'];
	}

	public static function resolve( $key ) {
		if ( 'auto' !== $key ) {
			return self::exists( $key ) ? $key : 'other';
		}
		if ( getenv( 'IS_DDEV_PROJECT' ) ) {
			return 'ddev';
		}
		if ( getenv( 'LANDO' ) ) {
			return 'lando';
		}
		return 'other';
	}

	public static function server( $key ) {
		$preset = self::get( $key );
		if ( 'detect' !== $preset['server'] ) {
			return $preset['server'];
		}
		return self::detected_server();
	}

	public static function detected_server() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) ) {
			return 'apache';
		}
		return 'unknown';
	}

	public static function nginx_snippet() {
		$local = Settings::local_uploads();
		$path  = trailingslashit( (string) wp_parse_url( $local['url'], PHP_URL_PATH ) );

		return 'location ^~ ' . $path . " {\n    try_files \$uri \$uri/ /index.php?\$args;\n}";
	}
}
