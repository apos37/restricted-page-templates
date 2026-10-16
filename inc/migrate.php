<?php
/**
 * MIGRATE
 *
 * First-run setup and the one-time import from Role Visibility, the previous name of this plugin.
 * Version 2.x data is renamed in place, and version 1.x options, post meta and custom messages are
 * converted into templates. Runs once on init, on any request, guarded by a lock.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Migrate {

    /**
     * Database version this release expects.
     */
    public const VERSION = '1.0.3';


    /**
     * Options used by the migration.
     */
    public const VERSION_OPTION = 'rptemplates_db_version';
    public const LOCK_OPTION = 'rptemplates_migration_lock';
    public const NOTICE_TRANSIENT = 'rptemplates_migration_notice';
    public const ERROR_OPTION = 'rptemplates_migration_error';
    public const CHECKED_OPTION = 'rptemplates_legacy_checked';


    /**
     * Role Visibility 1.x names.
     */
    public const LEGACY_PREFIX = 'role_visibility_';
    public const LEGACY_META_KEYS = [ 'role_visibility', '_role_visibility' ];
    public const LEGACY_MSG_META_KEYS = [ 'role_visibility_msg', '_role_visibility_msg' ];
    public const LEGACY_PLACEHOLDER = '{role_visibility_msg}';


    /**
     * Template conditions that existed before URL Parameter access.
     */
    public const LEGACY_CONDITIONS = [ 'login_required', 'missing_access', 'logout_required' ];


    /**
     * Template meta key of the URL Parameter scope used in 1.0.2, replaced by URL Parameter access.
     */
    public const URL_PARAM_SCOPE_META = '_rptemplates_tpl_url_param';


    /**
     * Role Visibility 2.x names.
     */
    public const RV_NAME = 'rolevisibility';
    public const RV_PREFIX = 'rolevisibility_';
    public const RV_VERSION_OPTION = 'rolevisibility_db_version';
    public const RV_POST_TYPE = 'rolevisibility_tpl';
    public const RV_BLOCK_ACCESS = 'rolevisibilityAccess';
    public const RV_BLOCK_ROLES = 'rolevisibilityRoles';
    public const RV_TRANSIENTS = [ 'rolevisibility_migration_notice', 'rolevisibility_logo_info' ];


    /**
     * Role Visibility 2.x options that are rebuilt or no longer needed, keyed without the prefix.
     */
    public const RV_DISCARD = [ 'restricted_index', 'template_index', 'db_version', 'migration_lock', 'migration_error', 'legacy_checked' ];


    /**
     * Role Visibility 2.x post meta keys and the keys that replace them.
     */
    public const RV_META_KEYS = [
        '_rolevisibility_access'         => Access::META_KEY,
        '_rolevisibility_menu_access'    => NavMenus::META_KEY,
        '_rolevisibility_tpl_scope'      => Templates::META_SCOPE,
        '_rolevisibility_tpl_targets'    => Templates::META_TARGETS,
        '_rolevisibility_tpl_conditions' => Templates::META_CONDITIONS,
    ];


    /**
     * User meta keys that may hold Role Visibility 2.x screen preferences, as LIKE patterns.
     */
    public const RV_USER_META_LIKE = [ 'rolevisibility\_%', 'metaboxhidden\_%', 'closedpostboxes\_%', 'meta-box-order\_%', 'manage%columnshidden' ];


    /**
     * Rows updated per query when rewriting post meta and post content.
     */
    public const CHUNK = 500;


    /**
     * @var Migrate|null Singleton instance
     */
    private static ?Migrate $instance = null;


    /**
     * Get instance
     *
     * @return self
     */
    public static function instance() : self {
        return self::$instance ??= new self();
    } // End instance()


    /**
     * Constructor
     */
    private function __construct() {
        add_action( 'init', [ $this, 'maybe_run' ], 20 );
        add_action( 'admin_notices', [ $this, 'render_notice' ] );
    } // End __construct()


    /**
     * Run setup or the import on init. Also converts any Role Visibility data left behind, once per release.
     *
     * Nothing is converted while Role Visibility is still active. An administrator's next visit to the
     * dashboard deactivates it, and the import runs on the request after that.
     *
     * @return void
     */
    public function maybe_run() : void {
        if ( ! empty( Bootstrap::predecessors() ) ) {
            if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
                Bootstrap::deactivate_predecessors();
            }

            return;
        }

        if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
            update_option( self::CHECKED_OPTION, self::VERSION, true );
            self::execute();

            return;
        }

        if ( self::VERSION === get_option( self::CHECKED_OPTION ) ) {
            return;
        }

        update_option( self::CHECKED_OPTION, self::VERSION, true );

        if ( self::has_legacy_data() ) {
            self::execute();
        }
    } // End maybe_run()


    /**
     * Check whether Role Visibility 1.x post meta still exists.
     *
     * @return bool
     */
    private static function has_v1_meta() : bool {
        global $wpdb;

        $keys = array_merge( self::LEGACY_META_KEYS, self::LEGACY_MSG_META_KEYS );
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) LIMIT 1", $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- checks for leftover Role Visibility 1.x data; the interpolated list only contains placeholders.
    } // End has_v1_meta()


    /**
     * Check whether Role Visibility 1.x options or post meta still exist.
     *
     * @return bool
     */
    public static function has_v1_data() : bool {
        global $wpdb;

        $option = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( self::LEGACY_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks for leftover Role Visibility 1.x data.
        
        return $option || self::has_v1_meta();
    } // End has_v1_data()


    /**
     * Check whether Role Visibility 2.x options, post meta or templates still exist.
     *
     * @return bool
     */
    public static function has_v2_data() : bool {
        global $wpdb;

        $option = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", $wpdb->esc_like( self::RV_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks for leftover Role Visibility 2.x data.
        $keys = array_keys( self::RV_META_KEYS );
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $meta = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) LIMIT 1", $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- checks for leftover Role Visibility 2.x data; the interpolated list only contains placeholders.
        $posts = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", self::RV_POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checks for leftover Role Visibility 2.x data.

        return (bool) ( $option || $meta || $posts );
    } // End has_v2_data()


    /**
     * Check whether any Role Visibility data still exists.
     *
     * @return bool
     */
    public static function has_legacy_data() : bool {
        return self::has_v2_data() || self::has_v1_data();
    } // End has_legacy_data()


    /**
     * Run the migration under a lock, capturing any error instead of leaving it half done.
     *
     * @param bool $force Ignore a lock left by an earlier run. Used by the manual re-run.
     * @return array Summary, or [ 'error' => message ], or [ 'skipped' => true ] while another request is running it.
     */
    public static function execute( bool $force = false ) : array {
        if ( ! empty( Bootstrap::predecessors() ) ) {
            return [ 'error' => __( 'Deactivate Role Visibility first.', 'restricted-page-templates' ) ];
        }

        if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
            if ( ! $force && time() - (int) get_option( self::LOCK_OPTION ) < 10 * MINUTE_IN_SECONDS ) {
                return [ 'skipped' => true ];
            }

            update_option( self::LOCK_OPTION, time(), false );
        }

        $previous = (string) get_option( self::VERSION_OPTION );

        try {
            $summary = self::run();
            self::upgrade( $previous );
        } catch ( \Throwable $error ) {
            $message = sprintf( '%1$s (%2$s:%3$d)', $error->getMessage(), basename( $error->getFile() ), $error->getLine() );

            update_option( self::ERROR_OPTION, $message, false );
            update_option( self::VERSION_OPTION, self::VERSION, true );
            delete_option( self::LOCK_OPTION );

            return [ 'error' => $message ];
        }

        delete_option( self::ERROR_OPTION );
        update_option( self::VERSION_OPTION, self::VERSION, true );
        delete_option( self::LOCK_OPTION );

        if ( ! empty( $summary[ 'legacy' ] ) || ! empty( $summary[ 'renamed' ] ) ) {
            set_transient( self::NOTICE_TRANSIENT, $summary, WEEK_IN_SECONDS );
        }

        do_action( 'rptemplates_migrated', $summary );

        return $summary;
    } // End execute()


    /**
     * Perform setup or the import.
     *
     * @return array Summary of what changed.
     */
    private static function run() : array {
        global $wpdb;

        $summary = [ 'renamed' => false, 'legacy' => false ];

        if ( self::has_v2_data() ) {
            $summary = array_merge( $summary, self::migrate_v2() );
        }

        $legacy = [];
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::LEGACY_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of Role Visibility 1.x options.

        foreach ( $rows as $row ) {
            $legacy[ substr( $row->option_name, strlen( self::LEGACY_PREFIX ) ) ] = maybe_unserialize( $row->option_value );
        }

        if ( empty( $legacy ) && ! self::has_v1_meta() ) {
            if ( empty( $summary[ 'was_set_up' ] ) ) {
                Templates::create_defaults();
            }

            return $summary;
        }

        $type_messages = self::migrate_settings( $legacy );
        self::migrate_no_access_page();

        $posts = self::migrate_access_meta();
        $bases = self::legacy_bases( $legacy );

        $global_contents = [];

        foreach ( array_keys( $bases ) as $condition ) {
            $global_contents[ $condition ] = str_replace( self::LEGACY_PLACEHOLDER, wp_kses_post( self::legacy_message( $legacy, $condition ) ), $bases[ $condition ] );
        }

        Templates::create_defaults( $global_contents );

        $templates = self::migrate_messages( $bases, $type_messages );

        foreach ( array_keys( $legacy ) as $key ) {
            delete_option( self::LEGACY_PREFIX . $key );
        }

        delete_transient( 'role_visibility_license_data' );
        delete_transient( 'role_visibility_update_check' );

        Settings::flush_cache();
        Templates::rebuild_index();
        RestrictedIndex::rebuild();

        return array_merge( $summary, [ 'legacy' => true, 'posts' => $posts, 'templates' => $templates ] );
    } // End run()


    /**
     * Bring templates up to date with URL Parameter access, for sites set up before 1.0.3.
     *
     * Templates that used the 1.0.2 URL Parameter scope become global templates for visitors whose link does
     * not match a URL Parameter rule. Specific Posts templates that followed each post's Access setting keep
     * following it. A ready-made template for the new situation is added when none exists.
     *
     * @param string $previous Database version before this run, or an empty string.
     * @return void
     */
    private static function upgrade( string $previous ) : void {
        if ( '' !== $previous && version_compare( $previous, '1.0.3', '>=' ) ) {
            return;
        }

        $template_ids = get_posts( [
            'post_type'              => Templates::POST_TYPE,
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ] );

        update_meta_cache( 'post', $template_ids );

        $has_param_template = false;

        foreach ( $template_ids as $template_id ) {
            $scope = (string) get_post_meta( $template_id, Templates::META_SCOPE, true );
            $conditions = array_values( (array) get_post_meta( $template_id, Templates::META_CONDITIONS, true ) );

            if ( 'url_param' === $scope ) {
                Templates::set_settings( $template_id, 'global', [ 'param_required' ] );
                $conditions = [ 'param_required' ];
            } elseif ( 'posts' === $scope && empty( array_diff( self::LEGACY_CONDITIONS, $conditions ) ) && ! in_array( 'param_required', $conditions, true ) ) {
                $conditions[] = 'param_required';
                update_post_meta( $template_id, Templates::META_CONDITIONS, $conditions );
            }

            delete_post_meta( $template_id, self::URL_PARAM_SCOPE_META );
            $has_param_template = $has_param_template || in_array( 'param_required', $conditions, true );
        }

        if ( ! $has_param_template ) {
            Templates::create( Templates::default_titles()[ 'param_required' ], Templates::default_content( 'param_required' ), 'global', [ 'param_required' ] );
        }

        Templates::rebuild_index();
    } // End upgrade()


    /**
     * Rename Role Visibility 2.x options, post meta, templates, block attributes and screen preferences in place.
     *
     * @return array [ renamed, was_set_up, rules, moved, menus ]
     */
    private static function migrate_v2() : array {
        global $wpdb;

        $was_set_up = false !== get_option( self::RV_VERSION_OPTION );
        $fields = Settings::fields();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::RV_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of Role Visibility 2.x options.

        foreach ( $rows as $row ) {
            $key = substr( $row->option_name, strlen( self::RV_PREFIX ) );

            if ( ! in_array( $key, self::RV_DISCARD, true ) && null === get_option( Settings::PREFIX . $key, null ) ) {
                $autoload = isset( $fields[ $key ] ) ? ! empty( $fields[ $key ][ 'autoload' ] ) : in_array( $row->autoload, [ 'yes', 'on', 'auto', 'auto-on' ], true );
                add_option( Settings::PREFIX . $key, maybe_unserialize( $row->option_value ), '', $autoload );
            }

            delete_option( $row->option_name );
        }

        foreach ( self::RV_TRANSIENTS as $transient ) {
            delete_transient( $transient );
        }

        Settings::flush_cache();

        $keys = array_keys( self::RV_META_KEYS );
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $counts = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT( DISTINCT post_id ) AS total FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) GROUP BY meta_key", $keys ), OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one-time migration count; the interpolated list only contains placeholders.
        $post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one-time migration read; the interpolated list only contains placeholders.

        foreach ( self::RV_META_KEYS as $old_key => $new_key ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ( SELECT post_id FROM ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ) AS existing )", $old_key, $new_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; drops an old key only where the new key is already set.
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", $new_key, $old_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration rename of post meta keys.
        }

        foreach ( $post_ids as $post_id ) {
            wp_cache_delete( (int) $post_id, 'post_meta' );
        }

        $templates = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = %s", self::RV_POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of Role Visibility 2.x templates.

        if ( ! empty( $templates ) ) {
            $wpdb->update( $wpdb->posts, [ 'post_type' => Templates::POST_TYPE ], [ 'post_type' => self::RV_POST_TYPE ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration of the template post type.

            foreach ( $templates as $template ) {
                clean_post_cache( (int) $template->ID );
            }
        }

        self::migrate_block_attributes();
        self::migrate_no_access_page();
        self::migrate_user_meta();

        Templates::rebuild_index();
        RestrictedIndex::rebuild();

        return [
            'renamed'    => true,
            'was_set_up' => $was_set_up,
            'rules'      => (int) ( $counts[ '_rolevisibility_access' ]->total ?? 0 ),
            'moved'      => count( array_filter( $templates, fn( $template ) => 'auto-draft' !== $template->post_status ) ),
            'menus'      => (int) ( $counts[ '_rolevisibility_menu_access' ]->total ?? 0 ),
        ];
    } // End migrate_v2()


    /**
     * Rename the Navigation block attributes saved in post content and in block widgets.
     *
     * @return void
     */
    private static function migrate_block_attributes() : void {
        global $wpdb;

        $pairs = [
            '"' . self::RV_BLOCK_ACCESS . '"' => '"' . NavMenus::BLOCK_ACCESS . '"',
            '"' . self::RV_BLOCK_ROLES . '"'  => '"' . NavMenus::BLOCK_ROLES . '"',
        ];

        $post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", '%' . $wpdb->esc_like( '"' . self::RV_NAME ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration read of posts with Role Visibility block attributes.

        foreach ( array_chunk( array_map( 'absint', $post_ids ), self::CHUNK ) as $chunk ) {
            $id_list = implode( ',', array_map( 'absint', $chunk ) );

            foreach ( $pairs as $old => $new ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE ID IN ( {$id_list} )", $old, $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time migration; the ID list is built from absint() values.
            }
        }

        foreach ( $post_ids as $post_id ) {
            clean_post_cache( (int) $post_id );
        }

        $widgets = get_option( 'widget_block' );

        if ( ! is_array( $widgets ) ) {
            return;
        }

        $changed = false;

        foreach ( $widgets as $number => $widget ) {
            if ( is_array( $widget ) && is_string( $widget[ 'content' ] ?? null ) ) {
                $content = strtr( $widget[ 'content' ], $pairs );
                $changed = $changed || $content !== $widget[ 'content' ];
                $widgets[ $number ][ 'content' ] = $content;
            }
        }

        if ( $changed ) {
            update_option( 'widget_block', $widgets );
        }
    } // End migrate_block_attributes()


    /**
     * Switch the No Access Page to the new shortcode. The old one keeps working anywhere else.
     *
     * @return void
     */
    private static function migrate_no_access_page() : void {
        global $wpdb;

        $page_id = (int) Settings::get( 'no_access_page' );
        $content = $page_id ? (string) get_post_field( 'post_content', $page_id, 'raw' ) : '';
        $updated = preg_replace( '/\[' . preg_quote( Shortcode::LEGACY_TAG, '/' ) . '\s*\/?\]/', '[' . Shortcode::TAG . ']', $content );

        if ( null === $updated || $updated === $content ) {
            return;
        }

        $wpdb->update( $wpdb->posts, [ 'post_content' => $updated ], [ 'ID' => $page_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; avoids a revision for a shortcode rename.
        clean_post_cache( $page_id );
    } // End migrate_no_access_page()


    /**
     * Carry over the templates list per-page choice and the screen preferences of the renamed boxes and columns.
     *
     * @return void
     */
    private static function migrate_user_meta() : void {
        global $wpdb;

        $key_clauses = implode( ' OR ', array_fill( 0, count( self::RV_USER_META_LIKE ), 'meta_key LIKE %s' ) );
        $needle = '%' . $wpdb->esc_like( self::RV_NAME ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE ( {$key_clauses} ) AND ( meta_key LIKE %s OR meta_value LIKE %s )", array_merge( self::RV_USER_META_LIKE, [ $needle, $needle ] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one-time migration read; the interpolated clauses only contain placeholders, filled from the array of values.

        $rename = function( $value ) use ( &$rename ) {
            if ( is_array( $value ) ) {
                return array_map( $rename, $value );
            }

            return is_string( $value ) ? str_replace( self::RV_NAME, 'rptemplates', $value ) : $value;
        };

        foreach ( $rows as $row ) {
            $key = str_replace( self::RV_NAME, 'rptemplates', $row->meta_key );

            if ( $key !== $row->meta_key && metadata_exists( 'user', (int) $row->user_id, $key ) ) {
                $wpdb->delete( $wpdb->usermeta, [ 'umeta_id' => (int) $row->umeta_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration; the new key is already set.
            } else {
                $wpdb->update( $wpdb->usermeta, [ 'meta_key' => $key, 'meta_value' => maybe_serialize( $rename( maybe_unserialize( $row->meta_value ) ) ) ], [ 'umeta_id' => (int) $row->umeta_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time migration of screen preferences.
            }

            wp_cache_delete( (int) $row->user_id, 'user_meta' );
        }
    } // End migrate_user_meta()


    /**
     * Copy Role Visibility 1.x settings into the new options.
     *
     * @param array $legacy Version 1.x options keyed without the prefix.
     * @return array Per post type messages: [ post_type => [ logged_in_msg, logged_out_msg ] ]
     */
    private static function migrate_settings( array $legacy ) : array {
        $values = [];
        $type_messages = [];

        if ( isset( $legacy[ 'no_access_page' ] ) ) {
            $values[ 'no_access_page' ] = absint( $legacy[ 'no_access_page' ] );
        }

        foreach ( [ 'login_url' => wp_login_url(), 'register_url' => wp_registration_url() ] as $key => $wp_default ) {
            if ( isset( $legacy[ $key ] ) && is_string( $legacy[ $key ] ) && $legacy[ $key ] !== $wp_default ) {
                $values[ $key ] = $legacy[ $key ];
            }
        }

        if ( isset( $legacy[ 'uninstall_reset' ] ) ) {
            $values[ 'clear_data_on_uninstall' ] = $legacy[ 'uninstall_reset' ];
        }

        if ( is_array( $legacy[ 'post_types' ] ?? null ) && ! empty( $legacy[ 'post_types' ] ) ) {
            $values[ 'post_types' ] = [];

            foreach ( $legacy[ 'post_types' ] as $row ) {
                if ( empty( $row[ 'post_type' ] ) ) {
                    continue;
                }

                $values[ 'post_types' ][ $row[ 'post_type' ] ] = $row[ 'default' ] ?? 'everyone';
                $type_messages[ $row[ 'post_type' ] ] = [
                    'logged_in_msg'  => trim( (string) ( $row[ 'logged_in_msg' ] ?? '' ) ),
                    'logged_out_msg' => trim( (string) ( $row[ 'logged_out_msg' ] ?? '' ) ),
                ];
            }
        }

        if ( is_array( $legacy[ 'roles' ] ?? null ) && ! empty( $legacy[ 'roles' ] ) ) {
            $values[ 'roles' ] = array_filter( array_map( fn( $row ) => $row[ 'role' ] ?? '', $legacy[ 'roles' ] ) );
        }

        Settings::save( $values );
        Settings::flush_cache();

        return $type_messages;
    } // End migrate_settings()


    /**
     * Normalize a Role Visibility 1.x rule string to the access the editor actually chose.
     *
     * Version 1.x stored the dropdown value plus any role boxes that were checked, even when the boxes
     * were hidden, so roles only count alongside "logged-in".
     *
     * @param string $value
     * @return string New rule string, or "default" to remove it.
     */
    public static function normalize_legacy_rule( string $value ) : string {
        $tokens = array_values( array_filter( array_map( 'trim', explode( ',', html_entity_decode( $value, ENT_QUOTES ) ) ) ) );

        if ( in_array( 'logged-in', $tokens, true ) ) {
            $roles = array_diff( $tokens, [ 'logged-in', 'logged-out', 'everyone', 'default' ] );

            return Access::build( 'logged-in', array_values( array_map( 'sanitize_key', $roles ) ) );
        }

        foreach ( [ 'logged-out', 'everyone' ] as $access ) {
            if ( in_array( $access, $tokens, true ) ) {
                return $access;
            }
        }

        return 'default';
    } // End normalize_legacy_rule()


    /**
     * Rename and normalize the Role Visibility 1.x access meta with a few grouped queries.
     *
     * @return int Number of posts with a rule kept.
     */
    private static function migrate_access_meta() : int {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( self::LEGACY_META_KEYS ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) ORDER BY meta_key ASC, meta_id ASC", self::LEGACY_META_KEYS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one-time migration of Role Visibility 1.x post meta; the interpolated list only contains placeholders.

        $groups = [];
        $seen = [];

        foreach ( $rows as $row ) {
            $post_id = (int) $row->post_id;
            $value = isset( $seen[ $post_id ] ) ? 'default' : self::normalize_legacy_rule( (string) $row->meta_value );

            $seen[ $post_id ] = true;
            $groups[ $value ][] = (int) $row->meta_id;
        }

        $kept = 0;

        foreach ( $groups as $value => $meta_ids ) {
            foreach ( array_chunk( $meta_ids, self::CHUNK ) as $chunk ) {
                $id_list = implode( ',', array_map( 'absint', $chunk ) );

                if ( 'default' === $value ) {
                    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ( {$id_list} )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time migration; the ID list is built from absint() values.
                } else {
                    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s, meta_value = %s WHERE meta_id IN ( {$id_list} )", Access::META_KEY, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time migration; the ID list is built from absint() values.
                    $kept += count( $chunk );
                }
            }
        }

        foreach ( $rows as $row ) {
            wp_cache_delete( (int) $row->post_id, 'post_meta' );
        }

        return $kept;
    } // End migrate_access_meta()


    /**
     * Get the Role Visibility 1.x template for each condition, with the message placeholder left in place.
     *
     * @param array $legacy
     * @return array
     */
    private static function legacy_bases( array $legacy ) : array {
        $keys = [
            'login_required'  => 'logged_in_template',
            'missing_access'  => 'missing_role_template',
            'logout_required' => 'logged_out_template',
        ];

        $bases = [];

        foreach ( $keys as $condition => $key ) {
            $template = is_string( $legacy[ $key ] ?? null ) ? trim( $legacy[ $key ] ) : '';
            $bases[ $condition ] = '' !== $template ? $template : Templates::default_content( $condition, self::LEGACY_PLACEHOLDER );
        }

        return $bases;
    } // End legacy_bases()


    /**
     * Get the Role Visibility 1.x global message for a condition.
     *
     * @param array  $legacy
     * @param string $condition
     * @return string
     */
    private static function legacy_message( array $legacy, string $condition ) : string {
        $keys = [
            'login_required'  => 'logged_in_msg',
            'missing_access'  => 'missing_role_msg',
            'logout_required' => 'logged_out_msg',
        ];

        $message = is_string( $legacy[ $keys[ $condition ] ] ?? null ) ? trim( $legacy[ $keys[ $condition ] ] ) : '';

        return '' !== $message ? $message : Templates::default_message( $condition );
    } // End legacy_message()


    /**
     * Turn per post type and per post messages into templates, grouping identical content.
     *
     * @param array $bases         Version 1.x templates per condition.
     * @param array $type_messages Per post type messages.
     * @return int Number of templates created.
     */
    private static function migrate_messages( array $bases, array $type_messages ) : int {
        global $wpdb;

        $groups = [];
        $add = function( string $scope, $target, array $conditions, string $message ) use ( &$groups, $bases ) : void {
            foreach ( $conditions as $condition ) {
                $content = str_replace( self::LEGACY_PLACEHOLDER, wp_kses_post( $message ), $bases[ $condition ] );
                $key = md5( $scope . '|' . $content );

                $groups[ $key ] ??= [ 'scope' => $scope, 'content' => $content, 'message' => $message, 'conditions' => [], 'targets' => [] ];
                $groups[ $key ][ 'conditions' ][ $condition ] = $condition;
                $groups[ $key ][ 'targets' ][ $target ] = $target;
            }
        };

        foreach ( $type_messages as $post_type => $messages ) {
            if ( '' !== $messages[ 'logged_in_msg' ] ) {
                $add( 'post_type', $post_type, [ 'login_required', 'missing_access' ], $messages[ 'logged_in_msg' ] );
            }

            if ( '' !== $messages[ 'logged_out_msg' ] ) {
                $add( 'post_type', $post_type, [ 'logout_required' ], $messages[ 'logged_out_msg' ] );
            }
        }

        $placeholders = implode( ',', array_fill( 0, count( self::LEGACY_MSG_META_KEYS ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} ) AND meta_value <> '' ORDER BY meta_key ASC", self::LEGACY_MSG_META_KEYS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one-time migration of Role Visibility 1.x post meta; the interpolated list only contains placeholders.

        foreach ( $rows as $row ) {
            $post_id = (int) $row->post_id;
            $conditions = Templates::rule_conditions( Access::get_rule( $post_id ) );

            if ( ! empty( $conditions ) ) {
                $add( 'posts', $post_id, $conditions, trim( (string) $row->meta_value ) );
            }
        }

        $created = 0;
        $labels = Templates::conditions();

        foreach ( $groups as $group ) {
            if ( 'post_type' === $group[ 'scope' ] ) {
                $name = implode( ', ', array_map( fn( $slug ) => get_post_type_object( $slug )?->labels->name ?? $slug, array_values( $group[ 'targets' ] ) ) );
            } else {
                $name = wp_trim_words( wp_strip_all_tags( $group[ 'message' ] ), 6 );
            }

            /* translators: 1: Post type names or the first words of the custom message, 2: When the template applies. */
            $title = sprintf( __( 'Custom Message: %1$s (%2$s)', 'restricted-page-templates' ), $name, implode( ', ', array_intersect_key( $labels, $group[ 'conditions' ] ) ) );

            if ( Templates::create( $title, $group[ 'content' ], $group[ 'scope' ], array_values( $group[ 'conditions' ] ), array_values( $group[ 'targets' ] ) ) ) {
                $created++;
            }
        }

        foreach ( self::LEGACY_MSG_META_KEYS as $meta_key ) {
            delete_post_meta_by_key( $meta_key );
        }

        return $created;
    } // End migrate_messages()


    /**
     * Check whether the current screen shows the import notices: the Plugins screen and the plugin's own screens.
     *
     * @return bool
     */
    private static function is_notice_screen() : bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return Helpers::is_plugin_screen() || ( $screen && 'plugins' === $screen->id );
    } // End is_notice_screen()


    /**
     * Show a one-time summary after importing from Role Visibility.
     *
     * @return void
     */
    public function render_notice() : void {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_notice_screen() ) {
            return;
        }

        $error = (string) get_option( self::ERROR_OPTION, '' );

        if ( '' !== $error && Helpers::is_plugin_screen() ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php
                    /* translators: %s: Error message. */
                    echo esc_html( sprintf( __( 'Restricted Page Templates could not finish importing your Role Visibility data: %s', 'restricted-page-templates' ), $error ) );
                    ?>
                    <a href="<?php echo esc_url( Settings::url() ); ?>"><?php esc_html_e( 'Try again under Settings > Advanced', 'restricted-page-templates' ); ?></a>
                </p>
            </div>
            <?php
        }

        $summary = get_transient( self::NOTICE_TRANSIENT );
        $replaced = (bool) get_option( Bootstrap::REPLACED_OPTION );

        if ( ! is_array( $summary ) && ! $replaced ) {
            return;
        }

        delete_transient( self::NOTICE_TRANSIENT );
        delete_option( Bootstrap::REPLACED_OPTION );

        $messages = [];

        if ( ! empty( $summary[ 'renamed' ] ) ) {
            $messages[] = sprintf(
                /* translators: 1: Number of posts with access rules, 2: Number of templates, 3: Number of menu items with visibility rules. */
                __( 'Role Visibility is now Restricted Page Templates. Your settings, the access rules on %1$d posts, %2$d templates and %3$d menu item rules were carried over.', 'restricted-page-templates' ),
                (int) ( $summary[ 'rules' ] ?? 0 ),
                (int) ( $summary[ 'moved' ] ?? 0 ),
                (int) ( $summary[ 'menus' ] ?? 0 )
            );
        }

        if ( ! empty( $summary[ 'legacy' ] ) ) {
            $messages[] = sprintf(
                /* translators: 1: Number of posts with access rules, 2: Number of templates created from custom messages. */
                __( 'Your Role Visibility 1.x settings were imported, the access rules on %1$d posts were kept, and %2$d templates were created from your custom messages. Messages are now edited as templates.', 'restricted-page-templates' ),
                (int) ( $summary[ 'posts' ] ?? 0 ),
                (int) ( $summary[ 'templates' ] ?? 0 )
            );
        }

        if ( $replaced ) {
            $messages[] = __( 'Role Visibility has been deactivated and can be deleted.', 'restricted-page-templates' );
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( implode( ' ', $messages ) ); ?> <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Templates::POST_TYPE ) ); ?>"><?php esc_html_e( 'Review your templates', 'restricted-page-templates' ); ?></a></p>
        </div>
        <?php
    } // End render_notice()

} // End class Migrate

Migrate::instance();
