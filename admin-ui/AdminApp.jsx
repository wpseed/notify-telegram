/**
 * The plugin's screen: one state, two tabs.
 *
 * The state is loaded and kept here rather than inside each tab, so switching between Events and
 * Settings never loses an edit and one Save button stores everything — the plugin keeps all of it in a
 * single option anyway.
 */
import { App as AntApp, Alert, Button, Space, Spin, Tabs, Typography } from 'antd';
import { useCallback, useEffect, useMemo, useState } from 'react';

import * as api from './api.js';
import EventsPage from './pages/EventsPage.jsx';
import SettingsPage from './pages/SettingsPage.jsx';

const { Text } = Typography;

/**
 * Turns the loaded state into the shape the REST route stores.
 *
 * @param {Object} state Loaded state with the full channel and event objects.
 * @return {Object} Submitted payload.
 */
function toPayload( state ) {
	const channels = {};
	const events = {};
	const templates = {};

	state.channels.forEach( ( channel ) => {
		const values = { enabled: channel.enabled };

		channel.fields.forEach( ( field ) => {
			values[ field.name ] = field.value;
		} );

		channels[ channel.id ] = values;
	} );

	state.events.forEach( ( event ) => {
		events[ event.id ] = event.enabled;
		templates[ event.id ] = event.template;
	} );

	return { enabled: state.enabled, channels, events, templates };
}

/**
 * The application.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.config Configuration printed by PHP.
 * @return {JSX.Element} Rendered output.
 */
export default function AdminApp( { config } ) {
	const { message } = AntApp.useApp();
	const [ state, setState ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( config.tab === 'settings' ? 'settings' : 'events' );

	const load = useCallback( async () => {
		setLoading( true );

		try {
			const loaded = await api.getState();
			setState( loaded );
			setDraft( loaded );
			setError( null );
		} catch ( requestError ) {
			setError( requestError.message );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const dirty = useMemo(
		() => Boolean( state && draft ) && JSON.stringify( state ) !== JSON.stringify( draft ),
		[ state, draft ]
	);

	/**
	 * Switches tabs and keeps the address bar in step, so a reload — or a shared link — opens the same
	 * tab. Nothing else has to follow: both tabs are the same WordPress screen.
	 *
	 * @param {string} key Tab key.
	 */
	const changeTab = ( key ) => {
		setTab( key );

		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', key );
		window.history.replaceState( {}, '', url );
	};

	const save = async () => {
		setSaving( true );

		try {
			const saved = await api.saveSettings( toPayload( draft ) );
			setState( saved );
			setDraft( saved );
			message.success( 'Settings saved.' );
		} catch ( requestError ) {
			message.error( requestError.message );
		} finally {
			setSaving( false );
		}
	};

	/**
	 * Applies a change to one event.
	 *
	 * @param {string} id      Event identifier.
	 * @param {Object} changes Fields to merge.
	 */
	const updateEvent = ( id, changes ) => {
		setDraft( ( current ) => ( {
			...current,
			events: current.events.map( ( event ) => ( event.id === id ? { ...event, ...changes } : event ) ),
		} ) );
	};

	/**
	 * Applies a change to one channel.
	 *
	 * @param {string} id      Channel identifier.
	 * @param {Object} changes Fields to merge.
	 */
	const updateChannel = ( id, changes ) => {
		setDraft( ( current ) => ( {
			...current,
			channels: current.channels.map( ( channel ) =>
				channel.id === id ? { ...channel, ...changes } : channel
			),
		} ) );
	};

	/**
	 * Applies a change to one field of one channel.
	 *
	 * @param {string} id    Channel identifier.
	 * @param {string} name  Field name.
	 * @param {string} value New value.
	 */
	const updateChannelField = ( id, name, value ) => {
		setDraft( ( current ) => ( {
			...current,
			channels: current.channels.map( ( channel ) =>
				channel.id === id
					? {
							...channel,
							fields: channel.fields.map( ( field ) =>
								field.name === name ? { ...field, value } : field
							),
					  }
					: channel
			),
		} ) );
	};

	if ( loading && ! draft ) {
		return <Spin size="large" />;
	}

	if ( error ) {
		return (
			<Alert
				type="error"
				showIcon
				message="The plugin state could not be loaded"
				description={ error }
				action={
					<Button size="small" onClick={ load }>
						Try again
					</Button>
				}
			/>
		);
	}

	if ( ! draft ) {
		return null;
	}

	return (
		<>
			<Tabs
				activeKey={ tab }
				onChange={ changeTab }
				style={ { marginTop: 16 } }
				tabBarExtraContent={
					<Space>
						{ dirty && <Text type="warning">Unsaved changes</Text> }
						<Button onClick={ load } loading={ loading }>
							Reload
						</Button>
						<Button type="primary" onClick={ save } loading={ saving } disabled={ ! dirty }>
							Save changes
						</Button>
					</Space>
				}
				items={ [
					{
						key: 'events',
						label: 'Events',
						children: (
							<EventsPage
								events={ draft.events }
								onToggle={ ( id, enabled ) => updateEvent( id, { enabled } ) }
								onTemplate={ ( id, template ) => updateEvent( id, { template } ) }
							/>
						),
					},
					{
						key: 'settings',
						label: 'Settings',
						children: (
							<SettingsPage
								state={ draft }
								onMasterSwitch={ ( enabled ) => setDraft( ( current ) => ( { ...current, enabled } ) ) }
								onChannelToggle={ ( id, enabled ) => updateChannel( id, { enabled } ) }
								onChannelField={ updateChannelField }
								onLogChange={ ( log ) => setDraft( ( current ) => ( { ...current, log } ) ) }
							/>
						),
					},
				] }
			/>
		</>
	);
}
