/**
 * Mirrors the PHP greeting format (Greeting::format()) and the validation the REST route performs, so
 * the form can show the result of a template before it is saved. PHP stays the authority: the rules
 * below are the rules the server enforces.
 */

export const PLACEHOLDER = '%s';

export const DEFAULT_TEMPLATE = 'Hello, %s!';

export const DEFAULT_NAME = 'world';

/**
 * Checks a template against the server's rules.
 *
 * @param {*} template Candidate template.
 * @return {string|null} Error message, or null when the template is acceptable.
 */
export function validateTemplate( template ) {
	if ( 'string' !== typeof template || '' === template.trim() ) {
		return 'The template cannot be empty.';
	}

	// sprintf() would choke on any other percent pattern, or quietly fill it with the wrong value.
	if ( template.replace( /%s|%%/g, '' ).includes( '%' ) ) {
		return 'Only the name placeholder may contain a percent sign.';
	}

	if ( ( template.match( /%s/g ) ?? [] ).length > 1 ) {
		return 'The template must contain the name placeholder at most once.';
	}

	return null;
}

/**
 * Renders the greeting exactly like Greeting::format() does.
 *
 * @param {string} template Greeting template.
 * @param {string} name     Name to insert.
 * @return {string} Greeting text.
 */
export function formatGreeting( template, name ) {
	const trimmed = 'string' === typeof template ? template.trim() : '';
	const base = '' === trimmed ? DEFAULT_TEMPLATE : trimmed;

	// PHP appends the placeholder when the template has none, and so does the preview.
	const withPlaceholder = base.includes( PLACEHOLDER ) ? base : `${ base } ${ PLACEHOLDER }`;
	const value = 'string' === typeof name && '' !== name.trim() ? name.trim() : DEFAULT_NAME;

	// %% is a literal percent sign in sprintf(); the token keeps it from colliding with the
	// placeholder replacement below.
	return withPlaceholder
		.replace( /%%/g, '\u0000' )
		.split( PLACEHOLDER )
		.join( value )
		.replace( /\u0000/g, '%' );
}

/**
 * The greeting the site would print, or null when the preview must not guess.
 *
 * A blank template falls back to the default one on the site, so it is still previewable. Anything else
 * that fails validation would not render the way this function would: PHP's sprintf() turns `Hi %d!`
 * into `Hi 0!`, so showing a preview for it would be a lie.
 *
 * @param {string} template Greeting template.
 * @param {string} name     Name to insert.
 * @return {string|null} Greeting text, or null for a template that cannot be previewed.
 */
export function previewGreeting( template, name ) {
	const is_blank = '' === String( template ?? '' ).trim();

	if ( ! is_blank && null !== validateTemplate( template ) ) {
		return null;
	}

	return formatGreeting( template, name );
}
