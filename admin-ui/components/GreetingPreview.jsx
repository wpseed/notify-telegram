import { Alert, Button, Card, Descriptions, Form, Input, Skeleton, Space, Typography } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';

import { previewGreeting } from '../greeting.js';

/**
 * Read-only preview of what the shortcode renders with the current form values.
 *
 * @param {Object} props             Component props.
 * @param {string} props.template    Current greeting template.
 * @param {string} props.sampleName  Name used for the preview.
 * @param {Function} props.onNameChange Called with the new sample name.
 * @param {string} props.shortcode   Shortcode tag of the plugin.
 * @return {JSX.Element} Rendered card.
 */
export default function GreetingPreview( { template, sampleName, onNameChange, shortcode } ) {
	const greeting = previewGreeting( template, sampleName );

	return (
		<Card title="Preview" size="small">
			<Space direction="vertical" size="middle" style={ { display: 'flex' } }>
				<Form layout="vertical">
					<Form.Item label="Name used in the preview" style={ { marginBottom: 0 } }>
						<Input
							value={ sampleName }
							placeholder="John"
							onChange={ ( event ) => onNameChange?.( event.target.value ) }
						/>
					</Form.Item>
				</Form>

				<Descriptions column={ 1 } size="small" bordered>
					<Descriptions.Item label={ `[${ shortcode } name="${ sampleName || 'John' }"]` }>
						{ null === greeting ? (
							<Typography.Text type="secondary" data-testid="greeting-preview">
								The site cannot render a greeting with this template.
							</Typography.Text>
						) : (
							<Typography.Text strong data-testid="greeting-preview">
								{ greeting }
							</Typography.Text>
						) }
					</Descriptions.Item>
				</Descriptions>

				<Alert
					type="info"
					showIcon
					message="Where this output appears"
					description={ `Insert [${ shortcode }] into any post or page to show this greeting on the site.` }
				/>
			</Space>
		</Card>
	);
}
