( () => {
	'use strict';

	const config = window.wpAiLmStudioBrowserStatus || {};

	function getRegistry() {
		const existing = window.wpAiProviderBrowserStatus || {};
		const checkers = existing.checkers || {};
		const listeners = existing.listeners || [];

		const registry = Object.assign( existing, {
			checkers,
			listeners,
			register( id, checker ) {
				if ( id && typeof checker === 'function' ) {
					checkers[ id ] = checker;
				}
			},
			onStatus( listener ) {
				if ( typeof listener !== 'function' ) {
					return () => {};
				}
				listeners.push( listener );
				return () => {
					const index = listeners.indexOf( listener );
					if ( index !== -1 ) {
						listeners.splice( index, 1 );
					}
				};
			},
			async check( provider ) {
				const providerConfig = typeof provider === 'string' ? { id: provider } : ( provider || {} );
				const providerId = providerConfig.id || providerConfig.providerId;
				const checker = checkers[ providerId ];
				let detail;

				if ( ! checker ) {
					detail = {
						providerId,
						reachable: false,
						status: 'unsupported',
						error: 'No browser status checker registered.',
						models: [],
						modelCount: 0,
					};
				} else {
					try {
						detail = await checker( providerConfig );
					} catch ( error ) {
						detail = {
							reachable: false,
							status: 'unreachable',
							error: error && error.message ? error.message : String( error || 'Could not connect.' ),
							models: [],
							modelCount: 0,
						};
					}
					detail = Object.assign( { providerId }, detail || {} );
				}

				if ( ! Array.isArray( detail.models ) ) {
					detail.models = [];
				}
				if ( typeof detail.modelCount !== 'number' ) {
					detail.modelCount = detail.models.length;
				}

				document.dispatchEvent( new CustomEvent( 'wp-ai-provider-browser-status', { detail } ) );
				listeners.slice().forEach( ( listener ) => listener( detail ) );

				return detail;
			},
		} );

		window.wpAiProviderBrowserStatus = registry;
		return registry;
	}

	function trimSlash( value ) {
		return String( value || '' ).replace( /\/+$/, '' );
	}

	function isUsableApiKey( key ) {
		return key && key !== 'lmstudio-local' && ! /[^\u0000-\u00FF]/.test( key );
	}

	async function fetchJson( endpoint, path, apiKey ) {
		const headers = {};
		if ( isUsableApiKey( apiKey ) ) {
			headers.Authorization = 'Bearer ' + apiKey;
		}

		const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		const timeout = controller ? setTimeout( () => controller.abort(), 3000 ) : null;

		try {
			const response = await fetch( endpoint + path, {
				headers,
				signal: controller ? controller.signal : undefined,
			} );
			if ( ! response.ok ) {
				throw new Error( response.statusText || 'HTTP ' + response.status );
			}

			return response.json();
		} finally {
			if ( timeout ) {
				clearTimeout( timeout );
			}
		}
	}

	function getInstanceId( model ) {
		if ( model.loaded_instances && model.loaded_instances.length > 0 && model.loaded_instances[ 0 ].instance_id ) {
			return model.loaded_instances[ 0 ].instance_id;
		}
		return model.key;
	}

	function getCapabilities( model ) {
		if ( model.type === 'embedding' ) {
			return [ 'embedding_generation' ];
		}

		const capabilities = [ 'text_generation', 'chat_history' ];
		const nativeCapabilities = model.capabilities || {};

		if ( nativeCapabilities.vision ) {
			capabilities.push( 'vision' );
		}
		if ( nativeCapabilities.trained_for_tool_use ) {
			capabilities.push( 'function_calling' );
		}
		if ( nativeCapabilities.image_generation ) {
			capabilities.push( 'image_generation' );
		}

		return capabilities;
	}

	function toBrowserModel( model ) {
		const id = getInstanceId( model );
		return {
			id,
			name: model.display_name || id,
			type: model.type || 'llm',
			loaded: !! ( model.loaded_instances && model.loaded_instances.length > 0 ),
			capabilities: getCapabilities( model ),
		};
	}

	function isUsableTextModel( model ) {
		return model.type !== 'embedding' && model.loaded_instances && model.loaded_instances.length > 0;
	}

	const registry = getRegistry();

	registry.register( 'lmstudio', async ( provider ) => {
		const endpoint = trimSlash( provider.endpoint || config.endpoint || 'http://localhost:1234' );
		const apiKey = provider.apiKey || config.apiKey || '';
		const data = await fetchJson( endpoint, '/api/v1/models', apiKey );
		const rawModels = Array.isArray( data.models ) ? data.models : [];
		const allModels = rawModels.map( toBrowserModel );
		const models = rawModels.filter( isUsableTextModel ).map( toBrowserModel );

		return {
			providerId: 'lmstudio',
			reachable: true,
			status: models.length > 0 ? 'ready' : 'no_models',
			endpoint,
			models,
			allModels,
			modelCount: models.length,
			downloadedModelCount: allModels.length,
		};
	} );
} )();
