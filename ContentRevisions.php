<?php
/**
 * Plugin Name: WP Content Revisions
 * Plugin URI: https://github.com/NarwhalDigital/wp-content-revisions
 * Description: Allows creation of content revisions which allow you to save and preview updates to content without publishing.
 * Author: Narwhal.Digital
 * Author URI: https://narwhal.digital/
 * Version: 1.0.0
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-content-revisions
 */

namespace Narwhal\WordPress;

require __DIR__ . '/includes/wp-post-meta.php';
require __DIR__ . '/includes/wp-post-revision-meta.php';
require __DIR__ . '/includes/wp-post-duplication.php';

/**
 * Class ContentRevisions
 * @package Narwhal\WordPress
 */
class ContentRevisions {

	/**
	 * @var string CREATE_REVISION_ACTION
	 * @internal
	 */
	const CREATE_REVISION_ACTION = 'wp-content-revisions::createRevision';

	/**
	 * ContentRevisions constructor.
	 */
	public function __construct() {
		// Setup restoration of post meta from revisions.
		PostRevisionMeta::initialize( true );

		// Handle publishing of content revisions.
		add_action( 'transition_post_status', [ __CLASS__, '_maybePublishContentRevision' ], 10, 3 );

		// Add handlers for trashing/un-trashing/deleting posts and content revisions.
		add_action( 'before_delete_post', [ __CLASS__, '_beforeDeleteCleanup' ] );
		add_action( 'trashed_post', [ __CLASS__, '_trashedPost' ] );
		add_action( 'untrashed_post', [ __CLASS__, '_unTrashedPost' ] );

		// Ensure our post meta isn't unexpectedly manipulated by our own actions.
		$excludeMetaKeys = [ __CLASS__, '_excludeMetaKeys' ];
		add_filter( __NAMESPACE__ . '\PostDuplication::preDuplicateMetaDelete', $excludeMetaKeys );
		add_filter( __NAMESPACE__ . '\PostDuplication::postDuplicateMetaCopy', $excludeMetaKeys );
		add_filter( __NAMESPACE__ . '\PostRevisionMeta::saveRevisionMetaExcludeKeys', $excludeMetaKeys );
		add_filter( __NAMESPACE__ . '\PostRevisionMeta::restoreRevisionMetaExcludeKeys', $excludeMetaKeys );

		if ( is_admin() ) {
			// Handle creation of content revisions.
			add_action( 'admin_post_' . self::CREATE_REVISION_ACTION, [ __CLASS__, '_createContentRevision' ] );

			// Add interface to post edit screens.
			add_action( 'post_submitbox_misc_actions', [ __CLASS__, '_showRevisionControls' ] );
			add_action( 'post_submitbox_start', [ __CLASS__, '_showRevisionPublishNotice' ] );

			// Filter admin post listing pages to keep them clean.
			add_action( 'pre_get_posts', [ __CLASS__, '_preGetPosts' ] );

			// Add interface (when applicable) to admin post listing pages.
			add_filter( 'post_row_actions', [ __CLASS__, '_postRowActions' ], 10, 2 );
			add_filter( 'page_row_actions', [ __CLASS__, '_postRowActions' ], 10, 2 );

			add_action( 'admin_notices', [ __CLASS__, '_adminNotices' ] );

			// TODO: Add notice of backed up meta on the revision compare/restore screen?
		}
	}

	/**
	 * Creates a content revision with post ID from URL query string if allowed.
	 *
	 * @internal
	 */
	public static function _createContentRevision() {
		$postId = filter_input( INPUT_GET, 'postId', FILTER_SANITIZE_NUMBER_INT );
		$post = get_post( $postId );

		if ( ! $post ) {
			wp_die( esc_html__( 'Cannot create content revision from invalid post ID!', 'wp-content-revisions' ) );
		}

		if ( ! self::postTypeSupportsRevisions( $post->post_type ) ) {
			wp_die( esc_html__( 'Post type does not support content revisions!', 'wp-content-revisions' ) );
		}

		if ( self::postHasRevision( $postId ) ) {
			wp_die( esc_html__( 'Post already has a content revision! Only 1 revision is allowed per post.', 'wp-content-revisions' ) );
		}

		if ( self::postIsRevision( $postId ) ) {
			wp_die( esc_html__( 'Cannot create a content revision of a content revision!', 'wp-content-revisions' ) );
		}

		$postType = get_post_type_object( $post->post_type );
		if ( $postType && ! current_user_can( $postType->cap->edit_post, $postId )
		     && ! apply_filters( __CLASS__ . '::createContentRevision', $canCreate = true, get_current_user_id() ) ) {
			wp_die( esc_html__( 'Sorry, you do not have permission to create a content revision.', 'wp-content-revisions' ) );
		}

		$newPostId = PostDuplication::duplicate( $postId, [
			// Set an empty name (slug). Duplicating this can cause content display issues.
			'post_name' => '',
		] );

		if ( ! is_wp_error( $newPostId ) ) {
			update_post_meta( $postId, '_content_revision_child', $newPostId );
			update_post_meta( $newPostId, '_content_revision_parent', $postId );

			// Redirect to newly created post edit screen.
			wp_safe_redirect( get_edit_post_link( $newPostId, '' ) );
			exit();
		}
	}

