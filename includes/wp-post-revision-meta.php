<?php

namespace Narwhal\WordPress;

/**
 * Class PostRevisionMeta
 * @author David Wood <david@davidwood.ninja>
 * @link https://davidwood.ninja/
 * @license GPLv3+
 * @package dfwood\WordPress
 */
class PostRevisionMeta {

	/**
	 * @var string META_KEY The meta key used to track if we saved post meta with the revision (added only to
	 *     revisions).
	 */
	const META_KEY = '_post_revision_saved_metadata';

	/**
	 * Helper function to add the action to allow restoring post meta on revision restore. Won't add itself more
	 * than once.
	 *
	 * @return bool True if action was added, false if already added.
	 */
	public static function setupRestoreFromRevision() {
		if ( false === has_action( 'wp_restore_post_revision', [ __CLASS__, '_maybeRestoreMetaWithRevision' ] ) ) {
			return add_action( 'wp_restore_post_revision', [ __CLASS__, '_maybeRestoreMetaWithRevision' ], 10, 2 );
		}

		return false;
	}

	/**
	 * Helper function to add the action to allow backing up post meta with a revision. Won't add itself more than once.
	 *
	 * @return bool True if action was added, false if already added.
	 */
	public static function setupBackupToRevision() {
		if ( false === has_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] ) ) {
			return add_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] );
		}

		return false;
	}

	/**
	 * Creates an exact revision of a post, including all post meta.
	 *
	 * @param int $postId
	 */
	public static function createExactRevisionWithMeta( $postId ) {
		// Setup backup hooks.
		$addedBackup = self::setupBackupToRevision();

		// Ensure we create a revision even if nothing has changed.
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false', 99 );

		// Trigger creation of revision.
		wp_save_post_revision( $postId );

		// Remove the filter so other revisions are created properly.
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false', 99 );

		// If we actually added our action hook, remove it.
		if ( $addedBackup ) {
			remove_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] );
		}
	}

	/**
	 * Copies post meta from the post to the revision being saved.
	 *
	 * Typically called only by a WordPress action. Intended to work with the `_wp_put_post_revision` action.
	 *
	 * @param int $revisionId ID of the revision.
	 */
	public static function _saveMetaWithRevision( $revisionId ) {
		$revision = get_post( $revisionId );
		if ( $revision && ( $postId = wp_is_post_revision( $revision ) )
		     // Prevent this from running twice on the same action.
		     && add_metadata( 'post', $revisionId, self::META_KEY, true, true ) ) {
			$excludeKeys = PostMeta::WP_LOCK_KEYS;
			$excludeKeys[] = self::META_KEY;
			/**
			 * Filters meta keys to exclude from copying operations. All non-excluded keys are copied from the post to
			 * the revision.
			 *
			 * @param array $excludeKeys Array of meta keys to exclude from manipulation.
			 * @param int $postId The post ID we are restoring post meta to.
			 * @param int $revisionId The ID of the revision being restored.
			 */
			$excludeKeys = apply_filters( __CLASS__ . '::saveRevisionMetaExcludeKeys', $excludeKeys, $postId, $revisionId );
			PostMeta::copyAllToRevision( $postId, $revisionId, $excludeKeys );
		}
	}

	/**
	 * Possibly restores post meta with a revision if our post meta is associated with the revision. Result is
	 * filterable.
	 *
	 * Typically called only by a WordPress action. Intended to work with the `wp_restore_post_revision` action.
	 *
	 * @param int $postId
	 * @param int $revisionId
	 */
	public static function _maybeRestoreMetaWithRevision( $postId, $revisionId ) {
		if ( metadata_exists( 'post', $revisionId, self::META_KEY ) ) {
			$excludeKeys = PostMeta::WP_LOCK_KEYS;
			$excludeKeys[] = self::META_KEY;
			/**
			 * Filters meta keys to exclude from deletion and copying operations.
			 *
			 * All non-excluded keys on the post are deleted before copying all non-excluded keys from the revision to
			 * the post.
			 *
			 * @param array $excludeKeys Array of meta keys to exclude from manipulation.
			 * @param int $postId The post ID we are restoring post meta to.
			 * @param int $revisionId The ID of the revision being restored.
			 */
			$excludeKeys = apply_filters( __CLASS__ . '::restoreRevisionMetaExcludeKeys', $excludeKeys, $postId, $revisionId );
			PostMeta::deleteAll( $postId, $excludeKeys );
			PostMeta::copyAll( $revisionId, $postId, $excludeKeys );
		}
	}

	/**
	 * Copies all non-excluded post meta to specified revision from its parent post. Adds an additional bit of post
	 * meta to revision to track that we have saved post meta to the revision.
	 *
	 * @param int $revisionId ID of the revision to save post meta to.
	 * @param array $exclude Meta keys to exclude from saving to the revision.
	 */
	public static function savePostMetaToRevision( $revisionId, array $exclude = [] ) {
		$revision = get_post( $revisionId );
		if ( $revision && ( $postId = wp_is_post_revision( $revision ) )
		     // Prevent this from running twice on the same action.
		     && add_metadata( 'post', $revisionId, self::META_KEY, true, true ) ) {
			// Ensure our meta key isn't touched.
			$exclude[] = self::META_KEY;
			PostMeta::copyAllToRevision( $postId, $revisionId, $exclude );
		}
	}

	/**
	 * Deletes all non-excluded post meta on destination post and copies all non-excluded meta from specified revision
	 * to destination post.
	 *
	 * @param int $revisionId Post ID of revision to restore meta from.
	 * @param int $postId Post ID to restore post meta to.
	 * @param array $exclude Meta keys to exclude from restore process.
	 */
	public static function restorePostMetaFromRevision( $revisionId, $postId, array $exclude = [] ) {
		if ( metadata_exists( 'post', $revisionId, self::META_KEY ) ) {
			// Ensure our meta key isn't touched.
			$exclude[] = self::META_KEY;
			PostMeta::deleteAll( $postId, $exclude );
			PostMeta::copyAll( $revisionId, $postId, $exclude );
		}
	}

}
