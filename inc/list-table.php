<?php
/**
 * LIST TABLE
 *
 * Adds the Access column plus Quick Edit and Bulk Edit controls to enabled post type lists.
 * Saving is handled by Access::save_post().
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class ListTable {

    /**
     * Column key.
     */
    public const COLUMN = 'rptemplates';


    /**
     * @var ListTable|null Singleton instance
     */
    private static ?ListTable $instance = null;


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
        add_action( 'admin_init', [ $this, 'register_columns' ] );
        add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 2 );
        add_action( 'bulk_edit_custom_box', [ $this, 'render_bulk_edit' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_filter( 'admin_body_class', [ $this, 'body_class' ] );
    } // End __construct()


    /**
     * Register the column for each enabled post type. Also runs during Quick Edit's AJAX save,
     * which re-renders the row.
     *
     * @return void
     */
    public function register_columns() : void {
        foreach ( array_keys( Settings::get( 'post_types' ) ) as $post_type ) {
            add_filter( 'manage_' . $post_type . '_posts_columns', [ $this, 'add_column' ] );
            add_action( 'manage_' . $post_type . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
        }
    } // End register_columns()


    /**
     * Enqueue assets on enabled list screens.
     *
     * @param string $hook
     * @return void
     */
    public function enqueue( $hook ) : void {
        $screen = get_current_screen();

        if ( 'edit.php' !== $hook || ! $screen || ! Access::is_enabled( (string) $screen->post_type ) ) {
            return;
        }

        Pickers::enqueue_fields_assets();
        wp_enqueue_script( 'rptemplates-list-table', Bootstrap::url() . 'inc/js/list-table.js', [ 'jquery', 'inline-edit-post', 'rptemplates-fields' ], Bootstrap::script_version(), true );
    } // End enqueue()


    /**
     * Mark enabled list screens that show templates in the column, so the column can be wider.
     *
     * @param string $classes
     * @return string
     */
    public function body_class( $classes ) : string {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( $screen && 'edit' === $screen->base && Access::is_enabled( (string) $screen->post_type ) && Settings::get( 'show_templates_column' ) ) {
            $classes .= ' rptemplates-show-templates';
        }

        return (string) $classes;
    } // End body_class()


    /**
     * Add the column.
     *
     * @param array $columns
     * @return array
     */
    public function add_column( array $columns ) : array {
        $columns[ self::COLUMN ] = __( 'Access', 'restricted-page-templates' );

        return $columns;
    } // End add_column()


    /**
     * Render the column, with the raw rule in data attributes for Quick Edit.
     *
     * @param string $column
     * @param int    $post_id
     * @return void
     */
    public function render_column( $column, $post_id ) : void {
        if ( self::COLUMN !== $column ) {
            return;
        }

        if ( Access::is_no_access_page( (int) $post_id ) ) {
            echo '<span class="rptemplates-column" data-access="everyone">' . esc_html__( 'Everyone (No Access Page)', 'restricted-page-templates' ) . '</span>';
            return;
        }

        $raw = (string) get_post_meta( $post_id, Access::META_KEY, true );
        $users = [];

        foreach ( Helpers::user_labels( Access::parse( $raw )[ 'users' ] ) as $id => $label ) {
            $users[] = [ 'id' => $id, 'text' => $label ];
        }

        printf(
            '<span class="rptemplates-column" data-access="%1$s" data-users="%2$s">%3$s</span>',
            esc_attr( $raw ),
            esc_attr( (string) wp_json_encode( $users ) ),
            esc_html( Access::describe( (int) $post_id ) )
        );

        if ( Settings::get( 'show_templates_column' ) ) {
            echo Templates::in_use_html( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped in Templates::in_use_html().
        }
    } // End render_column()


    /**
     * Render the Quick Edit controls.
     *
     * @param string $column
     * @param string $post_type
     * @return void
     */
    public function render_quick_edit( $column, $post_type ) : void {
        $this->render_inline( (string) $column, (string) $post_type, false );
    } // End render_quick_edit()


    /**
     * Render the Bulk Edit controls.
     *
     * @param string $column
     * @param string $post_type
     * @return void
     */
    public function render_bulk_edit( $column, $post_type ) : void {
        $this->render_inline( (string) $column, (string) $post_type, true );
    } // End render_bulk_edit()


    /**
     * Render the shared Quick Edit and Bulk Edit fieldset.
     *
     * @param string $column
     * @param string $post_type
     * @param bool   $bulk
     * @return void
     */
    private function render_inline( string $column, string $post_type, bool $bulk ) : void {
        if ( self::COLUMN !== $column || ! Access::is_enabled( $post_type ) ) {
            return;
        }

        $name = $bulk ? 'rptemplates_bulk' : 'rptemplates';
        $options = Access::options();
        $default = Access::post_type_default( $post_type );
        ?>
        <fieldset class="inline-edit-col-left rptemplates-inline-edit">
            <div class="inline-edit-col">
                <span class="title"><?php esc_html_e( 'Access', 'restricted-page-templates' ); ?></span>
                <?php
                wp_nonce_field( Access::NONCE, $name . '_nonce', false );
                Helpers::render_access_fields( $name, [ 'access' => 'default', 'roles' => [], 'users' => [] ], $options[ $default ] ?? $default, $bulk );
                ?>
            </div>
        </fieldset>
        <?php
    } // End render_inline()

} // End class ListTable

ListTable::instance();
