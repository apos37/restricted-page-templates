<?php
/**
 * SHORTCODE
 *
 * [restricted_page_template] displays the matching access denied template on the No Access Page.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Shortcode {

    /**
     * Shortcode tag.
     */
    public const TAG = 'restricted_page_template';


    /**
     * Tag used by Role Visibility, still registered so No Access Pages built with it keep working.
     */
    public const LEGACY_TAG = 'role_visibility_msg';


    /**
     * @var bool Guards against a template that contains the shortcode itself
     */
    private static bool $rendering = false;


    /**
     * @var Shortcode|null Singleton instance
     */
    private static ?Shortcode $instance = null;


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
        add_shortcode( self::TAG, [ $this, 'render' ] );
        add_shortcode( self::LEGACY_TAG, [ $this, 'render' ] );
    } // End __construct()


    /**
     * Render the shortcode.
     *
     * @return string
     */
    public function render() : string {
        if ( self::$rendering ) {
            return '';
        }

        self::$rendering = true;
        $output = $this->build();
        self::$rendering = false;

        return $output;
    } // End render()


    /**
     * Build the shortcode output.
     *
     * @return string
     */
    private function build() : string {
        $page_id = (int) Settings::get( 'no_access_page' );
        $current_id = (int) get_the_ID();
        $is_admin = current_user_can( 'manage_options' );
        $generic = '<p>' . esc_html__( 'Sorry, you do not have access to view this page.', 'restricted-page-templates' ) . '</p>';

        if ( ! $page_id && $current_id && is_singular() && 'publish' === get_post_status( $current_id ) ) {
            Settings::update( 'no_access_page', $current_id );

            return $is_admin ? '<p>' . esc_html__( 'This page has automatically been set as your No Access Page. You can change it under Restricted Pages > Settings. From now on, visitors will be sent here when they cannot view a page.', 'restricted-page-templates' ) . '</p>' : $generic;
        }

        $post_id = isset( $_GET[ 'no_access' ] ) ? absint( $_GET[ 'no_access' ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, identifies which template to display.

        if ( ! $post_id || $post_id === $page_id || ! get_post( $post_id ) ) {
            if ( ! $is_admin ) {
                return $generic;
            }

            if ( $page_id !== $current_id ) {
                return '<p>' . esc_html__( 'Hello, Admin! This page is not set as your No Access Page, so visitors will not be sent here. You can set it under Restricted Pages > Settings.', 'restricted-page-templates' ) . '</p>';
            }

            $example = add_query_arg( 'no_access', '{post_id}', get_permalink( $page_id ) );

            /* translators: %s: Example URL of the No Access Page. */
            return '<p>' . sprintf( esc_html__( 'Hello, Admin! This is your No Access Page. Visitors see the matching template here when they are redirected from a page they cannot view, for example: %s', 'restricted-page-templates' ), '<code>' . esc_html( $example ) . '</code>' ) . '</p>';
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard constant read by caching plugins.
        }

        $query_args = Helpers::carry_query_args();
        $redirect_to = add_query_arg( urlencode_deep( $query_args ), get_permalink( $post_id ) );
        $reason = Access::denial_reason( $post_id, null, $query_args );

        if ( '' === $reason ) {
            $retry = '<a href="' . esc_url( add_query_arg( 'cb', time(), $redirect_to ) ) . '">' . esc_html__( 'retry', 'restricted-page-templates' ) . '</a>';

            /* translators: %s: Retry link. */
            return '<p>' . sprintf( esc_html__( 'Oops! It looks like there was a mistake giving you access. Please %s.', 'restricted-page-templates' ), $retry ) . '</p>';
        }

        $rule = Access::get_rule( $post_id );

        $allowed_html = (array) apply_filters( 'rptemplates_template_allowed_html', wp_kses_allowed_html( 'post' ) );
        $content = wp_kses( Templates::render( $post_id, $reason, $redirect_to ), $allowed_html );

        return '<div class="access-denied-content rptemplates-message" data-condition="' . esc_attr( $reason ) . '" data-roles="' . esc_attr( implode( ' ', $rule[ 'roles' ] ) ) . '">' . $content . '</div>';
    } // End build()

} // End class Shortcode

Shortcode::instance();
