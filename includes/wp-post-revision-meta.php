<?php

namespace narwhal\WordPress;

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
	 * Initializes core functionality for this module.
	 *
	 * NOTE: If you do NOT call this, it is recommended to at least add the `wp_restore_post_revision` action. This
	 * will allow post meta data restoration to happen automatically. Alternatively, consider calling with `true` as
	 * the param value.
	 *
	 * @param bool $restoreOnly When set to true, only the restore action will be added.
	 */
	public static function initialize( $restoreOnly = false ) {
		if ( ! $restoreOnly ) {
			add_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] );
		}

		add_action( 'wp_restore_post_revision', [ __CLASS__, '_maybeRestoreMetaWithRevision' ], 10, 2 );

		// TODO: Add notice of backed up meta on the revision compare/restore screen?
	}

	/**
	 * Creates a revision of the provided post, even if it is identical to the previous revision. Will also save post
	 * meta with the revision by default.
	 *
	 * @param int $postId ID of the post to create a revision of.
	 * @param bool $includePostMeta If true (default), post meta will be saved with the revision.
	 */
	public static function createExactRevision( $postId, $includePostMeta = true ) {
		$metaActionAttached = false;
		$metaActionPriority = has_action( '_wp_put_post_revision', '_saveMetaWithRevision' );
		if ( false !== $metaActionPriority ) {
			$metaActionAttached = true;
		}

		// Add filter so we save a revision regardless of changes.
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false', 99 );

		// Verify our meta save actions reflect the requested action.
		if ( $includePostMeta && ! $metaActionAttached ) {
			add_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] );
		} elseif ( ! $includePostMeta && $metaActionAttached ) {
			remove_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ], $metaActionPriority );
		}
		// Trigger the creation of our revision.
		wp_save_post_revision( $postId );

		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false', 99 );

		// Reset our meta save action to the state is was in before this method was called.
		if ( $includePostMeta && ! $metaActionAttached ) {
			remove_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ] );
		} elseif ( ! $includePostMeta && $metaActionAttached ) {
			// Ensure this is reattached with the same priority.
			add_action( '_wp_put_post_revision', [ __CLASS__, '_saveMetaWithRevision' ], $metaActionPriority );
		}
	}

	/**
	 * Copies post meta from the post to the revision being saved.
	 *
	 * @internal Typically called only by a WordPress action.
	 *
	 * @param int $revisionId ID of the revision.
	 */
	public static function _saveMetaWithRevision( $revisionId ) {
		$revision = get_post( $revisionId );
		if ( $revision ) {
			$postId = $revision->post_parent;
			$excludeKeys = self::_getBaseExcludeKeys();
			/**
			 * Filters meta keys to exclude from copying operations. All non-excluded keys are copied from the post to
			 * the revision.
			 *
			 * @param array $excludeKeys Array of meta keys to exclude from manipulation.
			 * @param int $postId The post ID we are restoring post meta to.
			 * @param int $revisionId The ID of the revision being restored.
			 */
			$excludeKeys = apply_filters( __CLASS__ . '::saveRevisionMetaExcludeKeys', $excludeKeys, $postId, $revisionId );
			self::_copyAllMeta( $postId, $revisionId, $excludeKeys );
			add_metadata( 'post', $revisionId, self::META_KEY, true );
		}
	}

	/**
	 * Possibly restores post meta with a revision if our post meta is associated with the revision. Result is
	 * filterable.
	 *
	 * @internal Typically called only by a WordPress action.
	 *
	 * @param int $postId
	 * @param int $revisionId
	 */
	public static function _maybeRestoreMetaWithRevision( $postId, $revisionId ) {
		if ( metadata_exists( 'post', $revisionId, self::META_KEY )
		     /**
		      * Allows rejecting post meta restoration. Post meta will only ever be restored if this returns true
		      * (default) AND our post meta exists on the revision.
		      *
		      * @param bool $restoreMeta Determines if we should restore post meta or not.
		      * @param int $postId The post ID we are restoring post meta to.
		      * @param int $revisionId The ID of the revision being restored.
		      */
		     && true === apply_filters( __CLASS__ . '::restoreRevisionPostMeta', $restoreMeta = true, $postId, $revisionId ) ) {
			$excludeKeys = self::_getBaseExcludeKeys();
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
			self::_deleteAllMeta( $postId, $excludeKeys );
			self::_copyAllMeta( $revisionId, $postId, $excludeKeys );
		}
	}

	/**
	 * Returns the post meta keys that should always be ignored when copying/restoring post meta.
	 *
	 * @internal
	 *
	 * @return array
	 */
	private static function _getBaseExcludeKeys() {
		$keys = [
			// Don't touch WordPress' post lock meta.
			'_edit_lock',
			'_edit_last',
			// Don't touch our own post meta.
			self::META_KEY,
		];

		return $keys;
	}

	/**
	 * Copies all non-excluded meta values, by key, from one post to another.
	 *
	 * @internal
	 *
	 * @param int $originalId ID of the post to copy meta values from.
	 * @param int $destinationId ID of the post to copy meta values to.
	 * @param array $exclude Array of meta keys to exclude from copying.
	 */
	private static function _copyAllMeta( $originalId, $destinationId, array $exclude = [] ) {
		$meta = get_post_meta( $originalId );
		foreach ( $meta as $key => $values ) {
			if ( ! in_array( $key, $exclude, true ) ) {
				// Check and see if there is only 1 value for this key.
				if ( 1 === count( $values ) ) {
					// If only 1 value, allow an existing value (if any) to be overwritten.
					// Use `reset()` to ensure the correct value is retrieved.
					update_metadata( 'post', $destinationId, $key, maybe_unserialize( reset( $values ) ) );
				} else {
					// Otherwise, use add_metadata as there are multiple values with the same key.
					foreach ( $values as $value ) {
						add_metadata( 'post', $destinationId, $key, maybe_unserialize( $value ) );
					}
				}
			}
		}
	}

	/**
	 * Deletes all post meta values on the provided post. Does NOT delete
	 * any meta values that have a matching key in `$excludedKeys`.
	 *
	 * @internal
	 *
	 * @param int $postId ID of the post to delete all post meta for.
	 * @param array $excludeKeys Array of meta keys to exclude from deletion.
	 */
	private static function _deleteAllMeta( $postId, array $excludeKeys = [] ) {
		$metaKeys = get_post_custom_keys( $postId );
		foreach ( $metaKeys as $key ) {
			if ( ! in_array( $key, $excludeKeys, true ) ) {
				delete_metadata( 'post', $postId, $key );
			}
		}
	}

}
