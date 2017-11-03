<?php
/**
 * AnsPress comments handling.
 *
 * @author       Rahul Aryan <support@anspress.io>
 * @license      GPL-3.0+
 * @link         https://anspress.io
 * @copyright    2014 Rahul Aryan
 * @package      AnsPress
 * @subpackage   Comments Hooks
 */

/**
 * Comments class
 */
class AnsPress_Comment_Hooks {

	/**
	 * Filter comments array to include only comments which user can read.
	 *
	 * @param array $comments Comments.
	 * @return array
	 * @since 4.1.0
	 */
	public static function the_comments( $comments ) {
		foreach ( $comments as $k => $c ) {
			if ( 'anspress' === $c->comment_type && ! ap_user_can_read_comment( $c ) ) {
				unset( $comments[ $k ] );
			}
		}

		return $comments;
	}

	/**
	 * Ajax callback for loading comments.
	 *
	 * @since 2.0.1
	 * @since 3.0.0 Moved from AnsPress_Ajax class.
	 */
	public static function load_comments() {
		global $avatar_size;
		$paged = 1;
		$comment_id = ap_sanitize_unslash( 'comment_id', 'r' );

		if ( ! empty( $comment_id ) ) {
			$_comment = get_comment( $comment_id );
			$post_id = $_comment->comment_post_ID;
		} else {
			$post_id = ap_sanitize_unslash( 'post_id', 'r' );
			$paged = max( 1, ap_isset_post_value( 'paged', 1 ) );
		}

		$_post = ap_get_post( $post_id );

		$args = array(
			'number'    => ap_opt( 'comment_number' ),
			'show_more' => false,
			'paged'     => $paged,
		);

		if ( ! empty( $_comment ) ) {
			$avatar_size = 60;
			$args['comment__in'] = $_comment->comment_ID;
		}

		ob_start();
		ap_the_comments( $post_id, $args );
		$html = ob_get_clean();

		$type = 'question' === $_post->post_type ? __( 'Question', 'anspress-question-answer' ) : __( 'Answer', 'anspress-question-answer' );

		$result = array(
			'success'     => true,
			'modal_title' => sprintf(
				// Translators: %s contains post type.
				__( 'Comments on %s', 'anspress-question-answer' ),
				$type
			),
			'html'        => $html,
		);

		if ( $paged > 1 ) {
			$result['modal_title'] .= sprintf(
				// Translators: %d contains current paged value.
				__( ' | Page %d', 'anspress-question-answer' ),
				$paged
			);
		}

		ap_ajax_json( $result );
	}

	/**
	 * Ajax action for deleting comment.
	 *
	 * @since 2.0.0
	 * @since 3.0.0 Moved from ajax.php to here.
	 */
	public static function delete_comment() {
		$comment_id = (int) ap_sanitize_unslash( 'comment_id', 'r' );

		if ( ! $comment_id || ! ap_user_can_delete_comment( $comment_id ) || ! ap_verify_nonce( 'delete_comment_' . $comment_id ) ) {
			ap_ajax_json( array(
				'success' => false,
				'snackbar' => [ 'message' => __( 'Failed to delete comment', 'anspress-question-answer' ) ],
			) );
		}

		$_comment = get_comment( $comment_id );

		// Check if deleting comment is locked.
		if ( ap_comment_delete_locked( $_comment->comment_ID ) && ! is_super_admin() ) {
			ap_ajax_json( array(
				'success' => false,
				'snackbar' => [ 'message' => sprintf( __( 'This comment was created %s. Its locked hence you cannot delete it.', 'anspress-question-answer' ), ap_human_time( $_comment->comment_date_gmt, false ) ) ],
			) );
		}

		$delete = wp_delete_comment( (integer) $_comment->comment_ID, true );

		if ( $delete ) {
			do_action( 'ap_unpublish_comment', $_comment );
			do_action( 'ap_after_deleting_comment', $_comment );

			$count = get_comment_count( $_comment->comment_post_ID );

			ap_ajax_json( array(
				'success'       => true,
				'snackbar'      => [ 'message' => __( 'Comment successfully deleted', 'anspress-question-answer' ) ],
				'cb'        => 'commentDeleted',
				'post_ID'        => $_comment->comment_post_ID,
				'commentsCount' => [ 'text' => sprintf( _n( '%d Comment', '%d Comments', $count['all'], 'anspress-question-answer' ), $count['all'] ), 'number' => $count['all'], 'unapproved' => $count['awaiting_moderation'] ],
			) );
		}
	}

