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
				'where'  => __( 'In DevKinsta, open the site and use its Nginx Editor to add the block inside the server { } block. Save, then restart the site in DevKinsta.', 'load-media-from-production' ),
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

	private static function server_paths() {
		$local   = Settings::local_uploads();
		$uploads = trailingslashit( (string) wp_parse_url( $local['url'], PHP_URL_PATH ) );
		$home    = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( is_multisite() ) {
			$site    = trailingslashit( (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ) );
			$network = trailingslashit( get_network()->path );
			if ( 0 === strpos( $uploads, $site ) ) {
				$uploads = $network . substr( $uploads, strlen( $site ) );
			}
			$uploads = preg_replace( '#sites/[0-9]+/$#', '', $uploads );
			$home    = $network;
		}

		return array(
			'uploads' => $uploads,
			'home'    => $home,
		);
	}

	public static function nginx_snippet() {
		$paths = self::server_paths();

		return 'location ^~ ' . $paths['uploads'] . " {\n    try_files \$uri \$uri/ " . $paths['home'] . "index.php?\$args;\n}";
	}

	public static function apache_multisite_snippet() {
		$paths   = self::server_paths();
		$uploads = 0 === strpos( $paths['uploads'], $paths['home'] ) ? substr( $paths['uploads'], strlen( $paths['home'] ) ) : ltrim( $paths['uploads'], '/' );
		$uploads = untrailingslashit( $uploads );
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );

		return 'RewriteCond ' . $root . '$2 !-f' . "\n" . 'RewriteRule ^([_0-9a-zA-Z-]+/)?(' . $uploads . '/.+)$ index.php [L]';
	}
}
