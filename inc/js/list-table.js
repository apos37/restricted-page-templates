/**
 * RESTRICTED PAGE TEMPLATES: List table
 *
 * Fills the Quick Edit controls from the post's stored rule, read from the column's data attributes.
 */

( function ( $ ) {
    'use strict';

    if ( typeof inlineEditPost === 'undefined' || ! window.rpTemplatesFieldsApi ) {
        return;
    }

    var api = window.rpTemplatesFieldsApi;
    var originalEdit = inlineEditPost.edit;

    inlineEditPost.edit = function ( id ) {
        originalEdit.apply( this, arguments );

        var postId = 'object' === typeof id ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );

        if ( ! postId ) {
            return;
        }

        var column = $( '#post-' + postId + ' .rptemplates-column' );
        var editRow = $( '#edit-' + postId );
        var rule = parseRule( String( column.data( 'access' ) || '' ) );
        var users = column.data( 'users' ) || [];
        var select = editRow.find( 'select[name="rptemplates[access]"]' );

        select.val( rule.access );
        editRow.find( 'input[name="rptemplates[roles][]"]' ).each( function () {
            $( this ).prop( 'checked', rule.roles.indexOf( $( this ).val() ) !== -1 );
        } );

        editRow.find( 'input[name="rptemplates[param][name]"]' ).val( rule.param.name );
        editRow.find( 'select[name="rptemplates[param][operator]"]' ).val( rule.param.operator );
        editRow.find( 'input[name="rptemplates[param][value]"]' ).val( rule.param.value );

        var picker = editRow.find( '.rptemplates-picker' );
        var restrictTo = editRow.find( '.rptemplates-restrict-to' );

        picker.find( '.rptemplates-picker-selected' ).empty();
        restrictTo.children( 'input.rptemplates-kept-user' ).remove();

        users.forEach( function ( user ) {
            if ( picker.length ) {
                api.addItem( picker, user.id, user.text );
            } else {
                restrictTo.append( $( '<input type="hidden" class="rptemplates-kept-user" name="rptemplates[users][]">' ).val( user.id ) );
            }
        } );

        api.toggleRestrictions( select );
    };


    /**
     * Parse a stored rule string, matching Access::parse() in PHP.
     *
     * @param {string} raw
     * @return {Object}
     */
    function parseRule( raw ) {
        var flags = [ 'default', 'everyone', 'logged-in', 'logged-out', 'url-param' ];
        var paramTokens = { 'param:': 'name', 'op:': 'operator', 'value:': 'value' };
        var param = { name: '', operator: 'is_not', value: '' };
        var roles = [];
        var hasUsers = false;
        var tokens = raw.split( ',' ).map( function ( token ) {
            return token.trim();
        } ).filter( Boolean );

        tokens.forEach( function ( token ) {
            var prefix = Object.keys( paramTokens ).filter( function ( key ) {
                return token.indexOf( key ) === 0;
            } )[ 0 ];

            if ( flags.indexOf( token ) !== -1 ) {
                return;
            }

            if ( prefix ) {
                param[ paramTokens[ prefix ] ] = decodeURIComponent( token.substring( prefix.length ) );
            } else if ( token.indexOf( 'user:' ) === 0 ) {
                hasUsers = true;
            } else {
                roles.push( token );
            }
        } );

        var access = 'default';

        if ( tokens.indexOf( 'logged-out' ) !== -1 ) {
            access = 'logged-out';
            roles = [];
        } else if ( tokens.indexOf( 'logged-in' ) !== -1 || roles.length || hasUsers ) {
            access = 'logged-in';
        } else if ( tokens.indexOf( 'url-param' ) !== -1 ) {
            access = 'url-param';
        } else if ( tokens.indexOf( 'everyone' ) !== -1 ) {
            access = 'everyone';
        }

        return { access: access, roles: roles, param: param };
    } // End parseRule()

} )( jQuery );
