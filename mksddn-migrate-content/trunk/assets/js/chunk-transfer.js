/* eslint-disable no-console */
( function () {
	const settings = window.mksddnChunk || {};
	let currentJobId = null;
	let uploadInProgress = false;
	let downloadInProgress = false;
	const BYTES_KB = 1024;
	const BYTES_MB = 1024 * 1024;
	const BYTES_GB = 1024 * 1024 * 1024;
	const chunkSize = settings.chunkSize || 5 * BYTES_MB;
	const MIN_UPLOAD_CHUNK = 256 * BYTES_KB;
	const MAX_UPLOAD_CHUNK = Math.min( chunkSize, 5 * BYTES_MB );
	const baseUploadChunk = clamp(
		settings.uploadChunkSize || 1 * BYTES_MB,
		MIN_UPLOAD_CHUNK,
		MAX_UPLOAD_CHUNK
	);

	/**
	 * @param {string} message User-visible message.
	 * @returns {Error}
	 */
	function createChunkFailure( message ) {
		const err = new Error(
			message ||
			( settings.i18n && settings.i18n.uploadError ) ||
			'Chunk transfer failed.'
		);
		err.name = 'MksddnChunkFailure';
		return err;
	}

	/**
	 * @param {*} data Parsed JSON body.
	 * @returns {boolean}
	 */
	function isRestErrorPayload( data ) {
		return Boolean(
			data &&
			typeof data === 'object' &&
			typeof data.code === 'string' &&
			data.code.length > 0 &&
			typeof data.message === 'string'
		);
	}

	/**
	 * @param {*} payload Parsed JSON body.
	 * @returns {boolean}
	 */
	function isRestNoRoute( payload ) {
		return Boolean(
			isRestErrorPayload( payload ) &&
			payload.code === 'rest_no_route'
		);
	}

	/**
	 * @param {RequestInit} options Fetch options.
	 * @returns {RequestInit}
	 */
	function buildFetchOptions( options ) {
		return Object.assign(
			{
				credentials: 'same-origin',
				// Fail fast when http/https or www redirects would turn POST into GET.
				redirect: 'error',
			},
			options || {}
		);
	}

	/**
	 * @param {string} message User-visible message.
	 */
	function showRestWarning( message ) {
		if ( ! message ) {
			return;
		}

		document.querySelectorAll(
			'[data-mksddn-full-import], [data-mksddn-unified-import], [data-mksddn-full-export]'
		).forEach( ( form ) => {
			if ( form.querySelector( '.mksddn-chunk-rest-warning' ) ) {
				return;
			}

			const notice = document.createElement( 'div' );
			notice.className = 'notice notice-warning inline mksddn-chunk-rest-warning';
			const paragraph = document.createElement( 'p' );
			paragraph.textContent = message;
			notice.appendChild( paragraph );
			form.prepend( notice );
		} );
	}

	/**
	 * @param {Response} response Fetch response.
	 * @param {*} payload Parsed JSON body.
	 * @returns {string}
	 */
	function resolveRestFailureMessage( response, payload ) {
		const i18n = settings.i18n || {};

		if ( isRestNoRoute( payload ) ) {
			return i18n.restNoRoute ||
				'Migration REST endpoints are unavailable for this request.';
		}
		if ( isRestErrorPayload( payload ) ) {
			return payload.message;
		}
		if ( response.status === 404 ) {
			return i18n.restNotFound || 'WordPress REST API is not reachable (HTTP 404).';
		}
		if ( response.status === 401 || response.status === 403 ) {
			return i18n.restForbidden || 'You are not allowed to use the migration REST API.';
		}
		if ( response.status >= 500 ) {
			return i18n.restServerError || 'The server returned an error during chunked transfer.';
		}

		return ( i18n.uploadError || 'Chunk transfer failed.' ) + ' (' + response.status + ')';
	}

	/**
	 * @param {string} path REST path relative to mksddn/v1/.
	 * @param {RequestInit} options Fetch options.
	 * @returns {Promise<Object>}
	 */
	async function fetchChunkJson( path, options ) {
		let response;

		try {
			response = await fetch( settings.restUrl + path, buildFetchOptions( options ) );
		} catch ( error ) {
			if ( error instanceof TypeError ) {
				throw createChunkFailure(
					( settings.i18n && settings.i18n.restRedirect ) ||
					'The chunk transfer request was redirected.'
				);
			}
			throw error;
		}

		let payload;

		try {
			payload = await response.json();
		} catch ( e ) {
			if ( response.status === 404 ) {
				throw createChunkFailure( settings.i18n && settings.i18n.restNotFound );
			}
			throw createChunkFailure(
				( ( settings.i18n && settings.i18n.restInvalidResponse ) || 'Unexpected server response.' ) +
				' (' + response.status + ')'
			);
		}

		if ( ! response.ok || isRestErrorPayload( payload ) ) {
			throw createChunkFailure( resolveRestFailureMessage( response, payload ) );
		}

		return payload;
	}

	async function verifyChunkRestRoute() {
		if ( ! settings.restUrl || ! settings.nonce ) {
			return false;
		}

		try {
			const response = await fetch(
				settings.restUrl + 'chunk/ping',
				buildFetchOptions( {
					headers: { 'X-WP-Nonce': settings.nonce },
				} )
			);

			if ( ! response.ok ) {
				return false;
			}

			const payload = await response.json();
			return Boolean( payload && payload.ok );
		} catch ( error ) {
			return false;
		}
	}

	const ChunkClient = {
		async initJob( totalChunks, checksum, jobChunkSize ) {
			return fetchChunkJson( 'chunk/init', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {
					total_chunks: totalChunks,
					checksum,
					chunk_size: jobChunkSize,
				} ),
			} );
		},

		async uploadChunk( jobId, index, chunk ) {
			return fetchChunkJson( 'chunk/upload', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {
					job_id: jobId,
					index,
					chunk,
				} ),
			} );
		},
	};

	async function blobToBase64( blob ) {
		return new Promise( ( resolve, reject ) => {
			const reader = new FileReader();
			reader.onload = () => {
				const result = reader.result;
				if ( typeof result === 'string' ) {
					const commaIndex = result.indexOf( ',' );
					resolve( commaIndex >= 0 ? result.slice( commaIndex + 1 ) : result );
				} else {
					resolve( '' );
				}
			};
			reader.onerror = () => reject( reader.error || new Error( 'File read error' ) );
			reader.readAsDataURL( blob );
		} );
	}

	function yieldThread( delay = 0 ) {
		return new Promise( ( resolve ) => setTimeout( resolve, delay ) );
	}

	function clamp( value, min, max ) {
		return Math.max( min, Math.min( max, value ) );
	}

	/**
	 * @param {string} message User-visible message.
	 * @returns {Error}
	 */
	function createExportFailure( message ) {
		const err = new Error(
			message ||
			( settings.i18n && settings.i18n.exportUnknownError ) ||
			'Export failed.'
		);
		err.name = 'MksddnExportFailure';
		return err;
	}

	function isTransferFailure( error ) {
		return Boolean(
			error &&
			( error.name === 'MksddnChunkFailure' || error.name === 'MksddnExportFailure' ) &&
			error.message
		);
	}

	function formatBytes( bytes ) {
		if ( bytes >= BYTES_MB ) {
			return `${ ( bytes / BYTES_MB ).toFixed( 1 ) } MB`;
		}
		if ( bytes >= BYTES_KB ) {
			return `${ Math.round( bytes / BYTES_KB ) } KB`;
		}
		return `${ bytes } B`;
	}

	function selectChunkSize( fileSize ) {
		if ( fileSize >= 3 * BYTES_GB ) {
			return Math.min( 3 * BYTES_MB, MAX_UPLOAD_CHUNK );
		}
		if ( fileSize >= 2 * BYTES_GB ) {
			return Math.min( 2.5 * BYTES_MB, MAX_UPLOAD_CHUNK );
		}
		if ( fileSize >= 1 * BYTES_GB ) {
			return Math.min( 2 * BYTES_MB, MAX_UPLOAD_CHUNK );
		}
		if ( fileSize >= 512 * BYTES_MB ) {
			return Math.min( 1.5 * BYTES_MB, MAX_UPLOAD_CHUNK );
		}
		return baseUploadChunk;
	}

	function formatChunkInfo( bytes ) {
		if ( ! bytes ) {
			return '';
		}
		const template = settings.i18n.chunkInfo || `· ${ formatBytes( bytes ) } chunks`;
		return template.replace( '%s', formatBytes( bytes ) );
	}

	function withChunkInfo( message, bytes ) {
		const info = formatChunkInfo( bytes );
		return info ? `${ message } ${ info }` : message;
	}

