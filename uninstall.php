<?php
/**
 * Uninstall Ace Revisions: remove options only. Tracked data is left in place
 * on purpose so deleting the plugin never destroys history or content.
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'ace_revisions_options' );
delete_option( 'ace_revisions_version' );
