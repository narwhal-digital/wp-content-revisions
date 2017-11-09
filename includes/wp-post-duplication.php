<?php

namespace Narwhal\WordPress;

/**
 * Class PostDuplication
 * @author David Wood <david@davidwood.ninja>
 * @link https://davidwood.ninja/
 * @license GPLv3+
 * @package dfwood\WordPress
 */
class PostDuplication {

	/**
	 * Duplicates a post based on ID and provided arguments. Can overwrite an existing post based on arguments.
	 *
	 * Passing in a post ID as 'ID' in the args array will cause that post to be overwritten.
	 *
	 * @param int $postId ID of the post to duplicate
	 * @param array $args Arguments to pass through to `wp_insert_post()`
	 *
	 * @return int|\WP_Error New post ID on success, WP_Error on failure.
	 */
	public static function duplicate( $postId, array $args = [] ) {
		$post = get_post( $postId );
		// WordPress edit lock meta, we don't want to touch any of this!
		$metaExclude = PostMeta::WP_LOCK_KEYS;
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post_id', esc_html__( 'Invalid post ID supplied for `$postId`.', 'dfwood-wp-post-duplication' ), [ 'status' => 403 ] );
		}

		if ( ! empty( $args['ID'] ) ) {
			$overwritePost = get_post( $args['ID'] );
			if ( $overwritePost ) {
				$clearMetaExclude = apply_filters( __CLASS__ . '::preDuplicateMetaDelete', $metaExclude, $overwritePost, $post );
				PostMeta::deleteAll( $args['ID'], $clearMetaExclude );
			} else {
				return new \WP_Error( 'invalid_post_id', esc_html__( 'Invalid post ID supplied in `$args` array.', 'dfwood-wp-post-duplication' ), [ 'status' => 403 ] );
			}
		}

		// Setup all new data
		$postData = array_merge( self::constructPostData( $post ), $args );
		if ( ! isset( $args['tax_input'] ) ) {
			$postData['tax_input'] = self::termsAsData( $post );
		}

		// Insert the new post
		$newId = wp_insert_post( $postData, true );
		if ( is_wp_error( $newId ) ) {
			// Returning a WP_Error, occurred during `wp_insert_post()`.
			return $newId;
		}

		// Handle copying post meta
		if ( ! empty( $args['ID'] ) && ! empty( $args['meta_input'] ) && is_array( $args['meta_input'] ) ) {
			// If meta values were passed in to `wp_insert_post`, then ensure we don't import/overwrite values with the same meta keys.
			$metaExclude = array_merge( $metaExclude, array_keys( $args['meta_input'] ) );
		}
		$metaExclude = apply_filters( __CLASS__ . '::postDuplicateMetaCopy', $metaExclude, $newId, $post, $args );
		PostMeta::copyAll( $postId, $newId, $metaExclude );

		return $newId;
	}

	/**
	 * Helper function to retrieve post data to send to `wp_insert_post`.
	 *
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	protected static function constructPostData( \WP_Post $post ) {
		$fields = [
			'post_author',
			'post_category',
			'post_content',
			'post_title',
			'post_excerpt',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'post_content_filtered',
			'post_parent',
			'menu_order',
			'post_type',
			'post_mime_type',
			'tags_input',
		];
		$data = [];
		foreach ( $fields as $field ) {
			$data[ $field ] = $post->{$field};
		}

		return $data;
	}

	/**
	 * Processes post taxonomies (excluding `category` and `post_tag` taxonomies) to insert via 'tax_input' argument in
	 * `wp_insert_post()`.
	 *
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	protected static function termsAsData( \WP_Post $post ) {
		$taxInput = [];

		$taxonomies = get_object_taxonomies( $post );
		foreach ( $taxonomies as $taxonomy ) {
			if ( 'category' === $taxonomy || 'post_tag' === $taxonomy ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy, [
				'fields' => 'ids',
			] );
			if ( ! is_wp_error( $terms ) ) {
				$taxInput[ $taxonomy ] = $terms;
			}
		}

		return $taxInput;
	}

}
