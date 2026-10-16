<?php
/**
 * META BOX
 *
 * Access controls on the post edit screen for enabled post types, with the templates in use.
 * Saving is handled by Access::save_post().
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class MetaBox {

    /**
     * @var MetaBox|null Singleton instance
     */
    private static ?MetaBox $instance = null;


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
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    } // End __construct()


    /**
     * Check whether the current screen is the edit screen of an enabled post type.
     *
     * @return bool
     */
    private static function is_edit_screen() : bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return $screen && 'post' === $screen->base && Access::is_enabled( (string) $screen->post_type );
    } // End is_edit_screen()


    /**
     * Enqueue the field assets on enabled edit screens.
     *
     * @return void
     */
    public function enqueue() : void {
        if ( ! self::is_edit_screen() ) {
            return;
        }

        Pickers::enqueue_fields_assets();
        wp_enqueue_script( 'rptemplates-meta-box', Bootstrap::url() . 'inc/js/meta-box.js', [ 'jquery', 'rptemplates-fields' ], Bootstrap::script_version(), true );
    } // End enqueue()


    /**
     * Add the meta box to enabled post types.
     *
     * @param string   $post_type
     * @param \WP_Post $post
     * @return void
     */
    public function add_meta_box( $post_type, $post ) : void {
        if ( ! Access::is_enabled( (string) $post_type ) ) {
            return;
        }

        add_meta_box( 'rptemplates', __( 'Access', 'restricted-page-templates' ), [ $this, 'render' ], $post_type, 'side', 'default' );
    } // End add_meta_box()


    /**
     * Render the meta box.
     *
     * @param \WP_Post $post
     * @return void
     */
    public function render( \WP_Post $post ) : void {
        if ( Access::is_no_access_page( $post->ID ) ) {
            echo '<p>' . esc_html__( 'This is the No Access Page. It is always visible to everyone.', 'restricted-page-templates' ) . '</p>';
            return;
        }

        $rule = Access::parse( (string) get_post_meta( $post->ID, Access::META_KEY, true ) );
        $options = Access::options();
        $default = Access::post_type_default( $post->post_type );

        wp_nonce_field( Access::NONCE, 'rptemplates_nonce' );
        Helpers::render_access_fields( 'rptemplates', $rule, $options[ $default ] ?? $default );

        $this->render_templates( $post->ID );
    } // End render()


    /**
     * Show which templates are used for this post, with links to edit them or create a unique one.
     * Every condition is rendered, and meta-box.js shows the ones the selected access can lead to.
     *
     * @param int $post_id
     * @return void
     */
    private function render_templates( int $post_id ) : void {
        $conditions = Templates::rule_conditions( Access::get_rule( $post_id ) );
        $in_use = Templates::in_use( $post_id, array_keys( Templates::conditions() ) );
        $covered = ! empty( $conditions ) && empty( array_diff( $conditions, array_keys( array_filter( $in_use, fn( $template ) => $template[ 'unique' ] ) ) ) );
        $labels = Templates::conditions();
        ?>
        <div class="rptemplates-templates-in-use" data-default="<?php echo esc_attr( Access::post_type_default( (string) get_post_type( $post_id ) ) ); ?>"<?php echo empty( $conditions ) ? ' hidden' : ''; ?>>
            <span class="rptemplates-sub-label"><?php esc_html_e( 'Templates in use', 'restricted-page-templates' ); ?></span>
            <ul>
                <?php foreach ( $in_use as $condition => $template ) : ?>
                    <li data-condition="<?php echo esc_attr( $condition ); ?>" data-unique="<?php echo $template[ 'unique' ] ? '1' : '0'; ?>"<?php echo in_array( $condition, $conditions, true ) ? '' : ' hidden'; ?>>
                        <span><?php echo esc_html( $labels[ $condition ] ); ?>:</span>
                        <?php if ( $template[ 'id' ] && current_user_can( 'edit_post', $template[ 'id' ] ) ) : ?>
                            <a href="<?php echo esc_url( (string) get_edit_post_link( $template[ 'id' ] ) ); ?>"><?php echo esc_html( get_the_title( $template[ 'id' ] ) ); ?></a>
                        <?php elseif ( $template[ 'id' ] ) : ?>
                            <?php echo esc_html( get_the_title( $template[ 'id' ] ) ); ?>
                        <?php else : ?>
                            <em><?php esc_html_e( 'Built-in default', 'restricted-page-templates' ); ?></em>
                        <?php endif; ?>
                        <?php if ( $template[ 'unique' ] ) : ?>
                            <span class="rptemplates-badge"><?php esc_html_e( 'Unique', 'restricted-page-templates' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a class="rptemplates-unique-link" href="<?php echo esc_url( add_query_arg( [ 'post_type' => Templates::POST_TYPE, 'rptemplates_target' => $post_id ], admin_url( 'post-new.php' ) ) ); ?>" target="_blank" rel="noopener noreferrer"<?php echo $covered ? ' hidden' : ''; ?>><?php esc_html_e( 'Create a unique template for this post', 'restricted-page-templates' ); ?></a>
            <?php endif; ?>
        </div>
        <?php
    } // End render_templates()

} // End class MetaBox

MetaBox::instance();
