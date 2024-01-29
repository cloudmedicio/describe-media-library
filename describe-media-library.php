<?php
/**
 * Plugin Name: _🖼️️ Describe Media Library
 * Description: Use a local large language model to generate descriptions of images in the WordPress Media Library. Uses <a href="https://ollama.ai" target="_blank">ollama.ai</a>. Run <code>ollama pull llava</code> and <code>ollama serve</code> then <code>wp _medic describe-images</code>.
 * Version: 1
 * Author: 🌦️️🩺️ Cloud Medic
 * Author URI: https://github.com/cloudmedicio/
 * Plugin URI: https://github.com/cloudmedicio/describe-media-library/
 */

namespace 🌦️️🩺️;

use WP_CLI as 💻️;

class Describe_Media_Library {

	/**
	 * Default inputs for each description type if none is set. See readme.md.
	 */
	private $defaults = [
		'alt' => <<<MD
Please write a thorough description of the image in a format appropriate for an alt tag focused on accessibility.
MD,
		'description' => <<<MD
Please write a comma-separated keywords and relevant synonyms related to this image, focusing on relevancy for search.
MD,
		'caption' => <<<MD
Please write a descriptive caption for this image appropriate for displaying to a user reading an article where the image is referenced.
MD,
		'title' => <<<MD
Please write a short title for this image.
MD,
		'cache_file' => 'image-descriptions.csv',
		'image_size' => 'medium',
	];

	private $processed_attachment_ids = [];

	function __construct() {
		💻️::add_command( '_medic describe-images', [ $this, 'describe_images' ] );

		$this->cache_file = sprintf(
			'%s/%s',
			wp_upload_dir()['basedir'],
			( ! empty( $aa['cache_file'] && true !== $aa['cache_file'] ) )
				? sanitize_file_name( $aa['cache_file'] )
				: sanitize_file_name( $this->defaults['cache_file'] )
		);
	}

	public function describe_images( $a, $aa ) {
		$this->init_cache_file();

		if ( true === $aa['write-to-db'] ) {
			$this->write_csv_to_db();
		}else {
			// Set a different input for any of the contexts.
			foreach( [ 'alt', 'description', 'caption', 'title', 'image_size' ] as $context ) {
				if ( array_key_exists( $context, $aa ) ) {
					$this->defaults[ $context ] = trim( $aa[ $context ] );
				} 
			}

			$this->generate_descriptions();
		}
	}

	public function init_cache_file() {
		if ( ! file_exists( $this->cache_file ) ) {
			file_put_contents(
				$this->cache_file,
				'ID,alt,description,caption,title,URL' . PHP_EOL
			);
		}else {
			// Check IDs which have already been processed.
			$csv = fopen( $this->cache_file, 'r' );
			while ( false !== ( $row = fgetcsv( $csv ) ) ) {
				if (
					is_numeric( $row[0] )
					&& (
						! empty( $row[1] )
						|| ! empty( $row[2] )
						|| ! empty( $row[3] )
						|| ! empty( $row[4] )
					)
				) {
					$this->processed_attachment_ids[] = intval( $row[0] );
				}
			}
			fclose( $csv );
		}
	}

