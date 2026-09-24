/**
 * Thin wrapper around the plugin's REST API.
 *
 * The root URL and the nonce come from PHP (window.notifyTelegramAdmin), so the application never
 * hardcodes a path and always sends the cookie-authenticated request WordPress expects.
 */

/**
 * Performs a JSON request against the plugin's REST namespace.
 *
 * @param {string} path    Path below the namespace root, e.g. '/settings'.
 * @param {Object} options fetch() options.
 * @return {Promise<Object>} Parsed response body.
 */
export async function request( path, options = {} ) {
	const { apiRoot, nonce } = window.notifyTelegramAdmin ?? {};

	const response = await fetch( `${ apiRoot }${ path }`, {
		credentials: 'same-origin',
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
			...( options.headers ?? {} ),
		},
	} );

	const payload = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new Error( payload?.message ?? `Request failed with status ${ response.status }.` );
	}

	return payload;
}

/**
 * Reads the whole plugin state.
 *
 * @return {Promise<Object>} State: switches, channels, events and the log.
 */
export function getState() {
	return request( '/settings' );
}

/**
 * Stores the settings.
 *
 * @param {Object} settings Settings to store.
 * @return {Promise<Object>} State as saved by the server.
 */
export function saveSettings( settings ) {
	return request( '/settings', {
		method: 'POST',
		body: JSON.stringify( settings ),
	} );
}

/**
 * Sends a test message through every configured channel.
 *
 * @return {Promise<Object>} Per-channel results and the updated log.
 */
export function sendTest() {
	return request( '/test', { method: 'POST' } );
}

/**
 * Empties the delivery log.
 *
 * @return {Promise<Object>} The empty log.
 */
export function clearLog() {
	return request( '/log', { method: 'DELETE' } );
}
