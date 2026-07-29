( function () {
	'use strict';

	function setStatus( container, message, isError ) {
		const status = container.querySelector( '.inline-woo-ai-status' );
		status.textContent = message || '';
		status.classList.toggle( 'is-error', Boolean( isError ) );
	}

	async function parseResponse( response ) {
		let payload = null;

		try {
			payload = await response.json();
		} catch ( error ) {
			payload = null;
		}

		if ( ! response.ok ) {
			const message = payload && payload.message ? payload.message : InlineWooAI.i18n.error;
			throw new Error( message );
		}

		return payload;
	}

	function initializeAssistant( container ) {
		const productId = Number.parseInt( container.dataset.productId, 10 );
		const taskSelect = container.querySelector( '#inline-woo-ai-task' );
		const generateButton = container.querySelector( '.inline-woo-ai-generate' );
		const copyButton = container.querySelector( '.inline-woo-ai-copy' );
		const spinner = container.querySelector( '.spinner' );
		const resultContainer = container.querySelector( '.inline-woo-ai-result' );
		const suggestion = container.querySelector( '#inline-woo-ai-suggestion' );

		generateButton.addEventListener( 'click', async function () {
			generateButton.disabled = true;
			spinner.classList.add( 'is-active' );
			setStatus( container, InlineWooAI.i18n.generating, false );

			try {
				const response = await window.fetch(
					`${ InlineWooAI.restRoot }${ productId }/generate`,
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': InlineWooAI.nonce,
						},
						body: JSON.stringify( { task: taskSelect.value } ),
					}
				);

				const payload = await parseResponse( response );
				suggestion.value = payload.suggestion || '';
				resultContainer.hidden = false;
				setStatus( container, InlineWooAI.i18n.generated, false );
			} catch ( error ) {
				setStatus( container, error.message || InlineWooAI.i18n.error, true );
			} finally {
				generateButton.disabled = false;
				spinner.classList.remove( 'is-active' );
			}
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
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.inline-woo-ai' ).forEach( initializeAssistant );
	} );
}() );
