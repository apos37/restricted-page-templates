/**
 * RESTRICTED PAGE TEMPLATES: Access box
 *
 * Updates "Templates in use" as the access, roles and users change, before the post is saved,
 * and hides the unique template link when unique templates already cover every situation.
 */

( function ( $ ) {
    'use strict';

    $( document ).on( 'change rptemplates:change', '#rptemplates .rptemplates-access-select, #rptemplates .rptemplates-role-checkboxes input, #rptemplates .rptemplates-picker', update );

    $( function () {
        update();
    } );


    /**
     * Get the situations the selected access can block visitors under, matching Templates::rule_conditions().
     *
     * @param {jQuery} box
     * @param {jQuery} inUse
     * @return {Array}
     */
    function conditions( box, inUse ) {
        var access = box.find( '.rptemplates-access-select' ).val();

        if ( 'default' === access ) {
            access = String( inUse.data( 'default' ) || 'everyone' );
        } else if ( 'logged-in' === access ) {
            var limited = box.find( '.rptemplates-role-checkboxes input:checked' ).length || box.find( 'input[name="rptemplates[users][]"]' ).length;

            return limited ? [ 'login_required', 'missing_access' ] : [ 'login_required' ];
        }

        if ( 'logged-out' === access ) {
            return [ 'logout_required' ];
        }

        if ( 'url-param' === access ) {
            return [ 'param_required' ];
        }

        return 'logged-in' === access ? [ 'login_required' ] : [];
    } // End conditions()


    /**
     * Show the rows for the current access and toggle the unique template link.
     */
    function update() {
        var box = $( '#rptemplates' );
        var inUse = box.find( '.rptemplates-templates-in-use' );

        if ( ! inUse.length ) {
            return;
        }

        var active = conditions( box, inUse );
        var covered = active.length > 0;

        inUse.find( 'li[data-condition]' ).each( function () {
            var row = $( this );
            var shown = active.indexOf( row.data( 'condition' ) ) !== -1;

            row.prop( 'hidden', ! shown );

            if ( shown && '1' !== String( row.data( 'unique' ) ) ) {
                covered = false;
            }
        } );

        inUse.prop( 'hidden', ! active.length );
        inUse.find( '.rptemplates-unique-link' ).prop( 'hidden', covered );
    } // End update()

} )( jQuery );
