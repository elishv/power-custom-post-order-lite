/**
 * Power Custom Post Order - front-end debug helper.
 * Only enqueued when PCPO debug mode is on and the user can sort posts.
 * Reads the payload from #pcpo-debug-banner[data-pcpo-debug], logs it to the
 * console and handles the banner's close button.
 */
( function () {
	'use strict';

	function init() {
		var banner = document.getElementById( 'pcpo-debug-banner' );
		if ( ! banner ) {
			return;
		}
		try {
			var data = JSON.parse( banner.getAttribute( 'data-pcpo-debug' ) || '{}' );
			var d = data.payload || {};
			var s = d.summary || {};
			var events = d.events || [];
			if ( window.console && console.groupCollapsed ) {
				console.groupCollapsed(
					'%cPCPO DEBUG%c v' + d.version + '  •  ' + ( s.queries_marked || 0 ) + ' marked, ' + ( s.queries_skipped || 0 ) + ' skipped  •  ' + events.length + ' events',
					'background:#6c41dc;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700',
					''
				);
				console.log( 'URL:', d.url );
				console.log( 'Summary:', s );
				console.log( 'Events:', events );
				console.log( 'Admin page:', data.debugUrl );
				console.groupEnd();
			}
		} catch ( e ) {
			if ( window.console ) {
				console.error( 'PCPO DEBUG render error', e );
			}
		}

		var close = banner.querySelector( '.pcpo-debug-close' );
		if ( close ) {
			var hide = function () {
				banner.style.display = 'none';
			};
			close.addEventListener( 'click', hide );
			close.addEventListener( 'keydown', function ( ev ) {
				if ( 'Enter' === ev.key || ' ' === ev.key ) {
					ev.preventDefault();
					hide();
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