	public function write_csv_to_db() {
		$csv = fopen( $this->cache_file, 'r' );

		while ( false !== ( $row = fgetcsv( $csv ) ) ) {
			list( $attachment_id, $alt, $description, $caption, $title, $attachment_url ) = $row;

			if ( is_numeric( $attachment_id ) ) {
				$post_args = [
					'ID' => $attachment_id,
				];
				$log = [];
				if ( ! empty( $title ) ) {
					$post_args['post_title'] = $title;
					$log[] = sprintf( '    Title: %s', $title );
				}
				if ( ! empty( $description ) ) {
					$post_args['post_content'] = $description;
					$log[] = sprintf( '    Description: %s', $description );
				}
				if ( ! empty( $caption ) ) {
					$post_args['post_excerpt'] = $caption;
					$log[] = sprintf( '    Caption: %s', $caption );
				}
				if ( ! empty( $log ) ) {
					💻️::log(
						sprintf(
							'Updating database for attachment %d: %s',
							$attachment_id,
							$attachment_url
						)
					);
					wp_update_post( $post_args );
					💻️::log( implode( PHP_EOL, $log ) );
				}
				
				if ( ! empty( $alt ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
					💻️::log( sprintf( '    Alt: %s', $alt ) );
				}
			}
		}
	}

	public function generate_descriptions() {
		💻️::log( sprintf( '🏁️ Descriptions will be written to CSV:%s    %s' . PHP_EOL, PHP_EOL, $this->cache_file ) );
		💻️::log( 'After reviewing the file, contents can be saved to the database with:' );
		💻️::log( '    wp _medic describe-images --write-to-db' . PHP_EOL );

		$csv = fopen( $this->cache_file, 'a' );

		$image_attachments = get_posts(
			[
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'post_mime_type' => 'image/',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'post__not_in' => $this->processed_attachment_ids,
			]
		);
		$attachment_count = count( $image_attachments );
		$times = [];

		💻️::log(
			sprintf(
				'%s image attachments found.%s%s already processed.%s',
				number_format( $attachment_count, 0 ),
				PHP_EOL,
				number_format( count( $this->processed_attachment_ids ), 0 ),
				PHP_EOL
			)
		);

		foreach( $image_attachments as $count => $attachment_id ) {
			$attachment_url = wp_get_attachment_image_url( $attachment_id, $this->defaults['image_size'] );

			💻️::log(
				sprintf(
					'🖼️️  attachment %d (%s of %s):%s    %s',
					$attachment_id,
					number_format( $count + 1, 0 ),
					number_format( $attachment_count, 0 ),
					PHP_EOL,
					$attachment_url
				)
			);

			$start = time();
			$row = [ $attachment_id ];
			foreach( [ 'alt', 'description', 'caption', 'title' ] as $context ) {
				$response = $this->get_description( $attachment_url, $context );
				$row[] = $response;

				💻️::log( sprintf( '    %s: %s', $context, $response ) );
			}
			$row[] = $attachment_url;

			if (
				! empty( $row[1] )
				|| ! empty( $row[2] )
				|| ! empty( $row[3] )
				|| ! empty( $row[4] )
			) {
				fputcsv( $csv, $row );
				$processed_alts[] = $attachment_id;

				$time = time() - $start;
				$times[] = $time;
				$average = array_sum( $times ) / count( $times );

				💻️::log(
					sprintf(
						'    ⏲️️  attachment %d took %s seconds. About %s minutes remaining.',
						$attachment_id,
						number_format( $time, 0 ),
						number_format(
							( $attachment_count - ( $count + 2 ) ) * $average / MINUTE_IN_SECONDS, 
							0
						)
					)
				);
			}
		}

		fclose( $csv );

		💻️::log( sprintf( '✅️ All descriptions saved to CSV: %s', $this->cache_file ) );
		💻️::log( 'Review the file, then save to the database with:' );
		💻️::log( '    wp _medic describe-images --write-to-db' );
	}

	public function get_description( $attachment_url, $context ) {
		$prompt = trim( $this->defaults[ $context ] );

		if ( empty( $prompt ) || true === $prompt ) {
			return '';
		}

		$llm = wp_remote_post(
			'http://127.0.0.1:11434/api/generate',
			[
				// Should between 1 and 8 minutes per image description depending on hardware.
				'timeout' => 20 * MINUTE_IN_SECONDS,
				'body' => json_encode(
					[
						'model' => 'llava',
						'prompt' => $prompt,
						'images' => [
							base64_encode(
								wp_remote_retrieve_body(
									wp_remote_get( $attachment_url, [ 'sslverify' => false ] )
								)
							)
						],
						'stream' => false,
					]
				),
				'headers' => [ 'Content-Type' => 'application/json' ],
			]
		);

		$llm_body = wp_remote_retrieve_body( $llm );
		$llm_json = json_decode( $llm_body, true );

		if ( empty( $llm_json ) ) {
			💻️::log(
				sprintf(
					'No response from the language model for %s. Make sure Ollama is running on this machine. e.g., open the app or run `ollama pull llava` and `ollama serve` %s%s',
					$attachment_url,
					PHP_EOL,
					$llm_body
				)
			);
			exit;
		}

		return trim( $llm_json['response'] );
	}


}

add_action(
	'cli_init',
	function() {
		new Describe_Media_Library;
	}
);
