import { ReloadOutlined, SaveOutlined } from '@ant-design/icons';
import { Alert, App, Button, Card, Flex, Form, Input, Popconfirm, Skeleton, Space, Tag, Typography } from 'antd';
import { useCallback, useEffect, useState } from 'react';

import { getSettings, saveSettings } from './api.js';
import GreetingPreview from './components/GreetingPreview.jsx';
import { DEFAULT_TEMPLATE, PLACEHOLDER, validateTemplate } from './greeting.js';

/**
 * Settings screen: reads the stored values from the REST API, edits them and writes them back.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.config Bootstrap data passed by PHP.
 * @return {JSX.Element} Rendered screen.
 */
export default function AdminApp( { config } ) {
	const { message } = App.useApp();
	const [ form ] = Form.useForm();

	const defaults = config.defaults ?? { template: DEFAULT_TEMPLATE };
	const shortcode = config.shortcode ?? 'notify_telegram_hello';

	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ loadError, setLoadError ] = useState( null );
	const [ savedTemplate, setSavedTemplate ] = useState( defaults.template );
	const [ sampleName, setSampleName ] = useState( config.sampleName ?? 'John' );

	// The live field value drives the preview, so there is one source of truth for the form.
	const template = Form.useWatch( 'template', form ) ?? '';
	const templateError = validateTemplate( template );
	const dirty = template !== savedTemplate;

	/**
	 * Loads the settings from the server.
	 *
	 * @return {Promise<void>} Resolves when the request finished.
	 */
	const load = useCallback( async () => {
		setLoading( true );
		setLoadError( null );

		try {
			const settings = await getSettings();

			form.setFieldsValue( { template: settings.template } );
			setSavedTemplate( settings.template );
		} catch ( error ) {
			setLoadError( error.message );
		} finally {
			setLoading( false );
		}
	}, [ form ] );

	useEffect( () => {
		load();
	}, [ load ] );

	/**
	 * Validates the form and stores the settings.
	 *
	 * @return {Promise<void>} Resolves when the request finished.
	 */
	const save = async () => {
		const values = await form.validateFields().catch( () => null );

		if ( null === values ) {
			return;
		}

		setSaving( true );

		try {
			const settings = await saveSettings( { template: values.template } );

			form.setFieldsValue( { template: settings.template } );
			setSavedTemplate( settings.template );
			message.success( 'Settings saved.' );
		} catch ( error ) {
			message.error( error.message );
		} finally {
			setSaving( false );
		}
	};

	if ( loading ) {
		return <Skeleton active paragraph={ { rows: 5 } } />;
	}

	if ( loadError ) {
		return (
			<Alert
				type="error"
				showIcon
				message="The settings could not be loaded"
				description={ loadError }
				action={
					<Button size="small" onClick={ load }>
						Try again
					</Button>
				}
			/>
		);
	}

	return (
		<Flex vertical gap={ 16 }>
			<Card
				title="Notify Telegram settings"
				extra={
					config.version && <Tag color="blue">{ `v${ config.version }` }</Tag>
				}
			>
				<Form form={ form } layout="vertical" initialValues={ { template: defaults.template } }>
					<Form.Item
						label="Greeting template"
						name="template"
						extra={ `The ${ PLACEHOLDER } placeholder is replaced with the name; without it the name is appended at the end.` }
						rules={ [
							{
								// The same rules the REST route enforces, so a rejected value never leaves the form.
								validator: ( _, value ) => {
									const error = validateTemplate( value );

									return error ? Promise.reject( new Error( error ) ) : Promise.resolve();
								},
							},
						] }
					>
						<Input placeholder={ DEFAULT_TEMPLATE } maxLength={ 200 } allowClear />
					</Form.Item>

					<Space>
						<Button
							type="primary"
							icon={ <SaveOutlined /> }
							loading={ saving }
							// A value the server would reject never leaves the form.
							disabled={ ! dirty || null !== templateError }
							onClick={ save }
						>
							Save changes
						</Button>

						<Popconfirm
							title="Replace the template with the plugin default?"
							description={ `The default is "${ defaults.template }". Nothing is stored until you save.` }
							okText="Replace"
							cancelText="Cancel"
							onConfirm={ () => form.setFieldsValue( { template: defaults.template } ) }
						>
							<Button icon={ <ReloadOutlined /> }>Restore the default</Button>
						</Popconfirm>

						{ dirty && <Typography.Text type="warning">Unsaved changes</Typography.Text> }
					</Space>
				</Form>
			</Card>

			<GreetingPreview
				template={ template }
				sampleName={ sampleName }
				onNameChange={ setSampleName }
				shortcode={ shortcode }
			/>
		</Flex>
	);
}