	/**
	 * Modify comment query args for showing pending comments to moderator.
	 *
	 * @param  array $args Comment args.
	 * @return array
	 * @since  3.0.0
	 */
	public static function comments_template_query_args( $args ) {
		global $question_rendered;

		if ( true === $question_rendered && is_singular( 'question' ) ) {
			return false;
		}

		if ( ap_user_can_approve_comment() ) {
			$args['status'] = 'all';
		}

		return $args;
	}

	/**
	 * Ajax callback to approve comment.
	 */
	public static function approve_comment() {
		$comment_id = (int) ap_sanitize_unslash( 'comment_id', 'r' );

		if ( ! ap_verify_nonce( 'approve_comment_' . $comment_id ) || ! ap_user_can_approve_comment( ) ) {
			ap_ajax_json( array(
				'success'  => false,
				'snackbar' => [ 'message' => __( 'Sorry, unable to approve comment', 'anspress-question-answer' ) ],
			) );
		}

		$success = wp_set_comment_status( $comment_id, 'approve' );
		$_comment = get_comment( $comment_id );
		$count = get_comment_count( $_comment->comment_post_ID );

		if ( $success ) {
			$_comment  = get_comment( $comment_id );
			ap_ajax_json( array(
				'success'       => true,
				'cb' 		        => 'commentApproved',
				'comment_ID' 	  => $comment_id,
				'post_ID' 	    => $_comment->comment_post_ID,
				'commentsCount' => array(
					'text'         => sprintf(
						_n( '%d Comment', '%d Comments', $count['all'], 'anspress-question-answer' ),
						$count['all']
					),
					'number'       => $count['all'],
					'unapproved'   => $count['awaiting_moderation'],
				),
				'snackbar'      => array( 'message' => __( 'Comment approved successfully.', 'anspress-question-answer' ) ),
			) );
		}
	}

	/**
	 * Manipulate question and answer comments link.
	 *
	 * @param string     $link    The comment permalink with '#comment-$id' appended.
	 * @param WP_Comment $comment The current comment object.
	 * @param array      $args    An array of arguments to override the defaults.
	 */
	public static function comment_link( $link, $comment, $args ) {
		$_post = ap_get_post( $comment->comment_post_ID );

		if ( ! in_array( $_post->post_type, [ 'question', 'answer' ], true ) ) {
			return $link;
		}

		$permalink = get_permalink( $_post );
		return $permalink . '#/comment/' . $comment->comment_ID;
	}

	/**
	 * Callback for loading comment form.
	 *
	 * @return void
	 * @since 4.1.0
	 */
	public static function comment_form() {
		$comment_id = ap_sanitize_unslash( 'comment', 'r' );
		$_comment = get_comment( $comment_id );

		if ( $_comment ) {
			$_post = ap_get_post( $_comment->comment_post_ID );
		} else {
			$_post = ap_get_post( ap_sanitize_unslash( 'post_id', 'r' ) );
		}

		ob_start();
		ap_comment_form( $_post->ID, $_comment );
		$html = ob_get_clean();

		ap_ajax_json( array(
			'success'     => true,
			'html'        => $html,
			'modal_title' => __( 'Add comment on post', 'anspress-question-answer' ),
		) );
	}

