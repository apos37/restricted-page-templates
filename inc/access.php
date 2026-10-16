<?php
/**
 * ACCESS
 *
 * Access rules: parsing, evaluation, the No Access redirect, and saving rules from the editor screens.
 */

namespace PluginRx\RestrictedPageTemplates;

if ( ! defined( 'ABSPATH' ) ) exit;


class Access {

    /**
     * Post meta key holding a post's rule, e.g. "logged-in, editor, user:12" or "url-param, param:id, op:is_not, value:".
     */
    public const META_KEY = '_rptemplates_access';


    /**
     * Access flags a stored rule can contain.
     */
    public const FLAGS = [ 'default', 'everyone', 'logged-in', 'logged-out', 'url-param' ];


    /**
     * Prefixes of the URL Parameter tokens in a stored rule.
     */
    public const PARAM_TOKENS = [ 'param:' => 'name', 'op:' => 'operator', 'value:' => 'value' ];


    /**
     * Nonce action used by the meta box, Quick Edit and Bulk Edit.
     */
    public const NONCE = 'rptemplates_save_access';


    /**
     * @var array Parsed rules for the current request, keyed by post ID
     */
    private static array $rules = [];


    /**
     * @var array Access results for the current request, keyed by "post_id|user_id"
     */
    private static array $results = [];


