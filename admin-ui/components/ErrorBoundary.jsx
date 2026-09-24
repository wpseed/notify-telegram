import { Alert, Button } from 'antd';
import { Component } from 'react';

/**
 * Keeps a render error inside the plugin's screen instead of blanking the whole admin page.
 */
export default class ErrorBoundary extends Component {
	/**
	 * @param {Object} props Component props.
	 */
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	/**
	 * Stores the thrown error so the fallback can be rendered.
	 *
	 * @param {Error} error Thrown error.
	 * @return {Object} New state.
	 */
	static getDerivedStateFromError( error ) {
		return { error };
	}

	/**
	 * Renders the children, or the fallback when something below threw.
	 *
	 * @return {JSX.Element} Rendered output.
	 */
	render() {
		const { error } = this.state;

		if ( ! error ) {
			return this.props.children;
		}

		return (
			<Alert
				type="error"
				showIcon
				message="The settings screen stopped with an error"
				description={ error.message }
				action={
					<Button size="small" danger onClick={ () => window.location.reload() }>
						Reload the page
					</Button>
				}
			/>
		);
	}
}
