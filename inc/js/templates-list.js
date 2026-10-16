/**
 * RESTRICTED PAGE TEMPLATES: Templates list
 *
 * Saves the per-page choice and reloads the list.
 */

( function ( $ ) {
    'use strict';

    $( document ).on( 'change', '.rptemplates-per-page', function () {
        var url = new URL( window.location.href );

        url.searchParams.set( rpTemplatesList.key, $( this ).val() );
        url.searchParams.set( '_wpnonce', rpTemplatesList.nonce );
        url.searchParams.delete( 'paged' );

        window.location.href = url.toString();
    } );

} )( jQuery );
