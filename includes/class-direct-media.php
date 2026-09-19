<?php

defined( 'ABSPATH' ) || exit;

final class Neek_Media_Importer {
	private const AJAX_ACTION = 'neek_media_importer_transfer';
	private const NONCE_ACTION = 'neek_media_importer_transfer';
	private const DEFAULT_MAX_FILE_SIZE = 104857600;

	private static $instance;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_transfer' ) );
	}

	public function register_admin_page(): void {
		add_media_page(
			'Neek Media Importer',
			'Neek Media Importer',
			'upload_files',
			'neek-media-importer',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to upload files.', 'neek-media-importer' ) );
		}
		?>
		<div class="wrap neek-media-importer-page">
			<h1>Neek Media Importer</h1>
			<p>Transfer remote images, video, and audio directly into the WordPress Media Library.</p>
			<div id="neek-media-importer-admin-app"></div>
		</div>
		<?php
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'neek-media-importer',
			NEEK_MEDIA_IMPORTER_URL . 'assets/css/neek-media-importer.css',
			array(),
			NEEK_MEDIA_IMPORTER_VERSION
		);

		wp_enqueue_script(
			'neek-media-importer',
			NEEK_MEDIA_IMPORTER_URL . 'assets/js/neek-media-importer.js',
			array( 'jquery', 'media-views', 'wp-util' ),
			NEEK_MEDIA_IMPORTER_VERSION,
			true
		);

		wp_localize_script(
			'neek-media-importer',
			'neekMediaImporterSettings',
			array(
				'action'         => self::AJAX_ACTION,
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'maxItems'       => 20,
				'requestDelay'   => 300,
				'isAdminPage'    => 'media_page_neek-media-importer' === $hook_suffix,
				'supportedTypes' => $this->get_supported_output_types(),
				'strings'        => array(
					'tabTitle'          => 'Neek Media Importer',
					'heading'           => 'Transfer remote media',
					'urlHelp'           => 'Enter one direct image, video, or audio URL per line.',
					'addUrls'           => 'Add URLs',
					'transferAll'       => 'Transfer All',
					'clearCompleted'    => 'Clear Completed',
					'noItems'           => 'No media URLs have been added.',
					'url'               => 'Media URL',
					'format'            => 'Image Format',
					'noConversion'      => 'Transfer without conversion',
					'quality'           => 'Image Quality',
					'maxWidth'          => 'Max Width',
					'maxHeight'         => 'Max Height',
					'optional'          => 'Optional',
					'transfer'          => 'Transfer',
					'remove'            => 'Remove',
					'queued'            => 'Ready to transfer',
					'transferring'      => 'Transferring media…',
					'complete'          => 'Transfer complete',
					'failed'            => 'Transfer failed',
					'invalidUrls'       => 'Only valid HTTP or HTTPS URLs can be added.',
					'duplicateUrls'     => 'Duplicate URLs were skipped.',
					'limitReached'      => 'A maximum of 20 items can be queued at once.',
					'nothingToTransfer' => 'There are no pending items to transfer.',
					'confirmRemove'     => 'Remove this item while it is transferring?',
					'unknownError'      => 'An unexpected error occurred.',
					'unsupported'       => 'Not supported by this server',
					'imageHint'         => 'Image options appear when the URL has a recognized image extension.',
				),
			)
		);
	}

	public function handle_transfer(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to upload files.' ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $this->is_valid_remote_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'Please provide a valid HTTP or HTTPS media URL.' ), 400 );
		}

		$format     = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'original';
		$quality    = isset( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 82;
		$max_width  = isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 0;
		$max_height = isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 0;

		if ( ! in_array( $format, array( 'original', 'jpg', 'webp', 'avif' ), true ) ) {
			wp_send_json_error( array( 'message' => 'The requested image format is invalid.' ), 400 );
		}

		$quality    = max( 1, min( 100, $quality ) );
		$max_width  = min( 12000, $max_width );
		$max_height = min( 12000, $max_height );

		$result = $this->transfer( $url, $format, $quality, $max_width, $max_height );
		if ( is_wp_error( $result ) ) {
			$status = (int) $result->get_error_data( 'status' );
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				$status > 0 ? $status : 500
			);
		}

		wp_send_json_success( $result );
	}

	private function transfer( string $url, string $format, int $quality, int $max_width, int $max_height ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$temporary_file = download_url( $url, 30 );
		if ( is_wp_error( $temporary_file ) ) {
			return new WP_Error( 'download_failed', 'WordPress could not download this URL: ' . $temporary_file->get_error_message(), array( 'status' => 400 ) );
		}

		$processed_file = '';

		try {
			$file_size = filesize( $temporary_file );
			$max_size  = (int) apply_filters( 'neek_media_importer_max_file_size', self::DEFAULT_MAX_FILE_SIZE );

			if ( false === $file_size || $file_size < 1 ) {
				return new WP_Error( 'empty_file', 'The downloaded file is empty.', array( 'status' => 400 ) );
			}

			if ( $file_size > $max_size ) {
				return new WP_Error(
					'file_too_large',
					sprintf(
						'The remote file exceeds the %s transfer limit.',
						size_format( $max_size )
					),
					array( 'status' => 413 )
				);
			}

			$filename = $this->filename_from_url( $url );
			$filetype = wp_check_filetype_and_ext( $temporary_file, $filename );
			$mime     = $this->detect_file_mime( $temporary_file );

			if ( ! $mime && ! empty( $filetype['type'] ) ) {
				$mime = $filetype['type'];
			}

			if ( ! $mime ) {
				return new WP_Error( 'invalid_media', 'The URL did not return a supported media file.', array( 'status' => 415 ) );
			}

			if ( ! $this->is_allowed_media_mime( $mime ) ) {
				return new WP_Error( 'invalid_media_type', 'Only image, video, and audio files can be transferred.', array( 'status' => 415 ) );
			}

			$filename = $this->normalize_filename_extension( $filename, $mime );

			if ( 0 === strpos( $mime, 'image/' ) && ( 'original' !== $format || $max_width || $max_height ) ) {
				$processing = $this->process_image( $temporary_file, $filename, $mime, $format, $quality, $max_width, $max_height );
				if ( is_wp_error( $processing ) ) {
					return $processing;
				}

				$processed_file = $processing['path'];
				$filename       = $processing['filename'];
				$mime           = $processing['mime'];
			} elseif ( 'original' !== $format ) {
				return new WP_Error( 'not_an_image', 'Format conversion is only available for images.', array( 'status' => 400 ) );
			}

			$sideload = array(
				'name'     => $filename,
				'type'     => $mime,
				'tmp_name' => $processed_file ?: $temporary_file,
				'error'    => 0,
				'size'     => filesize( $processed_file ?: $temporary_file ),
			);

			$attachment_id = media_handle_sideload( $sideload, 0, sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ) );
			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error( 'sideload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
			}

			$attachment = wp_prepare_attachment_for_js( $attachment_id );

			return array(
				'id'        => $attachment_id,
				'url'       => wp_get_attachment_url( $attachment_id ),
				'editLink'  => get_edit_post_link( $attachment_id, 'raw' ),
				'filename'  => get_the_title( $attachment_id ),
				'mime'      => get_post_mime_type( $attachment_id ),
				'thumbnail' => $this->get_attachment_preview( $attachment_id, $attachment ),
				'attachment'=> $attachment,
			);
		} finally {
			if ( file_exists( $temporary_file ) ) {
				wp_delete_file( $temporary_file );
			}

			if ( $processed_file && file_exists( $processed_file ) ) {
				wp_delete_file( $processed_file );
			}
		}
	}

	private function process_image( string $source, string $filename, string $source_mime, string $format, int $quality, int $max_width, int $max_height ) {
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'image_editor_failed', $editor->get_error_message(), array( 'status' => 500 ) );
		}

		$output_mime = $this->format_to_mime( $format, $source_mime );
		if ( ! $editor->supports_mime_type( $output_mime ) ) {
			return new WP_Error( 'unsupported_format', 'This server cannot create ' . strtoupper( $format ) . ' images.', array( 'status' => 415 ) );
		}

		if ( $max_width || $max_height ) {
			$size          = $editor->get_size();
			$target_width  = $max_width ?: $size['width'];
			$target_height = $max_height ?: $size['height'];

			if ( $size['width'] > $target_width || $size['height'] > $target_height ) {
				$resized = $editor->resize( $target_width, $target_height, false );
				if ( is_wp_error( $resized ) ) {
					return new WP_Error( 'resize_failed', $resized->get_error_message(), array( 'status' => 500 ) );
				}
			}
		}

		$editor->set_quality( $quality );

		$extension   = $this->mime_to_extension( $output_mime );
		$base_name   = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		$output_path = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), $base_name . '.' . $extension );
		$saved       = $editor->save( $output_path, $output_mime );

		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'image_save_failed', $saved->get_error_message(), array( 'status' => 500 ) );
		}

		return array(
			'path'     => $saved['path'],
			'filename' => $base_name . '.' . $extension,
			'mime'     => $saved['mime-type'],
		);
	}

	private function is_valid_remote_url( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true );
	}

	private function filename_from_url( string $url ): string {
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( rawurldecode( basename( $path ) ) );

		return $filename ?: 'remote-media';
	}

	private function detect_file_mime( string $path ): string {
		$mime = wp_get_image_mime( $path );
		if ( is_string( $mime ) && $mime ) {
			return $mime;
		}

		if ( ! class_exists( 'finfo' ) ) {
			return '';
		}

		$file_info = new finfo( FILEINFO_MIME_TYPE );
		$detected  = $file_info->file( $path );
		$aliases   = array(
			'audio/x-wav'       => 'audio/wav',
			'audio/x-m4a'       => 'audio/mp4',
			'video/x-m4v'       => 'video/mp4',
			'video/x-msvideo'   => 'video/avi',
			'application/ogg'   => 'audio/ogg',
			'application/x-m4a' => 'audio/mp4',
		);

		if ( isset( $aliases[ $detected ] ) ) {
			return $aliases[ $detected ];
		}

		return is_string( $detected ) ? sanitize_mime_type( $detected ) : '';
	}

	private function is_allowed_media_mime( string $mime ): bool {
		if ( ! preg_match( '#^(image|video|audio)/#', $mime ) ) {
			return false;
		}

		return in_array( $mime, array_values( get_allowed_mime_types() ), true );
	}

	private function normalize_filename_extension( string $filename, string $mime ): string {
		$extension = $this->mime_to_extension( $mime );
		$current   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! $current || false === strpos( strtolower( $mime ), $current ) ) {
			$filename = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ) . '.' . $extension;
		}

		return $filename;
	}

	private function format_to_mime( string $format, string $original_mime ): string {
		$types = array(
			'jpg'  => 'image/jpeg',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return isset( $types[ $format ] ) ? $types[ $format ] : $original_mime;
	}

	private function mime_to_extension( string $mime ): string {
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/bmp'  => 'bmp',
			'image/tiff' => 'tif',
		);

		if ( isset( $extensions[ $mime ] ) ) {
			return $extensions[ $mime ];
		}

		$mime_types = wp_get_mime_types();
		foreach ( $mime_types as $extensions_list => $registered_mime ) {
			if ( $registered_mime === $mime ) {
				return explode( '|', $extensions_list )[0];
			}
		}

		return 'media';
	}

	private function get_supported_output_types(): array {
		return array(
			'jpg'  => wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ),
			'webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'avif' => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
		);
	}

	private function get_attachment_preview( int $attachment_id, $attachment ): string {
		if ( is_array( $attachment ) && ! empty( $attachment['sizes']['thumbnail']['url'] ) ) {
			return $attachment['sizes']['thumbnail']['url'];
		}

		$icon = wp_mime_type_icon( $attachment_id );

		return $icon ?: includes_url( 'images/media/default.png' );
	}
}
