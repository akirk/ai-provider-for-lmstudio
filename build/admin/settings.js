( () => {
	'use strict';

	const { __ } = window.wp.i18n;

	const ERROR_COLOR = '#d63638';

	function getSettings() {
		return window.aiProviderForLmStudioSettings;
	}

	async function lmFetch( path ) {
		const settings = getSettings();
		const headers  = {};
		if ( settings.apiKey && settings.apiKey !== 'lmstudio-local' ) {
			headers['Authorization'] = 'Bearer ' + settings.apiKey;
		}
		const response = await fetch( settings.lmstudioHost + path, { headers } );
		if ( ! response.ok ) {
			throw new Error( response.statusText );
		}
		return response.json();
	}

	async function lmPost( path, data ) {
		const settings = getSettings();
		const headers  = { 'Content-Type': 'application/json' };
		if ( settings.apiKey && settings.apiKey !== 'lmstudio-local' ) {
			headers['Authorization'] = 'Bearer ' + settings.apiKey;
		}
		const response = await fetch( settings.lmstudioHost + path, {
			method: 'POST',
			headers,
			body: JSON.stringify( data ),
		} );
		if ( ! response.ok ) {
			throw new Error( response.statusText );
		}
		return response.json();
	}

	async function apiPost( url, data ) {
		const body     = new URLSearchParams( data );
		const response = await fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body,
		} );
		return response.json();
	}

	function el( tag, props = {}, children = [] ) {
		const node = document.createElement( tag );
		for ( const [ key, val ] of Object.entries( props ) ) {
			if ( key === 'style' && typeof val === 'object' ) {
				Object.assign( node.style, val );
			} else if ( key === 'className' ) {
				node.className = val;
			} else if ( key === 'textContent' ) {
				node.textContent = val;
			} else {
				node.setAttribute( key, val );
			}
		}
		for ( const child of children ) {
			if ( typeof child === 'string' ) {
				node.appendChild( document.createTextNode( child ) );
			} else if ( child ) {
				node.appendChild( child );
			}
		}
		return node;
	}

	function td( children = [], props = {} ) {
		return el( 'td', props, Array.isArray( children ) ? children : [ children ] );
	}

	function capabilityBadges( model ) {
		if ( model.type === 'embedding' ) {
			return __( 'Embeddings', 'ai-provider-for-lmstudio' );
		}
		const badges = [ __( 'Text', 'ai-provider-for-lmstudio' ) ];
		const caps   = model.capabilities || {};
		if ( caps.vision ) {
			badges.push( __( 'Vision', 'ai-provider-for-lmstudio' ) );
		}
		if ( caps.trained_for_tool_use ) {
			badges.push( __( 'Tool use', 'ai-provider-for-lmstudio' ) );
		}
		if ( caps.image_generation ) {
			badges.push( __( 'Image gen', 'ai-provider-for-lmstudio' ) );
		}
		return badges.join( ', ' );
	}

	function getInstanceId( model ) {
		if ( model.loaded_instances && model.loaded_instances.length > 0 && model.loaded_instances[ 0 ].instance_id ) {
			return model.loaded_instances[ 0 ].instance_id;
		}
		return model.key;
	}

	function parseModels( rawModels ) {
		const settings    = getSettings();
		const modelOrder  = settings.modelOrder || [];

		const models = rawModels.map( ( model ) => ( {
			key:          model.key,
			instance_id:  getInstanceId( model ),
			display_name: model.display_name || model.key,
			type:         model.type || 'llm',
			capabilities: model.capabilities || {},
			is_loaded:    model.loaded_instances && model.loaded_instances.length > 0,
		} ) );

		if ( modelOrder.length > 0 ) {
			models.sort( ( a, b ) => {
				const pa = modelOrder.indexOf( a.instance_id );
				const pb = modelOrder.indexOf( b.instance_id );
				return ( pa === -1 ? Infinity : pa ) - ( pb === -1 ? Infinity : pb );
			} );
		}

		return models;
	}

	async function saveOrder( rows ) {
		const settings = getSettings();
		const order    = Array.from( rows ).map( ( row ) => row.dataset.instanceId );
		await apiPost( settings.saveOrderUrl, { order: JSON.stringify( order ) } ).catch( () => null );
	}

	function buildTable( models, container, onReload ) {
		const settings = getSettings();

		const table     = el( 'table', { className: 'wp-list-table widefat fixed striped', style: { marginBottom: '0.5em' } } );
		const thead     = el( 'thead' );
		const headerRow = el( 'tr', {}, [
			el( 'th', { style: { width: '2em' } } ),
			el( 'th', { textContent: __( 'Model', 'ai-provider-for-lmstudio' ) } ),
			el( 'th', { textContent: __( 'Type', 'ai-provider-for-lmstudio' ), style: { width: '6em' } } ),
			el( 'th', { textContent: __( 'Capabilities', 'ai-provider-for-lmstudio' ) } ),
			el( 'th', { textContent: __( 'Status', 'ai-provider-for-lmstudio' ), style: { width: '8em' } } ),
			el( 'th', { style: { width: '7em' } } ),
		] );
		thead.appendChild( headerRow );
		table.appendChild( thead );

		const tbody = el( 'tbody' );

		if ( models.length === 0 ) {
			tbody.appendChild( el( 'tr', {}, [
				el( 'td', { colspan: '6', textContent: __( 'No models found. Download a model in LM Studio.', 'ai-provider-for-lmstudio' ) } ),
			] ) );
		}

		// Drag-and-drop state
		let draggedRow = null;

		tbody.addEventListener( 'dragstart', ( e ) => {
			draggedRow = e.target.closest( 'tr' );
			if ( draggedRow ) {
				draggedRow.style.opacity = '0.4';
			}
		} );

		tbody.addEventListener( 'dragend', () => {
			if ( draggedRow ) {
				draggedRow.style.opacity = '';
			}
			draggedRow = null;
			tbody.querySelectorAll( 'tr' ).forEach( ( r ) => r.style.removeProperty( 'border-top' ) );
		} );

		tbody.addEventListener( 'dragover', ( e ) => {
			e.preventDefault();
			const target = e.target.closest( 'tr' );
			tbody.querySelectorAll( 'tr' ).forEach( ( r ) => r.style.removeProperty( 'border-top' ) );
			if ( target && target !== draggedRow ) {
				target.style.borderTop = '2px solid #2271b1';
			}
		} );

		tbody.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			tbody.querySelectorAll( 'tr' ).forEach( ( r ) => r.style.removeProperty( 'border-top' ) );
			const target = e.target.closest( 'tr' );
			if ( target && draggedRow && target !== draggedRow ) {
				tbody.insertBefore( draggedRow, target );
				saveOrder( tbody.querySelectorAll( 'tr[data-instance-id]' ) );
			}
		} );

		for ( const model of models ) {
			const isLoaded = model.is_loaded;
			const typeName = model.type === 'embedding'
				? __( 'Embedding', 'ai-provider-for-lmstudio' )
				: __( 'LLM', 'ai-provider-for-lmstudio' );

			const statusDot = el( 'span', {
				textContent: isLoaded
					? '● ' + __( 'Loaded', 'ai-provider-for-lmstudio' )
					: '○ ' + __( 'Not loaded', 'ai-provider-for-lmstudio' ),
				style: { color: isLoaded ? '#00a32a' : '#787c82' },
			} );

			const btn     = el( 'button', {
				type: 'button',
				textContent: isLoaded
					? __( 'Unload', 'ai-provider-for-lmstudio' )
					: __( 'Load', 'ai-provider-for-lmstudio' ),
				className: isLoaded ? 'button lmstudio-unload-btn' : 'button button-primary lmstudio-load-btn',
				'data-instance-id': model.instance_id,
				'data-action': isLoaded ? 'unload' : 'load',
			} );
			const btnCell = td( [ btn ] );

			btn.addEventListener( 'click', async () => {
				const instanceId = btn.dataset.instanceId;
				const isUnload   = btn.dataset.action === 'unload';

				btn.disabled    = true;
				btn.textContent = isUnload
					? __( 'Unloading…', 'ai-provider-for-lmstudio' )
					: __( 'Loading…', 'ai-provider-for-lmstudio' );

				try {
					if ( isUnload ) {
						await lmPost( '/api/v1/models/unload', { instance_id: instanceId } );
					} else {
						await lmPost( '/api/v1/models/load', { model: instanceId } );
					}
					onReload();
				} catch ( err ) {
					btn.disabled    = false;
					btn.textContent = isUnload
						? __( 'Unload', 'ai-provider-for-lmstudio' )
						: __( 'Load', 'ai-provider-for-lmstudio' );
					btnCell.appendChild( el( 'span', {
						textContent: ' ' + ( err.message || __( 'Failed.', 'ai-provider-for-lmstudio' ) ),
						style: { color: ERROR_COLOR },
					} ) );
				}
			} );

			tbody.appendChild(
				el( 'tr', { 'data-instance-id': model.instance_id, draggable: 'true' }, [
					td( [ el( 'span', { textContent: '⠿', style: { cursor: 'grab', color: '#787c82' } } ) ] ),
					td( [
						el( 'strong', { textContent: model.display_name } ),
						el( 'br' ),
						el( 'code', { style: { fontSize: '0.85em' }, textContent: model.instance_id } ),
					] ),
					td( [ document.createTextNode( typeName ) ] ),
					td( [ document.createTextNode( capabilityBadges( model ) ) ] ),
					td( [ statusDot ] ),
					btnCell,
				] )
			);
		}

		table.appendChild( tbody );
		container.replaceChildren( table );
	}

	async function loadModels( container ) {
		container.replaceChildren(
			el( 'p', { className: 'description', textContent: __( 'Loading…', 'ai-provider-for-lmstudio' ) } )
		);

		try {
			const data = await lmFetch( '/api/v1/models' );
			const models = parseModels( data.models || [] );
			buildTable( models, container, () => loadModels( container ) );
		} catch ( err ) {
			container.replaceChildren(
				el( 'p', {
					textContent: __( 'Could not connect to LM Studio — is the server running?', 'ai-provider-for-lmstudio' ),
					style: { color: ERROR_COLOR },
				} )
			);
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( ! getSettings() ) {
			return;
		}
		const container = document.getElementById( 'lmstudio-models-container' );
		if ( container ) {
			loadModels( container );
		}
	} );
} )();
