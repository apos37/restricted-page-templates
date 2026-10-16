/**
 * RESTRICTED PAGE TEMPLATES: Pages
 *
 * Expands a post type on the Pages tab and loads its restricted posts, a page at a time.
 * The posts are added as rows of the same table, so their columns line up with the summary rows.
 */

( function ( $ ) {
    'use strict';

    var config = window.rpTemplatesPages || {};

    $( document ).on( 'click', '.rptemplates-pages-toggle', function () {
        var button = $( this );
        var group = button.closest( '.rptemplates-pages-group' );
        var expanded = 'true' === button.attr( 'aria-expanded' );

        button.attr( 'aria-expanded', expanded ? 'false' : 'true' );
        group.toggleClass( 'is-expanded', ! expanded );
        group.children( '.rptemplates-pages-detail' ).prop( 'hidden', expanded );

        if ( ! expanded && ! group.data( 'loaded' ) ) {
            group.data( 'loaded', true );
            load( group, 1 );
        }
    } );

    $( document ).on( 'click', '.rptemplates-pages-more', function () {
        var button = $( this );

        load( button.closest( '.rptemplates-pages-group' ), parseInt( button.data( 'paged' ), 10 ) );
    } );


    /**
     * Build a full-width row inside a post type's group.
     *
     * @param {jQuery} group
     * @param {string} className
     * @return {jQuery} The row's cell.
     */
    function messageRow( group, className ) {
        var columns = group.closest( 'table' ).find( 'thead th' ).length;
        var cell = $( '<td></td>' ).attr( 'colspan', columns );

        group.append( $( '<tr class="rptemplates-pages-detail"></tr>' ).addClass( className ).append( cell ) );

        return cell;
    } // End messageRow()


    /**
     * Load one page of restricted posts into a post type's group.
     *
     * @param {jQuery} group
     * @param {number} paged
     */
    function load( group, paged ) {
        group.children( '.rptemplates-pages-more-row' ).remove();

        var status = messageRow( group, 'rptemplates-pages-status-row' ).append( $( '<span class="rptemplates-pages-loading"></span>' ).text( config.loading ) );

        $.ajax( {
            url: config.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: { action: 'rptemplates_pages_list', nonce: config.nonce, post_type: group.data( 'post-type' ), paged: paged }
        } ).done( function ( response ) {
            status.parent().remove();

            if ( ! response.success ) {
                messageRow( group, 'rptemplates-pages-status-row' ).append( $( '<span class="rptemplates-pages-error"></span>' ).text( response.data || config.error ) );
                return;
            }

            if ( ! group.children( '.rptemplates-pages-subhead' ).length ) {
                group.append( response.data.head );
            }

            group.append( response.data.rows );

            if ( response.data.more ) {
                messageRow( group, 'rptemplates-pages-more-row' ).append( $( '<button type="button" class="rptemplates-button rptemplates-button-secondary rptemplates-pages-more"></button>' ).text( config.loadMore ).data( 'paged', paged + 1 ) );
            }
        } ).fail( function () {
            status.parent().remove();
            messageRow( group, 'rptemplates-pages-status-row' ).append( $( '<span class="rptemplates-pages-error"></span>' ).text( config.error ) );
        } );
    } // End load()

} )( jQuery );
