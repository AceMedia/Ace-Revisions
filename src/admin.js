/**
 * Admin bootstrap: tabs + the shared fixed SaveBar.
 *
 * @package Ace_Revisions
 */

import SaveBar from './components/SaveBar.js';

( function ( $ ) {
	'use strict';

	const CONFIG = window.ace_revisions_admin || {};

	function activateTab( target ) {
		if ( ! target || target.charAt( 0 ) !== '#' || ! $( target ).length ) {
			return;
		}
		$( '.ace-redis-sidebar .nav-tab' ).removeClass( 'nav-tab-active' );
		$( `.ace-redis-sidebar .nav-tab[href="${ target }"]` ).addClass( 'nav-tab-active' );
		$( '.tab-content' ).removeClass( 'active' );
		$( target ).addClass( 'active' );
		try {
			localStorage.setItem( `${ CONFIG.storage_key || 'ace' }_tab`, target );
		} catch ( e ) {}
	}

	$( function () {
		if ( ! $( '.ace-redis-settings' ).length ) {
			return;
		}

		$( '.ace-redis-sidebar' ).on( 'click', '.nav-tab', function ( e ) {
			const href = $( this ).attr( 'href' ) || '';
			if ( href.charAt( 0 ) !== '#' ) {
				return;
			}
			e.preventDefault();
			activateTab( href );
			history.replaceState( null, '', href );
		} );

		let initial = window.location.hash;
		if ( ! initial ) {
			try {
				initial = localStorage.getItem( `${ CONFIG.storage_key || 'ace' }_tab` ) || '';
			} catch ( e ) {}
		}
		activateTab( initial );

		if ( $( '#ace-redis-settings-form' ).length ) {
			window.Ace_RevisionsSaveBar = new SaveBar( {
				containerSelector: '#ace-redis-settings-form',
				ajaxUrl: CONFIG.ajax_url || window.ajaxurl,
				action: CONFIG.save_action,
				nonce: CONFIG.nonce,
				storageKey: CONFIG.storage_key,
			} );
		}
	} );
} )( window.jQuery );
