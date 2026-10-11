<?php

namespace LMFP;

defined( 'ABSPATH' ) || exit;

class Admin {
	const PAGE         = 'load-media-from-production';
	const DISMISS_META = 'lmfp_donate_dismissed';

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_admin_bar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_bar' ) );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page' ) );
		add_action( 'admin_post_lmfp_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_lmfp_dismiss_donate', array( __CLASS__, 'handle_dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LMFP_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_options_page(
			__( 'Load Media from Production', 'load-media-from-production' ),
			__( 'Media from Production', 'load-media-from-production' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'lmfp',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function enqueue_admin_bar() {
		if ( is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( 'lmfp-admin-bar', LMFP_URL . 'assets/admin-bar.css', array( 'admin-bar' ), LMFP_VERSION );
		}
	}

	public static function enqueue_page( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'lmfp-admin', LMFP_URL . 'assets/admin.css', array(), LMFP_VERSION );
		}
	}

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = Settings::status();
		if ( in_array( $status, array( 'disabled', 'disabled_constant' ), true ) ) {
			return;
		}

		$active = 'active' === $status;
		$bar->add_node(
			array(
				'id'    => 'lmfp',
				'title' => '<span class="lmfp-dot" aria-hidden="true"></span>' . esc_html( $active ? __( 'Media: production', 'load-media-from-production' ) : __( 'Media: paused', 'load-media-from-production' ) ),
				'href'  => self::page_url(),
				'meta'  => array(
					'class' => $active ? 'lmfp-active' : 'lmfp-paused',
					'title' => self::status_message( $status ),
				),
			)
		);
	}

	public static function status_message( $status ) {
		switch ( $status ) {
			case 'active':
				return sprintf(
					/* translators: %s: production uploads URL */
					__( 'On: missing media loads from %s', 'load-media-from-production' ),
					Settings::production_uploads_url()
				);
			case 'disabled_constant':
				return __( 'Off: LMFP_DISABLE is set in wp-config.php.', 'load-media-from-production' );
			case 'no_url':
				return __( 'Paused: add your production site address below.', 'load-media-from-production' );
			case 'production':
				return __( 'Paused: this site is marked as production (WP_ENVIRONMENT_TYPE), so it will never load media from another site.', 'load-media-from-production' );
			case 'same_site':
				return __( 'Paused: the production address is this site\'s own address.', 'load-media-from-production' );
			case 'site_changed':
				return __( 'Paused: this site\'s address has changed since the settings were saved, for example after copying the database from another site. Check the settings below and save them to turn it back on.', 'load-media-from-production' );
			default:
				return __( 'Off: tick "Load media from production" below to turn it on.', 'load-media-from-production' );
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$status   = Settings::status();
		$locked   = Settings::url_from_constant();
		$name     = Settings::OPTION;
		$example  = Settings::production_uploads_url( '' !== $settings['production_url'] ? $settings['production_url'] : 'https://example.com' );
		?>
		<div class="wrap lmfp">
			<h1><?php esc_html_e( 'Load Media from Production', 'load-media-from-production' ); ?></h1>

			<div class="notice inline <?php echo 'active' === $status ? 'notice-success' : 'notice-warning'; ?>">
				<p><?php echo esc_html( self::status_message( $status ) ); ?></p>
			</div>

			<?php self::render_test_result(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'lmfp' ); ?>

				<?php if ( $locked ) : ?>
					<p><?php esc_html_e( 'The production address is set by LMFP_PRODUCTION_URL in wp-config.php, so it can\'t be changed here.', 'load-media-from-production' ); ?></p>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'load-media-from-production' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> <?php disabled( $locked ); ?>>
								<?php esc_html_e( 'Load media from production', 'load-media-from-production' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lmfp-production-url"><?php esc_html_e( 'Production site address', 'load-media-from-production' ); ?></label></th>
						<td>
							<input type="url" id="lmfp-production-url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[production_url]" value="<?php echo esc_attr( $settings['production_url'] ); ?>" placeholder="https://example.com" <?php disabled( $locked ); ?>>
							<p class="description"><?php esc_html_e( 'Your live site, for example https://example.com. Only the domain is used.', 'load-media-from-production' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lmfp-environment"><?php esc_html_e( 'Local environment', 'load-media-from-production' ); ?></label></th>
						<td>
							<select id="lmfp-environment" name="<?php echo esc_attr( $name ); ?>[environment]">
								<?php foreach ( Environments::all() as $key => $preset ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['environment'], $key ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The tool this site runs on. It decides the server setup instructions below.', 'load-media-from-production' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Method', 'load-media-from-production' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="both" <?php checked( $settings['mode'], 'both' ); ?>> <?php esc_html_e( 'Rewrite media URLs and redirect missing files (recommended)', 'load-media-from-production' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="rewrite" <?php checked( $settings['mode'], 'rewrite' ); ?>> <?php esc_html_e( 'Rewrite media URLs only', 'load-media-from-production' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="fallback" <?php checked( $settings['mode'], 'fallback' ); ?>> <?php esc_html_e( 'Redirect missing files only', 'load-media-from-production' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Rewriting works on any server. Redirecting catches files the rewrite misses, but some servers need a small change first (see Server setup).', 'load-media-from-production' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Local files', 'load-media-from-production' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[local_first]" value="1" <?php checked( $settings['local_first'] ); ?>>
								<?php esc_html_e( 'Only use production for files missing from this site', 'load-media-from-production' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Keeps images you upload on this site working. Untick to load every upload from production.', 'load-media-from-production' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Missing files', 'load-media-from-production' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[fallback_method]" value="redirect" <?php checked( $settings['fallback_method'], 'redirect' ); ?>> <?php esc_html_e( 'Redirect to the file on production', 'load-media-from-production' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[fallback_method]" value="download" <?php checked( $settings['fallback_method'], 'download' ); ?>> <?php esc_html_e( 'Download a copy into this site\'s uploads folder', 'load-media-from-production' ); ?></label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Downloading builds up a local copy of the files you actually view, so they keep working offline.', 'load-media-from-production' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lmfp-uploads-url"><?php esc_html_e( 'Production uploads URL', 'load-media-from-production' ); ?></label></th>
						<td>
							<input type="url" id="lmfp-uploads-url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[uploads_url]" value="<?php echo esc_attr( $settings['uploads_url'] ); ?>" placeholder="<?php echo esc_attr( $example ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: default production uploads URL */
									esc_html__( 'Optional. Leave blank to use %s. Set it if production keeps uploads at a different path, for example when only this site runs WordPress in a subfolder.', 'load-media-from-production' ),
									'<code>' . esc_html( $example ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Server setup', 'load-media-from-production' ); ?></h2>
			<?php self::render_server_help( $settings['environment'] ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lmfp_test">
				<?php wp_nonce_field( 'lmfp_test' ); ?>
				<?php submit_button( __( 'Test missing-file handling', 'load-media-from-production' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_donate(); ?>
		</div>
		<?php
	}

	private static function render_server_help( $environment ) {
		$key    = Environments::resolve( $environment );
		$preset = Environments::get( $key );
		$server = Environments::server( $key );

		echo '<div class="lmfp-server">';

		if ( 'auto' === $environment || 'detect' === $preset['server'] ) {
			$names = array(
				'apache'  => 'Apache',
				'nginx'   => 'nginx',
				'unknown' => __( 'unknown', 'load-media-from-production' ),
			);
			echo '<p>' . sprintf(
				/* translators: %s: web server name */
				esc_html__( 'Detected web server: %s', 'load-media-from-production' ),
				'<strong>' . esc_html( $names[ Environments::detected_server() ] ) . '</strong>'
			) . '</p>';
		}

		if ( 'apache' === $server ) {
			echo '<p>' . esc_html__( 'Apache passes requests for missing files to WordPress through its standard .htaccess rules, so no server changes should be needed. Run the test to confirm.', 'load-media-from-production' ) . '</p>';
		} elseif ( 'router' === $server ) {
			echo '<p>' . sprintf(
				/* translators: %s: local environment name */
				esc_html__( '%s passes requests for missing files to WordPress, so no server changes should be needed. Run the test to confirm.', 'load-media-from-production' ),
				esc_html( $preset['label'] )
			) . '</p>';
		} else {
			if ( 'nginx' === $server ) {
				echo '<p>' . esc_html__( 'nginx often answers requests for missing images itself, before WordPress can redirect them. If the test fails, add this block to the site\'s nginx config:', 'load-media-from-production' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Run the test. If it fails and your site runs on nginx, add this block to the site\'s nginx config:', 'load-media-from-production' ) . '</p>';
			}
			echo '<pre><code>' . esc_html( Environments::nginx_snippet() ) . '</code></pre>';
			$where = isset( $preset['where'] ) ? $preset['where'] : __( 'Add it inside the server { } block for this site, then reload nginx.', 'load-media-from-production' );
			echo '<p>' . esc_html( $where ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'URL rewriting doesn\'t depend on any of this. It only affects redirecting missing files.', 'load-media-from-production' ) . '</p>';
		echo '</div>';
	}

	private static function render_test_result() {
		$key    = 'lmfp_test_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		if ( 'reached' === $result['result'] ) {
			$class   = 'notice-success';
			$message = __( 'Test passed: requests for missing files reach WordPress, so redirecting missing files will work.', 'load-media-from-production' );
		} elseif ( 'server' === $result['result'] ) {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: %s: HTTP status code */
				__( 'Test failed: the web server answered (HTTP %s) without passing the request to WordPress. Add the block under Server setup, restart the site and test again.', 'load-media-from-production' ),
				$result['detail']
			);
		} else {
			$class   = 'notice-warning';
			$message = sprintf(
				/* translators: %s: connection error message */
				__( 'The test couldn\'t run because this site couldn\'t connect to itself (%s). This is common with Docker. To check by hand, open the address of an image that\'s missing locally: if the production image appears, it works.', 'load-media-from-production' ),
				$result['detail']
			);
		}

		echo '<div class="notice inline ' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'load-media-from-production' ) );
		}
		check_admin_referer( 'lmfp_test' );

		set_transient( 'lmfp_test_result_' . get_current_user_id(), Proxy::run_test(), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function render_donate() {
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=lmfp_dismiss_donate' ), 'lmfp_dismiss_donate' );
		?>
		<div class="lmfp-donate">
			<p><?php esc_html_e( 'Saved you copying a few gigabytes of uploads? This plugin is free. If it helps, you can buy me a coffee.', 'load-media-from-production' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( LMFP_DONATE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy me a coffee', 'load-media-from-production' ); ?></a>
				<a class="lmfp-dismiss" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Hide this', 'load-media-from-production' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'load-media-from-production' ) );
		}
		check_admin_referer( 'lmfp_dismiss_donate' );

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'load-media-from-production' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( LMFP_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( LMFP_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy me a coffee', 'load-media-from-production' ) . '</a>';
		}
		return $meta;
	}
}
