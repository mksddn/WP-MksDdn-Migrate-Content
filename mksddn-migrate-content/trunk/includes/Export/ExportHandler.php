<?php
/**
 * Export handler.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Export;

use MksDdn\MigrateContent\Archive\Packer;
use MksDdn\MigrateContent\Contracts\ExporterInterface;
use MksDdn\MigrateContent\Core\BatchLoader;
use MksDdn\MigrateContent\Media\AttachmentCollector;
use MksDdn\MigrateContent\Media\AttachmentCollection;
use MksDdn\MigrateContent\Options\OptionsExporter;
use MksDdn\MigrateContent\Selection\ContentSelection;
use MksDdn\MigrateContent\Support\FilenameBuilder;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles export operations for pages, options pages and forms.
 */
class ExportHandler implements ExporterInterface {

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

	private OptionsExporter $options_exporter;

	/**
	 * Batch loader for optimizing database queries.
	 *
	 * @var BatchLoader
	 */
	private BatchLoader $batch_loader;

	/**
	 * Whether to gather media files for the current export.
	 */
	private bool $collect_media = true;

	/**
	 * Setup handler.
	 *
	 * @param Packer|null              $packer           Optional packer.
	 * @param AttachmentCollector|null $media_collector  Optional collector.
	 * @param OptionsExporter|null     $options_exporter Optional options exporter.
	 * @param BatchLoader|null         $batch_loader     Optional batch loader.
	 */
	public function __construct( ?Packer $packer = null, ?AttachmentCollector $media_collector = null, ?OptionsExporter $options_exporter = null, ?BatchLoader $batch_loader = null ) {
		$this->packer           = $packer ?? new Packer();
		$this->media_collector  = $media_collector ?? new AttachmentCollector();
		$this->options_exporter = $options_exporter ?? new OptionsExporter();
		$this->batch_loader     = $batch_loader ?? new BatchLoader();
	}

	/**
	 * Handle export dispatch.
	 */
	public function export_single_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$export_type = \sanitize_key( $_POST['export_type'] ?? '' );

		if ( ! isset( self::EXPORT_TYPES[ $export_type ] ) ) {
			\wp_die( \esc_html__( 'Invalid export type.', 'mksddn-migrate-content' ) );
		}

		$this->format = $this->resolve_format( $export_type );

