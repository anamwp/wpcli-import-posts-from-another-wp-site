<?php
/**
 * Manage Posts.
 *
 * Import posts from another site and delete those imported posts if needed.
 *
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure WP_CLI is available
if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Import posts
 */
class Manage_Posts {

	/**
	 * Summary.
	 *
	 * Description.
	 *
	 * @since Version 3 digits
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Initiate WP CLI commands
	 */
	public function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'import-posts', array( $this, 'import_posts' ) );
			WP_CLI::add_command( 'delete-imported-posts', array( $this, 'delete_imported_posts' ) );
		}
	}
	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function import_posts() {

		$total_fetched = 0;
		$page          = 1;
		$per_page      = 10;

		WP_CLI::line( 'Importing posts...' );
		WP_CLI::line( 'Enter REST API Url to fetch posts:' );
		$rest_api_url = trim( fgets( STDIN ) );
		/**
		 * Check for rest api url
		 */
		if ( empty( $rest_api_url ) ) {
			WP_CLI::error( 'Please enter REST API Url to fetch posts' );
		} elseif ( ! filter_var( $rest_api_url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Please enter valid REST API Url valid format: https://anamstarter.local/wp-json/wp/v2/posts' );
		} elseif ( ! strpos( $rest_api_url, 'wp-json/wp/v2/posts' ) ) {
			WP_CLI::error( 'Please enter valid REST API Url https://anamstarter.local/wp-json/wp/v2/posts' );
		} elseif ( strpos( $rest_api_url, 'https' ) !== 0 ) {
			WP_CLI::error( 'Please enter valid REST API Url https' );
		}
		// elseif( !strpos($rest_api_url, 'https') ){
		// WP_CLI::error('Please enter valid REST API Url');
		// }elseif( !strpos($rest_api_url, 'www') ){
		// WP_CLI::error('Please enter valid REST API Url');
		// }elseif( !strpos($rest_api_url, 'localhost') ){
		// WP_CLI::error('Please enter valid REST API Url');
		// }

		/**
		 * Store data in browswer session
		 */
		set_transient( 'rest_api_url_to_import_posts', $rest_api_url, 60 * 60 * 24 );

		while ( true ) {
			WP_CLI::line( 'fetching posts from ..', $rest_api_url );
			$posts_arr   = $this->handleFetchPosts( $rest_api_url, $page, $per_page );
			$total_pages = $posts_arr['total_pages'];
			if ( empty( $posts_arr['posts'] ) ) {
				WP_CLI::success( "No more posts to fetch. Total fetched: {$total_fetched}" );
				break;
			}
			foreach ( $posts_arr['posts'] as $post ) {
				if ( $post ) {
					$this->gs_manage_posts( $post );
				}
				++$total_fetched;
			}
			// Update the skip value for the next batch
			++$page;

			// Stop if we've reached the last page
			if ( $page > $total_pages ) {
				WP_CLI::success( "All posts fetched successfully.Total: {$total_fetched}" );
				break;
			}
		}
	}
	/**
	 * Undocumented function
	 *
	 * @param [type] $post_title
	 * @return void
	 */
	public function gs_check_post_exists( $post_title ) {
		$post_id = '';
		// Set up WP_Query arguments
		$query_args = array(
			'post_type'      => 'post',      // Check only 'post' post type
			'post_status'    => 'any',      // Check posts with any status
			'title'          => $post_title, // Match the post title
			'posts_per_page' => 1,          // Limit to 1 result for performance
		);

		// Query the database
		$query = new \WP_Query( $query_args );

		// Check if a post was found
		if ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID(); // Get the ID of the matched post
			wp_reset_postdata();     // Reset post data
			// return $post_id;       // Return the post ID
			return array(
				'post_id'     => $post_id,
				'post_status' => true,
			);
		} else {
			return array(
				'post_id'     => false,
				'post_status' => false,
			);
		}
	}
	/**
	 * Undocumented function
	 *
	 * @param [type] $post
	 * @return void
	 */
	private function gs_manage_posts( $post ) {
		$post_exists = $this->gs_check_post_exists( $post['title']['rendered'] );
		if ( $post_exists['post_status'] ) {
			\WP_CLI::warning( 'Post already exists: ' . $post['title']['rendered'] );
			return false;
		} else {
			\WP_CLI::line( 'Importing post: ' . $post['title']['rendered'] );
			$post_id = wp_insert_post(
				array(
					'post_title'   => $post['title']['rendered'],
					'post_content' => $post['content']['rendered'],
					'post_status'  => 'publish',
					'post_author'  => 1,
					'post_type'    => 'post',
				)
			);
			/**
			 * if something wrong then return false
			 */
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				\WP_CLI::warning( 'Failed to insert post: ' . $post['title']['rendered'] );
				return false;
			}
			\WP_CLI::log( "Post is inserted. Now adding Meta for - {$post['title']['rendered']}" );

			// Process and assign categories
			if ( ! empty( $post['categories'] ) ) {
				$category_ids     = array();
				$taxonomy_api_url = get_transient( 'rest_api_url_to_import_posts' );
				/**
				 * Remove posts from the url and add categories
				 */
				$taxonomy_api_url = str_replace( 'posts', 'categories', $taxonomy_api_url );

				foreach ( $post['categories'] as $category_id ) {
					$category_name = $this->fetch_taxonomy_name_from_api( $taxonomy_api_url, $category_id );
					if ( $category_name ) {
						$term = term_exists( $category_name, 'category' );
						if ( ! $term || is_wp_error( $term ) ) {
							$term = wp_insert_term( $category_name, 'category' );
						}
						if ( ! is_wp_error( $term ) ) {
							$category_ids[] = (int) $term['term_id'];
						}
					}
				}
				wp_set_post_terms( $post_id, $category_ids, 'category' );
			}

			// Process and assign tags
			if ( ! empty( $post['tags'] ) ) {
				$tag_ids          = array();
				$taxonomy_api_url = get_transient( 'rest_api_url_to_import_posts' );
				$taxonomy_api_url = str_replace( 'posts', 'tags', $taxonomy_api_url );
				foreach ( $post['tags'] as $tag_id ) {
					$tag_name = $this->fetch_taxonomy_name_from_api( $taxonomy_api_url, $tag_id );
					WP_CLI::line( 'Tag Name: ' . $tag_name );
					if ( $tag_name ) {
						$term = term_exists( $tag_name, 'post_tag' );
						if ( ! $term || is_wp_error( $term ) ) {
							$term = wp_insert_term( $tag_name, 'post_tag' );
						}
						if ( ! is_wp_error( $term ) ) {
							$tag_ids[] = (int) $term['term_id'];
						}
					} else {
						WP_CLI::warning( 'Tag not found: ' . $tag_name );
					}
				}
				wp_set_post_terms( $post_id, $tag_ids, 'post_tag' );
			}
			/**
			 * Log the info in the wp-cli
			 */
			WP_CLI::success( 'Post inserted with taxonomies : -' . $post['title']['rendered'] );
		}
	}
	/**
	 * Undocumented function
	 *
	 * @param [type] $taxonomy_api_url
	 * @param [type] $taxonomy_id
	 * @return void
	 */
	public function fetch_taxonomy_name_from_api( $taxonomy_api_url, $taxonomy_id ) {
		$response = wp_remote_get( "{$taxonomy_api_url}/{$taxonomy_id}" );

		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( "Failed to fetch taxonomy ID {$taxonomy_id}: " . $response->get_error_message() );
			return false;
		}

		$taxonomy_data = json_decode( wp_remote_retrieve_body( $response ), true );

		return isset( $taxonomy_data['name'] ) ? $taxonomy_data['name'] : false;
	}
	/**
	 * Fetch posts from the rest api
	 *
	 * @param [url] $rest_api_url url to fetch posts.
	 * @return Array
	 */
	public function handleFetchPosts( $rest_api_url, $page = 1, $per_page = 10 ) {
		$rest_api_url = add_query_arg(
			array(
				'page'     => $page,
				'per_page' => $per_page,
			),
			$rest_api_url
		);
		$response     = wp_remote_get( $rest_api_url );
		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Error fetching posts' );
		}
		$body  = wp_remote_retrieve_body( $response );
		$posts = json_decode( $body, true );
		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts found' );
		}
		/**
		 * Fetch 10 posts in Arrary
		 */
		WP_CLI::success( 'Posts fetched successfully' );
		$headers     = wp_remote_retrieve_headers( $response );
		$total_pages = isset( $headers['x-wp-totalpages'] ) ? (int) $headers['x-wp-totalpages'] : 1;
		return array(
			'total_pages' => $total_pages,
			'posts'       => $posts,
		);
	}
	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	public function delete_imported_posts() {
		$total_fetched = 0;
		$page          = 1;
		$per_page      = 10;

		while ( true ) {
			\WP_CLI::line( 'Deleting posts...' );
			$rest_api_url_to_import_posts = get_transient( 'rest_api_url_to_import_posts' );
			$posts_arr                    = $this->handleFetchPosts( $rest_api_url_to_import_posts, $page, $per_page );
			$total_pages                  = $posts_arr['total_pages'];
			if ( empty( $posts_arr['posts'] ) ) {
				\WP_CLI::success( 'No more posts to delete.' );
				break;
			}
			// \WP_CLI::line( "Deleting {$skip} posts..." );
			foreach ( $posts_arr['posts'] as $post ) {
				if ( $post ) {
					$post_exists = $this->gs_check_post_exists( $post['title']['rendered'] );

					if ( $post_exists['post_status'] ) {
						$this->manage_delete_posts( $post_exists['post_id'] );
					} else {
						WP_CLI::warning( 'Post not found: ' . $post['title']['rendered'] );
					}
				}
				++$total_fetched;
			}
			// Update the skip value for the next batch
			++$page;
			if ( $page > $total_pages ) {
				WP_CLI::success( 'All posts deleted successfully. Total deleted: ' . $total_fetched );
				break;
			}
		}
	}
	/**
	 * Undocumented function
	 *
	 * @param [type] $post_id
	 * @return void
	 */
	private function manage_delete_posts( $post_id ) {
		wp_delete_post( $post_id, true );
		WP_CLI::success( 'Post deleted: ' . $post_id );
	}
}
