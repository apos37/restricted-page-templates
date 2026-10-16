<?php
/**
 * HELPERS
 *
 * Shared utility functions and reusable field markup used across the plugin.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Helpers {

    /**
     * Check whether the current admin screen is one of the plugin's tabbed screens.
     *
     * @return bool
     */
    public static function is_plugin_screen() : bool {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        if ( ! $screen ) {
            return false;
        }

        return in_array( $screen->id, Menu::screen_ids(), true );
    } // End is_plugin_screen()


    /**
     * Verify an AJAX request's nonce and capability, ending the request on failure.
     *
     * @param string $nonce_action
     * @param string $capability
     * @return void
     */
    public static function verify_ajax( string $nonce_action, string $capability = 'manage_options' ) : void {
        check_ajax_referer( $nonce_action, 'nonce' );

        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( __( 'You do not have permission to do that.', 'restricted-page-templates' ), 403 );
        }
    } // End verify_ajax()


    /**
     * Get the visitor's query string arguments to carry between the restricted page and the No Access Page.
     * WordPress query vars, such as page_id, are left out so they never change which page loads.
     *
     * @return array Sanitized key => value pairs.
     */
    public static function carry_query_args() : array {
        global $wp;

        $query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only carryover of the visitor's query string; each key and value is sanitized below.
        $skip = array_merge( [ 'no_access', 'cb' ], isset( $wp->public_query_vars ) ? (array) $wp->public_query_vars : [] );
        $carry = [];

        foreach ( (array) $query as $key => $value ) {
            $key = sanitize_key( $key );

            if ( '' !== $key && is_scalar( $value ) && ! in_array( $key, $skip, true ) ) {
                $carry[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return (array) apply_filters( 'rptemplates_carry_query_args', $carry );
    } // End carry_query_args()


    /**
     * Get all public post types that can be restricted.
     *
     * @return array Associative array of slug => WP_Post_Type.
     */
    public static function public_post_types() : array {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $types[ 'attachment' ] );

        return $types;
    } // End public_post_types()


    /**
     * Get every role on the site with translated labels.
     *
     * @return array Associative array of slug => label.
     */
    public static function all_roles() : array {
        static $roles = null;

        if ( null !== $roles ) {
            return $roles;
        }

        $roles = [];

        foreach ( wp_roles()->role_names as $slug => $name ) {
            $roles[ $slug ] = translate_user_role( $name );
        }

        return $roles;
    } // End all_roles()


    /**
     * Get display names for a list of user IDs.
     *
     * @param array $user_ids
     * @return array Associative array of ID => display name.
     */
    public static function user_labels( array $user_ids ) : array {
        $user_ids = array_filter( array_map( 'absint', $user_ids ) );

        if ( empty( $user_ids ) ) {
            return [];
        }

        $users = get_users( [
            'include' => $user_ids,
            'fields'  => [ 'ID', 'display_name' ],
            'orderby' => 'include',
        ] );

        $labels = [];

        foreach ( $users as $user ) {
            $labels[ (int) $user->ID ] = $user->display_name;
        }

        return $labels;
    } // End user_labels()


    /**
     * Render a tooltip icon with hover text, matching Admin Help Docs' pattern.
     *
     * @param string $text
     * @return void
     */
    public static function tooltip( string $text ) : void {
        if ( '' === $text ) {
            return;
        }
        ?>
        <span class="rptemplates-tooltip" tabindex="0">
            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
            <span class="rptemplates-tooltip-text" role="tooltip"><?php echo wp_kses_post( $text ); ?></span>
        </span>
        <?php
    } // End tooltip()


    /**
     * Render a searchable picker that stores selected IDs as hidden inputs.
     *
     * @param string $source   users|posts
     * @param string $name     Input name, including the trailing [].
     * @param array  $selected Associative array of ID => label.
     * @param string $placeholder
     * @return void
     */
    public static function render_picker( string $source, string $name, array $selected, string $placeholder ) : void {
        ?>
        <div class="rptemplates-picker" data-source="<?php echo esc_attr( $source ); ?>" data-name="<?php echo esc_attr( $name ); ?>">
            <ul class="rptemplates-picker-selected">
                <?php foreach ( $selected as $id => $label ) : ?>
                    <li data-id="<?php echo esc_attr( $id ); ?>">
                        <span><?php echo esc_html( $label ); ?></span>
                        <button type="button" class="rptemplates-picker-remove" aria-label="<?php esc_attr_e( 'Remove', 'restricted-page-templates' ); ?>">&times;</button>
                        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $id ); ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <input type="search" class="rptemplates-picker-search" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off">
            <ul class="rptemplates-picker-results" hidden></ul>
        </div>
        <?php
    } // End render_picker()


    /**
     * Render the access controls shared by the meta box, Quick Edit and Bulk Edit.
     *
     * @param string $name          Base input name.
     * @param array  $rule          Parsed rule from Access::parse().
     * @param string $default_label Label of the post type default, shown on the Default option.
     * @param bool   $bulk          Whether to include a "No Change" option.
     * @return void
     */
    public static function render_access_fields( string $name, array $rule, string $default_label, bool $bulk = false ) : void {
        $options = Access::options();
        $roles = Access::selectable_roles();
        $users = Helpers::user_labels( $rule[ 'users' ] );
        $restricted = 'logged-in' === $rule[ 'access' ];
        ?>
        <div class="rptemplates-access-fields">
            <label class="rptemplates-access-label">
                <span><?php esc_html_e( 'Who should have access?', 'restricted-page-templates' ); ?></span>
                <select name="<?php echo esc_attr( $name ); ?>[access]" class="rptemplates-access-select">
                    <?php if ( $bulk ) : ?>
                        <option value=""><?php esc_html_e( '— No Change —', 'restricted-page-templates' ); ?></option>
                    <?php endif; ?>
                    <?php foreach ( $options as $key => $label ) : ?>
                        <?php
                        if ( 'default' === $key && '' !== $default_label ) {
                            /* translators: %s: The post type's default access label, e.g. "Everyone". */
                            $label = sprintf( __( 'Default (%s)', 'restricted-page-templates' ), $default_label );
                        }
                        ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( ! $bulk && $rule[ 'access' ] === $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="rptemplates-restrict-to"<?php echo $restricted ? '' : ' hidden'; ?>>
                <?php if ( ! empty( $roles ) ) : ?>
                    <span class="rptemplates-sub-label"><?php esc_html_e( 'Limit to roles', 'restricted-page-templates' ); ?></span>
                    <div class="rptemplates-role-checkboxes">
                        <?php foreach ( $roles as $slug => $label ) : ?>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule[ 'roles' ], true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( Pickers::can_search_users() ) : ?>
                    <span class="rptemplates-sub-label"><?php esc_html_e( 'Limit to specific users', 'restricted-page-templates' ); ?></span>
                    <?php self::render_picker( 'users', $name . '[users][]', $users, __( 'Search users…', 'restricted-page-templates' ) ); ?>
                <?php else : ?>
                    <?php foreach ( array_keys( $users ) as $user_id ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $name ); ?>[users][]" value="<?php echo esc_attr( $user_id ); ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <p class="rptemplates-hint"><?php esc_html_e( 'All logged-in users have access unless you choose roles or users.', 'restricted-page-templates' ); ?></p>
            </div>

            <?php self::render_param_fields( $name . '[param]', (array) ( $rule[ 'param' ] ?? [] ), 'url-param' === $rule[ 'access' ] ); ?>
        </div>
        <?php
    } // End render_access_fields()


    /**
     * Render the URL Parameter fields: parameter name, condition and value.
     *
     * @param string $name  Base input name.
     * @param array  $param Stored requirement: [ name, operator, value ].
     * @param bool   $shown Whether the fields start visible.
     * @return void
     */
    public static function render_param_fields( string $name, array $param, bool $shown ) : void {
        $param = array_merge( Access::param_defaults(), $param );
        ?>
        <div class="rptemplates-param-fields"<?php echo $shown ? '' : ' hidden'; ?>>
            <label>
                <span class="rptemplates-sub-label"><?php esc_html_e( 'Parameter', 'restricted-page-templates' ); ?></span>
                <input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $param[ 'name' ] ); ?>" placeholder="<?php esc_attr_e( 'e.g. id', 'restricted-page-templates' ); ?>" autocomplete="off">
            </label>
            <label>
                <span class="rptemplates-sub-label"><?php esc_html_e( 'Condition', 'restricted-page-templates' ); ?></span>
                <select name="<?php echo esc_attr( $name ); ?>[operator]">
                    <?php foreach ( Access::operators() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $param[ 'operator' ], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="rptemplates-sub-label"><?php esc_html_e( 'Value', 'restricted-page-templates' ); ?></span>
                <input type="text" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $param[ 'value' ] ); ?>" placeholder="<?php esc_attr_e( 'Leave blank for empty', 'restricted-page-templates' ); ?>" autocomplete="off">
            </label>
            <p class="rptemplates-hint"><?php esc_html_e( 'Visitors can view the content when the link they arrive with matches. A missing parameter counts as empty, so "id is not" with no value requires an id, and "id is" with no value allows only links without one. WordPress query names, such as p, s and page_id, can\'t be used.', 'restricted-page-templates' ); ?></p>
        </div>
        <?php
    } // End render_param_fields()

} // End class Helpers
