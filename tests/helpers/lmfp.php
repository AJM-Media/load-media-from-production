<?php

$command = isset( $args[0] ) ? $args[0] : '';
$rest    = array_slice( $args, 1 );

switch ( $command ) {
	case 'set':
		$input = array();
		$bad   = false;
		foreach ( $rest as $pair ) {
			list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			if ( 'fingerprint' === $k ) {
				$bad = 'bad' === $v;
				continue;
			}
			$input[ $k ] = $v;
		}
		$clean = LMFP\Settings::sanitize( $input );
		if ( $bad ) {
			$clean['site_fingerprint'] = 'bad';
		}
		update_option( LMFP\Settings::OPTION, $clean );
		echo LMFP\Settings::status() . "\n";
		break;

	case 'status':
		echo LMFP\Settings::status() . "\n";
		break;

	case 'uploads':
		$uploads = wp_upload_dir( null, false );
		echo untrailingslashit( $uploads['baseurl'] ) . "\n";
		break;

	case 'attachment-url':
		echo wp_get_attachment_url( (int) $rest[0] ) . "\n";
		break;

	case 'make-post':
		$id      = (int) $rest[0];
		$uploads = wp_upload_dir( null, false );
		$base    = untrailingslashit( $uploads['baseurl'] );
		$src     = wp_get_original_image_url( $id );
		$json    = wp_json_encode( array( 'url' => $base . '/2020/01/json-missing.png' ) );

		$content  = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} -->';
		$content .= '<figure class="wp-block-image size-full"><img src="' . esc_url( $src ) . '" alt="" class="wp-image-' . $id . '"/></figure>';
		$content .= '<!-- /wp:image -->';
		$content .= '<p><img src="' . esc_url( $base . '/2020/01/missing.png' ) . '" alt=""></p>';
		$content .= '<div style="background-image:url(' . esc_url( $base . '/2020/01/bg-missing.jpg' ) . ')">bg</div>';
		$content .= '<script>var lmfpTest = ' . $json . ';</script>';

		wp_set_current_user( 1 );
		kses_remove_filters();
		echo wp_insert_post(
			array(
				'post_title'   => 'LMFP test',
				'post_content' => wp_slash( $content ),
				'post_status'  => 'publish',
				'post_author'  => 1,
			)
		) . "\n";
		break;

	case 'server-test':
		$test = LMFP\Proxy::run_test();
		echo $test['result'] . ' ' . $test['detail'] . "\n";
		break;

	case 'snippet':
		echo LMFP\Environments::nginx_snippet() . "\n";
		break;

	case 'apache-snippet':
		echo LMFP\Environments::apache_multisite_snippet() . "\n";
		break;

	case 'admin-check':
		$admins = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'number'   => 1,
				'blog_id'  => get_current_blog_id(),
			)
		);
		wp_set_current_user( $admins ? $admins[0]->ID : 1 );

		$errors = array();
		set_error_handler(
			function ( $no, $str, $file, $line ) use ( &$errors ) {
				if ( false !== strpos( $file, 'load-media-from-production' ) ) {
					$errors[] = "$str ($file:$line)";
				}
				return false;
			}
		);

		ob_start();
		LMFP\Admin::render();
		$html = ob_get_clean();

		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		LMFP\Admin::admin_bar( $bar );
		$node = $bar->get_node( 'lmfp' );

		restore_error_handler();

		$problems = $errors;
		if ( substr_count( $html, 'name="lmfp_settings[' ) < 5 ) {
			$problems[] = 'settings form fields missing';
		}
		if ( ! $node ) {
			$problems[] = 'admin bar node missing';
		}
		echo $problems ? implode( ' | ', $problems ) . "\n" : "ok\n";
		break;

	default:
		WP_CLI::error( "Unknown command: $command" );
}
