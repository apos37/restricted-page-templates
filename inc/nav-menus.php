<?php
/**
 * NAV MENUS
 *
 * Visibility controls for classic menu items (Appearance > Menus) and Navigation block links,
 * plus a one-time import from the Nav Menu Roles plugin.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class NavMenus {

    /**
     * Menu item meta key: match|everyone|logged-out|"logged-in, role, ...".
     */
    public const META_KEY = '_rptemplates_menu_access';


    /**
     * Nonce action for the classic menu editor fields.
     */
    public const NONCE = 'rptemplates_menu';


    /**
     * Meta key used by Nav Menu Roles.
     */
    public const NMR_META_KEY = '_nav_menu_role';


    /**
     * Navigation block types that get visibility controls.
     */
    public const BLOCKS = [ 'core/navigation-link', 'core/navigation-submenu' ];


    /**
     * Navigation block attributes holding the access and the roles.
     */
    public const BLOCK_ACCESS = 'rptemplatesAccess';
    public const BLOCK_ROLES = 'rptemplatesRoles';


    /**
     * @var bool Whether the nonce field has been printed on this page
     */
    private bool $nonce_printed = false;


    /**
     * @var NavMenus|null Singleton instance
     */
    private static ?NavMenus $instance = null;


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
        add_action( 'wp_ajax_rptemplates_import_menus', [ $this, 'ajax_import' ] );

        if ( ! Hide::is_enabled( 'nav_menus' ) ) {
            return;
        }

        add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'render_fields' ], 10, 2 );
        add_action( 'wp_update_nav_menu_item', [ $this, 'save_fields' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor' ] );
        add_filter( 'wp_get_nav_menu_items', [ $this, 'filter_items' ], 20 );

        foreach ( self::BLOCKS as $block ) {
            add_filter( 'render_block_' . $block, [ $this, 'filter_block' ], 10, 2 );
        }
    } // End __construct()


    /**
     * Choices for a menu item.
     *
     * @param bool $linked Whether the item links to a post.
     * @return array
     */
    private static function choices( bool $linked ) : array {
        $options = Access::options();
        unset( $options[ 'default' ], $options[ 'url-param' ] );

        return $linked ? array_merge( [ 'match' => __( 'Match linked content', 'restricted-page-templates' ) ], $options ) : $options;
    } // End choices()


    /**
     * Check whether the user can see an item, given its stored value.
     *
     * @param string $raw       Stored value; empty uses the default for the item type.
     * @param int    $object_id Linked post ID, or 0.
     * @return bool
     */
    public static function is_visible( string $raw, int $object_id ) : bool {
        $raw = '' === $raw ? ( $object_id ? 'match' : 'everyone' ) : $raw;

        if ( 'match' === $raw ) {
            return ! $object_id || Access::user_has_access( $object_id );
        }

        $user_id = get_current_user_id();

        return Access::user_bypasses( $user_id ) || Access::evaluate( Access::parse( $raw ), $user_id );
    } // End is_visible()


    /**
     * Enqueue the field assets on the menu editor.
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_admin( $hook ) : void {
        if ( 'nav-menus.php' === $hook ) {
            Pickers::enqueue_fields_assets();
        }
    } // End enqueue_admin()


    /**
     * Enqueue the Navigation block controls.
     *
     * @return void
     */
    public function enqueue_block_editor() : void {
        $roles = [];

        foreach ( Access::selectable_roles() as $slug => $label ) {
            $roles[] = [ 'value' => $slug, 'label' => $label ];
        }

        $choices = [];

        foreach ( self::choices( true ) as $value => $label ) {
            $choices[] = [ 'value' => $value, 'label' => $label ];
        }

        wp_enqueue_script( 'rptemplates-nav-block', Bootstrap::url() . 'inc/js/nav-block.js', [ 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components' ], Bootstrap::script_version(), true );

        wp_localize_script( 'rptemplates-nav-block', 'rpTemplatesNavBlock', [
            'blocks'       => self::BLOCKS,
            'choices'      => $choices,
            'roles'        => $roles,
            'panelTitle'   => __( 'Visibility', 'restricted-page-templates' ),
            'accessLabel'  => __( 'Who can see this link?', 'restricted-page-templates' ),
            'rolesLabel'   => __( 'Limit to roles', 'restricted-page-templates' ),
            'matchHelp'    => __( 'Hidden whenever the visitor cannot view the linked content.', 'restricted-page-templates' ),
        ] );
    } // End enqueue_block_editor()


    /**
     * Render the fields on each classic menu item.
     *
     * @param int      $item_id
     * @param \WP_Post $menu_item
     * @return void
     */
    public function render_fields( $item_id, $menu_item ) : void {
        $linked = 'post_type' === ( $menu_item->type ?? '' );
        $raw = (string) get_post_meta( $item_id, self::META_KEY, true );
        $raw = '' === $raw ? ( $linked ? 'match' : 'everyone' ) : $raw;
        $value = in_array( $raw, [ 'match', 'everyone', 'logged-out' ], true ) ? $raw : 'logged-in';
        $rule = Access::parse( $raw );
        $name = 'rptemplates_menu[' . (int) $item_id . ']';

        if ( ! $this->nonce_printed ) {
            wp_nonce_field( self::NONCE, 'rptemplates_menu_nonce', false );
            $this->nonce_printed = true;
        }
        ?>
        <div class="rptemplates-menu-fields rptemplates-access-fields description description-wide">
            <label class="rptemplates-access-label">
                <span><?php esc_html_e( 'Visibility', 'restricted-page-templates' ); ?></span>
                <select name="<?php echo esc_attr( $name ); ?>[access]" class="rptemplates-access-select widefat">
                    <?php foreach ( self::choices( $linked ) as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="rptemplates-restrict-to"<?php echo 'logged-in' === $value ? '' : ' hidden'; ?>>
                <span class="rptemplates-sub-label"><?php esc_html_e( 'Limit to roles', 'restricted-page-templates' ); ?></span>
                <div class="rptemplates-role-checkboxes">
                    <?php foreach ( Access::selectable_roles() as $slug => $label ) : ?>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule[ 'roles' ], true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    } // End render_fields()


    /**
     * Save the classic menu item fields.
     *
     * @param int $menu_id
     * @param int $item_id
     * @return void
     */
    public function save_fields( $menu_id, $item_id ) : void {
        $nonce = isset( $_POST[ 'rptemplates_menu_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'rptemplates_menu_nonce' ] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }

        $data = isset( $_POST[ 'rptemplates_menu' ][ $item_id ] ) && is_array( $_POST[ 'rptemplates_menu' ][ $item_id ] ) ? wp_unslash( $_POST[ 'rptemplates_menu' ][ $item_id ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.

        if ( null === $data ) {
            return;
        }

        $linked = 'post_type' === get_post_meta( $item_id, '_menu_item_type', true );
        $access = sanitize_key( $data[ 'access' ] ?? '' );

        if ( ! array_key_exists( $access, self::choices( $linked ) ) || ( $linked ? 'match' : 'everyone' ) === $access ) {
            delete_post_meta( $item_id, self::META_KEY );
            return;
        }

        $roles = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $data[ 'roles' ] ?? [] ) ), array_keys( Helpers::all_roles() ) ) );

        update_post_meta( $item_id, self::META_KEY, Access::build( $access, $roles ) );
    } // End save_fields()


    /**
     * Remove hidden menu items, and their children, on the front end.
     *
     * @param array $items
     * @return array
     */
    public function filter_items( $items ) : array {
        $items = (array) $items;

        if ( is_admin() || empty( $items ) ) {
            return $items;
        }

        $object_ids = [];

        foreach ( $items as $item ) {
            if ( 'post_type' === ( $item->type ?? '' ) ) {
                $object_ids[] = (int) $item->object_id;
            }
        }

        if ( ! empty( $object_ids ) ) {
            update_meta_cache( 'post', $object_ids );
        }

        $removed = [];
        $visible_items = [];

        foreach ( $items as $item ) {
            $parent = (int) ( $item->menu_item_parent ?? 0 );
            $object_id = 'post_type' === ( $item->type ?? '' ) ? (int) $item->object_id : 0;
            $visible = ! isset( $removed[ $parent ] ) && self::is_visible( (string) get_post_meta( $item->ID, self::META_KEY, true ), $object_id );
            $visible = (bool) apply_filters( 'rptemplates_menu_item_visible', $visible, $item );

            if ( $visible ) {
                $visible_items[] = $item;
            } else {
                $removed[ (int) $item->ID ] = true;
            }
        }

        return $visible_items;
    } // End filter_items()


    /**
     * Hide Navigation block links the visitor cannot see.
     *
     * @param string $block_content
     * @param array  $block
     * @return string
     */
    public function filter_block( $block_content, $block ) : string {
        $attrs = $block[ 'attrs' ] ?? [];
        $object_id = 'post-type' === ( $attrs[ 'kind' ] ?? '' ) ? absint( $attrs[ 'id' ] ?? 0 ) : 0;
        $access = sanitize_key( $attrs[ self::BLOCK_ACCESS ] ?? $attrs[ Migrate::RV_BLOCK_ACCESS ] ?? '' );
        $roles = (array) ( $attrs[ self::BLOCK_ROLES ] ?? $attrs[ Migrate::RV_BLOCK_ROLES ] ?? [] );
        $raw = 'logged-in' === $access ? Access::build( 'logged-in', array_map( 'sanitize_key', $roles ) ) : $access;
        $visible = (bool) apply_filters( 'rptemplates_nav_block_visible', self::is_visible( $raw, $object_id ), $block );

        return $visible ? (string) $block_content : '';
    } // End filter_block()


    /**
     * Check whether Nav Menu Roles data exists.
     *
     * @return bool
     */
    public static function has_nav_menu_roles_data() : bool {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::NMR_META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- runs only on the Settings tab to decide whether to show the import button.
    } // End has_nav_menu_roles_data()


    /**
     * Get the plugin file of Nav Menu Roles when it is active.
     *
     * @return string Plugin file relative to the plugins folder, or an empty string.
     */
    public static function active_nav_menu_roles_plugin() : string {
        $active = (array) get_option( 'active_plugins', [] );

        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }

        $found = '';

        foreach ( $active as $plugin ) {
            $plugin = (string) $plugin;

            if ( 'nav-menu-roles.php' === basename( $plugin ) || str_starts_with( $plugin, 'nav-menu-roles/' ) ) {
                $found = $plugin;
                break;
            }
        }

        return (string) apply_filters( 'rptemplates_nav_menu_roles_plugin', $found );
    } // End active_nav_menu_roles_plugin()


    /**
     * AJAX: import Nav Menu Roles rules and turn on the Navigation Menus option.
     *
     * @return void
     */
    public function ajax_import() : void {
        Helpers::verify_ajax( Settings::NONCE );

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::NMR_META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time import of another plugin's data.
        $count = 0;

        foreach ( $rows as $row ) {
            $value = maybe_unserialize( $row->meta_value );

            if ( 'in' === $value ) {
                $raw = 'logged-in';
            } elseif ( 'out' === $value ) {
                $raw = 'logged-out';
            } elseif ( is_array( $value ) && ! empty( $value ) ) {
                $raw = Access::build( 'logged-in', array_values( array_intersect( array_map( 'sanitize_key', $value ), array_keys( Helpers::all_roles() ) ) ) );
            } else {
                continue;
            }

            update_post_meta( (int) $row->post_id, self::META_KEY, $raw );
            $count++;
        }

        $hide = (array) Settings::get( 'hide' );

        if ( ! in_array( 'nav_menus', $hide, true ) ) {
            $hide[] = 'nav_menus';
            Settings::update( 'hide', $hide );
        }

        $plugin = self::active_nav_menu_roles_plugin();
        $deactivated = false;

        if ( '' !== $plugin && current_user_can( 'deactivate_plugin', $plugin ) ) {
            if ( ! function_exists( 'deactivate_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            deactivate_plugins( $plugin );
            $deactivated = ! is_plugin_active( $plugin );
        }

        $message = sprintf(
            /* translators: %d: Number of imported menu items. */
            _n( '%d menu item rule imported.', '%d menu item rules imported.', $count, 'restricted-page-templates' ),
            $count
        );

        $message .= ' ' . __( 'Navigation Menus is now on.', 'restricted-page-templates' );
        $message .= $deactivated ? ' ' . __( 'Nav Menu Roles was deactivated.', 'restricted-page-templates' ) : '';

        wp_send_json_success( $message . ' ' . __( 'Reloading…', 'restricted-page-templates' ) );
    } // End ajax_import()

} // End class NavMenus

NavMenus::instance();
