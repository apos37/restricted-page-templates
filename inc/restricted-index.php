<?php
/**
 * RESTRICTED INDEX
 *
 * Keeps a single option listing every post with its own access rule, so hiding restricted content
 * never needs a post meta join. Changes are collected during a request and written once at shutdown.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class RestrictedIndex {

    /**
     * Option holding the index: [ post_id => [ 't' => post type, 'r' => raw rule ] ].
     */
    public const OPTION = 'rptemplates_restricted_index';


    /**
     * @var array|null Index for the current request
     */
    private static ?array $index = null;


    /**
     * @var array Pending changes: post_id => raw rule, or null to remove
     */
    private static array $pending = [];


    /**
     * @var bool Whether a full rebuild is pending
     */
    private static bool $rebuild = false;


    /**
     * @var RestrictedIndex|null Singleton instance
     */
    private static ?RestrictedIndex $instance = null;


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
        add_action( 'added_post_meta', [ $this, 'meta_changed' ], 10, 4 );
        add_action( 'updated_post_meta', [ $this, 'meta_changed' ], 10, 4 );
        add_action( 'deleted_post_meta', [ $this, 'meta_changed' ], 10, 4 );
        add_action( 'deleted_post', [ $this, 'post_deleted' ] );
    } // End __construct()


    /**
     * Get the index, building it on first use.
     *
     * @return array
     */
    public static function get() : array {
        if ( null !== self::$index ) {
            return self::$index;
        }

        $stored = get_option( self::OPTION, null );
        self::$index = is_array( $stored ) ? $stored : self::rebuild();

        return self::$index;
    } // End get()


    /**
     * Rebuild the index from post meta with a single query.
     *
     * @return array
     */
    public static function rebuild() : array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s", Access::META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time build of a cached index; the result is stored in an option.
        $index = [];

        foreach ( $rows as $row ) {
            $index[ (int) $row->post_id ] = [ 't' => $row->post_type, 'r' => (string) $row->meta_value ];
        }

        update_option( self::OPTION, $index, false );

        self::$index = $index;
        self::$pending = [];
        self::$rebuild = false;

        return $index;
    } // End rebuild()


    /**
     * Queue an index change when a post's rule meta changes.
     *
     * @param int|array $meta_ids
     * @param int       $object_id
     * @param string    $meta_key
     * @param mixed     $meta_value
     * @return void
     */
    public function meta_changed( $meta_ids, $object_id, $meta_key, $meta_value ) : void {
        if ( Access::META_KEY !== $meta_key ) {
            return;
        }

        if ( ! $object_id ) {
            self::$rebuild = true;
        } else {
            self::$pending[ (int) $object_id ] = 'deleted_post_meta' === current_action() ? null : (string) $meta_value;
        }

        self::schedule_write();
    } // End meta_changed()


    /**
     * Queue removal of a deleted post.
     *
     * @param int $post_id
     * @return void
     */
    public function post_deleted( $post_id ) : void {
        $index = get_option( self::OPTION, null );

        if ( is_array( $index ) && isset( $index[ (int) $post_id ] ) ) {
            self::$pending[ (int) $post_id ] = null;
            self::schedule_write();
        }
    } // End post_deleted()


    /**
     * Write queued changes once, at the end of the request.
     *
     * @return void
     */
    private static function schedule_write() : void {
        if ( ! has_action( 'shutdown', [ __CLASS__, 'write' ] ) ) {
            add_action( 'shutdown', [ __CLASS__, 'write' ] );
        }
    } // End schedule_write()


    /**
     * Apply queued changes to the stored index.
     *
     * @return void
     */
    public static function write() : void {
        $stored = get_option( self::OPTION, null );

        if ( self::$rebuild || ! is_array( $stored ) ) {
            self::rebuild();
            return;
        }

        foreach ( self::$pending as $post_id => $raw ) {
            if ( null === $raw || '' === $raw ) {
                unset( $stored[ $post_id ] );
            } else {
                $stored[ $post_id ] = [ 't' => (string) get_post_type( $post_id ), 'r' => $raw ];
            }
        }

        update_option( self::OPTION, $stored, false );

        self::$index = $stored;
        self::$pending = [];
    } // End write()

} // End class RestrictedIndex

RestrictedIndex::instance();