	/**
	 * Change comment_type while adding comments for question or answer.
	 *
	 * @param array $commentdata Comment data array.
	 * @return array
	 * @since 4.1.0
	 */
	public static function preprocess_comment( $commentdata ) {
		if ( ! empty( $commentdata['comment_post_ID'] ) ) {
			$post_type = get_post_type( $commentdata['comment_post_ID'] );

			if ( in_array( $post_type, [ 'question', 'answer' ], true ) ) {
				$commentdata['comment_type'] = 'anspress';
			}
		}

		return $commentdata;
	}
}

/**
 * Load comment form button.
 *
 * @param 	mixed $_post Echo html.
 * @return 	string
 * @since 	0.1
 * @since 	4.1.0 Added @see `ap_user_can_read_comments()` check.
 */
function ap_comment_btn_html( $_post = null ) {
	if ( ! ap_user_can_read_comments( $_post ) ) {
		return;
	}

	$_post = ap_get_post( $_post );

	if ( 'question' === $_post->post_type  && ap_opt( 'disable_comments_on_question' ) ) {
		return;
	}

	if ( 'answer' === $_post->post_type && ap_opt( 'disable_comments_on_answer' ) ) {
		return;
	}

	$comment_count = get_comments_number( $_post->ID );
	$args = wp_json_encode( [ 'post_id' => $_post->ID, '__nonce' => wp_create_nonce( 'comment_form_nonce' ) ] );

	$unapproved = '';

	if ( ap_user_can_approve_comment() ) {
		$unapproved_count = ! empty( $_post->fields['unapproved_comments'] ) ? (int) $_post->fields['unapproved_comments'] : 0;
		$unapproved = '<b class="unapproved' . ( $unapproved_count > 0 ? ' have' : '' ) . '" ap-un-commentscount title="' . esc_attr__( 'Comments awaiting moderation', 'anspress-question-answer' ) . '">' . $unapproved_count . '</b>';
	}

	// Show comments button.
	$output = '<a href="#/comments/' . $_post->ID . '" class="ap-btn ap-btn-comments">';
	$output .= '<span ap-commentscount-text itemprop="commentCount">' . sprintf( _n( '%d Comment', '%d Comments', $comment_count, 'anspress-question-answer' ), $comment_count ) . '</span>';
	$output .= $unapproved . '</a>';

	// Add comment button.
	$q = '';

	if ( ap_user_can_comment( $_post->ID ) ) {
		$output .= '<a href="#/comments/' . $_post->ID . '/new" class="ap-btn-newcomment ap-btn ap-btn-small" ap="new-comment">';
		$output .= esc_attr__( 'Add a Comment', 'anspress-question-answer' );
		$output .= '</a>';
	}

	return $output;
}

/**
 * Comment actions args.
 *
 * @param object|integer $comment Comment object.
 * @return array
 * @since 4.0.0
 */
function ap_comment_actions( $comment ) {
	$comment = get_comment( $comment );
	$actions = [];

	if ( ap_user_can_edit_comment( $comment->comment_ID ) ) {
		$actions[] = array(
			'label'           => __( 'Edit', 'anspress-question-answer' ),
			'cb'              => 'edit_comment',
			'href' => get_permalink() . '#/comment/' . $comment->comment_ID . '/edit',
		);
	}

	if ( ap_user_can_delete_comment( $comment->comment_ID ) ) {
		$actions[] = array(
			'label' => __( 'Delete', 'anspress-question-answer' ),
			'href' => '#',
			'query' => array(
				'__nonce'        => wp_create_nonce( 'delete_comment_' . $comment->comment_ID ),
				'ap_ajax_action' => 'delete_comment',
				'comment_id'     => $comment->comment_ID,
			),
		);
	}

	if ( '0' === $comment->comment_approved && ap_user_can_approve_comment( ) ) {
		$actions[] = array(
			'label' => __( 'Approve', 'anspress-question-answer' ),
			'href' => '#',
			'query' => array(
				'__nonce'        => wp_create_nonce( 'approve_comment_' . $comment->comment_ID ),
				'ap_ajax_action' => 'approve_comment',
				'comment_id'     => $comment->comment_ID,
			),
		);
	}

	/**
	 * For filtering comment action buttons.
	 *
	 * @param array $actions Comment actions.
	 * @since   2.0.0
	 */
	return apply_filters( 'ap_comment_actions', $actions );
}

