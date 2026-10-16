/**
 * RESTRICTED PAGE TEMPLATES: Fields
 *
 * Shows role and user limits when "Logged-In Only" is chosen, the URL Parameter fields when
 * "URL Parameter" is chosen, and powers the searchable
 * user and post pickers. Events are delegated so cloned Quick Edit rows work too.
 * Pickers trigger "rptemplates:change" when an item is added or removed.
 */

( function ( $ ) {
    'use strict';

    var config = window.rpTemplatesFields || {};
    var searchTimer;
    var lastRequest;

    $( document ).on( 'change', '.rptemplates-access-select', function () {
        toggleRestrictions( $( this ) );
    } );

    $( document ).on( 'input', '.rptemplates-picker-search', function () {
        var input = $( this );

        clearTimeout( searchTimer );
        searchTimer = setTimeout( function () {
            search( input.closest( '.rptemplates-picker' ), input.val().trim() );
        }, 300 );
    } );

    $( document ).on( 'keydown', '.rptemplates-picker-search', function ( event ) {
        var results = $( this ).siblings( '.rptemplates-picker-results' );
        var items = results.children( 'li[data-id]' );
        var active = items.filter( '.is-active' );
        var index = items.index( active );

        if ( 'Enter' === event.key ) {
            event.preventDefault();

            if ( active.length ) {
                active.trigger( 'click' );
            }
        } else if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
            event.preventDefault();
            index = 'ArrowDown' === event.key ? Math.min( index + 1, items.length - 1 ) : Math.max( index - 1, 0 );
            items.removeClass( 'is-active' ).eq( index ).addClass( 'is-active' );
        } else if ( 'Escape' === event.key ) {
            results.prop( 'hidden', true );
        }
    } );

    $( document ).on( 'click', '.rptemplates-picker-results li[data-id]', function () {
        var item = $( this );
        var picker = item.closest( '.rptemplates-picker' );

        addItem( picker, item.data( 'id' ), item.text() );
        picker.find( '.rptemplates-picker-search' ).val( '' ).trigger( 'focus' );
        picker.find( '.rptemplates-picker-results' ).prop( 'hidden', true ).empty();
    } );

    $( document ).on( 'click', '.rptemplates-picker-remove', function () {
        var picker = $( this ).closest( '.rptemplates-picker' );

        $( this ).closest( 'li' ).remove();
        picker.trigger( 'rptemplates:change' );
    } );

    $( document ).on( 'click', function ( event ) {
        if ( ! $( event.target ).closest( '.rptemplates-picker' ).length ) {
            $( '.rptemplates-picker-results' ).prop( 'hidden', true );
        }
    } );


    /**
     * Show or hide the role and user limits, or the URL Parameter fields, for an access dropdown.
     *
     * @param {jQuery} select
     */
    function toggleRestrictions( select ) {
        var fields = select.closest( '.rptemplates-access-fields' );

        fields.find( '.rptemplates-restrict-to' ).prop( 'hidden', 'logged-in' !== select.val() );
        fields.find( '.rptemplates-param-fields' ).prop( 'hidden', 'url-param' !== select.val() );
    } // End toggleRestrictions()


    /**
     * Search users or posts and list the matches.
     *
     * @param {jQuery} picker
     * @param {string} term
     */
    function search( picker, term ) {
        var results = picker.find( '.rptemplates-picker-results' );
        var source = picker.data( 'source' );

        if ( term.length < 2 && ! ( 'posts' === source && /^\d+$/.test( term ) ) ) {
            results.prop( 'hidden', true ).empty();
            return;
        }

        if ( lastRequest ) {
            lastRequest.abort();
        }

        results.prop( 'hidden', false ).html( $( '<li class="is-empty"></li>' ).text( config.searching ) );

        lastRequest = $.ajax( {
            url: config.ajaxUrl,
            method: 'GET',
            dataType: 'json',
            data: { action: 'rptemplates_search_' + source, nonce: config.nonce, term: term }
        } ).done( function ( response ) {
            var selected = picker.find( '.rptemplates-picker-selected input' ).map( function () {
                return String( $( this ).val() );
            } ).get();
            var matches = response.success ? response.data.filter( function ( match ) {
                return selected.indexOf( String( match.id ) ) === -1;
            } ) : [];

            results.empty();

            if ( ! matches.length ) {
                results.append( $( '<li class="is-empty"></li>' ).text( config.noResults ) );
                return;
            }

            matches.forEach( function ( match, i ) {
                results.append( $( '<li></li>' ).attr( 'data-id', match.id ).toggleClass( 'is-active', 0 === i ).text( match.text ) );
            } );
        } );
    } // End search()


    /**
     * Add a selected item to a picker.
     *
     * @param {jQuery} picker
     * @param {number} id
     * @param {string} text
     */
    function addItem( picker, id, text ) {
        var list = picker.find( '.rptemplates-picker-selected' );

        if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
            return;
        }

        var item = $( '<li></li>' ).attr( 'data-id', id );

        item.append( $( '<span></span>' ).text( text ) );
        item.append( $( '<button type="button" class="rptemplates-picker-remove">&times;</button>' ).attr( 'aria-label', config.remove ) );
        item.append( $( '<input type="hidden">' ).attr( 'name', picker.data( 'name' ) ).val( id ) );

        list.append( item );
        picker.trigger( 'rptemplates:change' );
    } // End addItem()


    window.rpTemplatesFieldsApi = {
        addItem: addItem,
        toggleRestrictions: toggleRestrictions
    };

} )( jQuery );
