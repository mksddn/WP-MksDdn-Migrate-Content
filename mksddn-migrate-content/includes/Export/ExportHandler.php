<?php
/**
 * Export handler.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Export;

use Mksddn_MC\Archive\Packer;
use Mksddn_MC\Media\AttachmentCollector;
use Mksddn_MC\Media\AttachmentCollection;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles export operations for pages, options pages and forms.
 */
class ExportHandler {

	private const EXPORT_TYPES = array(
		'page' => 'export_page',
		'post' => 'export_page',
	);

	/**
	 * Requested export format (archive|json).
	 *
	 * @var string
	 */
	private string $format = 'archive';

	private Packer $packer;

	/**
	 * Attachment collector.
	 */
	private AttachmentCollector $media_collector;

	/**
	 * Setup handler.
	 *
	 * @param Packer|null             $packer          Optional packer.
	 * @param AttachmentCollector|null $media_collector Optional collector.
	 */
	public function __construct( ?Packer $packer = null, ?AttachmentCollector $media_collector = null ) {
		$this->packer          = $packer ?? new Packer();
		$this->media_collector = $media_collector ?? new AttachmentCollector();
	}

	/**
	 * Handle export dispatch.
	 */
	public function export_single_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$export_type = sanitize_key( $_POST['export_type'] ?? '' );

		if ( ! isset( self::EXPORT_TYPES[ $export_type ] ) ) {
			wp_die( esc_html__( 'Invalid export type.', 'mksddn-migrate-content' ) );
		}

		$this->format = $this->resolve_format( $export_type );

