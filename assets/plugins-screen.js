/* Asks what to do with WP MCP's data when it is deleted later. Shown when WP MCP (or the Pro add-on) is deactivated from the Plugins screen. */
( function () {
	'use strict';
	var cfg = window.wpmcpUninstall;
	if ( ! cfg || ! cfg.plugins ) { return; }
	var busy = false;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) { node.className = cls; }
		if ( undefined !== text ) { node.textContent = text; }
		return node;
	}

	function save( file, choice ) {
		var body = new URLSearchParams();
		body.set( 'action', 'wpmcp_uninstall_choice' );
		body.set( '_ajax_nonce', cfg.nonce );
		body.set( 'plugin', file );
		body.set( 'choice', choice );
		// If saving fails the setting stays as it was (keep, unless the owner chose otherwise): never block the deactivation.
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}

	function option( name, value, checked, title, hint ) {
		var label = el( 'label', 'wpmcp-modal-choice' );
		var input = el( 'input' );
		input.type = 'radio'; input.name = name; input.value = value; input.checked = checked;
		var text = el( 'span' );
		text.appendChild( el( 'strong', '', title ) );
		text.appendChild( el( 'small', '', hint ) );
		label.appendChild( input ); label.appendChild( text );
		return label;
	}

	/** Show the question for one plugin. done() runs after the answer is saved; cancel() if the owner backs out. */
	function ask( file, done, cancel ) {
		var info = cfg.plugins[ file ];
		var previous = document.activeElement;
		var back = el( 'div', 'wpmcp-modal-back' );
		var box = el( 'div', 'wpmcp-modal' );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-labelledby', 'wpmcp-modal-title' );
		var title = el( 'h2', '', 'Deactivate ' + info.name );
		title.id = 'wpmcp-modal-title';
		box.appendChild( title );
		box.appendChild( el( 'p', '', 'If you delete ' + info.name + ' later, what should happen to its data? It holds ' + info.data + '.' ) );
		box.appendChild( option( 'wpmcp-data', 'keep', ! info.delete, 'Keep the data', 'A later install picks up where this one left off.' ) );
		box.appendChild( option( 'wpmcp-data', 'delete', info.delete, 'Delete the data with the plugin', 'This cannot be undone.' ) );
		var actions = el( 'div', 'wpmcp-modal-actions' );
		var ok = el( 'button', 'button button-primary', 'Deactivate' ); ok.type = 'button';
		var no = el( 'button', 'button', 'Cancel' ); no.type = 'button';
		actions.appendChild( ok ); actions.appendChild( no );
		box.appendChild( actions );
		back.appendChild( box );
		document.body.appendChild( back );
		var first = box.querySelector( 'input:checked' ) || ok;
		first.focus();

		function close() {
			document.removeEventListener( 'keydown', keys, true );
			if ( back.parentNode ) { back.parentNode.removeChild( back ); }
			if ( previous && previous.focus ) { previous.focus(); }
		}
		function keys( e ) {
			if ( 'Escape' === e.key ) { e.preventDefault(); close(); cancel(); return; }
			if ( 'Tab' !== e.key ) { return; }
			var items = box.querySelectorAll( 'input, button' );
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === items[ 0 ] ) { e.preventDefault(); last.focus(); }
			else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); items[ 0 ].focus(); }
		}
		document.addEventListener( 'keydown', keys, true );
		no.addEventListener( 'click', function () { close(); cancel(); } );
		back.addEventListener( 'mousedown', function ( e ) { if ( e.target === back ) { close(); cancel(); } } );
		ok.addEventListener( 'click', function () {
			var choice = box.querySelector( 'input:checked' ).value;
			ok.disabled = true; no.disabled = true;
			save( file, choice ).then( function () { close(); done(); } );
		} );
	}

	/** Ask about each plugin in turn, then go on. */
	function askAll( files, done, cancel ) {
		if ( ! files.length ) { done(); return; }
		ask( files[ 0 ], function () { askAll( files.slice( 1 ), done, cancel ); }, cancel );
	}

	// One plugin: the Deactivate link. Capture phase, so this runs before WordPress's own handlers.
	document.addEventListener( 'click', function ( e ) {
		if ( busy || e.defaultPrevented || e.button || ! e.target || ! e.target.closest ) { return; }
		var link = e.target.closest( 'tr[data-plugin] .deactivate a' );
		if ( ! link ) { return; }
		var file = link.closest( 'tr' ).getAttribute( 'data-plugin' );
		if ( ! cfg.plugins[ file ] ) { return; }
		e.preventDefault();
		e.stopPropagation();
		busy = true;
		askAll( [ file ], function () { window.location.href = link.href; }, function () { busy = false; } );
	}, true );

	// Several plugins: the bulk action.
	var form = document.getElementById( 'bulk-action-form' );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			var action = form.querySelector( 'select[name="action"]' );
			var action2 = form.querySelector( 'select[name="action2"]' );
			var chosen = ( action && action.value ) || ( action2 && action2.value );
			if ( busy || 'deactivate-selected' !== chosen ) { return; }
			var files = [];
			Array.prototype.forEach.call( form.querySelectorAll( 'input[name="checked[]"]:checked' ), function ( box ) { if ( cfg.plugins[ box.value ] ) { files.push( box.value ); } } );
			if ( ! files.length ) { return; }
			e.preventDefault();
			busy = true;
			askAll( files, function () { form.submit(); }, function () { busy = false; } );
		} );
	}
}() );
