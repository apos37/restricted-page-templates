<?php
/**
 * SITEMAPS
 *
 * Removes content that logged-out visitors cannot view from XML sitemaps: WordPress core,
 * Yoast SEO, Rank Math, All in One SEO and SEOPress. Each filter only runs when its plugin calls it.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Sitemaps {

    /**
     * @var array|null Post IDs hidden from guests, for the current request
     */
    private static ?array $denied_ids = null;


    /**
     * @var Sitemaps|null Singleton instance
     */
    private static ?Sitemaps $instance = null;


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
        add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'core_query_args' ] );
        add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ $this, 'merge_denied_ids' ] );
        add_filter( 'rank_math/sitemap/entry', [ $this, 'rank_math_entry' ], 10, 3 );
        add_filter( 'aioseo_sitemap_exclude_posts', [ $this, 'merge_denied_ids' ] );
        add_filter( 'seopress_sitemaps_single_query', [ $this, 'seopress_query_args' ] );
    } // End __construct()


    /**
     * Check whether sitemap hiding is on.
     *
     * @return bool
     */
    private static function enabled() : bool {
        return Hide::is_enabled( 'sitemaps' );
    } // End enabled()


    /**
     * Get every post ID that logged-out visitors cannot view, in enabled post types.
     *
     * @return array
     */
    public static function denied_ids() : array {
        if ( null !== self::$denied_ids ) {
            return self::$denied_ids;
        }

        $restrictions = Hide::restrictions( 0, 'sitemaps' );
        $ids = $restrictions[ 'denied_ids' ];

        if ( ! empty( $restrictions[ 'denied_types' ] ) ) {
            $ids = array_merge( $ids, get_posts( [
                'post_type'              => $restrictions[ 'denied_types' ],
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'post__not_in'           => $restrictions[ 'exceptions' ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- runs only while a sitemap is generated.
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ] ) );
        }

        self::$denied_ids = (array) apply_filters( 'rptemplates_sitemap_excluded_ids', array_values( array_unique( array_map( 'absint', $ids ) ) ) );

        return self::$denied_ids;
    } // End denied_ids()


    /**
     * WordPress core sitemaps: mark the query so Hide applies the logged-out restrictions.
     *
     * @param array $args
     * @return array
     */
    public function core_query_args( $args ) : array {
        if ( self::enabled() ) {
            $args[ Hide::QUERY_VAR ] = 'sitemaps';
        }

        return (array) $args;
    } // End core_query_args()


    /**
     * Yoast SEO and All in One SEO: add restricted IDs to the excluded list.
     *
     * @param array $ids
     * @return array
     */
    public function merge_denied_ids( $ids ) : array {
        $ids = (array) $ids;

        return self::enabled() ? array_values( array_unique( array_merge( $ids, self::denied_ids() ) ) ) : $ids;
    } // End merge_denied_ids()


    /**
     * Rank Math: drop the entry for restricted posts.
     *
     * @param array|false $url
     * @param string      $type
     * @param object      $object
     * @return array|false
     */
    public function rank_math_entry( $url, $type, $object ) {
        static $denied = null;

        if ( ! self::enabled() || 'post' !== $type || ! isset( $object->ID ) ) {
            return $url;
        }

        $denied ??= array_flip( self::denied_ids() );

        return isset( $denied[ (int) $object->ID ] ) ? false : $url;
    } // End rank_math_entry()


    /**
     * SEOPress: exclude restricted IDs from each post type sitemap query.
     *
     * @param array $args
     * @return array
     */
    public function seopress_query_args( $args ) : array {
        $args = (array) $args;

        if ( self::enabled() ) {
            $args[ 'post__not_in' ] = array_values( array_unique( array_merge( (array) ( $args[ 'post__not_in' ] ?? [] ), self::denied_ids() ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- runs only while a sitemap is generated.
        }

        return $args;
    } // End seopress_query_args()

} // End class Sitemaps

Sitemaps::instance();
