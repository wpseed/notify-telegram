/**
 * The Settings page: the master switch, the channels, the test button and the log.
 */
import { App as AntApp, Alert, Button, Card, Input, Popconfirm, Space, Switch, Table, Tag, Typography } from 'antd';
import { useState } from 'react';

import { clearLog, sendTest } from '../api.js';

const { Paragraph, Text } = Typography;
const { TextArea } = Input;

/**
 * One channel: its switch and the fields it declares itself.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.channel     Channel from the REST payload.
 * @param {Function} props.onToggle    Called with the new switch value.
 * @param {Function} props.onField     Called with a field name and its new value.
 * @return {JSX.Element} Rendered output.
 */
function ChannelCard( { channel, onToggle, onField } ) {
	return (
		<Card
			size="small"
			style={ { marginBottom: 16 } }
			title={
				<Space>
					<Switch checked={ channel.enabled } onChange={ onToggle } size="small" />
					<Text strong={ channel.enabled }>{ channel.label }</Text>
					<Tag color={ channel.configured ? 'green' : 'default' }>
						{ channel.configured ? 'configured' : 'not configured yet' }
					</Tag>
				</Space>
			}
		>
			{ channel.fields.map( ( field ) => (
				<div key={ field.name } style={ { marginBottom: 12 } }>
					<label htmlFor={ `field-${ channel.id }-${ field.name }` }>
						<Text strong>{ field.label }</Text>
					</label>
					<div style={ { marginTop: 4 } }>
						{ field.type === 'textarea' ? (
							<TextArea
								id={ `field-${ channel.id }-${ field.name }` }
								value={ field.value }
								onChange={ ( changed ) => onField( field.name, changed.target.value ) }
								placeholder={ field.placeholder }
								autoSize={ { minRows: 3, maxRows: 8 } }
								style={ { fontFamily: 'Consolas, Monaco, monospace' } }
							/>
						) : (
							<Input
								id={ `field-${ channel.id }-${ field.name }` }
								value={ field.value }
								onChange={ ( changed ) => onField( field.name, changed.target.value ) }
								placeholder={ field.placeholder }
								style={ { maxWidth: 480, fontFamily: 'Consolas, Monaco, monospace' } }
							/>
						) }
					</div>
					{ field.description !== '' && (
						<Paragraph type="secondary" style={ { margin: '4px 0 0' } }>
							{ field.description }
						</Paragraph>
					) }
				</div>
			) ) }
		</Card>
	);
}

/**
 * The page.
 *
 * @param {Object}   props                 Component props.
 * @param {Object}   props.state           Full state being edited.
 * @param {Function} props.onMasterSwitch  Called with the new master switch value.
 * @param {Function} props.onChannelToggle Called with a channel identifier and the new switch value.
 * @param {Function} props.onChannelField  Called with a channel identifier, a field name and its value.
 * @param {Function} props.onLogChange     Called with the log returned by the server.
 * @return {JSX.Element} Rendered output.
 */
export default function SettingsPage( {
	state,
	onMasterSwitch,
	onChannelToggle,
	onChannelField,
	onLogChange,
} ) {
	const { message } = AntApp.useApp();
	const [ testing, setTesting ] = useState( false );
	const [ results, setResults ] = useState( null );

	/**
	 * Sends the test message and shows what each channel answered.
	 */
	const runTest = async () => {
		setTesting( true );

		try {
			const response = await sendTest();
			setResults( response.results );

			if ( response.results.length === 0 ) {
				message.warning( 'No channel is configured yet, so there was nothing to send through.' );
			}

			onLogChange( response.log );
		} catch ( requestError ) {
			message.error( requestError.message );
		} finally {
			setTesting( false );
		}
	};

	const clearTheLog = async () => {
		try {
			const response = await clearLog();
			onLogChange( response.log );
			message.success( 'Log cleared.' );
		} catch ( requestError ) {
			message.error( requestError.message );
		}
	};

	return (
		<>
			<Card size="small" style={ { marginBottom: 16 } }>
				<Space>
					<Switch checked={ state.enabled } onChange={ onMasterSwitch } />
					<Text strong>Notifications</Text>
				</Space>
				<Paragraph type="secondary" style={ { margin: '4px 0 0' } }>
					Sends a message when one of the events you enabled happens. Delivery runs through WP-Cron, so
					the site needs traffic for the queue to move.
				</Paragraph>
			</Card>

			<Paragraph type="secondary">
				A channel with an empty field is skipped, not reported as an error. Adding a channel means
				shipping a class, not editing this screen: each channel brings its own fields.
			</Paragraph>

			{ state.channels.map( ( channel ) => (
				<ChannelCard
					key={ channel.id }
					channel={ channel }
					onToggle={ ( enabled ) => onChannelToggle( channel.id, enabled ) }
					onField={ ( name, value ) => onChannelField( channel.id, name, value ) }
				/>
			) ) }

			<Card
				size="small"
				style={ { marginBottom: 16 } }
				title="Test"
				extra={
					<Button type="primary" onClick={ runTest } loading={ testing }>
						Send test message
					</Button>
				}
			>
				<Paragraph type="secondary" style={ { marginBottom: results ? 12 : 0 } }>
					Sends one message through every channel that has its credentials filled in, whatever the
					switches say.
				</Paragraph>
				{ results &&
					results.map( ( result ) => (
						<Alert
							key={ result.channel }
							style={ { marginTop: 8 } }
							type={ result.ok ? 'success' : 'error' }
							showIcon
							message={ result.channel }
							description={ result.message }
						/>
					) ) }
			</Card>

			<Card
				size="small"
				title="Recent deliveries"
				extra={
					state.log.length > 0 && (
						<Popconfirm title="Clear the log?" onConfirm={ clearTheLog } okText="Clear" >
							<Button danger size="small">
								Clear log
							</Button>
						</Popconfirm>
					)
				}
			>
				{ state.log.length === 0 ? (
					<Paragraph type="secondary" style={ { margin: 0 } }>
						Nothing has been delivered yet.
					</Paragraph>
				) : (
					<Table
						size="small"
						rowKey={ ( entry ) => `${ entry.time }-${ entry.channel }-${ entry.message }` }
						pagination={ false }
						dataSource={ state.log }
						columns={ [
							{ title: 'Time', dataIndex: 'date', width: 160 },
							{ title: 'Event', dataIndex: 'event_label', width: 200 },
							{ title: 'Channel', dataIndex: 'channel_label', width: 140 },
							{
								title: 'Result',
								dataIndex: 'message',
								render: ( message, entry ) => (
									<Text type={ entry.ok ? 'success' : 'danger' }>{ message }</Text>
								),
							},
						] }
					/>
				) }
			</Card>
		</>
	);
}
