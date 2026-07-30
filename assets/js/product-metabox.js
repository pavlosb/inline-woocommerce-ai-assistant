( function () {
	'use strict';

	function setStatus( container, message, isError ) {
		const status = container.querySelector( '.inline-woo-ai-status' );
		status.textContent = message || '';
		status.classList.toggle( 'is-error', Boolean( isError ) );
	}

	async function parseResponse( response, fallbackMessage ) {
		let payload = null;

		try {
			payload = await response.json();
		} catch ( error ) {
			payload = null;
		}

		if ( ! response.ok ) {
			const message = payload && payload.message ? payload.message : fallbackMessage;
			throw new Error( message );
		}

		return payload;
	}

	async function request( productId, action, method, body, fallbackMessage ) {
		const options = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': InlineWooAI.nonce,
			},
		};

		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		const response = await window.fetch(
			`${ InlineWooAI.restRoot }${ productId }/${ action }`,
			options
		);

		return parseResponse( response, fallbackMessage );
	}

	function formatAppliedBy( user, timestamp ) {
		const date = timestamp ? new Date( timestamp * 1000 ).toLocaleString() : '';
		return InlineWooAI.i18n.appliedBy
			.replace( '%1$s', user || '—' )
			.replace( '%2$s', date );
	}

	function renderHistory( container, records, undoHandler ) {
		const list = container.querySelector( '.inline-woo-ai-history ul' );
		const empty = container.querySelector( '.inline-woo-ai-history-empty' );
		list.replaceChildren();

		if ( ! Array.isArray( records ) || records.length === 0 ) {
			list.hidden = true;
			empty.hidden = false;
			return;
		}

		records.forEach( function ( record ) {
			const item = document.createElement( 'li' );
			const title = document.createElement( 'strong' );
			const meta = document.createElement( 'small' );
			const change = document.createElement( 'div' );

			title.textContent = record.target_label || record.task || '';
			meta.textContent = formatAppliedBy( record.applied_by, record.applied_at );
			change.textContent = `${ record.before_preview || '∅' } → ${ record.after_preview || '∅' }`;

			item.appendChild( title );
			item.appendChild( meta );
			item.appendChild( change );

			if ( record.provider || record.model ) {
				const provider = document.createElement( 'small' );
				provider.textContent = [ record.provider, record.model ].filter( Boolean ).join( ' / ' );
				item.appendChild( provider );
			}

			if ( record.undoable ) {
				const undoButton = document.createElement( 'button' );
				undoButton.type = 'button';
				undoButton.className = 'button button-small inline-woo-ai-history-undo';
				undoButton.textContent = InlineWooAI.i18n.undo;
				undoButton.addEventListener( 'click', function () {
					undoHandler( record.audit_id, true );
				} );
				item.appendChild( undoButton );
			}

			list.appendChild( item );
		} );

		empty.hidden = true;
		list.hidden = false;
	}

	function initializeAssistant( container ) {
		const productId = Number.parseInt( container.dataset.productId, 10 );
		const taskSelect = container.querySelector( '#inline-woo-ai-task' );
		const generateButton = container.querySelector( '.inline-woo-ai-generate' );
		const copyButton = container.querySelector( '.inline-woo-ai-copy' );
		const applyButton = container.querySelector( '.inline-woo-ai-apply' );
		const undoButton = container.querySelector( '.inline-woo-ai-undo' );
		const spinner = container.querySelector( '.spinner' );
		const resultContainer = container.querySelector( '.inline-woo-ai-result' );
		const target = container.querySelector( '.inline-woo-ai-target strong' );
		const currentValue = container.querySelector( '#inline-woo-ai-current' );
		const suggestion = container.querySelector( '#inline-woo-ai-suggestion' );
		const state = {
			suggestionId: '',
			auditId: '',
			targetLabel: '',
			busy: false,
		};

		function updateButtons() {
			generateButton.disabled = state.busy;
			copyButton.disabled = state.busy || ! suggestion.value;
			applyButton.disabled = state.busy || ! state.suggestionId;
			undoButton.disabled = state.busy || ! state.auditId;
			spinner.classList.toggle( 'is-active', state.busy );
		}

		function setBusy( isBusy ) {
			state.busy = isBusy;
			updateButtons();
		}

		async function loadHistory() {
			try {
				const payload = await request(
					productId,
					'history',
					'GET',
					null,
					InlineWooAI.i18n.undoError
				);
				renderHistory( container, payload.history || [], performUndo );
			} catch ( error ) {
				renderHistory( container, [], performUndo );
			}
		}

		async function performUndo( auditId, fromHistory ) {
			if ( ! auditId || state.busy || ! window.confirm( InlineWooAI.i18n.confirmUndo ) ) {
				return;
			}

			setBusy( true );
			setStatus( container, InlineWooAI.i18n.undoing, false );

			try {
				const payload = await request(
					productId,
					'undo',
					'POST',
					{ audit_id: auditId },
					InlineWooAI.i18n.undoError
				);

				if ( fromHistory ) {
					resultContainer.hidden = true;
					state.suggestionId = '';
					state.auditId = '';
				} else {
					currentValue.value = payload.restored_value || '';
					state.auditId = '';
					undoButton.hidden = true;
				}

				setStatus( container, InlineWooAI.i18n.undone, false );
				await loadHistory();
			} catch ( error ) {
				setStatus( container, error.message || InlineWooAI.i18n.undoError, true );
			} finally {
				setBusy( false );
			}
		}

		generateButton.addEventListener( 'click', async function () {
			setBusy( true );
			state.suggestionId = '';
			state.auditId = '';
			undoButton.hidden = true;
			resultContainer.hidden = true;
			setStatus( container, InlineWooAI.i18n.generating, false );

			try {
				const payload = await request(
					productId,
					'generate',
					'POST',
					{ task: taskSelect.value },
					InlineWooAI.i18n.generateError
				);

				state.suggestionId = payload.suggestion_id || '';
				state.targetLabel = payload.target_label || '';
				target.textContent = state.targetLabel;
				currentValue.value = payload.current_value || '';
				suggestion.value = payload.suggestion || '';
				resultContainer.hidden = false;
				setStatus( container, InlineWooAI.i18n.generated, false );
			} catch ( error ) {
				setStatus( container, error.message || InlineWooAI.i18n.generateError, true );
			} finally {
				setBusy( false );
			}
		} );

		applyButton.addEventListener( 'click', async function () {
			if ( ! state.suggestionId || state.busy ) {
				return;
			}

			const confirmation = InlineWooAI.i18n.confirmApply.replace( '%s', state.targetLabel || '' );
			if ( ! window.confirm( confirmation ) ) {
				return;
			}

			setBusy( true );
			setStatus( container, InlineWooAI.i18n.applying, false );

			try {
				const payload = await request(
					productId,
					'apply',
					'POST',
					{ suggestion_id: state.suggestionId },
					InlineWooAI.i18n.applyError
				);

				state.suggestionId = '';
				state.auditId = payload.audit_id || '';
				currentValue.value = payload.applied_value || '';
				undoButton.hidden = ! state.auditId;
				setStatus( container, InlineWooAI.i18n.applied, false );
				await loadHistory();
			} catch ( error ) {
				setStatus( container, error.message || InlineWooAI.i18n.applyError, true );
			} finally {
				setBusy( false );
			}
		} );

		undoButton.addEventListener( 'click', function () {
			performUndo( state.auditId, false );
		} );

		copyButton.addEventListener( 'click', async function () {
			try {
				await navigator.clipboard.writeText( suggestion.value );
				setStatus( container, InlineWooAI.i18n.copied, false );
			} catch ( error ) {
				suggestion.removeAttribute( 'readonly' );
				suggestion.select();
				suggestion.setAttribute( 'readonly', 'readonly' );
				setStatus( container, InlineWooAI.i18n.copyFailed, true );
			}
		} );

		taskSelect.addEventListener( 'change', function () {
			state.suggestionId = '';
			state.auditId = '';
			resultContainer.hidden = true;
			undoButton.hidden = true;
			setStatus( container, '', false );
			updateButtons();
		} );

		updateButtons();
		loadHistory();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.inline-woo-ai' ).forEach( initializeAssistant );
	} );
}() );
