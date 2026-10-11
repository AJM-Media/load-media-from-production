<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lmfp_settings' );
delete_metadata( 'user', 0, 'lmfp_donate_dismissed', '', true );
