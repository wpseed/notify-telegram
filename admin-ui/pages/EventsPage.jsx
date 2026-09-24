/**
 * The Events page: which events are reported, and what their message says.
 */
import { Card, Input, Space, Switch, Tag, Typography } from 'antd';

const { Paragraph, Text } = Typography;
const { TextArea } = Input;

/**
 * One event: a toggle, the message and the placeholders it fills.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.event      Event from the REST payload.
 * @param {Function} props.onToggle   Called with the new switch value.
 * @param {Function} props.onTemplate Called with the new message text.
 * @return {JSX.Element} Rendered output.
 */
function EventCard( { event, onToggle, onTemplate } ) {
	/**
	 * Adds a placeholder at the end of the message.
	 *
	 * @param {string} name Placeholder name.
	 */
	const appendPlaceholder = ( name ) => {
		const template = event.template === '' ? event.default_template : event.template;
		onTemplate( `${ template }%${ name }%` );
	};

	return (
		<Card
			size="small"
			style={ { marginBottom: 16 } }
			title={
				<Space>
					<Switch checked={ event.enabled } onChange={ onToggle } size="small" />
					<Text strong={ event.enabled }>{ event.label }</Text>
					<Tag>{ event.id }</Tag>
				</Space>
			}
		>
			<TextArea
				value={ event.template }
				onChange={ ( changed ) => onTemplate( changed.target.value ) }
				placeholder={ event.default_template }
				autoSize={ { minRows: 2, maxRows: 6 } }
				disabled={ ! event.enabled }
				style={ { fontFamily: 'Consolas, Monaco, monospace' } }
			/>
			<Paragraph type="secondary" style={ { marginTop: 8, marginBottom: 8 } }>
				Leave the message empty to use the default: <code>{ event.default_template }</code>
			</Paragraph>
			<Space size={ [ 4, 4 ] } wrap>
				<Text type="secondary">Placeholders:</Text>
				{ event.placeholders.map( ( placeholder ) => (
					<Tag
						key={ placeholder.name }
						color="blue"
						style={ { cursor: 'pointer' } }
						title={ placeholder.description }
						onClick={ () => appendPlaceholder( placeholder.name ) }
					>
						%{ placeholder.name }%
					</Tag>
				) ) }
			</Space>
			<Paragraph type="secondary" style={ { marginTop: 8, marginBottom: 0 } }>
				{ event.placeholders
					.map( ( placeholder ) => `${ placeholder.name }: ${ placeholder.description }` )
					.join( ' · ' ) }
			</Paragraph>
		</Card>
	);
}

/**
 * The page.
 *
 * @param {Object}   props            Component props.
 * @param {Array}    props.events     Events from the REST payload.
 * @param {Function} props.onToggle   Called with an event identifier and the new switch value.
 * @param {Function} props.onTemplate Called with an event identifier and the new message text.
 * @return {JSX.Element} Rendered output.
 */
export default function EventsPage( { events, onToggle, onTemplate } ) {
	return (
		<>
			<Paragraph type="secondary">
				Every event has its own switch and its own message. A message that is left empty uses the
				default, and anything else is checked against the placeholders of that event before it is
				stored.
			</Paragraph>
			{ events.map( ( event ) => (
				<EventCard
					key={ event.id }
					event={ event }
					onToggle={ ( enabled ) => onToggle( event.id, enabled ) }
					onTemplate={ ( template ) => onTemplate( event.id, template ) }
				/>
			) ) }
		</>
	);
}