/**
 * Check if comment delete is locked.
 * @param  integer $comment_ID     Comment ID.
 * @return bool
 * @since  3.0.0
 */
function ap_comment_delete_locked( $comment_ID ) {
	$comment = get_comment( $comment_ID );
	$commment_time = mysql2date( 'U', $comment->comment_date_gmt ) + (int) ap_opt( 'disable_delete_after' );
	return current_time( 'timestamp', true ) > $commment_time;
}

/**
 * Output comment wrapper.
 *
 * @param mixed $_post Post ID or object.
 * @param array $args  Arguments.
 *
 * @return void
 * @since 2.1
 * @since 4.1.0 Added two args `$_post` and `$args` and using WP_Comment_Query.
 */
function ap_the_comments( $_post = null, $args = [] ) {
	global $comment;

	$_post = ap_get_post( $_post );
	if ( ! ap_user_can_read_comments( $_post ) ) {
		echo '<div class="ap-comment-no-perm">' . __( 'Sorry, you do not have permission to read comments.', 'anspress-question-answer' ) . '</div>';

		return;
	}

	if ( 'question' === $_post->post_type && ap_opt( 'disable_comments_on_question' ) ) {
		return;
	}

	if ( 'answer' === $_post->post_type && ap_opt( 'disable_comments_on_answer' ) ) {
		return;
	}

	$user_id = get_current_user_id();

	$default = array(
		'post_id'   => $_post->ID,
		'order'     => 'ASC',
		'status'    => 'approve',
		'number' 	  => ap_opt( 'comment_number' ),
		//'type'      => 'anspress',
		'show_more' => true,
		'paged'     => 1,
		'no_found_rows' => false,
	);

	// Always include current user comments.
	if ( ! empty( $user_id ) && $user_id > 0 ) {
		$default['include_unapproved'] = [ $user_id ];
	}

	if ( ap_user_can_approve_comment() ) {
		$default['status'] = 'all';
	}

	$args = wp_parse_args( $args, $default );
	$args['offset'] = ceil( ( (int) $args['paged'] - 1 ) * (int) $args['number'] );

	$query = new WP_Comment_Query( $args );

	if ( 0 == $query->found_comments ) {
		echo '<div class="ap-comment-no-perm">' . __( 'No comments found.', 'anspress-question-answer' ) . '</div>';

		return;
	}

	echo '<apcomments id="comments-' . esc_attr( $_post->ID ) . '" class="have-comments"><div class="ap-comments">';

	foreach ( $query->comments as $c ) {
		$comment = $c;
		ap_get_template_part( 'comment' );
	}

	if ( $query->max_num_pages > 1 ) {
		ap_pagination( $args['paged'], $query->max_num_pages, '?paged=%#%', get_permalink( $_post ) . '#/comments/' . $_post->ID . '/page/%#%' );
	}

	echo '</div></apcomments>';
}

/**
 * Return ajax comment data.
 *
 * @param object $c Comment object.
 * @return array
 * @since 4.0.0
 */
function ap_comment_ajax_data( $c, $actions = true ) {
	return array(
		'ID'        => $c->comment_ID,
		'post_id'   => $c->comment_post_ID,
		'link'      => get_comment_link( $c ),
		'avatar'    => get_avatar( $c->user_id, 30 ),
		'user_link' => ap_user_link( $c->user_id ),
		'user_name' => ap_user_display_name( $c->user_id ),
		'iso_date'  => date( 'c', strtotime( $c->comment_date ) ),
		'time'      => ap_human_time( $c->comment_date_gmt, false ),
		'content'   => $c->comment_content,
		'approved'  => $c->comment_approved,
		'class'     => implode( ' ', get_comment_class( 'ap-comment', $c->comment_ID, null, false ) ),
		'actions' 	 => $actions ? ap_comment_actions( $c ) : [],
	);
}

