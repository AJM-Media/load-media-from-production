<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $lmfp_site_id ) {
		delete_blog_option( $lmfp_site_id, 'lmfp_settings' );
	}
} else {
	delete_option( 'lmfp_settings' );
}
delete_metadata( 'user', 0, 'lmfp_donate_dismissed', '', true );