    /**
     * @var Access|null Singleton instance
     */
    private static ?Access $instance = null;


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
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );
        add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
    } // End __construct()


    /**
     * Access choices and their labels.
     *
     * @return array
     */
    public static function options() : array {
        $options = [
            'default'    => __( 'Default', 'restricted-page-templates' ),
            'everyone'   => __( 'Everyone', 'restricted-page-templates' ),
            'logged-in'  => __( 'Logged-In Only', 'restricted-page-templates' ),
            'logged-out' => __( 'Logged-Out Only', 'restricted-page-templates' ),
            'url-param'  => __( 'URL Parameter', 'restricted-page-templates' ),
        ];

        return array_merge( $options, array_intersect_key( (array) apply_filters( 'rptemplates_access_options', $options ), $options ) );
    } // End options()


    /**
     * URL Parameter conditions and their labels.
     *
     * @return array
     */
    public static function operators() : array {
        return [
            'is'           => __( 'is', 'restricted-page-templates' ),
            'is_not'       => __( 'is not', 'restricted-page-templates' ),
            'contains'     => __( 'contains', 'restricted-page-templates' ),
            'not_contains' => __( 'does not contain', 'restricted-page-templates' ),
            'starts_with'  => __( 'starts with', 'restricted-page-templates' ),
            'ends_with'    => __( 'ends with', 'restricted-page-templates' ),
            'greater_than' => __( 'is greater than', 'restricted-page-templates' ),
            'less_than'    => __( 'is less than', 'restricted-page-templates' ),
        ];
    } // End operators()


    /**
     * Empty URL Parameter requirement.
     *
     * @return array [ name, operator, value ]
     */
    public static function param_defaults() : array {
        return [ 'name' => '', 'operator' => 'is_not', 'value' => '' ];
    } // End param_defaults()


    /**
     * Sanitize a URL Parameter requirement.
     *
     * @param array $param
     * @return array [ name, operator, value ]
     */
    public static function sanitize_param( array $param ) : array {
        $operator = sanitize_key( (string) ( $param[ 'operator' ] ?? '' ) );

        return [
            'name'     => sanitize_key( (string) ( $param[ 'name' ] ?? '' ) ),
            'operator' => array_key_exists( $operator, self::operators() ) ? $operator : 'is_not',
            'value'    => sanitize_text_field( (string) ( $param[ 'value' ] ?? '' ) ),
        ];
    } // End sanitize_param()


    /**
     * Check whether a URL Parameter requirement matches a query string. A missing parameter counts as empty,
     * and a requirement without a parameter name never matches.
     *
     * @param array $param
     * @param array $query_args Sanitized key => value pairs.
     * @return bool
     */
    public static function param_matches( array $param, array $query_args ) : bool {
        if ( '' === ( $param[ 'name' ] ?? '' ) ) {
            return false;
        }

        $actual = strtolower( (string) ( $query_args[ $param[ 'name' ] ] ?? '' ) );
        $expected = strtolower( (string) ( $param[ 'value' ] ?? '' ) );

        switch ( $param[ 'operator' ] ?? 'is_not' ) {
            case 'is':
                return $actual === $expected;

            case 'contains':
                return str_contains( $actual, $expected );

            case 'not_contains':
                return ! str_contains( $actual, $expected );

            case 'starts_with':
                return str_starts_with( $actual, $expected );

            case 'ends_with':
                return str_ends_with( $actual, $expected );

            case 'greater_than':
                return is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected;

            case 'less_than':
                return is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected;
        }

        return $actual !== $expected;
    } // End param_matches()


    /**
     * Describe a URL Parameter requirement, e.g. "id is not (empty)".
     *
     * @param array $param
     * @return string
     */
    public static function describe_param( array $param ) : string {
        if ( '' === ( $param[ 'name' ] ?? '' ) ) {
            return __( 'no parameter set', 'restricted-page-templates' );
        }

        $value = '' === $param[ 'value' ] ? __( '(empty)', 'restricted-page-templates' ) : '"' . $param[ 'value' ] . '"';

        return $param[ 'name' ] . ' ' . ( self::operators()[ $param[ 'operator' ] ] ?? '' ) . ' ' . $value;
    } // End describe_param()


    /**
     * Roles that can be chosen in access controls.
     *
     * @return array Associative array of slug => label.
     */
    public static function selectable_roles() : array {
        return array_intersect_key( Helpers::all_roles(), array_flip( (array) Settings::get( 'roles' ) ) );
    } // End selectable_roles()


    /**
     * Check whether access controls are enabled for a post type.
     *
     * @param string $post_type
     * @return bool
     */
    public static function is_enabled( string $post_type ) : bool {
        return isset( Settings::get( 'post_types' )[ $post_type ] );
    } // End is_enabled()


    /**
     * Get the default rule for a post type.
     *
     * @param string $post_type
     * @return array [ access, roles, users ], plus param for URL Parameter access.
     */
    public static function post_type_rule( string $post_type ) : array {
        $rule = self::parse( (string) ( Settings::get( 'post_types' )[ $post_type ] ?? 'everyone' ) );

        return 'default' === $rule[ 'access' ] ? [ 'access' => 'everyone', 'roles' => [], 'users' => [] ] : $rule;
    } // End post_type_rule()


    /**
     * Get the default access for a post type.
     *
     * @param string $post_type
     * @return string
     */
    public static function post_type_default( string $post_type ) : string {
        return self::post_type_rule( $post_type )[ 'access' ];
    } // End post_type_default()


    /**
     * Parse a stored rule string.
     *
     * @param string $raw
     * @return array [ access, roles, users ], plus param for URL Parameter access.
     */
    public static function parse( string $raw ) : array {
        $tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $flags = [];
        $roles = [];
        $users = [];
        $param = [];

        foreach ( $tokens as $token ) {
            $param_key = self::param_token_key( $token );

            if ( in_array( $token, self::FLAGS, true ) ) {
                $flags[ $token ] = true;
            } elseif ( '' !== $param_key ) {
                $param[ self::PARAM_TOKENS[ $param_key ] ] = rawurldecode( substr( $token, strlen( $param_key ) ) );
            } elseif ( str_starts_with( $token, 'user:' ) ) {
                $users[] = absint( substr( $token, 5 ) );
            } else {
                $roles[] = sanitize_key( $token );
            }
        }

        $users = array_values( array_unique( array_filter( $users ) ) );
        $roles = array_values( array_unique( array_filter( $roles ) ) );

        if ( isset( $flags[ 'logged-out' ] ) ) {
            return [ 'access' => 'logged-out', 'roles' => [], 'users' => [] ];
        }

        if ( isset( $flags[ 'logged-in' ] ) || ! empty( $roles ) || ! empty( $users ) ) {
            return [ 'access' => 'logged-in', 'roles' => $roles, 'users' => $users ];
        }

        if ( isset( $flags[ 'url-param' ] ) ) {
            return [ 'access' => 'url-param', 'roles' => [], 'users' => [], 'param' => self::sanitize_param( $param ) ];
        }

        return [ 'access' => isset( $flags[ 'everyone' ] ) ? 'everyone' : 'default', 'roles' => [], 'users' => [] ];
    } // End parse()


    /**
     * Get the URL Parameter prefix a stored token starts with.
     *
     * @param string $token
     * @return string The prefix, or an empty string.
     */
    private static function param_token_key( string $token ) : string {
        foreach ( array_keys( self::PARAM_TOKENS ) as $prefix ) {
            if ( str_starts_with( $token, $prefix ) ) {
                return $prefix;
            }
        }

        return '';
    } // End param_token_key()


    /**
     * Build a stored rule string.
     *
     * @param string $access
     * @param array  $roles
     * @param array  $users
     * @param array  $param  Used for URL Parameter access: [ name, operator, value ].
     * @return string
     */
    public static function build( string $access, array $roles = [], array $users = [], array $param = [] ) : string {
        if ( 'url-param' === $access ) {
            $param = self::sanitize_param( $param );

            return implode( ', ', [ 'url-param', 'param:' . $param[ 'name' ], 'op:' . $param[ 'operator' ], 'value:' . rawurlencode( $param[ 'value' ] ) ] );
        }

        if ( 'logged-in' !== $access ) {
            return $access;
        }

        $users = array_map( fn( $id ) => 'user:' . absint( $id ), $users );

        return implode( ', ', array_merge( [ 'logged-in' ], $roles, $users ) );
    } // End build()


    /**
     * Get the effective rule for a post, with the post type default applied.
     *
     * @param int $post_id
     * @return array [ access, roles, users, source ], plus param for URL Parameter access.
     */
    public static function get_rule( int $post_id ) : array {
        if ( isset( self::$rules[ $post_id ] ) ) {
            return self::$rules[ $post_id ];
        }

        $post_type = (string) get_post_type( $post_id );

        if ( '' === $post_type || ! self::is_enabled( $post_type ) ) {
            $rule = [ 'access' => 'everyone', 'roles' => [], 'users' => [], 'source' => 'disabled' ];
        } else {
            $rule = self::parse( (string) get_post_meta( $post_id, self::META_KEY, true ) );
            $rule[ 'source' ] = 'post';

            if ( 'default' === $rule[ 'access' ] ) {
                $rule = array_merge( self::post_type_rule( $post_type ), [ 'source' => 'post_type' ] );
            }
        }

        self::$rules[ $post_id ] = (array) apply_filters( 'rptemplates_access_rule', $rule, $post_id );

        return self::$rules[ $post_id ];
    } // End get_rule()


    /**
     * Check whether a post is the No Access Page, which is always viewable.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_no_access_page( int $post_id ) : bool {
        return $post_id > 0 && (int) Settings::get( 'no_access_page' ) === $post_id;
    } // End is_no_access_page()


    /**
     * Check whether a user skips every restriction.
     *
     * @param int $user_id
     * @return bool
     */
    public static function user_bypasses( int $user_id ) : bool {
        static $cache = [];

        if ( ! isset( $cache[ $user_id ] ) ) {
            $bypass = $user_id > 0 && Settings::get( 'admin_bypass' ) && user_can( $user_id, 'manage_options' );
            $cache[ $user_id ] = (bool) apply_filters( 'rptemplates_bypass', $bypass, $user_id );
        }

        return $cache[ $user_id ];
    } // End user_bypasses()


    /**
     * Get a user's roles.
     *
     * @param int $user_id
     * @return array
     */
    private static function user_roles( int $user_id ) : array {
        static $cache = [];

        if ( ! isset( $cache[ $user_id ] ) ) {
            $user = $user_id ? get_userdata( $user_id ) : false;
            $cache[ $user_id ] = $user ? (array) $user->roles : [];
        }

        return $cache[ $user_id ];
    } // End user_roles()


    /**
     * Evaluate a rule for a user, without filters or bypass checks.
     *
     * @param array $rule
     * @param int   $user_id    0 for a logged-out visitor.
     * @param array $query_args The visitor's query string, for URL Parameter access. Empty for a plain link.
     * @return bool
     */
    public static function evaluate( array $rule, int $user_id, array $query_args = [] ) : bool {
        $access = $rule[ 'access' ] ?? 'everyone';

        if ( 'url-param' === $access ) {
            return self::param_matches( (array) ( $rule[ 'param' ] ?? [] ), $query_args );
        }

        if ( 'logged-out' === $access ) {
            return 0 === $user_id;
        }

        if ( 'logged-in' !== $access ) {
            return true;
        }

        if ( 0 === $user_id ) {
            return false;
        }

        if ( empty( $rule[ 'roles' ] ) && empty( $rule[ 'users' ] ) ) {
            return true;
        }

        return in_array( $user_id, $rule[ 'users' ] ?? [], true ) || ! empty( array_intersect( $rule[ 'roles' ] ?? [], self::user_roles( $user_id ) ) );
    } // End evaluate()


    /**
     * Check whether a user can view a post.
     *
     * @param int        $post_id
     * @param int|null   $user_id    Defaults to the current user.
     * @param array|null $query_args The visitor's query string, for URL Parameter access. Null checks a plain link without one.
     * @return bool
     */
    public static function user_has_access( int $post_id, ?int $user_id = null, ?array $query_args = null ) : bool {
        $user_id ??= get_current_user_id();
        $query_args ??= [];
        $key = $post_id . '|' . $user_id . ( empty( $query_args ) ? '' : '|' . md5( (string) wp_json_encode( $query_args ) ) );

        if ( isset( self::$results[ $key ] ) ) {
            return self::$results[ $key ];
        }

        $rule = self::get_rule( $post_id );
        $has_access = self::is_no_access_page( $post_id ) || self::user_bypasses( $user_id ) || self::evaluate( $rule, $user_id, $query_args );

        self::$results[ $key ] = (bool) apply_filters( 'rptemplates_user_has_access', $has_access, $post_id, $user_id, $rule, $query_args );

        return self::$results[ $key ];
    } // End user_has_access()


    /**
     * Get why a user cannot view a post, matching the template conditions.
     *
     * @param int        $post_id
     * @param int|null   $user_id
     * @param array|null $query_args The visitor's query string, for URL Parameter access.
     * @return string Empty when the user has access, otherwise login_required, missing_access, logout_required or param_required.
     */
    public static function denial_reason( int $post_id, ?int $user_id = null, ?array $query_args = null ) : string {
        $user_id ??= get_current_user_id();

        if ( self::user_has_access( $post_id, $user_id, $query_args ) ) {
            return '';
        }

        $access = self::get_rule( $post_id )[ 'access' ];

        if ( 'url-param' === $access ) {
            return 'param_required';
        }

        if ( 'logged-out' === $access ) {
            return 'logout_required';
        }

        return $user_id ? 'missing_access' : 'login_required';
    } // End denial_reason()


    /**
     * Get a readable summary of a parsed rule, e.g. "Logged-In Only: Editor" or "URL Parameter: id is not (empty)".
     *
     * @param array $rule
     * @return string
     */
    public static function describe_rule( array $rule ) : string {
        $label = self::options()[ $rule[ 'access' ] ] ?? $rule[ 'access' ];

        if ( 'url-param' === $rule[ 'access' ] ) {
            return $label . ': ' . self::describe_param( (array) ( $rule[ 'param' ] ?? [] ) );
        }

        $roles = Helpers::all_roles();
        $limits = array_merge( array_map( fn( $role ) => $roles[ $role ] ?? $role, $rule[ 'roles' ] ?? [] ), array_values( Helpers::user_labels( $rule[ 'users' ] ?? [] ) ) );

        return empty( $limits ) ? $label : $label . ': ' . implode( ', ', $limits );
    } // End describe_rule()


    /**
     * Get a readable summary of a post's stored rule.
     *
     * @param int $post_id
     * @return string
     */
    public static function describe( int $post_id ) : string {
        $rule = self::parse( (string) get_post_meta( $post_id, self::META_KEY, true ) );

        if ( 'default' === $rule[ 'access' ] ) {
            /* translators: %s: The post type's default access, e.g. "Everyone". */
            return sprintf( __( 'Default (%s)', 'restricted-page-templates' ), self::describe_rule( self::post_type_rule( (string) get_post_type( $post_id ) ) ) );
        }

        return self::describe_rule( $rule );
    } // End describe()


    /**
     * Redirect visitors who cannot view the current singular content to the No Access Page.
     *
     * @return void
     */
    public function maybe_redirect() : void {
        if ( is_home() && ! is_front_page() ) {
            $post_id = (int) get_option( 'page_for_posts' );
        } elseif ( is_singular() ) {
            $post_id = get_queried_object_id();
        } else {
            return;
        }

        $page_id = (int) Settings::get( 'no_access_page' );

        if ( ! $post_id || ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
            return;
        }

        $query_args = Helpers::carry_query_args();
        $reason = self::denial_reason( $post_id, null, $query_args );

        if ( '' === $reason ) {
            return;
        }

        $url = add_query_arg( urlencode_deep( array_merge( $query_args, [ 'no_access' => $post_id ] ) ), get_permalink( $page_id ) );
        $url = apply_filters( 'rptemplates_redirect_url', $url, $post_id, $reason );

        do_action( 'rptemplates_before_redirect', $post_id, $reason );

        nocache_headers();
        wp_safe_redirect( $url, 302, 'Restricted Page Templates' );
        exit;
    } // End maybe_redirect()


    /**
     * Save a rule submitted from the meta box, Quick Edit or Bulk Edit.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @return void
     */
    public function save_post( int $post_id, \WP_Post $post ) : void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! self::is_enabled( $post->post_type ) ) {
            return;
        }

        $is_bulk = isset( $_REQUEST[ 'bulk_edit' ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which nonce is verified next.
        $field = $is_bulk ? 'rptemplates_bulk' : 'rptemplates';
        $nonce = isset( $_REQUEST[ $field . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field . '_nonce' ] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( $is_bulk ) {
            $targets = isset( $_REQUEST[ 'post' ] ) ? wp_parse_id_list( wp_unslash( $_REQUEST[ 'post' ] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by wp_parse_id_list().
        } else {
            $targets = isset( $_REQUEST[ 'post_ID' ] ) ? [ absint( $_REQUEST[ 'post_ID' ] ) ] : [];
        }

        if ( ! in_array( $post_id, $targets, true ) ) {
            return;
        }

        $data = isset( $_REQUEST[ $field ] ) && is_array( $_REQUEST[ $field ] ) ? wp_unslash( $_REQUEST[ $field ] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in self::set().
        $access = sanitize_key( $data[ 'access' ] ?? '' );

        if ( ! array_key_exists( $access, self::options() ) ) {
            return;
        }

        self::set( $post_id, $access, (array) ( $data[ 'roles' ] ?? [] ), (array) ( $data[ 'users' ] ?? [] ), (array) ( $data[ 'param' ] ?? [] ) );
    } // End save_post()


    /**
     * Store a rule on a post.
     *
     * @param int    $post_id
     * @param string $access default|everyone|logged-in|logged-out|url-param
     * @param array  $roles
     * @param array  $users
     * @param array  $param  Used for URL Parameter access: [ name, operator, value ].
     * @return void
     */
    public static function set( int $post_id, string $access, array $roles = [], array $users = [], array $param = [] ) : void {
        $roles = array_values( array_intersect( array_map( 'sanitize_key', $roles ), array_keys( Helpers::all_roles() ) ) );
        $users = array_values( array_unique( array_filter( array_map( 'absint', $users ) ) ) );

        if ( 'default' === $access ) {
            delete_post_meta( $post_id, self::META_KEY );
            $raw = '';
        } else {
            $raw = self::build( $access, $roles, $users, $param );
            update_post_meta( $post_id, self::META_KEY, $raw );
        }

        unset( self::$rules[ $post_id ] );
        self::$results = [];

        do_action( 'rptemplates_access_updated', $post_id, $raw );
    } // End set()

} // End class Access

Access::instance();
