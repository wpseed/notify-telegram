/**
 * Entry point of the plugin's React admin application.
 *
 * The container and the bootstrap data are rendered by PHP: `#notify-telegram-admin-root` in the page
 * markup and `window.notifyTelegramAdmin` through wp_add_inline_script().
 */
import { App as AntApp, ConfigProvider } from 'antd';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import AdminApp from './AdminApp.jsx';
import ErrorBoundary from './components/ErrorBoundary.jsx';
import './styles.css';

const container = document.getElementById( 'notify-telegram-admin-root' );
const config = window.notifyTelegramAdmin ?? {};

if ( container ) {
	createRoot( container ).render(
		<StrictMode>
			<ConfigProvider
				theme={ {
					token: {
						// The WordPress admin blue, so the page does not look like a foreign body.
						colorPrimary: '#2271b1',
						borderRadius: 4,
					},
				} }
			>
				<AntApp>
					<ErrorBoundary>
						<AdminApp config={ config } />
					</ErrorBoundary>
				</AntApp>
			</ConfigProvider>
		</StrictMode>
	);
}
