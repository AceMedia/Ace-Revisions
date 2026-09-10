/**
 * Admin bootstrap: sidebar tabs + sub-navigation, guide panels, the shared SaveBar.
 *
 * @package Ace_Revisions
 */

import SaveBar from './components/SaveBar.js';

( function ( $ ) {
	'use strict';

	const CONFIG = window.ace_revisions_admin || {};
	const KEY = CONFIG.storage_key || 'ace';

	const store = ( k, v ) => {
		try {
			if ( v === undefined ) {
				return localStorage.getItem( `${ KEY }_${ k }` );
			}
			localStorage.setItem( `${ KEY }_${ k }`, v );
		} catch ( e ) {}
		return null;
	};

	function updateSubnav( tabId ) {
		const $blocks = $( '.ace-redis-sidebar .ace-tab-subnav' );
		$blocks.removeClass( 'active' );
		$blocks.filter( `[data-tab="${ tabId }"]` ).addClass( 'active' );
	}

	function setActiveSubtab( tabId, groupId ) {
		const $links = $( '.ace-redis-sidebar .ace-subtab-link' ).removeClass( 'is-active' );
		if ( tabId && groupId ) {
			$links.filter( `[data-target-tab="${ tabId }"][data-target-group="${ groupId }"]` ).addClass( 'is-active' );
		}
	}

	function activateTab( tabId ) {
		if ( ! tabId || ! document.getElementById( tabId ) ) {
			return false;
		}
		$( '.ace-redis-sidebar .nav-tab' ).removeClass( 'nav-tab-active' );
		$( `.ace-redis-sidebar .nav-tab[href="#${ tabId }"]` ).addClass( 'nav-tab-active' );
		$( '.tab-content' ).removeClass( 'active' );
		$( `#${ tabId }` ).addClass( 'active' );
		updateSubnav( tabId );
		setActiveSubtab( null );
		store( 'tab', tabId );
		$( '.ace-redis-save-bar' ).toggle( ! $( `#${ tabId }` ).hasClass( 'ace-custom-tab' ) );
		document.dispatchEvent( new CustomEvent( 'ace:tab', { detail: { tabId } } ) );
		return true;
	}

	function activateTabAndGroup( tabId, groupId ) {
		if ( ! activateTab( tabId ) ) {
			return;
		}
		if ( groupId ) {
			const group = document.getElementById( groupId );
			if ( group ) {
				group.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			setActiveSubtab( tabId, groupId );
		}
		history.replaceState( null, '', `#${ tabId }${ groupId ? '/' + groupId : '' }` );
	}

	function initGuidePanels() {
		$( '.ace-guide-panel' ).each( function () {
			const $panel = $( this );
			const id = $panel.data( 'guide' );
			const hidden = store( `guide_${ id }` ) === '0';
			const apply = ( show ) => {
				$panel.toggleClass( 'is-collapsed', ! show );
				$panel.find( '.ace-guide-panel__toggle' ).attr( 'aria-expanded', show ? 'true' : 'false' );
				$panel.find( '.ace-guide-panel__toggle-text' ).text( show ? 'Hide guide' : 'Show guide' );
				$panel.find( '.ace-guide-panel__toggle .dashicons' ).toggleClass( 'dashicons-visibility', show ).toggleClass( 'dashicons-hidden', ! show );
			};
			apply( ! hidden );
			$panel.on( 'click', '.ace-guide-panel__toggle', () => {
				const show = $panel.hasClass( 'is-collapsed' );
				store( `guide_${ id }`, show ? '1' : '0' );
				apply( show );
			} );
		} );
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
			activateTab( href.slice( 1 ) );
			history.replaceState( null, '', href );
		} );

		$( document ).on( 'click', '.ace-subtab-link', function ( e ) {
			e.preventDefault();
			activateTabAndGroup( $( this ).data( 'target-tab' ), $( this ).data( 'target-group' ) );
		} );

		const hash = window.location.hash.replace( '#', '' );
		if ( hash ) {
			const [ tabId, groupId ] = hash.split( '/' );
			activateTabAndGroup( tabId, groupId );
		} else {
			activateTab( store( 'tab' ) || $( '.ace-redis-sidebar .nav-tab' ).first().attr( 'href' ).slice( 1 ) );
		}

		initGuidePanels();

		window.addEventListener( 'hashchange', () => {
			const [ tabId, groupId ] = window.location.hash.replace( '#', '' ).split( '/' );
			activateTabAndGroup( tabId, groupId );
		} );

		if ( $( '#ace-redis-settings-form' ).length ) {
			window.Ace_RevisionsSaveBar = new SaveBar( {
				containerSelector: '#ace-redis-settings-form',
				ajaxUrl: CONFIG.ajax_url || window.ajaxurl,
				action: CONFIG.save_action,
				nonce: CONFIG.nonce,
				storageKey: CONFIG.storage_key,
			} );
			$( '.ace-redis-save-bar' ).toggle( ! $( '.tab-content.active' ).hasClass( 'ace-custom-tab' ) );
		}
	} );

	window.AceAdmin = { activateTab, activateTabAndGroup };
} )( window.jQuery );
