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

	const ChunkClient = {
		async initJob( totalChunks, checksum, jobChunkSize ) {
			const response = await fetch( settings.restUrl + 'chunk/init', {
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

			return response.json();
		},

		async uploadChunk( jobId, index, chunk ) {
			const response = await fetch( settings.restUrl + 'chunk/upload', {
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

			return response.json();
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

	async function initDownloadJob() {
		const response = await fetch( settings.restUrl + 'chunk/download/init', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce,
			},
			body: JSON.stringify( {} ),
		} );

		return response.json();
	}

	async function fetchDownloadChunk( jobId, index ) {
		const response = await fetch(
			`${ settings.restUrl }chunk/download?job_id=${ jobId }&index=${ index }`,
			{
				headers: { 'X-WP-Nonce': settings.nonce },
			}
		);

		return response.json();
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
		try {
			setProgressLabel( 1, settings.i18n.preparing );
			setProgressLabel( 5, settings.i18n.exportBusy );

			const init = await initDownloadJob();
			const jobId = init.job_id;
			currentJobId = jobId;
			downloadInProgress = true;
			const totalChunks = init.total_chunks || 0;
			const parts = [];

			for ( let i = 0; i < totalChunks; i++ ) {
				const { chunk } = await fetchDownloadChunk( jobId, i );
				parts.push( base64ToUint8( chunk ) );

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
			console.error( error );
			alert( settings.i18n.downloadError );
			setProgressLabel( 0, settings.i18n.downloadError );
			hideProgressLabel( 2000 );
			if ( currentJobId ) {
				cancelChunkJob( currentJobId );
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
			const serverRadio = form.querySelector( 'input[name="import_source"][value="server"]' );
			if ( serverRadio && serverRadio.checked ) {
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

				fileInput.value = '';
				fileInput.disabled = true;

				setProgressLabel( 100, settings.i18n.importProcessing );
				form.submit();
			} catch ( error ) {
				console.error( error );
				alert( settings.i18n.uploadError );
				setProgressLabel( 0, settings.i18n.importError );
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
				setProgressLabel( 0, settings.i18n.exportFallback );
				form.removeAttribute( 'data-mksddn-full-export' );
				form.submit();
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
			fetch( settings.restUrl + 'chunk/cancel', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( { job_id: jobId } ),
				keepalive: keepAlive,
			} );
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
	} );
} )();
