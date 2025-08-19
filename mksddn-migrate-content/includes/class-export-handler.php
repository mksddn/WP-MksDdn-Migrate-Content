<?php
/**
 * Export handler.
 *
 * @package MksDdn_Migrate_Content
 */

/**
 * Handles export operations for pages, options pages and forms.
 */
class Export_Handler {

	private const EXPORT_TYPES = array(
		'page'         => 'export_page',
		'options_page' => 'export_options_page',
		'forms'        => 'export_form',
	);


	/**
	 * Handle export dispatch.
	 */
	public function export_single_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$export_type = sanitize_key( $_POST['export_type'] ?? '' );

		if ( ! isset( self::EXPORT_TYPES[ $export_type ] ) ) {
			wp_die( esc_html__( 'Invalid export type.', 'mksddn-migrate-content' ) );
		}

		$method = self::EXPORT_TYPES[ $export_type ];
		$this->$method();
	}


	/**
	 * Export options page via ACF.
	 *
	 * @return void
	 */
	private function export_options_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$options_page_slug = sanitize_key( $_POST['options_page_slug'] ?? '' );
		if ( '' === $options_page_slug ) {
			wp_die( esc_html__( 'Invalid options page slug.', 'mksddn-migrate-content' ) );
		}

		$options_helper = new Options_Helper();
		$options_pages  = $options_helper->get_all_options_pages();
		$target_page    = $this->find_options_page( $options_pages, $options_page_slug );

		if ( ! $target_page ) {
			wp_die( esc_html__( 'Invalid options page slug.', 'mksddn-migrate-content' ) );
		}

		$data     = $this->prepare_options_page_data( $target_page );
		$filename = 'options-page-' . $options_page_slug . '.json';
		$this->download_json( $data, $filename );
	}


	/**
	 * Export a single page.
	 *
	 * @return void
	 */
	private function export_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in admin controller before dispatch.
		$page_id = absint( $_POST['page_id'] ?? 0 );
		if ( 0 === $page_id ) {
			wp_die( esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			wp_die( esc_html__( 'Invalid page ID.', 'mksddn-migrate-content' ) );
		}

		$data     = $this->prepare_page_data( $page );
		$filename = 'page-' . $page_id . '.json';
		$this->download_json( $data, $filename );
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

		$data     = $this->prepare_form_data( $form );
		$filename = 'form-' . $form_id . '.json';
		$this->download_json( $data, $filename );
	}

	/**
	 * Find options page by slug.
	 *
	 * @param array  $options_pages Options page list.
	 * @param string $slug          Options page slug.
	 * @return array|null
	 */
	private function find_options_page( array $options_pages, string $slug ): ?array {
		foreach ( $options_pages as $page ) {
			if ( $page['menu_slug'] === $slug ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Prepare options page payload.
	 *
	 * @param array $target_page Target page.
	 * @return array
	 */
	private function prepare_options_page_data( array $target_page ): array {
		return array(
			'type'       => 'options_page',
			'menu_slug'  => $target_page['menu_slug'],
			'page_title' => $target_page['page_title'] ?? '',
			'menu_title' => $target_page['menu_title'] ?? '',
			'post_id'    => $target_page['post_id'] ?? '',
			'acf_fields' => function_exists( 'get_fields' ) ? get_fields( $target_page['post_id'] ) : array(),
		);
	}

	/**
	 * Prepare page payload.
	 *
	 * @param WP_Post $page Page.
	 * @return array
	 */
	private function prepare_page_data( WP_Post $page ): array {
		return array(
			'type'       => 'page',
			'ID'         => $page->ID,
			'title'      => $page->post_title,
			'content'    => $page->post_content,
			'excerpt'    => $page->post_excerpt,
			'slug'       => $page->post_name,
			'acf_fields' => function_exists( 'get_fields' ) ? get_fields( $page->ID ) : array(),
			'meta'       => get_post_meta( $page->ID ),
		);
	}

	/**
	 * Prepare form payload.
	 *
	 * @param WP_Post $form Form.
	 * @return array
	 */
	private function prepare_form_data( WP_Post $form ): array {
		$fields_config = get_post_meta( $form->ID, '_fields_config', true );

		return array(
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
	}

	/**
	 * Stream JSON file to the browser.
	 *
	 * @param array  $data     Payload.
	 * @param string $filename Filename.
	 * @return void
	 */
	private function download_json( array $data, string $filename ): void {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		// Clear all output buffering levels.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers for file download.
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is a JSON file payload.
		echo $json;
		exit;
	}
}