		$method = self::EXPORT_TYPES[ $export_type ];
		$this->$method();
	}

	/**
	 * Export arbitrary selection of content items.
	 *
	 * @param ContentSelection $selection Selection object.
	 * @param string           $format    Requested format.
	 * @return void
	 */
	public function export_selected_content( ContentSelection $selection, string $format = 'archive' ): void {
		if ( ! $selection->has_items() && ! $selection->has_options() ) {
			\wp_die( \esc_html__( 'Select at least one item to export.', 'mksddn-migrate-content' ) );
		}

		$this->format = $this->normalize_format( $format );

		if ( 1 === $selection->count_items() && ! $selection->has_options() ) {
			$first = $selection->first_item();
			if ( $first ) {
				$this->export_post_by_id( (int) $first['id'] );
				return;
			}
		}

		$this->export_selection_bundle( $selection );
	}

	/**
	 * Toggle media collection for current export.
	 *
	 * @param bool $enabled Flag state.
	 */
	public function set_collect_media( bool $enabled ): void {
		$this->collect_media = $enabled;
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
			\wp_die( \esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$this->export_post_by_id( $target_id, array( 'page', 'post' ) );
	}

	/**
	 * Export a single post object by ID.
	 *
	 * @param int   $post_id      Target post ID.
	 * @param array $allowed_types Optional whitelist (empty = allow all).
	 */
	private function export_post_by_id( int $post_id, array $allowed_types = array() ): void {
		$post = \get_post( $post_id );
		if ( ! $post ) {
			\wp_die( \esc_html__( 'Invalid content ID.', 'mksddn-migrate-content' ) );
		}

		if ( ! empty( $allowed_types ) && ! in_array( $post->post_type, $allowed_types, true ) ) {
			\wp_die( \esc_html__( 'Requested type is not allowed for this export.', 'mksddn-migrate-content' ) );
		}

		$media = $this->collect_media_for_post( $post );
		$data  = $this->prepare_payload_for_post( $post, $media );
		$this->deliver_payload( $data, 'selected', $media );
	}

	/**
	 * Prepare payload data for post/form entity.
	 *
	 * @param WP_Post                 $post  Post object.
	 * @param AttachmentCollection|null $media Media bundle.
	 * @return array
	 */
	private function prepare_payload_for_post( WP_Post $post, ?AttachmentCollection $media = null ): array {
		if ( 'forms' === $post->post_type ) {
			return $this->prepare_form_data( $post, $media );
		}

		return $this->prepare_post_data( $post, $media );
	}
	private function export_selection_bundle( ContentSelection $selection ): void {
		$bundle = array(
			'type'    => 'bundle',
			'items'   => array(),
			'options' => array(
				'options' => array(),
				'widgets' => array(),
			),
		);

		$combined_media = new AttachmentCollection();

		// Collect all post IDs first for batch loading.
		$all_post_ids = array();
		$posts_by_id  = array();

		foreach ( $selection->get_items() as $type => $ids ) {
			foreach ( $ids as $id ) {
				$all_post_ids[] = (int) $id;
			}
		}

		// Load all posts in batch.
		if ( ! empty( $all_post_ids ) ) {
			$posts = \get_posts(
				array(
					'post__in'       => $all_post_ids,
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			);

			foreach ( $posts as $post ) {
				$posts_by_id[ $post->ID ] = $post;
			}

			// Preload meta, thumbnails, and ACF fields in batch.
			$this->batch_loader->load_post_meta_batch( $all_post_ids );
			$this->batch_loader->load_thumbnails_batch( $all_post_ids );
			$this->batch_loader->load_acf_fields_batch( $all_post_ids );

			// Preload taxonomy terms for posts.
			$post_type_ids = array();
			foreach ( $posts_by_id as $post ) {
				if ( 'post' === $post->post_type ) {
					$post_type_ids[] = $post->ID;
				}
			}

			if ( ! empty( $post_type_ids ) ) {
				$taxonomies = \get_object_taxonomies( 'post', 'names' );
				foreach ( $taxonomies as $taxonomy ) {
					$this->batch_loader->load_terms_batch( $post_type_ids, $taxonomy );
				}
			}
		}

		foreach ( $selection->get_items() as $type => $ids ) {
			foreach ( $ids as $id ) {
				$post = $posts_by_id[ $id ] ?? null;
				if ( ! $post ) {
					continue;
				}

				$media = $this->collect_media_for_post( $post );
				$bundle['items'][] = $this->prepare_payload_for_post( $post, $media );

				if ( $media && $media->has_items() ) {
					$combined_media->absorb( $media );
				}
			}
		}

		if ( $selection->has_options() ) {
			$bundle['options']['options'] = $this->options_exporter->export_options( $selection->get_options() );
			$bundle['options']['widgets'] = $this->options_exporter->export_widgets( $selection->get_widgets() );
		}

		$media_payload = $combined_media->has_items() ? $combined_media : null;
		$this->deliver_payload( $bundle, 'selected', $media_payload );
	}


	/**
	 * Export a form.
	 *
	 * @return void
	 */
	private function export_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$form_id = \absint( $_POST['form_id'] ?? 0 );
		if ( 0 === $form_id ) {
			\wp_die( \esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$form = \get_post( $form_id );
		if ( ! $form || 'forms' !== $form->post_type ) {
			\wp_die( \esc_html__( 'Invalid form ID.', 'mksddn-migrate-content' ) );
		}

		$media = $this->collect_media_for_post( $form );
		$data  = $this->prepare_form_data( $form, $media );
		$this->deliver_payload( $data, 'selected', $media );
	}

	/**
	 * Prepare post/page payload.
	 *
	 * @param WP_Post                 $post  Post object.
	 * @param AttachmentCollection|null $media Media bundle.
	 * @return array
	 */
	private function prepare_post_data( WP_Post $post, ?AttachmentCollection $media = null ): array {
		// Use batch loader for optimized queries.
		$meta_data = $this->batch_loader->get_post_meta( $post->ID );
		$acf_fields = $this->batch_loader->get_acf_fields( $post->ID );
		$thumbnail_id = $this->batch_loader->get_post_thumbnail_id( $post->ID );

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
			'acf_fields' => $acf_fields,
			'meta'       => $meta_data,
			'featured_media' => $thumbnail_id,
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in admin controller before calling handler.
		$type = \sanitize_key( $_POST['export_type'] ?? 'page' );
		return match ( $type ) {
			'post' => \absint( $_POST['post_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validated upstream
			default => \absint( $_POST['page_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validated upstream
		};
	}

	/**
	 * Collect taxonomy terms for posts.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function collect_taxonomies( int $post_id ): array {
		$taxonomies = \get_object_taxonomies( 'post', 'names' );
		$result     = array();

		foreach ( $taxonomies as $taxonomy ) {
			// Use batch loader for optimized queries.
			$terms = $this->batch_loader->get_terms( $post_id, $taxonomy );
			if ( empty( $terms ) ) {
				continue;
			}

			$result[ $taxonomy ] = $terms;
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
		// Use batch loader for optimized queries.
		$meta_data = $this->batch_loader->get_post_meta( $form->ID );
		$acf_fields = $this->batch_loader->get_acf_fields( $form->ID );
		$fields_config = $meta_data['_fields_config'] ?? '';

		$data = array(
			'type'          => 'forms',
			'ID'            => $form->ID,
			'title'         => $form->post_title,
			'content'       => $form->post_content,
			'excerpt'       => $form->post_excerpt,
			'slug'          => $form->post_name,
			'fields_config' => is_string( $fields_config ) ? $fields_config : '',
			'fields'        => is_string( $fields_config ) ? json_decode( $fields_config, true ) : array(),
			'acf_fields'    => $acf_fields,
			'meta'          => $meta_data,
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
	private function deliver_payload( array $data, string $context, ?AttachmentCollection $media = null ): void {
		$is_json   = $this->should_output_json();
		$extension = $is_json ? 'json' : 'wpbkp';
		$filename  = FilenameBuilder::build( $context, $extension );
		$label     = pathinfo( $filename, PATHINFO_FILENAME );

		if ( $is_json ) {
			$this->download_json( $data, $filename );
			return;
		}

		$media_manifest = $media && $media->has_items() ? $media->get_manifest() : array();

		$archive = $this->packer->create_archive(
			$data,
			array(
				'type'  => $data['type'] ?? 'page',
				'label' => $label,
				'media' => $media_manifest,
			),
			$media ? $media->get_assets() : array()
		);

		if ( \is_wp_error( $archive ) ) {
			\wp_die( \esc_html( $archive->get_error_message() ) );
		}

		$this->download_archive( $archive, $filename );
	}

	/**
	 * Determine whether JSON export is enabled.
	 */
	private function should_output_json(): bool {
		if ( 'json' === $this->format ) {
			return true;
		}

		$toggle = defined( 'MKSDDN_MC_DEBUG_JSON_EXPORT' ) && \MKSDDN_MC_DEBUG_JSON_EXPORT;
		/**
		 * Filter enabling legacy JSON export output.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled JSON export enabled.
		 */
		return (bool) \apply_filters( 'mksddn_mc_enable_json_export', $toggle );
	}

	/**
	 * Download archive file to browser.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @param string $filename     Download filename.
	 */
	private function download_archive( string $archive_path, string $filename ): void {
		$handle = fopen( $archive_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming download requires native handle

		if ( ! $handle ) {
			\wp_die( \esc_html__( 'Failed to open archive for download.', 'mksddn-migrate-content' ) );
		}

		// Clear buffers.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filesize = filesize( $archive_path );

		\nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . $filesize );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream output.
		fpassthru( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen
		FilesystemHelper::delete( $archive_path );
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
		$json = \wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		\nocache_headers();
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
		$requested = \sanitize_key( $_POST['export_format'] ?? 'archive' );
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
	 * Normalize raw format string (selection mode).
	 *
	 * @param string $format Requested format.
	 * @return string
	 */
	private function normalize_format( string $format ): string {
		$allowed = array( 'archive', 'json' );
		$format  = \sanitize_key( $format );

		return in_array( $format, $allowed, true ) ? $format : 'archive';
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
		$allowed_types = \apply_filters( 'mksddn_mc_json_export_types', $allowed_types );

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

		if ( ! $this->collect_media ) {
			return null;
		}

		if ( 'archive' !== $this->format ) {
			return null;
		}

		return $this->media_collector->collect_for_post( $post );
	}
}
