<?php
/**
 * HIDE
 *
 * Removes restricted content from archives, search, feeds, the REST API and previous/next links
 * for anyone who cannot view it. Restrictions are applied as one SQL clause built from the
 * restricted index, without any post meta joins.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Hide {

    /**
     * Query var that marks a query with an explicit context.
     */
    public const QUERY_VAR = 'rptemplates_context';


    /**
     * @var array Restrictions for the current request, keyed by "user_id|context"
     */
    private static array $cache = [];


    /**
     * @var Hide|null Singleton instance
     */
    private static ?Hide $instance = null;


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
        add_filter( 'posts_where', [ $this, 'posts_where' ], 10, 2 );
        add_action( 'rest_api_init', [ $this, 'register_rest_filters' ] );
        add_filter( 'get_previous_post_where', [ $this, 'adjacent_where' ], 10, 5 );
        add_filter( 'get_next_post_where', [ $this, 'adjacent_where' ], 10, 5 );
    } // End __construct()


    /**
     * Hiding contexts and their labels.
     *
     * @return array
     */
    public static function contexts() : array {
        return [
            'archives'  => [
                'label' => __( 'Archives, Blog and Post Lists', 'restricted-page-templates' ),
                'desc'  => __( 'Category, tag, date and author archives, the blog page, and post lists on the front end, including Query Loop blocks and most page builder post widgets.', 'restricted-page-templates' ),
            ],
            'search'    => [
                'label' => __( 'Search Results', 'restricted-page-templates' ),
                'desc'  => __( 'Front-end search results, including their excerpts.', 'restricted-page-templates' ),
            ],
            'feeds'     => [
                'label' => __( 'RSS Feeds', 'restricted-page-templates' ),
                'desc'  => __( 'Site, category and other feeds. Feed readers are not logged in, so they only receive content that logged-out visitors can view.', 'restricted-page-templates' ),
            ],
            'rest'      => [
                'label' => __( 'REST API', 'restricted-page-templates' ),
                'desc'  => __( 'Lists and single items from the REST API. Requests made by the block editor while editing are not affected.', 'restricted-page-templates' ),
            ],
            'nav_menus' => [
                'label' => __( 'Navigation Menus', 'restricted-page-templates' ),
                'desc'  => __( 'Adds visibility controls to menu items in Appearance > Menus and to the Navigation block. Items that link to content default to matching that content\'s access; custom links can be set manually.', 'restricted-page-templates' ),
            ],
            'adjacent'  => [
                'label' => __( 'Previous/Next Post Links', 'restricted-page-templates' ),
                'desc'  => __( 'Skips restricted posts in previous and next post links created by WordPress functions and blocks. Themes that build these links with their own queries may not be affected.', 'restricted-page-templates' ),
            ],
            'sitemaps'  => [
                'label' => __( 'XML Sitemaps', 'restricted-page-templates' ),
                'desc'  => __( 'Removes content that logged-out visitors cannot view from the WordPress core sitemap and from Yoast SEO, Rank Math, All in One SEO and SEOPress sitemaps. Clear your SEO plugin\'s sitemap cache after changing access rules.', 'restricted-page-templates' ),
            ],
        ];
    } // End contexts()


    /**
     * Check whether a context is enabled, optionally for a single post type.
     *
     * @param string $context
     * @param string $post_type
     * @return bool
     */
    public static function is_enabled( string $context, string $post_type = '' ) : bool {
        $enabled = in_array( $context, (array) Settings::get( 'hide' ), true );

        return (bool) apply_filters( 'rptemplates_hide_context', $enabled, $context, $post_type );
    } // End is_enabled()


    /**
     * Work out what a user is denied, for a context.
     *
     * @param int    $user_id
     * @param string $context
     * @return array [ denied_types, exceptions, denied_ids ]
     */
    public static function restrictions( int $user_id, string $context ) : array {
        $key = $user_id . '|' . $context;

        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        $restrictions = [ 'denied_types' => [], 'exceptions' => [], 'denied_ids' => [] ];

        if ( ! Access::user_bypasses( $user_id ) ) {
            $active_types = [];

            foreach ( array_keys( Settings::get( 'post_types' ) ) as $post_type ) {
                if ( ! self::is_enabled( $context, $post_type ) ) {
                    continue;
                }

                $active_types[ $post_type ] = true;

                if ( ! Access::evaluate( Access::post_type_rule( $post_type ), $user_id ) ) {
                    $restrictions[ 'denied_types' ][] = $post_type;
                }
            }

            foreach ( RestrictedIndex::get() as $post_id => $entry ) {
                if ( ! isset( $active_types[ $entry[ 't' ] ] ) ) {
                    continue;
                }

                $rule = Access::parse( $entry[ 'r' ] );

                if ( 'default' === $rule[ 'access' ] ) {
                    continue;
                }

                $allowed = Access::evaluate( $rule, $user_id );
                $type_denied = in_array( $entry[ 't' ], $restrictions[ 'denied_types' ], true );

                if ( $type_denied && $allowed ) {
                    $restrictions[ 'exceptions' ][] = $post_id;
                } elseif ( ! $type_denied && ! $allowed ) {
                    $restrictions[ 'denied_ids' ][] = $post_id;
                }
            }

            $page_id = (int) Settings::get( 'no_access_page' );

            if ( $page_id ) {
                $restrictions[ 'exceptions' ][] = $page_id;
                $restrictions[ 'denied_ids' ] = array_values( array_diff( $restrictions[ 'denied_ids' ], [ $page_id ] ) );
            }
        }

        self::$cache[ $key ] = (array) apply_filters( 'rptemplates_query_restrictions', $restrictions, $user_id, $context );

        return self::$cache[ $key ];
    } // End restrictions()


    /**
     * Build the SQL clause for a set of restrictions.
     *
     * @param array  $restrictions
     * @param string $alias Table name or alias of the posts table.
     * @return string Empty, or a clause starting with " AND".
     */
    public static function sql_clause( array $restrictions, string $alias ) : string {
        $clause = '';
        $types = array_map( 'sanitize_key', $restrictions[ 'denied_types' ] ?? [] );
        $exceptions = array_filter( array_map( 'absint', $restrictions[ 'exceptions' ] ?? [] ) );
        $denied = array_filter( array_map( 'absint', $restrictions[ 'denied_ids' ] ?? [] ) );

        if ( ! empty( $types ) ) {
            $type_list = "'" . implode( "','", esc_sql( $types ) ) . "'";
            $clause .= " AND ( {$alias}.post_type NOT IN ( {$type_list} )";
            $clause .= empty( $exceptions ) ? ' )' : " OR {$alias}.ID IN ( " . implode( ',', $exceptions ) . ' ) )';
        }

        if ( ! empty( $denied ) ) {
            $clause .= " AND {$alias}.ID NOT IN ( " . implode( ',', $denied ) . ' )';
        }

        return $clause;
    } // End sql_clause()


    /**
     * Get the context of a query, or an empty string when it should not be filtered.
     *
     * @param \WP_Query $query
     * @return string
     */
    private static function query_context( \WP_Query $query ) : string {
        $context = (string) $query->get( self::QUERY_VAR );

        if ( '' !== $context ) {
            return $context;
        }

        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || $query->is_singular() ) {
            return '';
        }

        if ( $query->is_feed() ) {
            return 'feeds';
        }

        if ( $query->is_search() ) {
            return 'search';
        }

        return 'archives';
    } // End query_context()


    /**
     * Check whether a query only targets post types without access controls.
     *
     * @param \WP_Query $query
     * @return bool
     */
    private static function targets_other_types( \WP_Query $query ) : bool {
        $post_type = $query->get( 'post_type' );

        if ( empty( $post_type ) || 'any' === $post_type ) {
            return false;
        }

        return empty( array_intersect( (array) $post_type, array_keys( Settings::get( 'post_types' ) ) ) );
    } // End targets_other_types()


    /**
     * Add the restriction clause to front-end queries.
     *
     * @param string    $where
     * @param \WP_Query $query
     * @return string
     */
    public function posts_where( $where, $query ) : string {
        $where = (string) $where;

        if ( ! $query instanceof \WP_Query ) {
            return $where;
        }

        $context = self::query_context( $query );

        if ( '' === $context || ! self::is_enabled( $context ) || self::targets_other_types( $query ) ) {
            return $where;
        }

        global $wpdb;

        $user_id = in_array( $context, [ 'feeds', 'sitemaps' ], true ) ? 0 : get_current_user_id();

        return $where . self::sql_clause( self::restrictions( $user_id, $context ), $wpdb->posts );
    } // End posts_where()


    /**
     * Add REST filters for each enabled post type.
     *
     * @return void
     */
    public function register_rest_filters() : void {
        if ( ! self::is_enabled( 'rest' ) ) {
            return;
        }

        foreach ( array_keys( Settings::get( 'post_types' ) ) as $post_type ) {
            add_filter( 'rest_' . $post_type . '_query', [ $this, 'rest_query' ], 10, 2 );
        }

        add_filter( 'rest_post_search_query', [ $this, 'rest_query' ], 10, 2 );
        add_filter( 'rest_request_before_callbacks', [ $this, 'rest_single_item' ], 10, 3 );
    } // End register_rest_filters()


    /**
     * Mark REST collection queries, unless they come from an editing context.
     *
     * @param array            $args
     * @param \WP_REST_Request $request
     * @return array
     */
    public function rest_query( $args, $request ) : array {
        if ( 'edit' !== $request->get_param( 'context' ) ) {
            $args[ self::QUERY_VAR ] = 'rest';
        }

        return (array) $args;
    } // End rest_query()


    /**
     * Block single REST items the user cannot view, before the posts controller runs.
     *
     * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
     * @param array                                                $handler
     * @param \WP_REST_Request                                     $request
     * @return mixed
     */
    public function rest_single_item( $response, $handler, $request ) {
        $callback = $handler[ 'callback' ] ?? null;

        if ( null !== $response || ! is_array( $callback ) || 'get_item' !== ( $callback[ 1 ] ?? '' ) || ! $callback[ 0 ] instanceof \WP_REST_Posts_Controller ) {
            return $response;
        }

        $post = get_post( absint( $request->get_param( 'id' ) ) );

        if ( ! $post || 'edit' === $request->get_param( 'context' ) || ! Access::is_enabled( $post->post_type ) || ! self::is_enabled( 'rest', $post->post_type ) ) {
            return $response;
        }

        if ( Access::user_has_access( $post->ID ) || current_user_can( 'edit_post', $post->ID ) ) {
            return $response;
        }

        return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view this item.', 'restricted-page-templates' ), [ 'status' => rest_authorization_required_code() ] );
    } // End rest_single_item()


    /**
     * Skip restricted posts in previous/next post links.
     *
     * @param string        $where
     * @param bool          $in_same_term
     * @param int[]|string  $excluded_terms
     * @param string        $taxonomy
     * @param \WP_Post|null $post
     * @return string
     */
    public function adjacent_where( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ) : string {
        if ( is_admin() || ! self::is_enabled( 'adjacent' ) ) {
            return (string) $where;
        }

        return (string) $where . self::sql_clause( self::restrictions( get_current_user_id(), 'adjacent' ), 'p' );
    } // End adjacent_where()

} // End class Hide

Hide::instance();
