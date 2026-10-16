<?php
/**
 * PLUGIN PAGE
 *
 * Adds row meta links (Guide, Docs, Support) and a Settings action link on the Plugins screen.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class PluginPage {

    /**
     * @var PluginPage|null Singleton instance
     */
    private static ?PluginPage $instance = null;


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
        add_filter( 'plugin_row_meta', [ $this, 'add_row_meta' ], 10, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( Bootstrap::file() ), [ $this, 'add_action_links' ] );
    } // End __construct()


    /**
     * External links shown on the Plugins screen and in the subheader.
     *
     * @return array
     */
    public static function links() : array {
        return [
            'guide'   => [ 'label' => __( 'How-To Guide', 'restricted-page-templates' ), 'url' => 'https://pluginrx.com/guide/plugin/restricted-page-templates/' ],
            'docs'    => [ 'label' => __( 'Developer Docs', 'restricted-page-templates' ), 'url' => 'https://pluginrx.com/docs/plugin/restricted-page-templates/' ],
            'support' => [ 'label' => __( 'Support', 'restricted-page-templates' ), 'url' => 'https://pluginrx.com/support/plugin/restricted-page-templates/' ],
        ];
    } // End links()


    /**
     * Add Guide, Docs, and Support links to the plugin's row meta.
     *
     * @param array  $links
     * @param string $plugin_file
     * @return array
     */
    public function add_row_meta( array $links, string $plugin_file ) : array {
        if ( plugin_basename( Bootstrap::file() ) !== $plugin_file ) {
            return $links;
        }

        foreach ( self::links() as $link ) {
            $links[] = '<a href="' . esc_url( $link[ 'url' ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link[ 'label' ] ) . '</a>';
        }

        return $links;
    } // End add_row_meta()


    /**
     * Add a Settings link to the plugin's action links.
     *
     * @param array $links
     * @return array
     */
    public function add_action_links( array $links ) : array {
        array_unshift( $links, '<a href="' . esc_url( Settings::url() ) . '">' . esc_html__( 'Settings', 'restricted-page-templates' ) . '</a>' );

        return $links;
    } // End add_action_links()

} // End class PluginPage

PluginPage::instance();
