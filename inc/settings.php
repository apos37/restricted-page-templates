<?php
/**
 * SETTINGS
 *
 * Option registry, getters, sanitizing, and the Settings tab, saved over AJAX.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Settings {

    /**
     * Option name prefix.
     */
    public const PREFIX = 'rptemplates_';


    /**
     * Nonce action shared by the Settings tab requests.
     */
    public const NONCE = 'rptemplates_settings_nonce';


    /**
     * Access values allowed as a post type default.
     */
    public const DEFAULT_ACCESS = [ 'everyone', 'logged-in', 'logged-out', 'url-param' ];


    /**
     * Roles included before the setting is first saved.
     */
    public const DEFAULT_ROLES = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];


    /**
     * @var array Resolved values for the current request
     */
    private static array $cache = [];


    /**
     * @var Settings|null Singleton instance
     */
    private static ?Settings $instance = null;


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
        add_action( 'wp_ajax_rptemplates_save_settings', [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_rptemplates_create_page', [ $this, 'ajax_create_page' ] );
        add_action( 'rptemplates_subheader_left', [ $this, 'render_subheader_buttons' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    } // End __construct()


    /**
     * Setting definitions, keyed without the option prefix.
     *
     * @return array
     */
    public static function fields() : array {
        $fields = [
            'no_access_page'          => [ 'type' => 'int', 'default' => 0, 'autoload' => true ],
            'login_url'               => [ 'type' => 'url', 'default' => '', 'autoload' => true ],
            'register_url'            => [ 'type' => 'url', 'default' => '', 'autoload' => true ],
            'admin_bypass'            => [ 'type' => 'bool', 'default' => 0, 'autoload' => true ],
            'show_templates_column'   => [ 'type' => 'bool', 'default' => 0, 'autoload' => true ],
            'post_types'              => [ 'type' => 'post_types', 'default' => null, 'autoload' => true ],
            'roles'                   => [ 'type' => 'roles', 'default' => null, 'autoload' => true ],
            'hide'                    => [ 'type' => 'contexts', 'default' => [ 'archives', 'search', 'feeds', 'rest', 'nav_menus', 'adjacent' ], 'autoload' => true ],
            'clear_data_on_uninstall' => [ 'type' => 'bool', 'default' => 0, 'autoload' => false ],
        ];

        return apply_filters( 'rptemplates_settings_fields', $fields );
    } // End fields()


    /**
     * Get every option name managed by the Settings tab.
     *
     * @return array
     */
    public static function option_names() : array {
        return array_map( fn( $key ) => self::PREFIX . $key, array_keys( self::fields() ) );
    } // End option_names()


    /**
     * Get a setting value.
     *
     * @param string $key Key without the option prefix.
     * @return mixed
     */
    public static function get( string $key ) : mixed {
        if ( array_key_exists( $key, self::$cache ) ) {
            return self::$cache[ $key ];
        }

        $fields = self::fields();

        if ( ! isset( $fields[ $key ] ) ) {
            return null;
        }

        $value = get_option( self::PREFIX . $key, null );

        if ( null === $value ) {
            $value = self::default( $key );
        }

        if ( 'post_types' === $key ) {
            $value = (array) apply_filters( 'rptemplates_post_types', (array) $value );
        } elseif ( 'roles' === $key ) {
            $value = (array) apply_filters( 'rptemplates_roles', (array) $value );
        }

        if ( did_action( 'init' ) ) {
            self::$cache[ $key ] = $value;
        }

        return $value;
    } // End get()


    /**
     * Get a setting's default value.
     *
     * @param string $key
     * @return mixed
     */
    public static function default( string $key ) : mixed {
        if ( 'post_types' === $key ) {
            return array_fill_keys( array_keys( Helpers::public_post_types() ), 'everyone' );
        }

        if ( 'roles' === $key ) {
            return array_values( array_intersect( self::DEFAULT_ROLES, array_keys( Helpers::all_roles() ) ) );
        }

        return self::fields()[ $key ][ 'default' ] ?? null;
    } // End default()


    /**
     * Sanitize a value for a setting.
     *
     * @param string $key
     * @param mixed  $value
     * @return mixed
     */
    public static function sanitize( string $key, mixed $value ) : mixed {
        $field = self::fields()[ $key ] ?? [];

        if ( isset( $field[ 'sanitize' ] ) && is_callable( $field[ 'sanitize' ] ) ) {
            return call_user_func( $field[ 'sanitize' ], $value );
        }

        switch ( $field[ 'type' ] ?? '' ) {
            case 'int':
                return absint( $value );

            case 'url':
                return is_string( $value ) ? esc_url_raw( trim( $value ) ) : '';

            case 'bool':
                return empty( $value ) ? 0 : 1;

            case 'post_types':
                $clean = [];

                foreach ( (array) $value as $post_type => $access ) {
                    $post_type = sanitize_key( $post_type );

                    if ( '' !== $post_type && post_type_exists( $post_type ) ) {
                        $access = is_string( $access ) ? $access : '';
                        $flag = trim( explode( ',', $access )[ 0 ] );
                        $clean[ $post_type ] = in_array( $flag, self::DEFAULT_ACCESS, true ) ? Access::build( $flag, [], [], (array) ( Access::parse( $access )[ 'param' ] ?? [] ) ) : 'everyone';
                    }
                }

                return $clean;

            case 'roles':
                return array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array_keys( Helpers::all_roles() ) ) );

            case 'contexts':
                return array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array_keys( Hide::contexts() ) ) );
        }

        return is_string( $value ) ? sanitize_text_field( $value ) : '';
    } // End sanitize()


    /**
     * Sanitize and store one setting.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function update( string $key, mixed $value ) : void {
        $fields = self::fields();

        if ( ! isset( $fields[ $key ] ) ) {
            return;
        }

        update_option( self::PREFIX . $key, self::sanitize( $key, $value ), ! empty( $fields[ $key ][ 'autoload' ] ) );
        unset( self::$cache[ $key ] );
    } // End update()


    /**
     * Sanitize and store several settings at once. Keys that are not provided are left alone.
     *
     * @param array $values Values keyed without the option prefix, in stored format.
     * @return void
     */
    public static function save( array $values ) : void {
        foreach ( array_keys( self::fields() ) as $key ) {
            if ( array_key_exists( $key, $values ) ) {
                self::update( $key, $values[ $key ] );
            }
        }

        do_action( 'rptemplates_settings_saved', $values );
    } // End save()


    /**
     * Delete every setting so defaults apply again.
     *
     * @return void
     */
    public static function reset() : void {
        foreach ( self::option_names() as $option ) {
            delete_option( $option );
        }

        self::$cache = [];

        do_action( 'rptemplates_settings_reset' );
    } // End reset()


    /**
     * Clear the request cache, used after options are changed outside this class.
     *
     * @return void
     */
    public static function flush_cache() : void {
        self::$cache = [];
    } // End flush_cache()


    /**
     * Get the URL of the Settings tab.
     *
     * @return string
     */
    public static function url() : string {
        return admin_url( 'admin.php?page=' . Menu::SETTINGS_SLUG );
    } // End url()


    /**
     * Check whether the current screen is the Settings tab.
     *
     * @return bool
     */
    private static function is_settings_screen() : bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return $screen && Menu::settings_screen_id() === $screen->id;
    } // End is_settings_screen()


    /**
     * Enqueue assets on the Settings tab.
     *
     * @return void
     */
    public function enqueue() : void {
        if ( ! self::is_settings_screen() ) {
            return;
        }

        Pickers::enqueue_fields_assets();
        wp_enqueue_script( 'rptemplates-settings', Bootstrap::url() . 'inc/js/settings.js', [ 'jquery', 'rptemplates-fields' ], Bootstrap::script_version(), true );

        wp_localize_script( 'rptemplates-settings', 'rpTemplatesSettings', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( self::NONCE ),
            'savingText'    => __( 'Saving', 'restricted-page-templates' ),
            'savedText'     => __( 'Settings saved successfully.', 'restricted-page-templates' ),
            'errorText'     => __( 'Error saving settings. Please try again.', 'restricted-page-templates' ),
            'importOk'      => __( 'Settings imported. Reloading…', 'restricted-page-templates' ),
            'importError'   => __( 'That file is not a valid settings export.', 'restricted-page-templates' ),
            'resetOk'       => __( 'Settings reset. Reloading…', 'restricted-page-templates' ),
            'pageCreated'   => __( 'No Access Page created and saved.', 'restricted-page-templates' ),
            'confirmReset'  => __( 'Reset all Restricted Page Templates settings to their defaults? Your templates and the access rules on your posts are not affected.', 'restricted-page-templates' ),
            'confirmImport' => __( 'Importing will overwrite your current Restricted Page Templates settings. Continue?', 'restricted-page-templates' ),
            'confirmMenus'  => __( 'Import the visibility rules from Nav Menu Roles into Restricted Page Templates? Existing menu rules on the same items will be overwritten and Nav Menu Roles will be deactivated.', 'restricted-page-templates' ),
            'exportName'    => 'restricted-page-templates-settings-' . gmdate( 'Y-m-d' ) . '.json',
        ] );
    } // End enqueue()


    /**
     * Render the Save button and dirty-state reminder in the subheader.
     *
     * @param string $active_tab
     * @return void
     */
    public function render_subheader_buttons( string $active_tab ) : void {
        if ( 'settings' !== $active_tab ) {
            return;
        }
        ?>
        <button type="button" class="rptemplates-button" id="rptemplates-save"><?php esc_html_e( 'Save', 'restricted-page-templates' ); ?></button>
        <span id="rptemplates-save-reminder"><?php esc_html_e( 'Remember to click "Save" after making changes to your settings.', 'restricted-page-templates' ); ?></span>
        <?php
    } // End render_subheader_buttons()


    /**
     * Render the Settings tab.
     *
     * @return void
     */
    public static function render_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'restricted-page-templates' ) );
        }

        $no_access_page = (int) self::get( 'no_access_page' );
        $post_types = self::get( 'post_types' );
        $roles = self::get( 'roles' );
        $hide = self::get( 'hide' );
        $access_options = Access::options();
        unset( $access_options[ 'default' ] );
        ?>
        <div class="wrap rptemplates-wrap rptemplates-content-wrap">
            <?php if ( ! $no_access_page || 'publish' !== get_post_status( $no_access_page ) ) : ?>
                <div class="rptemplates-note rptemplates-note-warning">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <p><?php esc_html_e( 'Visitors are only redirected once a published No Access Page is selected below. Until then, restricted content stays viewable when visited directly.', 'restricted-page-templates' ); ?></p>
                </div>
            <?php endif; ?>

            <form id="rptemplates-settings-form" class="rptemplates-settings-grid" autocomplete="off">

                <div class="rptemplates-box">
                    <div class="rptemplates-box-header"><h2><?php esc_html_e( 'General', 'restricted-page-templates' ); ?></h2></div>
                    <div class="rptemplates-box-body">
                        <div class="rptemplates-field">
                            <label for="rptemplates_no_access_page">
                                <?php esc_html_e( 'No Access Page', 'restricted-page-templates' ); ?>
                                <?php
                                Helpers::tooltip( sprintf(
                                    /* translators: %s: The shortcode that displays the access denied template. */
                                    __( 'Visitors are redirected here when they cannot view a page. The page must include the %s shortcode, which displays the matching template.', 'restricted-page-templates' ),
                                    '<code>[' . Shortcode::TAG . ']</code>'
                                ) );
                                ?>
                            </label>
                            <div class="rptemplates-inline-actions">
                                <?php
                                wp_dropdown_pages( [
                                    'name'              => 'rptemplates[no_access_page]',
                                    'id'                => 'rptemplates_no_access_page',
                                    'selected'          => absint( $no_access_page ),
                                    'show_option_none'  => esc_html__( '— Select a Page —', 'restricted-page-templates' ),
                                    'option_none_value' => 0,
                                ] );
                                ?>
                                <button type="button" class="rptemplates-button rptemplates-button-secondary" id="rptemplates-create-page"<?php echo $no_access_page ? ' hidden' : ''; ?>><?php esc_html_e( 'Create Page', 'restricted-page-templates' ); ?></button>
                            </div>
                        </div>

                        <div class="rptemplates-field">
                            <label for="rptemplates_login_url">
                                <?php esc_html_e( 'Login URL', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Used by the login merge tags. Leave blank to use the default WordPress login page. Visitors are sent back to the page they tried to view after logging in.', 'restricted-page-templates' ) ); ?>
                            </label>
                            <input type="url" id="rptemplates_login_url" name="rptemplates[login_url]" value="<?php echo esc_attr( self::get( 'login_url' ) ); ?>" placeholder="<?php echo esc_attr( wp_login_url() ); ?>">
                        </div>

                        <div class="rptemplates-field">
                            <label for="rptemplates_register_url">
                                <?php esc_html_e( 'Register URL', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Used by the register merge tags. Leave blank to use the default WordPress registration page.', 'restricted-page-templates' ) ); ?>
                            </label>
                            <input type="url" id="rptemplates_register_url" name="rptemplates[register_url]" value="<?php echo esc_attr( self::get( 'register_url' ) ); ?>" placeholder="<?php echo esc_attr( wp_registration_url() ); ?>">
                        </div>

                        <div class="rptemplates-field">
                            <label class="rptemplates-inline-label">
                                <input type="checkbox" name="rptemplates[admin_bypass]" value="1" <?php checked( (bool) self::get( 'admin_bypass' ) ); ?>>
                                <?php esc_html_e( 'Administrators Bypass Restrictions', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Lets users who can manage options view all restricted content, including in menus, searches and feeds. When unchecked, administrators follow the same rules as everyone else.', 'restricted-page-templates' ) ); ?>
                            </label>
                        </div>

                        <div class="rptemplates-field">
                            <label class="rptemplates-inline-label">
                                <input type="checkbox" name="rptemplates[show_templates_column]" value="1" <?php checked( (bool) self::get( 'show_templates_column' ) ); ?>>
                                <?php esc_html_e( 'Show Templates in the Access Column', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Lists the template each restricted post uses below its access in the post lists, and marks the ones made just for that post as Unique.', 'restricted-page-templates' ) ); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="rptemplates-box">
                    <div class="rptemplates-box-header"><h2><?php esc_html_e( 'Hide Restricted Content', 'restricted-page-templates' ); ?></h2></div>
                    <div class="rptemplates-box-body">
                        <p class="rptemplates-section-desc"><?php esc_html_e( 'Restricted content is always protected when visited directly. These options also remove it from the places below for anyone who cannot view it. Content with URL Parameter access is checked as a link without any parameters.', 'restricted-page-templates' ); ?></p>
                        <?php foreach ( Hide::contexts() as $key => $context ) : ?>
                            <div class="rptemplates-field">
                                <label class="rptemplates-inline-label">
                                    <input type="checkbox" name="rptemplates[hide][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $hide, true ) ); ?>>
                                    <?php echo esc_html( $context[ 'label' ] ); ?>
                                    <?php Helpers::tooltip( $context[ 'desc' ] ); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rptemplates-box">
                    <div class="rptemplates-box-header"><h2><?php esc_html_e( 'Post Types', 'restricted-page-templates' ); ?></h2></div>
                    <div class="rptemplates-box-body">
                        <p class="rptemplates-section-desc"><?php esc_html_e( 'Enable access controls for each post type and choose the access its content uses unless a post says otherwise.', 'restricted-page-templates' ); ?></p>
                        <table class="rptemplates-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Enabled', 'restricted-page-templates' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Post Type', 'restricted-page-templates' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Default Access', 'restricted-page-templates' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( Helpers::public_post_types() as $slug => $object ) : ?>
                                    <?php
                                    $field_id = 'rptemplates_pt_' . $slug;
                                    $default_rule = isset( $post_types[ $slug ] ) ? Access::post_type_rule( $slug ) : [ 'access' => 'everyone' ];
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="rptemplates[post_types][<?php echo esc_attr( $slug ); ?>][enabled]" value="1" <?php checked( isset( $post_types[ $slug ] ) ); ?>></td>
                                        <td><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $object->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code></label></td>
                                        <td>
                                            <div class="rptemplates-access-fields">
                                                <select name="rptemplates[post_types][<?php echo esc_attr( $slug ); ?>][default]" class="rptemplates-access-select" aria-label="<?php echo esc_attr( $object->labels->name ); ?>">
                                                    <?php foreach ( $access_options as $key => $label ) : ?>
                                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $default_rule[ 'access' ], $key ); ?>><?php echo esc_html( $label ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php Helpers::render_param_fields( 'rptemplates[post_types][' . $slug . '][param]', (array) ( $default_rule[ 'param' ] ?? [] ), 'url-param' === $default_rule[ 'access' ] ); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rptemplates-box">
                    <div class="rptemplates-box-header"><h2><?php esc_html_e( 'Roles', 'restricted-page-templates' ); ?></h2></div>
                    <div class="rptemplates-box-body">
                        <div class="rptemplates-field">
                            <label>
                                <?php esc_html_e( 'Roles Available in Access Controls', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Choose which roles can be selected when limiting "Logged-In Only" content, menu items and templates.', 'restricted-page-templates' ) ); ?>
                            </label>
                            <div class="rptemplates-checkboxes">
                                <?php foreach ( Helpers::all_roles() as $slug => $label ) : ?>
                                    <label>
                                        <input type="checkbox" name="rptemplates[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $roles, true ) ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php do_action( 'rptemplates_settings_boxes' ); ?>

                <div class="rptemplates-box">
                    <div class="rptemplates-box-header"><h2><?php esc_html_e( 'Advanced', 'restricted-page-templates' ); ?></h2></div>
                    <div class="rptemplates-box-body">
                        <div class="rptemplates-field">
                            <label class="rptemplates-inline-label">
                                <input type="checkbox" name="rptemplates[clear_data_on_uninstall]" value="1" <?php checked( (bool) self::get( 'clear_data_on_uninstall' ) ); ?>>
                                <?php esc_html_e( 'Remove All Plugin Data on Uninstall', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Permanently deletes all Restricted Page Templates settings, templates, menu rules and the access rules saved on your posts when the plugin is deleted.', 'restricted-page-templates' ) ); ?>
                            </label>
                        </div>

                        <div class="rptemplates-field">
                            <label>
                                <?php esc_html_e( 'Download/Upload Settings', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Export the settings on this page as a JSON file for backup or to copy to another site. Templates and the access rules on your posts are not included. Importing overwrites your current settings.', 'restricted-page-templates' ) ); ?>
                            </label>
                            <div class="rptemplates-inline-actions">
                                <button type="button" class="rptemplates-button" id="rptemplates-download-settings"><?php esc_html_e( 'Download Settings', 'restricted-page-templates' ); ?></button>
                                <label for="rptemplates-upload-settings" class="rptemplates-button" tabindex="0"><?php esc_html_e( 'Upload Settings', 'restricted-page-templates' ); ?></label>
                                <input type="file" id="rptemplates-upload-settings" accept="application/json,.json" hidden>
                                <span id="rptemplates-upload-filename"></span>
                            </div>
                        </div>

                        <?php if ( '' !== NavMenus::active_nav_menu_roles_plugin() ) : ?>
                            <div class="rptemplates-field">
                                <label>
                                    <?php esc_html_e( 'Import from Nav Menu Roles', 'restricted-page-templates' ); ?>
                                    <?php Helpers::tooltip( __( 'Copies the menu item rules saved by the Nav Menu Roles plugin turns on the Navigation Menus option, and deactivates Nav Menu Roles. Its own data is left untouched.', 'restricted-page-templates' ) ); ?>
                                </label>
                                <div><button type="button" class="rptemplates-button" id="rptemplates-import-menus"><?php esc_html_e( 'Import Menu Rules', 'restricted-page-templates' ); ?></button></div>
                            </div>
                        <?php endif; ?>

                        <?php if ( Migrate::has_legacy_data() ) : ?>
                            <div class="rptemplates-field">
                                <label>
                                    <?php esc_html_e( 'Import from Role Visibility', 'restricted-page-templates' ); ?>
                                    <?php Helpers::tooltip( __( 'Settings, access rules, templates or custom messages from Role Visibility, the previous name of this plugin, were found. This converts them. It runs automatically, so you only need it if something was left behind.', 'restricted-page-templates' ) ); ?>
                                </label>
                                <div><button type="button" class="rptemplates-button" id="rptemplates-run-migration"><?php esc_html_e( 'Run Import Again', 'restricted-page-templates' ); ?></button></div>
                            </div>
                        <?php endif; ?>

                        <div class="rptemplates-field">
                            <label>
                                <?php esc_html_e( 'Reset Settings', 'restricted-page-templates' ); ?>
                                <?php Helpers::tooltip( __( 'Resets every option on this page to its default. Templates and the access rules on your posts are never touched.', 'restricted-page-templates' ) ); ?>
                            </label>
                            <div><button type="button" class="rptemplates-button rptemplates-button-danger" id="rptemplates-reset-settings"><?php esc_html_e( 'Reset Settings', 'restricted-page-templates' ); ?></button></div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
        <?php
    } // End render_page()


    /**
     * Convert the submitted form into stored-format values.
     *
     * @param array $form Unslashed values from the rptemplates[] inputs.
     * @return array
     */
    private static function form_to_values( array $form ) : array {
        $values = [];

        foreach ( self::fields() as $key => $field ) {
            $raw = $form[ $key ] ?? null;

            if ( 'post_types' === $field[ 'type' ] ) {
                $enabled = [];

                foreach ( (array) $raw as $post_type => $row ) {
                    if ( is_array( $row ) && ! empty( $row[ 'enabled' ] ) ) {
                        $enabled[ $post_type ] = Access::build( sanitize_key( (string) ( $row[ 'default' ] ?? 'everyone' ) ), [], [], (array) ( $row[ 'param' ] ?? [] ) );
                    }
                }

                $values[ $key ] = $enabled;
            } elseif ( in_array( $field[ 'type' ], [ 'roles', 'contexts' ], true ) ) {
                $values[ $key ] = (array) $raw;
            } else {
                $values[ $key ] = $raw ?? '';
            }
        }

        return $values;
    } // End form_to_values()


    /**
     * AJAX: save every field on the Settings tab.
     *
     * @return void
     */
    public function ajax_save() : void {
        Helpers::verify_ajax( self::NONCE );

        $form = isset( $_POST[ 'rptemplates' ] ) && is_array( $_POST[ 'rptemplates' ] ) ? wp_unslash( $_POST[ 'rptemplates' ] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified by Helpers::verify_ajax() before this runs, and every value is sanitized individually in self::sanitize().

        self::save( self::form_to_values( $form ) );

        wp_send_json_success();
    } // End ajax_save()


    /**
     * AJAX: create a published No Access Page containing the shortcode and select it.
     *
     * @return void
     */
    public function ajax_create_page() : void {
        Helpers::verify_ajax( self::NONCE );

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __( 'Access Denied', 'restricted-page-templates' ),
            'post_content' => '<!-- wp:shortcode -->[' . Shortcode::TAG . ']<!-- /wp:shortcode -->',
        ], true );

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( $page_id->get_error_message() );
        }

        self::update( 'no_access_page', $page_id );

        wp_send_json_success( [ 'id' => $page_id, 'title' => get_the_title( $page_id ) ] );
    } // End ajax_create_page()

} // End class Settings

Settings::instance();
