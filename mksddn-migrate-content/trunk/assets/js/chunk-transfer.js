/* eslint-disable no-console */
( function () {
	const settings = window.mksddnChunk || {};
	let currentJobId = null;
	let uploadInProgress = false;
	let exportInProgress = false;
	let exportJobId = null;
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

		const data = await response.json();
		
		// Check for WordPress REST API error format.
		if ( ! response.ok || data.code ) {
			throw data;
		}
		
		// Set export tracking variables IMMEDIATELY after getting job_id.
		// This ensures they are set even if page is closed before export completes.
		if ( data.job_id ) {
			exportJobId = data.job_id;
			exportInProgress = true;
			
			// Also store in sessionStorage for reliability.
			try {
				sessionStorage.setItem( 'mksddn_export_job_id', data.job_id );
				sessionStorage.setItem( 'mksddn_export_in_progress', 'true' );
			} catch ( e ) {
				// Ignore storage errors.
			}
			
			console.log( 'MksDdn Migrate: Export started, jobId:', exportJobId );
		}
		
		return data;
	}

	async function getJobStatus( jobId ) {
		const response = await fetch(
			`${ settings.restUrl }chunk/status?job_id=${ jobId }`,
			{
				headers: { 'X-WP-Nonce': settings.nonce },
			}
		);

		const data = await response.json();
		
		// Check for WordPress REST API error format.
		if ( ! response.ok || data.code ) {
			throw data;
		}
		
		return data;
	}

	async function pollExportStatus( jobId, maxAttempts = 300 ) {
		let attempts = 0;
		
		while ( attempts < maxAttempts ) {
			// Check if export was cancelled.
			if ( ! exportInProgress || ! exportJobId ) {
				throw new Error( 'Export was cancelled' );
			}

			const status = await getJobStatus( jobId );
			
			if ( status.status === 'ready' ) {
				return status;
			}
			
			if ( status.status === 'error' ) {
				throw new Error( status.error || 'Export failed' );
			}

			// Wait before next poll.
			await yieldThread( 1000 );
			attempts++;
			
			// Update progress.
			const percent = Math.min( 10, Math.round( ( attempts / maxAttempts ) * 10 ) );
			setProgressLabel( percent, settings.i18n.exportBusy || 'Exporting...' );
		}
		
		throw new Error( 'Export timeout' );
	}

	async function fetchDownloadChunk( jobId, index ) {
		const response = await fetch(
			`${ settings.restUrl }chunk/download?job_id=${ jobId }&index=${ index }`,
			{
				headers: { 'X-WP-Nonce': settings.nonce },
			}
		);

		const data = await response.json();
		
		// Check for WordPress REST API error format.
		if ( ! response.ok || data.code ) {
			throw data;
		}
		
		return data;
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
			// Variables are already set in initDownloadJob().

			// If export is still processing, poll for status.
			if ( init.status === 'processing' ) {
				await pollExportStatus( init.job_id );
			}

			const status = await getJobStatus( init.job_id );
			if ( status.status === 'error' ) {
				throw new Error( status.error || 'Export failed' );
			}

			const totalChunks = status.total_chunks || 0;
			if ( totalChunks === 0 ) {
				throw new Error( 'Export not ready' );
			}

			const parts = [];

			for ( let i = 0; i < totalChunks; i++ ) {
				try {
					const response = await fetchDownloadChunk( init.job_id, i );
					const { chunk } = response;
					parts.push( base64ToUint8( chunk ) );
				} catch ( error ) {
					// Check if job was cancelled.
					if ( error.code === 'mksddn_job_cancelled' || ( error.data && error.data.status === 410 ) ) {
						throw new Error( settings.i18n.exportCancelled || 'Export was cancelled' );
					}
					throw error;
				}

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
			throw error;
		} finally {
			exportInProgress = false;
			exportJobId = null;
			
			// Clear sessionStorage.
			try {
				sessionStorage.removeItem( 'mksddn_export_job_id' );
				sessionStorage.removeItem( 'mksddn_export_in_progress' );
			} catch ( e ) {
				// Ignore storage errors.
			}
		}
	}
	function attachFullImportHandler() {
		const form = document.querySelector( '[data-mksddn-full-import]' );
		if ( ! form ) {
			return;
		}

		const fileInput = form.querySelector( 'input[type="file"]' );
		const submitButton = form.querySelector( 'button[type="submit"]' );

		form.addEventListener( 'submit', async ( event ) => {
			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				return;
			}

			event.preventDefault();

			const file = fileInput.files[ 0 ];
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

			// Set exportInProgress IMMEDIATELY when form is submitted.
			// This ensures it's set even if page is closed before request completes.
			exportInProgress = true;
			console.log( 'MksDdn Migrate: Export form submitted, exportInProgress set to true' );

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
			console.warn( 'MksDdn Migrate: cancelChunkJob called without jobId' );
			return;
		}

		console.log( 'MksDdn Migrate: Cancelling job', jobId, 'keepAlive:', keepAlive );

		try {
			const controller = new AbortController();
			const timeoutId = setTimeout( () => controller.abort(), 5000 ); // 5 second timeout
			
			fetch( settings.restUrl + 'chunk/cancel', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce,
				},
				body: JSON.stringify( { job_id: jobId } ),
				keepalive: keepAlive,
				signal: controller.signal,
			} ).then( ( response ) => {
				clearTimeout( timeoutId );
				console.log( 'MksDdn Migrate: Job cancellation request sent for', jobId, 'status:', response.status );
				return response.json();
			} ).then( ( data ) => {
				console.log( 'MksDdn Migrate: Job cancellation response for', jobId, data );
			} ).catch( ( error ) => {
				clearTimeout( timeoutId );
				if ( error.name !== 'AbortError' ) {
					console.error( 'MksDdn Migrate: Failed to cancel job', jobId, error );
				}
			} );
		} catch ( error ) {
			console.error( 'MksDdn Migrate: Exception cancelling job', jobId, error );
		}
	}

	function handlePageUnload() {
		console.log( 'MksDdn Migrate: Page unloading, exportInProgress:', exportInProgress, 'exportJobId:', exportJobId );
		
		// Try to get job ID from sessionStorage if not set in memory.
		let jobIdToCancel = exportJobId;
		let fromStorage = false;
		
		if ( ! jobIdToCancel ) {
			try {
				jobIdToCancel = sessionStorage.getItem( 'mksddn_export_job_id' );
				fromStorage = !! jobIdToCancel;
				if ( jobIdToCancel ) {
					console.log( 'MksDdn Migrate: Got job ID from sessionStorage:', jobIdToCancel );
				}
			} catch ( e ) {
				console.warn( 'MksDdn Migrate: Failed to read sessionStorage:', e );
			}
		}
		
		// Check if export is in progress (from memory or storage).
		let isExportInProgress = exportInProgress;
		if ( ! isExportInProgress ) {
			try {
				isExportInProgress = sessionStorage.getItem( 'mksddn_export_in_progress' ) === 'true';
				if ( isExportInProgress ) {
					console.log( 'MksDdn Migrate: Export in progress detected from sessionStorage' );
				}
			} catch ( e ) {
				// Ignore storage errors.
			}
		}
		
		console.log( 'MksDdn Migrate: Final check - isExportInProgress:', isExportInProgress, 'jobIdToCancel:', jobIdToCancel );
		
		if ( uploadInProgress && currentJobId ) {
			cancelChunkJob( currentJobId, true );
		}
		if ( isExportInProgress && jobIdToCancel ) {
			console.log( 'MksDdn Migrate: Cancelling export job from unload handler:', jobIdToCancel, 'fromStorage:', fromStorage );
			cancelChunkJob( jobIdToCancel, true );
		} else if ( isExportInProgress && ! jobIdToCancel ) {
			console.warn( 'MksDdn Migrate: Export in progress but no job ID found to cancel!' );
		}
	}

	// Use multiple events for better reliability.
	window.addEventListener( 'beforeunload', handlePageUnload );
	window.addEventListener( 'pagehide', handlePageUnload );
	document.addEventListener( 'visibilitychange', () => {
		if ( document.visibilityState === 'hidden' ) {
			handlePageUnload();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', () => {
		attachFullImportHandler();
		attachFullExportHandler();
	} );
} )();
