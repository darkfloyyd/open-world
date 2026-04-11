( function () {
	'use strict';

	if ( typeof owEditor === 'undefined' ) return;

	const DEBOUNCE = 900;
	const i18n = owEditor.i18n || {};

	function parseWarningPayload( value ) {
		if ( ! value ) return null;

		try {
			return JSON.parse( value );
		} catch ( e ) {
			return null;
		}
	}

	function createWarningBadge( id ) {
		const badge = document.createElement( 'span' );
		badge.className = 'ow-warning-badge';
		badge.dataset.id = id;
		badge.title = i18n.warning_title || 'Warning';
		badge.textContent = '⚠';
		return badge;
	}

	function createWarningDetails( id, messages ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'ow-warning-details';
		wrap.dataset.id = id;
		wrap.style.display = 'none';

		( messages || [] ).forEach( function ( message ) {
			const line = document.createElement( 'div' );
			line.className = 'ow-warning-message';
			line.textContent = message;
			wrap.appendChild( line );
		} );

		const actions = document.createElement( 'div' );
		actions.className = 'ow-warning-actions';

		const ignoreBtn = document.createElement( 'button' );
		ignoreBtn.className = 'ow-ignore-btn button button-small';
		ignoreBtn.dataset.id = id;
		ignoreBtn.textContent = i18n.ignore || 'Ignore';
		actions.appendChild( ignoreBtn );

		const retranslateBtn = document.createElement( 'button' );
		retranslateBtn.className = 'ow-retranslate-btn button button-small';
		retranslateBtn.dataset.id = id;
		retranslateBtn.textContent = i18n.retranslate || 'Re-translate';
		actions.appendChild( retranslateBtn );

		wrap.appendChild( actions );
		return wrap;
	}

	function updateWarningUI( row, warning, messages ) {
		if ( ! row ) return;

		const id = row.dataset.id;
		const statusCell = row.querySelector( '.ow-col-status' );
		if ( ! statusCell ) return;

		const existingBadge = statusCell.querySelector( '.ow-warning-badge' );
		const existingDetails = statusCell.querySelector( '.ow-warning-details' );
		const hasWarning = warning && ! warning.ignored && ( warning.placeholder || warning.html );

		row.dataset.warning = JSON.stringify( warning || {} );

		if ( ! hasWarning ) {
			if ( existingBadge ) existingBadge.remove();
			if ( existingDetails ) existingDetails.remove();
			return;
		}

		let badge = existingBadge;
		if ( ! badge ) {
			badge = createWarningBadge( id );
			statusCell.appendChild( badge );
		}

		if ( existingDetails ) {
			existingDetails.remove();
		}

		statusCell.appendChild( createWarningDetails( id, messages ) );
	}

	function closeAllWarningDetails() {
		document.querySelectorAll( '.ow-warning-details' ).forEach( function ( detail ) {
			detail.style.display = 'none';
		} );
	}

	async function postAction( payload ) {
		const body = new URLSearchParams( {
			...payload,
			_ajax_nonce: owEditor.nonce,
		} );

		const resp = await fetch( owEditor.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} );

		return resp.json();
	}

	document.querySelectorAll( '.ow-remove-form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			const btn  = form.querySelector( '[data-lang]' );
			const lang = btn ? btn.dataset.lang : '?';
			const ok   = window.confirm(
				'Remove language "' + lang + '" and all its translations?\nThis cannot be undone.'
			);
			if ( ! ok ) e.preventDefault();
		} );
	} );

	document.querySelectorAll( '.ow-msgstr[contenteditable]' ).forEach( function ( cell ) {
		let timer;

		cell.addEventListener( 'input', function () {
			const id     = this.dataset.id;
			const status = document.querySelector( '.ow-save-status[data-id="' + id + '"]' );

			clearTimeout( timer );

			if ( status ) {
				status.textContent = '…';
				status.className   = 'ow-save-status is-saving';
			}

			const text = this.innerText;

			timer = setTimeout( async function () {
				try {
					const data = await postAction( {
						action: 'ow_save_translation',
						id: id,
						msgstr: text,
					} );

					if ( status ) {
						if ( data.success ) {
							status.textContent = '✓';
							status.className   = 'ow-save-status is-saved';
							const row = cell.closest( 'tr' );
							if ( row ) {
								row.classList.add( 'ow-row-translated' );
								row.classList.remove( 'ow-row-untranslated' );
								updateWarningUI( row, data.data.warning, data.data.warning_messages || [] );
							}
							setTimeout( () => {
								if ( status.textContent === '✓' ) status.textContent = '';
							}, 2000 );
						} else {
							status.textContent = '✗';
							status.className   = 'ow-save-status is-error';
						}
					}
				} catch ( e ) {
					if ( status ) {
						status.textContent = '✗';
						status.className   = 'ow-save-status is-error';
					}
				}
			}, DEBOUNCE );
		} );

		cell.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				cell.blur();
			}
		} );
	} );

	document.addEventListener( 'click', async function ( event ) {
		const badge = event.target.closest( '.ow-warning-badge' );
		if ( badge ) {
			const details = document.querySelector( '.ow-warning-details[data-id="' + badge.dataset.id + '"]' );
			const isOpen = details && details.style.display !== 'none';
			closeAllWarningDetails();
			if ( details ) {
				details.style.display = isOpen ? 'none' : 'block';
			}
			return;
		}

		const ignoreBtn = event.target.closest( '.ow-ignore-btn' );
		if ( ignoreBtn ) {
			event.preventDefault();
			try {
				const data = await postAction( {
					action: 'ow_ignore_warning',
					id: ignoreBtn.dataset.id,
				} );
				if ( data.success ) {
					const row = document.querySelector( 'tr[data-id="' + ignoreBtn.dataset.id + '"]' );
					updateWarningUI( row, { ignored: 1 }, [] );
				}
			} catch ( e ) {
				window.alert( i18n.network_error || 'Network error' );
			}
			return;
		}

		const retranslateBtn = event.target.closest( '.ow-retranslate-btn' );
		if ( retranslateBtn ) {
			event.preventDefault();
			retranslateBtn.disabled = true;
			try {
				const data = await postAction( {
					action: 'ow_retranslate_single',
					id: retranslateBtn.dataset.id,
				} );
				if ( data.success ) {
					const row = document.querySelector( 'tr[data-id="' + retranslateBtn.dataset.id + '"]' );
					if ( row ) {
						const singularCell = row.querySelector( '.ow-msgstr:not(.ow-msgstr-plural)' ) || row.querySelector( '.ow-msgstr' );
						if ( singularCell && typeof data.data.msgstr === 'string' ) {
							singularCell.textContent = data.data.msgstr;
						}
						updateWarningUI( row, data.data.warning, data.data.warning_messages || [] );
					}
				}
			} catch ( e ) {
				window.alert( i18n.network_error || 'Network error' );
			} finally {
				retranslateBtn.disabled = false;
			}
			return;
		}

		if ( ! event.target.closest( '.ow-warning-details' ) ) {
			closeAllWarningDetails();
		}
	} );

	document.querySelectorAll( 'tr[data-warning]' ).forEach( function ( row ) {
		const warning = parseWarningPayload( row.dataset.warning );
		if ( ! warning || warning.ignored || ( ! warning.placeholder && ! warning.html ) ) {
			updateWarningUI( row, warning, [] );
		}
	} );
} )();
