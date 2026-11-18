<?php
/**
 * Public-change detection and durable, bounded automatic purging.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Collects changes without depending on the current visitor's role. */
final class Auto_Purge {

	const PENDING = 'clear_nginx_for_webuzo_pending';
	const CRON    = 'clear_nginx_for_webuzo_retry';

	/**
	 * Original comments retained until a successful edit notification.
	 *
	 * @var array
	 */
	private static $previous_comments = array();

	/** Register late content hooks and background execution. */
	public static function boot() {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'post_saved' ), 10, 4 );
		add_action( 'deleted_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		add_action( 'attachment_updated', array( __CLASS__, 'attachment_updated' ), 10, 3 );
		add_action( 'add_attachment', array( __CLASS__, 'attachment_added' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'attachment_deleted' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'attachment_meta' ), 10, 3 );
		}
		add_action( 'wp_insert_comment', array( __CLASS__, 'comment_inserted' ), 10, 2 );
		add_action( 'edit_comment', array( __CLASS__, 'comment_edited' ) );
		add_filter( 'wp_update_comment_data', array( __CLASS__, 'comment_before_update' ), 10, 2 );
		add_action( 'deleted_comment', array( __CLASS__, 'comment_deleted' ), 10, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'comment_transition' ), 10, 3 );
		foreach ( array( 'wp_update_nav_menu', 'wp_update_nav_menu_item', 'wp_delete_nav_menu', 'switch_theme', 'permalink_structure_changed', 'customize_save_after' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue' ) );
		}
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'shutdown', array( __CLASS__, 'run' ), 100 );
	}

	/**
	 * Determine whether a post can affect anonymous visitors.
	 *
	 * @param \WP_Post|null $post Post to inspect.
	 * @return bool
	 */
	private static function visible( $post ) {
		if ( ! $post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return false;
		}
		if ( 'attachment' === $post->post_type && 'inherit' === $post->post_status ) {
			return ! $post->post_parent || self::visible( get_post( $post->post_parent ) );
		}
		// Theme and navigation inputs affect public rendering without being publicly queryable.
		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'custom_css', 'nav_menu_item' ), true ) ) {
			return 'publish' === $post->post_status;
		}
		return is_post_publicly_viewable( $post );
	}

	/**
	 * Handle publish, edits, withdrawals, trash, restores and scheduled posts.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post      $post Updated post.
	 * @param bool          $update Whether updating.
	 * @param \WP_Post|null $before Previous post.
	 */
	public static function post_saved( $post_id, $post, $update, $before ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( self::visible( $post ) || self::visible( $before ) ) {
			self::queue();
		}
	}

	/**
	 * Purge after deletion, when the object no longer exists in the database.
	 *
	 * @param int      $post_id Deleted post ID.
	 * @param \WP_Post $post Previous post.
	 */
	public static function post_deleted( $post_id, $post ) {
		if ( self::visible( $post ) ) {
			self::queue();
		}
	}

	/**
	 * Attachment edits can affect a previously or currently public parent.
	 *
	 * @param int      $post_id Attachment ID.
	 * @param \WP_Post $after Updated attachment.
	 * @param \WP_Post $before Previous attachment.
	 */
	public static function attachment_updated( $post_id, $after, $before ) {
		self::post_saved( $post_id, $after, true, $before );
	}

	/**
	 * Core attachment insertion does not use the normal late post-save hook.
	 *
	 * @param int $post_id New attachment ID.
	 */
	public static function attachment_added( $post_id ) {
		self::post_deleted( $post_id, get_post( $post_id ) );
	}

	/**
	 * Attachment deletion occurs before its row disappears.
	 *
	 * @param int      $post_id Attachment ID.
	 * @param \WP_Post $post Attachment.
	 */
	public static function attachment_deleted( $post_id, $post ) {
		self::post_deleted( $post_id, $post );
	}

	/**
	 * Changes to public image files, dimensions and alternative text affect HTML.
	 *
	 * @param int|array $meta_id Updated metadata identifier.
	 * @param int       $post_id Attachment ID.
	 * @param string    $key Metadata key.
	 */
	public static function attachment_meta( $meta_id, $post_id, $key ) {
		if ( in_array( $key, array( '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_image_alt' ), true ) ) {
			$post = get_post( $post_id );
			if ( $post && 'attachment' === $post->post_type && self::visible( $post ) ) {
				self::queue();
			}
		}
	}

	/**
	 * Collect an approved comment change on a public post.
	 *
	 * @param \WP_Comment|null $comment Comment object.
	 */
	private static function public_comment( $comment ) {
		$settings = Settings::get();
		if ( $settings['purge_comments'] && $comment && self::visible( get_post( $comment->comment_post_ID ) ) ) {
			self::queue();
		}
	}

	/**
	 * New comment, including programmatic insertion.
	 *
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment Inserted comment.
	 */
	public static function comment_inserted( $comment_id, $comment ) {
		if ( '1' === (string) $comment->comment_approved ) {
			self::public_comment( $comment );
		}
	}

	/**
	 * Content edits of approved comments.
	 *
	 * @param int $comment_id Updated comment ID.
	 */
	public static function comment_edited( $comment_id ) {
		$comment = get_comment( $comment_id );
		$before  = self::$previous_comments[ $comment_id ] ?? null;
		unset( self::$previous_comments[ $comment_id ] );
		if ( $before && $comment && '1' === (string) $before->comment_approved && (int) $before->comment_post_ID !== (int) $comment->comment_post_ID ) {
			self::public_comment( $before );
		}
		if ( $comment ) {
			self::comment_inserted( $comment_id, $comment );
		}
	}

	/**
	 * Remember the public source of a comment moved to another post.
	 *
	 * @param array|\WP_Error $data New comment data, or a prior validation error.
	 * @param array           $before Original database row.
	 * @return array|\WP_Error
	 */
	public static function comment_before_update( $data, $before ) {
		if ( is_array( $data ) && is_array( $before ) && isset( $before['comment_ID'] ) ) {
			self::$previous_comments[ $before['comment_ID'] ] = (object) $before;
		}
		return $data;
	}

	/**
	 * Deleted approved comments still carry their original object.
	 *
	 * @param int         $comment_id Deleted comment ID.
	 * @param \WP_Comment $comment Previous comment.
	 */
	public static function comment_deleted( $comment_id, $comment ) {
		self::comment_inserted( $comment_id, $comment );
	}

	/**
	 * Only changes into or out of approved status affect public comments.
	 *
	 * @param string      $updated New status.
	 * @param string      $old Previous status.
	 * @param \WP_Comment $comment Comment object.
	 */
	public static function comment_transition( $updated, $old, $comment ) {
		if ( $updated !== $old && ( 'approved' === $updated || 'approved' === $old ) ) {
			self::public_comment( $comment );
		}
	}

	/** Persist a fresh generation immediately, before any request can purge it. */
	public static function queue() {
		if ( is_multisite() || ! Settings::get()['automatic_purge'] ) {
			return;
		}
		$old = get_option( self::PENDING, array() );
		$new = array(
			'token'    => wp_generate_uuid4(),
			'attempts' => 0,
			'after'    => max( time(), $old['after'] ?? 0 ),
		);
		update_option( self::PENDING, $new, false );
		self::schedule( $new['after'] + 1 );
	}

	/**
	 * Ensure a wakeup exists, including if the foreground worker dies.
	 *
	 * @param int $when Earliest wakeup.
	 */
	private static function schedule( $when ) {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			$result = wp_schedule_single_event( max( time() + 1, $when ), self::CRON, array(), true );
			if ( is_wp_error( $result ) || false === $result ) {
				update_option(
					'clear_nginx_for_webuzo_auto_result',
					array(
						'ok'      => false,
						'time'    => time(),
						'message' => __( 'Automatic cache clearing could not be scheduled. Check WP-Cron; the change remains pending.', 'clear-nginx-for-webuzo' ),
					),
					false
				);
			}
		}
	}

	/** Clear work when disabled or deactivated, without touching account cache. */
	public static function cancel() {
		delete_option( self::PENDING );
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Execute at most one account request, retaining concurrent newer changes. */
	public static function run() {
		if ( is_multisite() || ! Settings::get()['automatic_purge'] ) {
			return;
		}
		$pending = get_option( self::PENDING );
		if ( ! is_array( $pending ) ) {
			return;
		}
		if ( $pending['after'] > time() ) {
			self::schedule( $pending['after'] );
			return;
		}
		$lock = State::lock();
		if ( false === $lock ) {
			self::schedule( time() + 60 );
			return;
		}
		try {
			self::schedule( time() + 61 );
			$result = Cache_Service::run( 'purge' );
			$data   = is_wp_error( $result ) ? $result->get_error_data() : null;
			$retry  = is_array( $data ) && in_array( $data['native_code'] ?? '', array( 'busy', 'rate_limited' ), true );
			if ( is_wp_error( $result ) && $retry && $pending['attempts'] < 4 ) {
				$next = $pending;
				++$next['attempts'];
				$next['after'] = time() + max( 10, min( 60, $data['retry_after'] ?? 10 ), 10 * $next['attempts'] );
				State::compare( self::PENDING, $pending, $next );
			} else {
				State::compare( self::PENDING, $pending );
				update_option(
					'clear_nginx_for_webuzo_auto_result',
					array(
						'ok'      => ! is_wp_error( $result ),
						'time'    => time(),
						'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Automatic account cache clear completed.', 'clear-nginx-for-webuzo' ),
					),
					false
				);
			}
			wp_clear_scheduled_hook( self::CRON );
			$remaining = get_option( self::PENDING );
			if ( is_array( $remaining ) ) {
				self::schedule( max( time() + 10, $remaining['after'] ) );
			}
		} finally {
			State::compare( 'clear_nginx_for_webuzo_worker', $lock );
		}
	}
}
