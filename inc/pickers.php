<?php
/**
 * PICKERS
 *
 * AJAX search for the user and post pickers, plus the shared editor assets used by every access control.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Pickers {

    /**
     * Nonce action for picker searches.
     */
    public const NONCE = 'rptemplates_picker';


    /**
     * Maximum results per search.
     */
    public const LIMIT = 20;


    /**
     * @var Pickers|null Singleton instance
     */
    private static ?Pickers $instance = null;


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
        add_action( 'wp_ajax_rptemplates_search_users', [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_rptemplates_search_posts', [ $this, 'ajax_search_posts' ] );
    } // End __construct()


    /**
     * Capability required to search users in the access controls.
     *
     * @return string
     */
    public static function user_search_capability() : string {
        return (string) apply_filters( 'rptemplates_user_search_capability', 'edit_others_posts' );
    } // End user_search_capability()


    /**
     * Check whether the current user can search users.
     *
     * @return bool
     */
    public static function can_search_users() : bool {
        return current_user_can( self::user_search_capability() );
    } // End can_search_users()


    /**
     * Enqueue the shared field styles and script.
     *
     * @return void
     */
    public static function enqueue_fields_assets() : void {
        wp_enqueue_style( 'rptemplates-fields', Bootstrap::url() . 'inc/css/fields.css', [], Bootstrap::script_version() );
        wp_enqueue_script( 'rptemplates-fields', Bootstrap::url() . 'inc/js/fields.js', [ 'jquery' ], Bootstrap::script_version(), true );

        wp_localize_script( 'rptemplates-fields', 'rpTemplatesFields', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::NONCE ),
            'noResults' => __( 'No matches found.', 'restricted-page-templates' ),
            'searching' => __( 'Searching…', 'restricted-page-templates' ),
            'remove'    => __( 'Remove', 'restricted-page-templates' ),
        ] );
    } // End enqueue_fields_assets()


    /**
     * Get the sanitized search term from the request.
     *
     * @return string
     */
    private static function term() : string {
        return isset( $_GET[ 'term' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'term' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified by Helpers::verify_ajax() before this runs.
    } // End term()


    /**
     * AJAX: search users by name, login or email.
     *
     * @return void
     */
    public function ajax_search_users() : void {
        Helpers::verify_ajax( self::NONCE, self::user_search_capability() );

        $term = self::term();

        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [] );
        }

        $users = get_users( [
            'search'         => '*' . $term . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
            'number'         => self::LIMIT,
            'orderby'        => 'display_name',
            'fields'         => [ 'ID', 'display_name', 'user_login' ],
            'count_total'    => false,
        ] );

        $results = array_map( fn( $user ) => [ 'id' => (int) $user->ID, 'text' => $user->display_name . ' (' . $user->user_login . ')' ], $users );

        wp_send_json_success( $results );
    } // End ajax_search_users()


    /**
     * AJAX: search posts in the enabled post types, by title or ID.
     *
     * @return void
     */
    public function ajax_search_posts() : void {
        Helpers::verify_ajax( self::NONCE );

        $term = self::term();
        $post_types = array_keys( Settings::get( 'post_types' ) );

        if ( '' === $term || empty( $post_types ) ) {
            wp_send_json_success( [] );
        }

        $args = [
            'post_type'              => $post_types,
            'post_status'            => [ 'publish', 'private', 'draft', 'pending', 'future' ],
            'posts_per_page'         => self::LIMIT,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ( ctype_digit( $term ) ) {
            $args[ 'post__in' ] = [ absint( $term ) ];
        } else {
            $args[ 's' ] = $term;
        }

        $results = array_map( fn( $post_id ) => [ 'id' => $post_id, 'text' => Templates::post_label( $post_id ) ], get_posts( $args ) );

        wp_send_json_success( $results );
    } // End ajax_search_posts()

} // End class Pickers

Pickers::instance();
