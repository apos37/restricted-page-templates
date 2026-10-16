<?php
/**
 * Plugin Name:         Restricted Page Templates
 * Plugin URI:          https://pluginrx.com/plugin/restricted-page-templates/
 * Description:         Restrict pages and posts by role, user or login status, and show each blocked visitor a customizable template that explains why.
 * Version:             1.0.1
 * Requires at least:   6.0
 * Requires PHP:        8.0
 * Author:              PluginRx
 * Author URI:          https://pluginrx.com/
 * Discord URI:         https://discord.gg/3HnzNEJVnR
 * Text Domain:         restricted-page-templates
 * License:             GPLv2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Created on:          October 15, 2024
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * BOOTSTRAP
 *
 * Loads plugin metadata, performs environment checks, and initializes the plugin.
 */
final class Bootstrap {

    /**
     * Plugin files to load, in order.
     */
    public const FILES = [
        'helpers.php',
        'settings.php',
        'menu.php',
        'pages.php',
        'plugin-page.php',
        'access.php',
        'restricted-index.php',
        'templates.php',
        'templates-list.php',
        'shortcode.php',
        'pickers.php',
        'meta-box.php',
        'list-table.php',
        'hide.php',
        'sitemaps.php',
        'nav-menus.php',
        'advanced.php',
        'migrate.php',
    ];


    /**
     * Folder and text domain of Role Visibility, the plugin this one replaces.
     */
    public const PREDECESSOR = 'role-visibility';


    /**
     * Option set when Role Visibility is deactivated, read by the upgrade notice.
     */
    public const REPLACED_OPTION = 'rptemplates_replaced_plugin';


    /**
     * Plugin header keys for get_file_data()
     */
    public const HEADER_KEYS = [
        'name'         => 'Plugin Name',
        'description'  => 'Description',
        'version'      => 'Version',
        'plugin_uri'   => 'Plugin URI',
        'requires_php' => 'Requires PHP',
        'textdomain'   => 'Text Domain',
        'author'       => 'Author',
        'author_uri'   => 'Author URI',
        'discord_uri'  => 'Discord URI',
    ];


    /**
     * @var array Plugin metadata from file header
     */
    private array $meta;


    /**
     * @var Bootstrap|null Singleton instance
     */
    private static ?Bootstrap $instance = null;


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
        $this->meta = get_file_data( __FILE__, self::HEADER_KEYS );
        $this->check_environment();
        add_action( 'plugins_loaded', [ $this, 'load_files' ] );
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
    } // End __construct()


    /**
     * Get a meta value
     *
     * @param string $key
     * @return string
     */
    public function meta( string $key ) : string {
        return $this->meta[ $key ] ?? '';
    } // End meta()


    /**
     * Get plugin version
     *
     * @return string
     */
    public static function version() : string {
        return self::instance()->meta( 'version' );
    } // End version()


    /**
     * Check if the site is in test mode via Developer Debug Tools.
     *
     * @return bool
     */
    public static function test_mode() : bool {
        return (bool) get_option( 'ddtt_test_mode' );
    } // End test_mode()


    /**
     * Get the version string used for enqueued scripts/styles.
     *
     * @return string
     */
    public static function script_version() : string {
        return self::test_mode() ? (string) time() : self::version();
    } // End script_version()


    /**
     * Get the plugin's base directory path, with trailing slash.
     *
     * @return string
     */
    public static function dir() : string {
        return plugin_dir_path( __FILE__ );
    } // End dir()


    /**
     * Get the plugin's base URL, with trailing slash.
     *
     * @return string
     */
    public static function url() : string {
        return plugin_dir_url( __FILE__ );
    } // End url()


    /**
     * Get the plugin's main file path.
     *
     * @return string
     */
    public static function file() : string {
        return __FILE__;
    } // End file()


    /**
     * Check environment requirements
     *
     * @return void
     */
    private function check_environment() : void {
        $requires_php = $this->meta( 'requires_php' );

        if ( $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_php_version' ] );
        }
    } // End check_environment()


    /**
     * Admin notice for unsupported PHP version
     *
     * @return void
     */
    public function notice_php_version() : void {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Restricted Page Templates requires a newer version of PHP. Please contact your host to upgrade.', 'restricted-page-templates' ) . '</p></div>';
    } // End notice_php_version()


    /**
     * Load plugin files
     *
     * @return void
     */
    public function load_files() : void {
        if ( version_compare( PHP_VERSION, $this->meta( 'requires_php' ), '<' ) ) {
            return;
        }

        foreach ( self::FILES as $file ) {
            require_once self::dir() . 'inc/' . $file;
        }

        do_action( 'rptemplates_loaded' );
    } // End load_files()


    /**
     * Activation hook. Deactivates Role Visibility, which this plugin replaces, so both never run together.
     * Setup and migration run on the next init, once all files are loaded.
     *
     * @return void
     */
    public function activate() : void {
        if ( ! get_option( 'rptemplates_activated_time' ) ) {
            update_option( 'rptemplates_activated_time', time(), false );
        }

        self::deactivate_predecessors( true );
    } // End activate()


    /**
     * Get the active copies of Role Visibility, the plugin this one replaces.
     *
     * @param bool $scan_headers Also match by plugin header, for copies installed in a renamed folder.
     * @return array Plugin basenames.
     */
    public static function predecessors( bool $scan_headers = false ) : array {
        $active = (array) get_option( 'active_plugins', [] );

        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }

        $headers = [];

        if ( $scan_headers ) {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $headers = get_plugins();
        }

        $found = [];

        foreach ( array_unique( array_map( 'strval', $active ) ) as $plugin ) {
            if ( plugin_basename( __FILE__ ) === $plugin ) {
                continue;
            }

            if ( self::PREDECESSOR === dirname( $plugin ) || self::PREDECESSOR === ( $headers[ $plugin ][ 'TextDomain' ] ?? '' ) ) {
                $found[] = $plugin;
            }
        }

        if ( class_exists( '\\PluginRx\\RoleVisibility\\Bootstrap' ) && method_exists( '\\PluginRx\\RoleVisibility\\Bootstrap', 'file' ) ) {
            $found[] = plugin_basename( \PluginRx\RoleVisibility\Bootstrap::file() );
        }

        return array_values( array_unique( $found ) );
    } // End predecessors()


    /**
     * Deactivate any active copy of Role Visibility and remember it for the upgrade notice.
     *
     * @param bool $scan_headers
     * @return array Plugin basenames that were deactivated.
     */
    public static function deactivate_predecessors( bool $scan_headers = false ) : array {
        $plugins = self::predecessors( $scan_headers );

        if ( empty( $plugins ) ) {
            return [];
        }

        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins( $plugins );
        update_option( self::REPLACED_OPTION, 1, false );

        return $plugins;
    } // End deactivate_predecessors()

} // End class Bootstrap

Bootstrap::instance();