function setProgressLabel( percent, message ) {
	if ( window.mksddnMcProgress && typeof window.mksddnMcProgress.set === 'function' ) {
		const clamped = typeof percent === 'number'
			? Math.max( 0, Math.min( 100, percent ) )
			: 0;
		window.mksddnMcProgress.set( clamped, message || '' );
	}
}

function hideProgressLabel( delay = 0 ) {
	const hide = () => {
		if ( window.mksddnMcProgress && typeof window.mksddnMcProgress.hide === 'function' ) {
			window.mksddnMcProgress.hide();
		}
	};

	if ( delay > 0 ) {
		setTimeout( hide, delay );
	} else {
		hide();
	}
}

	function base64ToUint8( base64 ) {
		const binary = atob( base64 );
		const len = binary.length;
		const bytes = new Uint8Array( len );
		for ( let i = 0; i < len; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return bytes;
	}

	async function uploadFileInChunks( file ) {
		let jobChunkSize = selectChunkSize( file.size );
		let totalChunks = Math.max( 1, Math.ceil( file.size / jobChunkSize ) );
		const init = await ChunkClient.initJob( totalChunks, '', jobChunkSize );
		const jobId = init.job_id;

		if ( ! jobId ) {
			throw createChunkFailure(
				( settings.i18n && settings.i18n.restInvalidInit ) ||
				'Invalid response when starting chunked upload.'
			);
		}

		const negotiatedSize = init.chunk_size || jobChunkSize;
		currentJobId = jobId;
		uploadInProgress = true;

		if ( negotiatedSize !== jobChunkSize ) {
			jobChunkSize = negotiatedSize;
			totalChunks = Math.max( 1, Math.ceil( file.size / jobChunkSize ) );
		}

		setProgressLabel(
			0,
			withChunkInfo( settings.i18n.importBusy.replace( '%d', 0 ), jobChunkSize )
		);

		let index = 0;
		while ( index < totalChunks ) {
			const start = index * jobChunkSize;
			const chunkBlob = file.slice( start, start + jobChunkSize );
			const base64 = await blobToBase64( chunkBlob );

			await ChunkClient.uploadChunk( jobId, index, base64 );
			await yieldThread();

			index++;

			const percent = Math.min( 100, Math.round( ( index / totalChunks ) * 100 ) );
			setProgressLabel(
					percent,
					settings.i18n.uploading.replace( '%d', percent )
			);
		}

		setProgressLabel( 100, settings.i18n.importDone );
		uploadInProgress = false;
		return jobId;
	}

	async function downloadFullSite() {
		let jobId = null;
		try {
			setProgressLabel( 1, settings.i18n.preparing );
			setProgressLabel( 5, settings.i18n.exportBusy );

			const init = await fetchChunkJson( 'chunk/download/init', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( {} ),
			} );

			if ( ! init.job_id || ! init.total_chunks ) {
				throw createExportFailure(
					( settings.i18n && settings.i18n.exportInvalidInit ) || 'Invalid export response from server.'
				);
			}

			jobId = init.job_id;
			currentJobId = jobId;
			downloadInProgress = true;
			const totalChunks = init.total_chunks;
			const parts = [];

			for ( let i = 0; i < totalChunks; i++ ) {
				const payload = await fetchChunkJson(
					`chunk/download?job_id=${ encodeURIComponent( jobId ) }&index=${ i }`,
					{
						headers: { 'X-WP-Nonce': settings.nonce },
					}
				);

				if ( typeof payload.chunk !== 'string' ) {
					throw createExportFailure(
						( settings.i18n && settings.i18n.exportInvalidChunk ) || 'Invalid chunk data from server.'
					);
				}

				parts.push( base64ToUint8( payload.chunk ) );

				const percent = Math.min( 100, Math.round( ( ( i + 1 ) / totalChunks ) * 100 ) );
				setProgressLabel(
					percent,
					settings.i18n.downloading.replace( '%d', percent )
				);
			}

			const blob = new Blob( parts, { type: 'application/octet-stream' } );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			const fallbackName = `full-site-${ Date.now() }.wpbkp`;
			a.download = settings.downloadFilename || fallbackName;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );

			setProgressLabel( 100, settings.i18n.downloadComplete );
			setProgressLabel( 100, settings.i18n.exportDone );
			hideProgressLabel( 2000 );
		} catch ( error ) {
			if ( jobId ) {
				cancelChunkJob( jobId );
			}
			throw error;
		} finally {
			downloadInProgress = false;
			currentJobId = null;
		}
	}
	function attachFullImportHandler() {
		const form = document.querySelector( '[data-mksddn-full-import], [data-mksddn-unified-import]' );
		if ( ! form ) {
			return;
		}

		const fileInput = form.querySelector( 'input[type="file"]' );
		const submitButton = form.querySelector( 'button[type="submit"]' );

		form.addEventListener( 'submit', async ( event ) => {
			// Skip chunked upload if server file is selected.
			const sourceInput = form.querySelector( 'input[name="import_source"]:checked' )
				|| form.querySelector( 'input[name="import_source"]' );
			if ( sourceInput && 'server' === sourceInput.value ) {
				return;
			}

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				return;
			}

			const file = fileInput.files[ 0 ];
			// Only use chunked upload for .wpbkp files (full site imports).
			// JSON files are typically smaller and don't need chunking.
			if ( ! file.name.toLowerCase().endsWith( '.wpbkp' ) ) {
				return;
			}

			event.preventDefault();

			if ( submitButton ) {
				submitButton.disabled = true;
			}

			try {
				const jobId = await uploadFileInChunks( file );
				const hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = 'chunk_job_id';
				hidden.value = jobId;
				form.appendChild( hidden );

				const hiddenName = document.createElement( 'input' );
				hiddenName.type = 'hidden';
				hiddenName.name = 'chunk_original_name';
				hiddenName.value = file.name;
				form.appendChild( hiddenName );

				fileInput.value = '';
				fileInput.disabled = true;

				setProgressLabel( 100, settings.i18n.importProcessing );
				form.submit();
			} catch ( error ) {
				console.error( error );
				const message = isTransferFailure( error ) ? error.message : settings.i18n.uploadError;
				alert( message );
				setProgressLabel( 0, message );
				hideProgressLabel( 2500 );
				cancelChunkJob( currentJobId );
				if ( submitButton ) {
					submitButton.disabled = false;
				}
			} finally {
				uploadInProgress = false;
				await yieldThread( 0 );
			}
		} );
	}

	function attachFullExportHandler() {
		const form = document.querySelector( '[data-mksddn-full-export]' );
		if ( ! form ) {
			return;
		}

		let busy = false;
		form.addEventListener( 'submit', async ( event ) => {
			if ( busy ) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			busy = true;
			const button = form.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
			}

			try {
				await downloadFullSite();
			} catch ( error ) {
				if ( isTransferFailure( error ) ) {
					window.alert( error.message );
					setProgressLabel( 0, error.message );
					hideProgressLabel( 4000 );
				} else {
					console.error( error );
					setProgressLabel( 0, settings.i18n.exportFallback );
					form.removeAttribute( 'data-mksddn-full-export' );
					form.submit();
				}
			} finally {
				if ( button ) {
					button.disabled = false;
				}
				busy = false;
			}
		} );
	}

	function cancelChunkJob( jobId, keepAlive = false ) {
		if ( ! jobId ) {
			return;
		}

		try {
			fetch(
				settings.restUrl + 'chunk/cancel',
				buildFetchOptions( {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': settings.nonce,
					},
					body: JSON.stringify( { job_id: jobId } ),
					keepalive: keepAlive,
				} )
			);
		} catch ( error ) {
			// Ignore cleanup failures.
		}
	}

	window.addEventListener( 'beforeunload', () => {
		if ( currentJobId && ( uploadInProgress || downloadInProgress ) ) {
			cancelChunkJob( currentJobId, true );
		}
	} );

	document.addEventListener( 'DOMContentLoaded', () => {
		attachFullImportHandler();
		attachFullExportHandler();

		verifyChunkRestRoute()
			.then( ( restReady ) => {
				if ( restReady ) {
					return;
				}

				showRestWarning(
					( settings.i18n && settings.i18n.restPreflightFailed ) ||
					'Chunk transfer endpoints are not reachable yet.'
				);
			} )
			.catch( () => {
				// Ignore check failures: form handlers are already attached.
			} );
	} );
} )();