	/**
	 * @internal
	 *
	 * @param string $newStatus
	 * @param string $oldStatus
	 * @param \WP_Post $post
	 */
	public static function _maybePublishContentRevision( $newStatus, $oldStatus, \WP_Post $post ) {
		// TODO: Add permissions check?
		if ( 'publish' === $newStatus && $newStatus !== $oldStatus && self::postIsRevision( $post->ID ) ) {
			// Delay our actual publish as late as possible. Prevents other
			// things hooked into the publish process from breaking.
			add_action( 'wp_insert_post', [ __CLASS__, '_publishContentRevision' ], 99, 2 );
		}
	}

	/**
	 * @internal
	 *
	 * @param int $postId
	 * @param \WP_Post $post
	 */
	public static function _publishContentRevision( $postId, \WP_Post $post ) {
		// This action shouldn't be run again unless explicitly requested.
		remove_action( 'wp_insert_post', [ __CLASS__, '_publishContentRevision' ], 99 );

		if ( 'publish' === $post->post_status && self::postIsRevision( $postId ) ) {
			$originalPostId = (int) get_post_meta( $post->ID, '_content_revision_parent', true );
			$originalPost = get_post( $originalPostId );
			if ( $originalPost ) {
				// Remove our action
				remove_action( 'transition_post_status', [ __CLASS__, '_maybePublishContentRevision' ] );
				// Create an exact revision of the original post as a restore point.
				PostRevisionMeta::createExactRevision( $originalPostId );
				// Copy the content from content revision to original post (including post meta).
				$args = [
					'ID'          => $originalPostId,
					// Preserve name (slug) for SEO reasons.
					'post_name'   => $originalPost->post_name,
					// Preserve the current status of the original.
					'post_status' => $originalPost->post_status,
				];
				PostDuplication::duplicate( $post->ID, $args );
				// Delete our post meta from the original.
				delete_post_meta( $originalPostId, '_content_revision_child' );
				// Fully delete content revision, it is now live on the original.
				wp_delete_post( $post->ID, true );
				// Re-add our action.
				add_action( 'transition_post_status', [ __CLASS__, '_maybePublishContentRevision' ], 10, 3 );
				// TODO: Add success message and redirect to appropriate page!
			}
		}
	}

	/**
	 * Deletes content revisions when parent is deleted. Cleans
	 * up post meta on parent when content revision is deleted.
	 *
	 * @internal
	 *
	 * @param int $postId
	 */
	public static function _beforeDeleteCleanup( $postId ) {
		if ( self::postIsRevision( $postId ) ) {
			$parentId = (int) get_post_meta( $postId, '_content_revision_parent', true );
			delete_post_meta( $parentId, '_content_revision_child' );
		} elseif ( self::postHasRevision( $postId ) ) {
			$revisionId = (int) get_post( $postId, '_content_revision_child', true );
			wp_delete_post( $revisionId, true );
		}
	}

	/**
	 * Trashes content revisions if their parent post is trashed. Fully deletes
	 * content revisions trying to be trashed independent of their parent post.
	 *
	 * @internal
	 *
	 * @param int $postId
	 */
	public static function _trashedPost( $postId ) {
		if ( self::postIsRevision( $postId ) ) {
			// There's no way to recover trashed content revisions, so delete it.
			wp_delete_post( $postId, true );
		} elseif ( self::postHasRevision( $postId ) ) {
			$revisionId = (int) get_post( $postId, '_content_revision_child', true );
			// Remove action before trashing the content revision.
			remove_action( 'trashed_post', [ __CLASS__, '_trashedPost' ] );
			wp_trash_post( $revisionId );
			add_action( 'trashed_post', [ __CLASS__, '_trashedPost' ] );
		}
	}

