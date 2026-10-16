<?php
/**
 * TEMPLATES LIST
 *
 * The Templates tab list screen: search box in the subheader and a per-page dropdown.
 * Styles and scripts load on this screen only.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class TemplatesList {

    /**
     * Available per-page choices.
     */
    public const CHOICES = [ 10, 20, 50, 100 ];


    /**
     * Default per-page value, matching WordPress core.
     */
    public const DEFAULT_PER_PAGE = 20;


    /**
     * User meta key, query arg and nonce action for the per-page setting.
     */
    public const KEY = 'rptemplates_per_page';


    /**
     * @var TemplatesList|null Singleton instance
     */
    private static ?TemplatesList $instance = null;


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
        add_action( 'load-edit.php', [ $this, 'maybe_save_per_page' ] );
        add_filter( 'edit_posts_per_page', [ $this, 'filter_per_page' ], 10, 2 );
        add_action( 'manage_posts_extra_tablenav', [ $this, 'render_per_page' ] );
        add_action( 'rptemplates_subheader_right', [ $this, 'render_search' ], 5 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_notices', [ $this, 'render_restore_notice' ] );
        add_action( 'admin_post_rptemplates_restore_templates', [ $this, 'restore_defaults' ] );
    } // End __construct()


    /**
     * Check whether the current screen is the templates list.
     *
     * @return bool
     */
    private static function is_list_screen() : bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return $screen && 'edit-' . Templates::POST_TYPE === $screen->id;
    } // End is_list_screen()


    /**
     * Get the saved per-page value for the current user, or 0 when none.
     *
     * @return int
     */
    private static function saved() : int {
        $value = absint( get_user_meta( get_current_user_id(), self::KEY, true ) );

        return in_array( $value, self::CHOICES, true ) ? $value : 0;
    } // End saved()


    /**
     * Save the dropdown selection, then redirect to a clean URL.
     *
     * @return void
     */
    public function maybe_save_per_page() : void {
        if ( ! isset( $_GET[ self::KEY ] ) || ! self::is_list_screen() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
            return;
        }

        check_admin_referer( self::KEY );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'restricted-page-templates' ), '', [ 'response' => 403 ] );
        }

        $value = absint( wp_unslash( $_GET[ self::KEY ] ) );

        if ( in_array( $value, self::CHOICES, true ) ) {
            update_user_meta( get_current_user_id(), self::KEY, $value );
        }

        wp_safe_redirect( remove_query_arg( [ self::KEY, '_wpnonce', 'paged' ] ) );
        exit;
    } // End maybe_save_per_page()


    /**
     * Apply the saved per-page value to the templates list.
     *
     * @param int    $per_page
     * @param string $post_type
     * @return int
     */
    public function filter_per_page( $per_page, $post_type ) : int {
        if ( Templates::POST_TYPE !== $post_type ) {
            return (int) $per_page;
        }

        return self::saved() ?: self::DEFAULT_PER_PAGE;
    } // End filter_per_page()


    /**
     * Render the per-page dropdown above and below the list.
     *
     * @param string $which
     * @return void
     */
    public function render_per_page( $which ) : void {
        if ( ! self::is_list_screen() ) {
            return;
        }

        $id = 'rptemplates-per-page-' . sanitize_key( $which );
        $current = self::saved() ?: self::DEFAULT_PER_PAGE;
        ?>
        <div class="alignleft actions">
            <label for="<?php echo esc_attr( $id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Items per page', 'restricted-page-templates' ); ?></label>
            <select id="<?php echo esc_attr( $id ); ?>" class="rptemplates-per-page">
                <?php foreach ( self::CHOICES as $choice ) : ?>
                    <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current, $choice ); ?>>
                        <?php
                        /* translators: %d: number of items per page */
                        echo esc_html( sprintf( __( '%d per page', 'restricted-page-templates' ), $choice ) );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    } // End render_per_page()


    /**
     * Render the search form in the right subheader.
     *
     * @param string $active_tab
     * @return void
     */
    public function render_search( $active_tab = '' ) : void {
        if ( 'templates' !== $active_tab ) {
            return;
        }

        $search = isset( $_GET[ 's' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 's' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $carry = [ 'post_type' => Templates::POST_TYPE ];

        foreach ( [ 'post_status', 'orderby', 'order' ] as $arg ) {
            if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
                $carry[ $arg ] = sanitize_key( wp_unslash( $_GET[ $arg ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }
        ?>
        <form method="get" class="rptemplates-search">
            <?php foreach ( $carry as $name => $value ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
            <?php endforeach; ?>

            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search Templates', 'restricted-page-templates' ); ?>" class="rptemplates-search-input" aria-label="<?php esc_attr_e( 'Search Templates', 'restricted-page-templates' ); ?>">
            <input type="submit" class="rptemplates-button" value="<?php esc_attr_e( 'Search', 'restricted-page-templates' ); ?>">
            <a href="<?php echo esc_url( add_query_arg( 'post_type', Templates::POST_TYPE, admin_url( 'edit.php' ) ) ); ?>" class="rptemplates-button"><?php esc_html_e( 'Clear', 'restricted-page-templates' ); ?></a>
        </form>
        <?php
    } // End render_search()


    /**
     * Offer to restore the default templates when none exist.
     *
     * @return void
     */
    public function render_restore_notice() : void {
        if ( ! self::is_list_screen() ) {
            return;
        }

        $counts = (array) wp_count_posts( Templates::POST_TYPE );

        if ( array_sum( array_map( 'intval', array_diff_key( $counts, [ 'auto-draft' => 1, 'trash' => 1 ] ) ) ) > 0 ) {
            return;
        }

        $url = wp_nonce_url( admin_url( 'admin-post.php?action=rptemplates_restore_templates' ), 'rptemplates_restore_templates' );
        ?>
        <div class="rptemplates-note rptemplates-restore-note">
            <span class="dashicons dashicons-info" aria-hidden="true"></span>
            <p>
                <?php esc_html_e( 'No templates exist, so the built-in default messages are shown to visitors.', 'restricted-page-templates' ); ?>
                <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Restore the default templates', 'restricted-page-templates' ); ?></a>
            </p>
        </div>
        <?php
    } // End render_restore_notice()


    /**
     * Recreate the default templates, then return to the list.
     *
     * @return void
     */
    public function restore_defaults() : void {
        check_admin_referer( 'rptemplates_restore_templates' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'restricted-page-templates' ), '', [ 'response' => 403 ] );
        }

        Templates::create_defaults();

        wp_safe_redirect( admin_url( 'edit.php?post_type=' . Templates::POST_TYPE ) );
        exit;
    } // End restore_defaults()


    /**
     * Enqueue the list styles and script on the templates list only.
     *
     * @param string $hook_suffix
     * @return void
     */
    public function enqueue( $hook_suffix ) : void {
        if ( 'edit.php' !== $hook_suffix || ! self::is_list_screen() ) {
            return;
        }

        wp_enqueue_style( 'rptemplates-templates-list', Bootstrap::url() . 'inc/css/templates-list.css', [ 'rptemplates-theme' ], Bootstrap::script_version() );
        wp_enqueue_script( 'rptemplates-templates-list', Bootstrap::url() . 'inc/js/templates-list.js', [ 'jquery' ], Bootstrap::script_version(), true );

        wp_localize_script( 'rptemplates-templates-list', 'rpTemplatesList', [
            'key'   => self::KEY,
            'nonce' => wp_create_nonce( self::KEY ),
        ] );
    } // End enqueue()

} // End class TemplatesList

TemplatesList::instance();
