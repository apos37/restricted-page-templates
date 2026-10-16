<?php
/**
 * PAGES
 *
 * The Pages tab: how much of each enabled post type is restricted, with expandable lists of the
 * restricted posts and the templates they use. Lists load over AJAX, a page at a time.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Pages {

    /**
     * Nonce action for the list requests.
     */
    public const NONCE = 'rptemplates_pages';


    /**
     * Posts per list request.
     */
    public const PER_PAGE = 50;


    /**
     * Post statuses included in the counts and lists.
     */
    public const STATUSES = [ 'publish', 'future', 'private', 'pending', 'draft' ];


    /**
     * Access values counted on the Pages tab, in column order. Everything but Everyone is restricted.
     */
    public const COUNTED = [ 'logged-in', 'logged-out', 'url-param', 'everyone' ];


    /**
     * @var Pages|null Singleton instance
     */
    private static ?Pages $instance = null;


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
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_rptemplates_pages_list', [ $this, 'ajax_list' ] );
    } // End __construct()


    /**
     * Get the URL of the Pages tab.
     *
     * @return string
     */
    public static function url() : string {
        return admin_url( 'admin.php?page=' . Menu::SLUG );
    } // End url()


    /**
     * Enqueue assets on the Pages tab.
     *
     * @return void
     */
    public function enqueue() : void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen || Menu::pages_screen_id() !== $screen->id ) {
            return;
        }

        wp_enqueue_style( 'rptemplates-fields', Bootstrap::url() . 'inc/css/fields.css', [], Bootstrap::script_version() );
        wp_enqueue_style( 'rptemplates-pages', Bootstrap::url() . 'inc/css/pages.css', [ 'rptemplates-theme' ], Bootstrap::script_version() );
        wp_enqueue_script( 'rptemplates-pages', Bootstrap::url() . 'inc/js/pages.js', [ 'jquery' ], Bootstrap::script_version(), true );

        wp_localize_script( 'rptemplates-pages', 'rpTemplatesPages', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE ),
            'loading'  => __( 'Loading…', 'restricted-page-templates' ),
            'loadMore' => __( 'Load More', 'restricted-page-templates' ),
            'error'    => __( 'The list could not be loaded. Please try again.', 'restricted-page-templates' ),
        ] );
    } // End enqueue()


    /**
     * Count the posts of each enabled post type by the access they end up with, in one grouped query.
     *
     * @return array post_type => [ logged-in, logged-out, url-param, everyone ]
     */
    public static function counts() : array {
        global $wpdb;

        $post_types = array_keys( (array) Settings::get( 'post_types' ) );
        $counts = array_fill_keys( $post_types, array_fill_keys( self::COUNTED, 0 ) );

        if ( empty( $post_types ) ) {
            return $counts;
        }

        $type_list = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $status_list = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_type, pm.meta_value, COUNT(*) AS total FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type IN ( {$type_list} ) AND p.post_status IN ( {$status_list} ) GROUP BY p.post_type, pm.meta_value", array_merge( [ Access::META_KEY ], $post_types, self::STATUSES ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- admin overview counts in one grouped query; the interpolated lists only contain placeholders, filled from the array of values.

        foreach ( $rows as $row ) {
            $access = self::effective_access( (string) $row->post_type, (string) $row->meta_value );
            $counts[ $row->post_type ][ $access ] += (int) $row->total;
        }

        $page_id = (int) Settings::get( 'no_access_page' );
        $page_type = $page_id ? (string) get_post_type( $page_id ) : '';

        if ( isset( $counts[ $page_type ] ) && in_array( get_post_status( $page_id ), self::STATUSES, true ) ) {
            $access = self::effective_access( $page_type, (string) get_post_meta( $page_id, Access::META_KEY, true ) );
            $counts[ $page_type ][ $access ]--;
            $counts[ $page_type ][ 'everyone' ]++;
        }

        return $counts;
    } // End counts()


    /**
     * Get the access a stored rule ends up with, after the post type default.
     *
     * @param string $post_type
     * @param string $raw
     * @return string One of self::COUNTED.
     */
    private static function effective_access( string $post_type, string $raw ) : string {
        $access = Access::parse( $raw )[ 'access' ];
        $access = 'default' === $access ? Access::post_type_default( $post_type ) : $access;

        return in_array( $access, self::COUNTED, true ) ? $access : 'everyone';
    } // End effective_access()


    /**
     * Render the Pages tab.
     *
     * @return void
     */
    public static function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'restricted-page-templates' ) );
        }

        $counts = self::counts();
        $options = Access::options();
        ?>
        <div class="wrap rptemplates-wrap rptemplates-content-wrap">
            <div class="rptemplates-box">
                <div class="rptemplates-box-header"><h2><?php esc_html_e( 'Restricted Content', 'restricted-page-templates' ); ?></h2></div>
                <div class="rptemplates-box-body">
                    <?php if ( empty( $counts ) ) : ?>
                        <p class="rptemplates-section-desc">
                            <?php esc_html_e( 'No post types have access controls yet.', 'restricted-page-templates' ); ?>
                            <a href="<?php echo esc_url( Settings::url() ); ?>"><?php esc_html_e( 'Enable them under Settings', 'restricted-page-templates' ); ?></a>
                        </p>
                    <?php else : ?>
                        <p class="rptemplates-section-desc"><?php esc_html_e( 'Published, scheduled, private, pending and draft content in each post type with access controls. Expand a post type to see what is restricted and which templates blocked visitors will see.', 'restricted-page-templates' ); ?></p>
                        <table class="rptemplates-pages-table">
                            <colgroup>
                                <col class="rptemplates-pages-col-name">
                                <col class="rptemplates-pages-col-access">
                                <?php foreach ( array_merge( [ 'restricted' ], self::COUNTED ) as $key ) : ?>
                                    <col class="rptemplates-pages-col-count">
                                <?php endforeach; ?>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Post Type', 'restricted-page-templates' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Default Access', 'restricted-page-templates' ); ?></th>
                                    <th scope="col" class="rptemplates-count"><?php esc_html_e( 'Restricted', 'restricted-page-templates' ); ?></th>
                                    <?php foreach ( self::COUNTED as $access ) : ?>
                                        <th scope="col" class="rptemplates-count"><?php echo esc_html( $options[ $access ] ); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <?php foreach ( $counts as $post_type => $count ) : ?>
                                <?php
                                $object = get_post_type_object( $post_type );

                                if ( ! $object ) {
                                    continue;
                                }

                                $restricted = array_sum( $count ) - $count[ 'everyone' ];
                                ?>
                                <tbody class="rptemplates-pages-group" data-post-type="<?php echo esc_attr( $post_type ); ?>">
                                    <tr class="rptemplates-pages-summary">
                                        <td>
                                            <?php if ( $restricted ) : ?>
                                                <button type="button" class="rptemplates-pages-toggle" aria-expanded="false">
                                                    <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                                    <?php echo esc_html( $object->labels->name ); ?>
                                                </button>
                                            <?php else : ?>
                                                <span class="rptemplates-pages-name"><?php echo esc_html( $object->labels->name ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( Access::describe_rule( Access::post_type_rule( $post_type ) ) ); ?></td>
                                        <td class="rptemplates-count"><strong><?php echo esc_html( number_format_i18n( $restricted ) ); ?></strong></td>
                                        <?php foreach ( self::COUNTED as $access ) : ?>
                                            <td class="rptemplates-count"><?php echo esc_html( number_format_i18n( $count[ $access ] ) ); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                </tbody>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } // End render_page()


    /**
     * Get one page of restricted posts for a post type. When the default restricts the post type, every post is
     * listed except those opened to everyone. Otherwise, only posts with their own restricting rule are listed.
     *
     * @param string $post_type
     * @param int    $paged
     * @return \WP_Query
     */
    public static function query( string $post_type, int $paged ) : \WP_Query {
        $page_id = (int) Settings::get( 'no_access_page' );
        $everyone = [];
        $restricted = [];

        foreach ( RestrictedIndex::get() as $post_id => $entry ) {
            if ( $post_type !== $entry[ 't' ] ) {
                continue;
            }

            $access = self::effective_access( $post_type, (string) $entry[ 'r' ] );

            if ( 'everyone' === $access ) {
                $everyone[] = (int) $post_id;
            } else {
                $restricted[] = (int) $post_id;
            }
        }

        $args = [
            'post_type'              => $post_type,
            'post_status'            => self::STATUSES,
            'posts_per_page'         => self::PER_PAGE,
            'paged'                  => max( 1, $paged ),
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'update_post_term_cache' => false,
        ];

        if ( 'everyone' === Access::post_type_default( $post_type ) ) {
            $args[ 'post__in' ] = array_values( array_diff( $restricted, [ $page_id ] ) ) ?: [ 0 ];
        } else {
            $args[ 'post__not_in' ] = array_merge( $everyone, [ $page_id ] ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- admin overview; excludes the few posts opened to everyone.
        }

        return new \WP_Query( $args );
    } // End query()


    /**
     * AJAX: return one page of restricted posts for a post type as table rows.
     *
     * @return void
     */
    public function ajax_list() : void {
        Helpers::verify_ajax( self::NONCE );

        $post_type = isset( $_POST[ 'post_type' ] ) ? sanitize_key( wp_unslash( $_POST[ 'post_type' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by Helpers::verify_ajax() above.
        $paged = isset( $_POST[ 'paged' ] ) ? absint( $_POST[ 'paged' ] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified by Helpers::verify_ajax() above.

        if ( ! Access::is_enabled( $post_type ) ) {
            wp_send_json_error( __( 'Access controls are not enabled for this post type.', 'restricted-page-templates' ) );
        }

        $query = self::query( $post_type, $paged );
        $statuses = get_post_stati( [], 'objects' );
        $span = count( self::COUNTED ) + 1;
        $rows = '';

        foreach ( $query->posts as $post ) {
            $title = get_the_title( $post );
            $title = '' !== $title ? $title : __( '(no title)', 'restricted-page-templates' );
            $status = 'publish' !== $post->post_status && isset( $statuses[ $post->post_status ] ) ? ' <span class="rptemplates-pages-status">— ' . esc_html( $statuses[ $post->post_status ]->label ) . '</span>' : '';
            $edit = current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID ) : '';
            $name = '' !== $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
            $view = 'publish' === $post->post_status ? ' <a class="rptemplates-pages-view" href="' . esc_url( (string) get_permalink( $post ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'restricted-page-templates' ) . '</a>' : '';

            $rows .= '<tr class="rptemplates-pages-detail"><td class="rptemplates-pages-title">' . $name . $status . $view . '</td><td>' . esc_html( Access::describe( $post->ID ) ) . '</td><td colspan="' . esc_attr( (string) $span ) . '">' . Templates::in_use_html( $post->ID ) . '</td></tr>';
        }

        wp_send_json_success( [
            'rows'  => $rows,
            'more'  => $paged < (int) $query->max_num_pages,
            'paged' => $paged,
            'head'  => '<tr class="rptemplates-pages-detail rptemplates-pages-subhead"><th scope="col">' . esc_html__( 'Title', 'restricted-page-templates' ) . '</th><th scope="col">' . esc_html__( 'Access', 'restricted-page-templates' ) . '</th><th scope="col" colspan="' . esc_attr( (string) $span ) . '">' . esc_html__( 'Templates', 'restricted-page-templates' ) . '</th></tr>',
        ] );
    } // End ajax_list()

} // End class Pages

Pages::instance();
