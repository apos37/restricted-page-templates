<?php
/**
 * UNINSTALL
 *
 * Fires only on full plugin deletion via the WordPress Plugins screen.
 * Clears all plugin data only if the user opted in via the Advanced settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

if ( ! get_option( 'rptemplates_clear_data_on_uninstall' ) ) {
    return;
}

global $wpdb;

$rptemplates_options = [
    'rptemplates_no_access_page',
    'rptemplates_login_url',
    'rptemplates_register_url',
    'rptemplates_admin_bypass',
    'rptemplates_show_templates_column',
    'rptemplates_post_types',
    'rptemplates_roles',
    'rptemplates_hide',
    'rptemplates_clear_data_on_uninstall',
    'rptemplates_restricted_index',
    'rptemplates_template_index',
    'rptemplates_db_version',
    'rptemplates_migration_lock',
    'rptemplates_migration_error',
    'rptemplates_legacy_checked',
    'rptemplates_activated_time',
    'rptemplates_replaced_plugin',
];

foreach ( $rptemplates_options as $rptemplates_option ) {
    delete_option( $rptemplates_option );
}

delete_transient( 'rptemplates_migration_notice' );
delete_transient( 'rptemplates_logo_info' );
delete_metadata( 'user', 0, 'rptemplates_per_page', '', true );

$rptemplates_template_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'rptemplates_tpl' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall; the post type is not registered here.

foreach ( $rptemplates_template_ids as $rptemplates_template_id ) {
    wp_delete_post( (int) $rptemplates_template_id, true );
}

delete_post_meta_by_key( '_rptemplates_access' );
delete_post_meta_by_key( '_rptemplates_menu_access' );