		$method = self::EXPORT_TYPES[ $export_type ];
		$this->$method();
	}


	/**
	 * Export a single page.
	 *
	 * @return void
	 */
	private function export_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$target_id = $this->resolve_target_id();
		if ( 0 === $target_id ) {
			wp_die( esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$post = get_post( $target_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			wp_die( esc_html__( 'Invalid content ID.', 'mksddn-migrate-content' ) );
		}

		$media = $this->collect_media_for_post( $post );
		$data  = $this->prepare_post_data( $post, $media );
		$this->deliver_payload( $data, $post->post_type . '-' . $target_id, $media );
	}


	/**
	 * Export a form.
	 *
	 * @return void
	 */
	private function export_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$form_id = absint( $_POST['form_id'] ?? 0 );
		if ( 0 === $form_id ) {
			wp_die( esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$form = get_post( $form_id );
		if ( ! $form || 'forms' !== $form->post_type ) {
			wp_die( esc_html__( 'Invalid form ID.', 'mksddn-migrate-content' ) );
		}

		$media = $this->collect_media_for_post( $form );
		$data  = $this->prepare_form_data( $form, $media );
		$this->deliver_payload( $data, 'form-' . $form_id, $media );
	}

	/**
	 * Prepare post/page payload.
	 *
	 * @param WP_Post                 $post  Post object.
	 * @param AttachmentCollection|null $media Media bundle.
	 * @return array
	 */
	private function prepare_post_data( WP_Post $post, ?AttachmentCollection $media = null ): array {
		$data = array(
			'type'       => $post->post_type,
			'ID'         => $post->ID,
			'title'      => $post->post_title,
			'content'    => $post->post_content,
			'excerpt'    => $post->post_excerpt,
			'slug'       => $post->post_name,
			'status'     => $post->post_status,
			'author'     => $post->post_author,
			'date'       => $post->post_date_gmt,
			'acf_fields' => function_exists( 'get_fields' ) ? get_fields( $post->ID ) : array(),
			'meta'       => get_post_meta( $post->ID ),
			'featured_media' => get_post_thumbnail_id( $post ),
		);

		if ( 'post' === $post->post_type ) {
			$data['taxonomies'] = $this->collect_taxonomies( $post->ID );
		}

		if ( $media && $media->has_items() ) {
			$data['_mksddn_media'] = $media->get_manifest();
		}

		return $data;
	}
	/**
	 * Resolve target ID based on requested type.
	 */
	private function resolve_target_id(): int {
		$type = sanitize_key( $_POST['export_type'] ?? 'page' );
		return match ( $type ) {
			'post' => absint( $_POST['post_id'] ?? 0 ),
			default => absint( $_POST['page_id'] ?? 0 ),
		};
	}

	/**
	 * Collect taxonomy terms for posts.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function collect_taxonomies( int $post_id ): array {
		$taxonomies = get_object_taxonomies( 'post', 'names' );
		$result     = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$result[ $taxonomy ] = array_map(
				static function ( $term ) {
					return array(
						'slug'        => $term->slug,
						'name'        => $term->name,
						'description' => $term->description,
					);
				},
				$terms
			);
		}

		return $result;
	}

	/**
	 * Prepare form payload.
	 *
	 * @param WP_Post $form Form.
	 * @return array
	 */
	private function prepare_form_data( WP_Post $form, ?AttachmentCollection $media = null ): array {
		$fields_config = get_post_meta( $form->ID, '_fields_config', true );

		$data = array(
			'type'          => 'forms',
			'ID'            => $form->ID,
			'title'         => $form->post_title,
			'content'       => $form->post_content,
			'excerpt'       => $form->post_excerpt,
			'slug'          => $form->post_name,
			'fields_config' => $fields_config,
			'fields'        => json_decode( $fields_config, true ),
			'acf_fields'    => function_exists( 'get_fields' ) ? get_fields( $form->ID ) : array(),
			'meta'          => get_post_meta( $form->ID ),
		);

		if ( $media && $media->has_items() ) {
			$data['_mksddn_media'] = $media->get_manifest();
		}

		return $data;
	}

	/**
	 * Ship payload either as archive or debug JSON.
	 *
	 * @param array  $data     Payload contents.
	 * @param string                 $basename Filename base without extension.
	 * @param AttachmentCollection|null $media Media bundle.
	 */
	private function deliver_payload( array $data, string $basename, ?AttachmentCollection $media = null ): void {
		if ( $this->should_output_json() ) {
			$this->download_json( $data, $basename . '.json' );
			return;
		}

		$media_manifest = $media && $media->has_items() ? $media->get_manifest() : array();

		$archive = $this->packer->create_archive(
			$data,
			array(
				'type'  => $data['type'] ?? 'page',
				'label' => $basename,
				'media' => $media_manifest,
			),
			$media ? $media->get_assets() : array()
		);

		if ( is_wp_error( $archive ) ) {
			wp_die( esc_html( $archive->get_error_message() ) );
		}

		$this->download_archive( $archive, $basename . '.wpbkp' );
	}

	/**
	 * Determine whether JSON export is enabled.
	 */
	private function should_output_json(): bool {
		if ( 'json' === $this->format ) {
			return true;
		}

		$toggle = defined( 'MKSDDN_MC_DEBUG_JSON_EXPORT' ) && MKSDDN_MC_DEBUG_JSON_EXPORT;
		/**
		 * Filter enabling legacy JSON export output.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled JSON export enabled.
		 */
		return (bool) apply_filters( 'mksddn_mc_enable_json_export', $toggle );
	}

	/**
	 * Download archive file to browser.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @param string $filename     Download filename.
	 */
	private function download_archive( string $archive_path, string $filename ): void {
		$handle = @fopen( $archive_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

		if ( ! $handle ) {
			wp_die( esc_html__( 'Failed to open archive for download.', 'mksddn-migrate-content' ) );
		}

		// Clear buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filesize = filesize( $archive_path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . $filesize );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream output.
		fpassthru( $handle );
		fclose( $handle );
		unlink( $archive_path );
		exit;
	}

	/**
	 * Stream JSON file to the browser (debug mode only).
	 *
	 * @param array  $data     Payload.
	 * @param string $filename Filename.
	 * @return void
	 */
	private function download_json( array $data, string $filename ): void {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a JSON file payload.
		echo $json;
		exit;
	}

	/**
	 * Decide which format to use for current export.
	 *
	 * @param string $export_type Selected export type.
	 * @return string
	 */
	private function resolve_format( string $export_type ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked upstream.
		$requested = sanitize_key( $_POST['export_format'] ?? 'archive' );
		$allowed   = array( 'archive', 'json' );

		if ( ! in_array( $requested, $allowed, true ) ) {
			return 'archive';
		}

		if ( 'json' === $requested && ! $this->is_json_allowed( $export_type ) ) {
			return 'archive';
		}

		return $requested;
	}

	/**
	 * Whether JSON format is supported for given type.
	 *
	 * @param string $export_type Export type.
	 */
	private function is_json_allowed( string $export_type ): bool {
		$allowed_types = array( 'page', 'post', 'options_page', 'forms' );
		/**
		 * Filter list of export types that support JSON output.
		 *
		 * @since 1.0.0
		 *
		 * @param array $allowed_types Types list.
		 */
		$allowed_types = apply_filters( 'mksddn_mc_json_export_types', $allowed_types );

		return in_array( $export_type, $allowed_types, true );
	}

	/**
	 * Collect media for given post if supported.
	 *
	 * @param WP_Post|null $post Post reference.
	 * @return AttachmentCollection|null
	 */
	private function collect_media_for_post( ?WP_Post $post ): ?AttachmentCollection {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( 'archive' !== $this->format ) {
			return null;
		}

		return $this->media_collector->collect_for_post( $post );
	}
}