	/**
	 * Restores content revisions if their parent post is un-trashed.
	 *
	 * @internal
	 *
	 * @param int $postId
	 */
	public static function _unTrashedPost( $postId ) {
		if ( self::postHasRevision( $postId ) ) {
			$revisionId = (int) get_post( $postId, '_content_revision_child', true );
			wp_untrash_post( $revisionId );
		}
	}

	/**
	 * @internal
	 *
	 * @param \WP_Post $post
	 */
	public static function _showRevisionControls( \WP_Post $post ) {
		$postType = get_post_type_object( $post->post_type );
		if ( $postType && self::postTypeSupportsRevisions( $post->post_type ) ) {
			?>
            <div class="misc-pub-section wp-content-revisions-section">
                <strong><?php esc_html_e( 'Content Revisions', 'wp-content-revisions' ); ?></strong><br />
				<?php
				if ( self::postHasRevision( $post->ID ) ) {
					$revisionId = (int) $post->_content_revision_child;
					if ( current_user_can( $postType->cap->edit_post, $revisionId )
					     && apply_filters( __CLASS__ . '::editContentRevision', $canEdit = true, $revisionId, get_current_user_id() ) ) {
						?><a href="<?php echo esc_url( get_edit_post_link( $revisionId, '' ) ); ?>"><?php
						esc_html_e( 'Edit content revision', 'wp-content-revisions' );
						?></a><?php
					}
					// TODO: Show content revision post status?
				} elseif ( self::postIsRevision( $post->ID ) ) {
					$originalId = (int) $post->_content_revision_parent;
					?><a href="<?php echo esc_url( get_edit_post_link( $originalId, '' ) ); ?>"><?php
					esc_html_e( 'Edit original content', 'wp-content-revisions' );
					?></a><?php
				} else {
					if ( 'publish' === $post->post_status
					     && current_user_can( $postType->cap->publish_posts, $post->ID )
					     && apply_filters( __CLASS__ . '::publishContentRevision', $canPublish = true, $post->ID, get_current_user_id() )
					) {
						?><a href="<?php echo esc_url( admin_url( add_query_arg( [
							'action' => self::CREATE_REVISION_ACTION,
							'postId' => $post->ID,
						], '/admin-post.php' ) ) ) ?>"><?php
						esc_html_e( 'Create content revision', 'wp-content-revisions' );
						?></a><?php
					}
				}
				?>
            </div>
			<?php
		}
	}

	/**
	 * @internal
	 */
	public static function _showRevisionPublishNotice() {
		if ( self::postIsRevision( get_the_ID() ) ) {
			// TODO: Make language clearer and more concise.
			?>
            <p class="description"><?php
				esc_html_e( 'Warning: Publishing this content revision will replace the original content.', 'wp-content-revisions' );
				?></p>
			<?php
		}
	}

	/**
	 * Filter out content revisions from admin post listing screens unless specifically requested.
	 *
	 * @internal
	 *
	 * @param \WP_Query $query
	 */
	public static function _preGetPosts( \WP_Query $query ) {
		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( $screen && 'edit' === $screen->base && $screen->post_type && self::postTypeSupportsRevisions( $screen->post_type ) ) {
				$query->set( 'meta_query', [
					[
						// If the `parent` key exists, then it is a child as the key references the parent.
						'key'     => '_content_revision_parent',
						'compare' => 'NOT EXISTS',
					]
				] );
			}
		}
	}

	/**
	 * Add a quick link to edit a content revision from the post listing screen.
	 *
	 * @internal
	 *
	 * @param array $actions
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	public static function _postRowActions( array $actions, \WP_Post $post ) {
		if ( self::postTypeSupportsRevisions( $post->post_type ) && self::postHasRevision( $post->ID ) ) {
			$postType = get_post_type_object( $post->post_type );
			$tmpActions = [];
			$revisionId = (int) $post->_content_revision_child;
			foreach ( $actions as $key => $value ) {
				if ( 'edit' === $key && $postType
				     && current_user_can( $postType->cap->edit_post, $revisionId )
				     && apply_filters( __CLASS__ . '::editContentRevision', $canEdit = true, $revisionId, get_current_user_id() ) ) {
					$tmpActions[ $key ] = $value;
					$tmpActions['edit-revision'] = sprintf(
						'<a href="%1$s">%2$s</a>',
						get_edit_post_link( $revisionId, '' ),
						esc_html__( 'Edit Revision', 'wp-content-revisions' )
					);
				} else {
					$tmpActions[ $key ] = $value;
				}
			}
			$actions = $tmpActions;
		}

		return $actions;
	}

	/**
	 * Outputs our admin notices.
	 *
	 * @internal
	 */
	public static function _adminNotices() {
		$screen = get_current_screen();
		if ( $screen && 'post' === $screen->base && self::postTypeSupportsRevisions( $screen->post_type ) ) {
			$postId = get_the_ID();
			if ( $postId && ( self::postIsRevision( $postId ) || self::postHasRevision( $postId ) ) ) {
				if ( self::postIsRevision( $postId ) ) {
					?>
                    <div class="notice notice-warning"><p><?php
						if ( self::postTypeSupportsRealRevision( $screen->post_type ) ) {
							esc_html_e( 'This is a content revision. Publishing this will replace the original page content with this revisions content. A backup will be made of the original content before publishing.', 'wp-content-revisions' );
						} else {
							esc_html_e( 'This is a content revision. Publishing this will replace the original page content with this revisions content. Revision history is turned off for this post type, a backup of the original content will not be made.', 'wp-content-revisions' );
						}
						?></p></div><?php
				} elseif ( self::postHasRevision( $postId ) ) {
					?>
                    <div class="notice notice-warning"><p><?php
						$revisionId = (int) get_post_meta( $postId, '_content_revision_child', true );
						printf( wp_kses( __( 'This page has a content revision. Did you mean to <a href="%1$s">edit it</a> instead?', 'wp-content-revisions' ), [
							'a' => [
								'href' => true,
							]
						] ), esc_url( get_edit_post_link( $revisionId, '' ) ) );
						?></p></div><?php
				}
			}
		}
	}

	/**
	 * Excludes our post meta keys from unexpected manipulation.
	 *
	 * @internal
	 *
	 * @param array $excludeKeys
	 *
	 * @return array
	 */
	public static function _excludeMetaKeys( array $excludeKeys ) {
		$excludeKeys[] = '_content_revision_parent';
		$excludeKeys[] = '_content_revision_child';

		return $excludeKeys;
	}

	/**
	 * Returns an array of post types that support content revisions.
	 *
	 * @return array
	 */
	public static function getSupportedPostTypes() {
		// Try caching if caching is available.
		$cacheKey = md5( __METHOD__ );
		$postTypes = wp_cache_get( $cacheKey );
		if ( ! is_array( $postTypes ) ) {
			$postTypes = get_post_types_by_support( 'revisions' );
			wp_cache_set( $cacheKey, $postTypes, '', 5 * MINUTE_IN_SECONDS );
		}

		return apply_filters( __CLASS__ . '::supportedPostTypes', $postTypes );
	}

	/**
	 * Checks if the provided post type supports content revisions.
	 *
	 * @param string $postType
	 *
	 * @return bool
	 */
	public static function postTypeSupportsRevisions( $postType ) {
		return in_array( $postType, self::getSupportedPostTypes(), true );
	}

	/**
	 * Check if the post has a content revision ID associated and specified ID exists.
	 *
	 * @param int $postId
	 *
	 * @return bool
	 */
	public static function postHasRevision( $postId ) {
		$revisionId = (int) get_post_meta( $postId, '_content_revision_child', true );

		return 0 < $revisionId && is_a( get_post( $revisionId ), 'WP_Post' );
	}

	/**
	 * Check if the post is a revision.
	 *
	 * @param int $postId
	 *
	 * @return bool
	 */
	public static function postIsRevision( $postId ) {
		return metadata_exists( 'post', $postId, '_content_revision_parent' )
		       && 0 < (int) get_post_meta( $postId, '_content_revision_parent', true );
	}

	/**
	 * Internal helper function to see if a post type supports WordPress revisions.
	 *
	 * @internal
	 *
	 * @param string $postType
	 *
	 * @return bool
	 */
	private static function postTypeSupportsRealRevision( $postType ) {
		return post_type_supports( $postType, 'revisions' );
	}

}

new ContentRevisions();
