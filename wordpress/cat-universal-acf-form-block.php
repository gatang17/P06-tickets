<?php
/**
 * CAT — Universal ACF Form Block
 *
 * Front-end create/edit/delete forms generated from the ACF field groups
 * assigned to any WordPress post type.
 *
 * Presentation CSS intentionally lives outside this snippet (Customizer,
 * theme stylesheet, or a dedicated CSS file).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CAT_Universal_ACF_Form_Block' ) ) {

	final class CAT_Universal_ACF_Form_Block {

		const VERSION       = '1.3.0';
		const BLOCK_NAME    = 'cat/universal-acf-form';
		const SCRIPT_HANDLE = 'cat-universal-acf-form-editor';

		private static $frontend_script_printed = false;
		private static $frontend_script_needed  = false;

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_block' ) );
			add_action( 'init', array( __CLASS__, 'register_pattern' ), 20 );
			add_action( 'template_redirect', array( __CLASS__, 'prepare_frontend' ), 1 );
			add_action( 'template_redirect', array( __CLASS__, 'handle_delete_request' ), 2 );
			add_action( 'wp_footer', array( __CLASS__, 'print_frontend_script' ), 99 );
		}

		/**
		 * ACF must process the request before the theme sends any HTML.
		 */
		public static function prepare_frontend() {
			if ( is_admin() || wp_doing_ajax() || ! function_exists( 'acf_form_head' ) ) {
				return;
			}

			acf_form_head();
		}

		public static function register_block() {
			$script = self::editor_script();

			wp_register_script(
				self::SCRIPT_HANDLE,
				'',
				array(
					'wp-blocks',
					'wp-block-editor',
					'wp-components',
					'wp-element',
					'wp-i18n',
				),
				self::VERSION,
				true
			);

			wp_add_inline_script( self::SCRIPT_HANDLE, $script );
			wp_localize_script(
				self::SCRIPT_HANDLE,
				'CatUniversalAcfFormData',
				array(
					'postTypes' => self::get_post_type_options(),
					'hasAcf'    => function_exists( 'acf_form' ),
				)
			);

			register_block_type(
				self::BLOCK_NAME,
				array(
					'api_version'     => 2,
					'editor_script'   => self::SCRIPT_HANDLE,
					'render_callback' => array( __CLASS__, 'render_block' ),
					'attributes'      => self::block_attributes(),
					'supports'        => array(
						'align'      => array( 'wide', 'full' ),
						'anchor'     => true,
						'html'       => false,
						'className'  => true,
						'color'      => false,
						'spacing'    => false,
						'typography' => false,
					),
				)
			);
		}

		private static function block_attributes() {
			return array(
				'postType' => array(
					'type'    => 'string',
					'default' => 'equipment',
				),
				'allowEntityParameter' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'entityParameter' => array(
					'type'    => 'string',
					'default' => 'entity',
				),
				'formMode' => array(
					'type'    => 'string',
					'default' => 'auto',
				),
				'recordSource' => array(
					'type'    => 'string',
					'default' => 'query',
				),
				'recordParameter' => array(
					'type'    => 'string',
					'default' => 'record_id',
				),
				'postStatus' => array(
					'type'    => 'string',
					'default' => 'publish',
				),
				'showPostTitle' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showPostContent' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'createLabel' => array(
					'type'    => 'string',
					'default' => 'Create',
				),
				'updateLabel' => array(
					'type'    => 'string',
					'default' => 'Update',
				),
				'enableDelete' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'deleteLabel' => array(
					'type'    => 'string',
					'default' => 'Delete',
				),
				'deleteConfirmation' => array(
					'type'    => 'string',
					'default' => 'Are you sure you want to move this record to the trash?',
				),
				'returnUrl' => array(
					'type'    => 'string',
					'default' => '',
				),
				'deleteReturnUrl' => array(
					'type'    => 'string',
					'default' => '',
				),
				'loginMessage' => array(
					'type'    => 'string',
					'default' => 'You must be logged in to manage records.',
				),
				'permissionMessage' => array(
					'type'    => 'string',
					'default' => 'You do not have permission to perform this action.',
				),
				'emptyEditMessage' => array(
					'type'    => 'string',
					'default' => 'Choose a record before opening the edit form.',
				),
			);
		}

		public static function register_pattern() {
			if ( ! function_exists( 'register_block_pattern' ) || ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
				return;
			}

			if ( function_exists( 'register_block_pattern_category' ) ) {
				register_block_pattern_category(
					'cat-management',
					array( 'label' => __( 'CAT Management', 'cat-uacf' ) )
				);
			}

			register_block_pattern(
				'cat-management/universal-acf-form',
				array(
					'title'       => __( 'Universal ACF Form', 'cat-uacf' ),
					'description' => __( 'Create or edit records from the front end using the ACF groups assigned to a content type.', 'cat-uacf' ),
					'categories'  => array( 'cat-management' ),
					'content'     => '<!-- wp:cat/universal-acf-form {"postType":"equipment","formMode":"auto"} /-->',
				)
			);
		}

		public static function render_block( $attributes, $content = '', $block = null ) {
			$attributes = wp_parse_args( $attributes, self::attribute_defaults() );
			$post_type  = self::resolve_post_type( $attributes );

			if ( self::is_block_editor_preview() ) {
				return self::render_editor_preview( $post_type, $attributes );
			}

			if ( ! function_exists( 'acf_form' ) ) {
				return self::notice( __( 'Advanced Custom Fields is required for this form.', 'cat-uacf' ), 'error' );
			}

			if ( ! is_user_logged_in() ) {
				return self::notice( $attributes['loginMessage'], 'warning' );
			}

			if ( ! self::is_manageable_post_type( $post_type ) ) {
				return self::notice( __( 'The selected content type is not available.', 'cat-uacf' ), 'error' );
			}

			$record_id = self::resolve_record_id( $attributes, $post_type );
			$mode      = self::resolve_mode( $attributes['formMode'], $record_id );

			if ( 'edit' === $mode && ! $record_id ) {
				return self::notice( $attributes['emptyEditMessage'], 'info' );
			}

			if ( 'edit' === $mode ) {
				$post = get_post( $record_id );
				if ( ! $post || $post_type !== $post->post_type || 'trash' === $post->post_status ) {
					return self::notice( __( 'The requested record could not be found.', 'cat-uacf' ), 'error' );
				}

				if ( ! current_user_can( 'edit_post', $record_id ) ) {
					return self::notice( $attributes['permissionMessage'], 'error' );
				}
			} elseif ( ! self::current_user_can_create( $post_type ) ) {
				return self::notice( $attributes['permissionMessage'], 'error' );
			}

			$field_groups = self::get_field_group_keys_for_post_type( $post_type );
			$submit_label = 'edit' === $mode ? $attributes['updateLabel'] : $attributes['createLabel'];
			$return_url   = self::build_return_url( $attributes['returnUrl'] );
			$show_title   = self::should_show_post_title( $post_type, $attributes );

			$form_args = array(
				'id'                    => 'cat-uacf-form-' . wp_unique_id(),
				'post_id'               => 'edit' === $mode ? $record_id : 'new_post',
				'post_title'            => $show_title,
				'post_content'          => (bool) $attributes['showPostContent'],
				'field_groups'          => ! empty( $field_groups ) ? $field_groups : false,
				'form'                  => true,
				'return'                => $return_url,
				'submit_value'          => sanitize_text_field( $submit_label ),
				'updated_message'       => __( 'The record was saved successfully.', 'cat-uacf' ),
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'html_submit_button'    => '<button type="submit" class="cat-uacf-form__submit button button-primary">%s</button>',
				'form_attributes'       => array(
					'class' => 'cat-uacf-form__form',
				),
			);

			if ( 'create' === $mode ) {
				$form_args['new_post'] = array(
					'post_type'   => $post_type,
					'post_status' => self::allowed_post_status( $attributes['postStatus'] ),
				);
			}

			$wrapper_classes = array(
				'cat-uacf-form',
				'cat-uacf-form--' . $mode,
				'cat-uacf-form--' . sanitize_html_class( $post_type ),
			);

			// get_block_wrapper_attributes() merges in whatever align/anchor/
			// className the editor's block supports produced (including the
			// className this method builds below), so those supports actually
			// take effect on the front end instead of being editor-only cosmetics.
			$wrapper_attributes = get_block_wrapper_attributes(
				array(
					'class'          => implode( ' ', array_filter( $wrapper_classes ) ),
					'data-post-type' => $post_type,
					'data-form-mode' => $mode,
				)
			);

			ob_start();
			echo '<section ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::status_notice_from_request(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			acf_form( $form_args );

			if ( 'edit' === $mode && ! empty( $attributes['enableDelete'] ) && current_user_can( 'delete_post', $record_id ) ) {
				self::$frontend_script_needed = true;
				echo self::render_delete_form( $record_id, $post_type, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</section>';

			return ob_get_clean();
		}

		/**
		 * A single form page can serve several entities. For example:
		 * /manage-record/?entity=sale or /manage-record/?entity=loan.
		 * The block's selected content type remains the safe fallback.
		 */
		private static function resolve_post_type( $attributes ) {
			$post_type = sanitize_key( $attributes['postType'] );

			if ( ! empty( $attributes['allowEntityParameter'] ) ) {
				$parameter = sanitize_key( $attributes['entityParameter'] );
				$parameter = $parameter ? $parameter : 'entity';

				if ( isset( $_GET[ $parameter ] ) ) {
					$candidate = sanitize_key( wp_unslash( $_GET[ $parameter ] ) );
					if ( $candidate && self::is_manageable_post_type( $candidate ) ) {
						$post_type = $candidate;
					}
				}
			}

			return $post_type;
		}

		/**
		 * Post types this block will ever open a form for. Excludes
		 * attachments plus WordPress/ACF's own internal storage types
		 * (templates, ACF's field-group/post-type/taxonomy/options-page
		 * post types, etc.) - without this, ?entity=<any-registered-slug>
		 * would let a logged-in user with matching capabilities open a
		 * create/edit form for content this block was never meant to touch,
		 * not just the project's own CPTs. Mirrors the exclusion list already
		 * used by the CAT Dynamic Data Table block for the same reason.
		 */
		private static function excluded_post_types() {
			return array(
				'attachment',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'wp_font_family',
				'wp_font_face',
				'acf-field-group',
				'acf-field',
				'acf-post-type',
				'acf-taxonomy',
				'acf-ui-options-page',
				'spectra-popup',
			);
		}

		/**
		 * Post types whose title is generated automatically elsewhere
		 * (e.g. cat-equipment-auto-title.php builds "Model — Workstation —
		 * Location" for equipment on save) and must never show a manual
		 * WordPress title field, regardless of the block's own
		 * showPostTitle toggle - that toggle is shared across every entity
		 * this one block serves via ?entity=, so it can't tell "equipment"
		 * apart from "person" or "supplier" on its own.
		 */
		private static function post_types_with_auto_title() {
			return array( 'equipment' );
		}

		private static function should_show_post_title( $post_type, $attributes ) {
			if ( in_array( $post_type, self::post_types_with_auto_title(), true ) ) {
				return false;
			}

			return (bool) $attributes['showPostTitle'];
		}

		private static function is_manageable_post_type( $post_type ) {
			if ( ! $post_type || ! post_type_exists( $post_type ) ) {
				return false;
			}

			if ( in_array( $post_type, self::excluded_post_types(), true ) ) {
				return false;
			}

			$object = get_post_type_object( $post_type );
			return $object && ! empty( $object->show_ui );
		}

		private static function attribute_defaults() {
			$defaults = array();
			foreach ( self::block_attributes() as $key => $definition ) {
				$defaults[ $key ] = isset( $definition['default'] ) ? $definition['default'] : null;
			}
			return $defaults;
		}

		private static function resolve_mode( $requested_mode, $record_id ) {
			$requested_mode = sanitize_key( $requested_mode );
			if ( in_array( $requested_mode, array( 'create', 'edit' ), true ) ) {
				return $requested_mode;
			}
			return $record_id ? 'edit' : 'create';
		}

		private static function resolve_record_id( $attributes, $post_type ) {
			$source = sanitize_key( $attributes['recordSource'] );

			if ( 'current' === $source ) {
				$current_id = absint( get_queried_object_id() );
				return $current_id && $post_type === get_post_type( $current_id ) ? $current_id : 0;
			}

			$parameter = sanitize_key( $attributes['recordParameter'] );
			if ( ! $parameter ) {
				$parameter = 'record_id';
			}

			return isset( $_GET[ $parameter ] ) ? absint( wp_unslash( $_GET[ $parameter ] ) ) : 0;
		}

		private static function current_user_can_create( $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object || ! isset( $object->cap ) ) {
				return false;
			}

			$capability = ! empty( $object->cap->create_posts ) ? $object->cap->create_posts : $object->cap->edit_posts;
			return current_user_can( $capability );
		}

		private static function allowed_post_status( $status ) {
			$status  = sanitize_key( $status );
			$allowed = array( 'publish', 'draft', 'pending', 'private' );
			return in_array( $status, $allowed, true ) ? $status : 'publish';
		}

		private static function get_field_group_keys_for_post_type( $post_type ) {
			if ( ! function_exists( 'acf_get_field_groups' ) ) {
				return array();
			}

			$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
			$keys   = array();

			foreach ( (array) $groups as $group ) {
				if ( ! empty( $group['key'] ) ) {
					$keys[] = $group['key'];
				} elseif ( ! empty( $group['ID'] ) ) {
					$keys[] = absint( $group['ID'] );
				}
			}

			return array_values( array_unique( $keys ) );
		}

		private static function build_return_url( $configured_url ) {
			$configured_url = trim( (string) $configured_url );
			$base           = $configured_url
				? $configured_url
				: self::current_url_without_management_args();

			// Validated in both branches now - previously only the
			// "configured URL" branch was checked against wp_validate_redirect(),
			// leaving the default ("return to this same page") branch trusting
			// $_SERVER['HTTP_HOST'] unchecked. render_delete_form() already
			// validated its own return URL unconditionally; this brings the
			// save/create return URL in line with that.
			$base = wp_validate_redirect( $base, home_url( '/' ) );

			$url = add_query_arg(
				array(
					'cat_form_status' => 'saved',
					'record_id'       => '__CAT_POST_ID__',
				),
				$base
			);

			return str_replace( '__CAT_POST_ID__', '%post_id%', $url );
		}

		private static function current_url_without_management_args() {
			$scheme      = is_ssl() ? 'https' : 'http';
			$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$url         = $scheme . '://' . $host . $request_uri;

			return remove_query_arg(
				array( 'cat_form_status', 'cat_uacf_action', '_cat_uacf_nonce' ),
				$url
			);
		}

		private static function render_delete_form( $record_id, $post_type, $attributes ) {
			$return_url = trim( (string) $attributes['deleteReturnUrl'] );
			if ( ! $return_url ) {
				$return_url = remove_query_arg(
					array( sanitize_key( $attributes['recordParameter'] ), 'record_id', 'cat_form_status' ),
					self::current_url_without_management_args()
				);
			}

			$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );

			$out  = '<form method="post" class="cat-uacf-form__delete-form" data-cat-uacf-delete-form data-confirmation="' . esc_attr( $attributes['deleteConfirmation'] ) . '">';
			$out .= '<input type="hidden" name="cat_uacf_action" value="delete">';
			$out .= '<input type="hidden" name="cat_uacf_record_id" value="' . esc_attr( $record_id ) . '">';
			$out .= '<input type="hidden" name="cat_uacf_post_type" value="' . esc_attr( $post_type ) . '">';
			$out .= '<input type="hidden" name="cat_uacf_delete_return" value="' . esc_url( $return_url ) . '">';
			$out .= wp_nonce_field( 'cat_uacf_delete_' . $record_id, '_cat_uacf_nonce', true, false );
			$out .= '<button type="submit" class="cat-uacf-form__delete button button-secondary">' . esc_html( $attributes['deleteLabel'] ) . '</button>';
			$out .= '</form>';

			return $out;
		}

		public static function handle_delete_request() {
			if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
				return;
			}

			$action = isset( $_POST['cat_uacf_action'] ) ? sanitize_key( wp_unslash( $_POST['cat_uacf_action'] ) ) : '';
			if ( 'delete' !== $action ) {
				return;
			}

			$record_id = isset( $_POST['cat_uacf_record_id'] ) ? absint( wp_unslash( $_POST['cat_uacf_record_id'] ) ) : 0;
			$post_type = isset( $_POST['cat_uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['cat_uacf_post_type'] ) ) : '';
			$nonce     = isset( $_POST['_cat_uacf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cat_uacf_nonce'] ) ) : '';

			if ( ! $record_id || ! wp_verify_nonce( $nonce, 'cat_uacf_delete_' . $record_id ) ) {
				wp_die(
					esc_html__( 'The delete request could not be verified.', 'cat-uacf' ),
					esc_html__( 'Invalid request', 'cat-uacf' ),
					array( 'response' => 403 )
				);
			}

			$post = get_post( $record_id );
			if ( ! $post || $post_type !== $post->post_type || ! current_user_can( 'delete_post', $record_id ) ) {
				wp_die(
					esc_html__( 'You do not have permission to delete this record.', 'cat-uacf' ),
					esc_html__( 'Permission denied', 'cat-uacf' ),
					array( 'response' => 403 )
				);
			}

			/**
			 * Project-specific extensions can remove dependent relationship records
			 * here. The universal core deliberately does not delete unrelated entities.
			 */
			do_action( 'cat_uacf_before_trash_record', $record_id, $post_type );
			$result = wp_trash_post( $record_id );
			do_action( 'cat_uacf_after_trash_record', $record_id, $post_type, $result );

			$return_url = isset( $_POST['cat_uacf_delete_return'] ) ? esc_url_raw( wp_unslash( $_POST['cat_uacf_delete_return'] ) ) : home_url( '/' );
			$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );
			$return_url = add_query_arg( 'cat_form_status', $result ? 'deleted' : 'delete_error', $return_url );

			wp_safe_redirect( $return_url );
			exit;
		}

		public static function print_frontend_script() {
			if ( self::$frontend_script_printed || ! self::$frontend_script_needed ) {
				return;
			}

			self::$frontend_script_printed = true;
			?>
			<script>
			(function () {
				'use strict';
				document.addEventListener('submit', function (event) {
					var form = event.target.closest('[data-cat-uacf-delete-form]');
					if (!form) return;
					var message = form.getAttribute('data-confirmation') || 'Are you sure?';
					if (!window.confirm(message)) event.preventDefault();
				});
			}());
			</script>
			<?php
		}

		private static function status_notice_from_request() {
			$status = isset( $_GET['cat_form_status'] ) ? sanitize_key( wp_unslash( $_GET['cat_form_status'] ) ) : '';
			$map    = array(
				'saved'        => array( __( 'The record was saved successfully.', 'cat-uacf' ), 'success' ),
				'deleted'      => array( __( 'The record was moved to the trash.', 'cat-uacf' ), 'success' ),
				'delete_error' => array( __( 'The record could not be moved to the trash.', 'cat-uacf' ), 'error' ),
			);

			return isset( $map[ $status ] ) ? self::notice( $map[ $status ][0], $map[ $status ][1] ) : '';
		}

		private static function notice( $message, $type = 'info' ) {
			return '<div class="cat-uacf-form__notice cat-uacf-form__notice--' . esc_attr( sanitize_html_class( $type ) ) . '" role="status">' . esc_html( $message ) . '</div>';
		}

		private static function get_post_type_options() {
			$options = array();
			$types   = get_post_types( array( 'show_ui' => true ), 'objects' );

			foreach ( $types as $type ) {
				if ( in_array( $type->name, self::excluded_post_types(), true ) ) {
					continue;
				}

				$options[] = array(
					'label' => $type->labels->singular_name . ' (' . $type->name . ')',
					'value' => $type->name,
				);
			}

			usort(
				$options,
				function ( $a, $b ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
			);

			return $options;
		}

		private static function is_block_editor_preview() {
			if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
				return false;
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			return false !== strpos( $request_uri, '/block-renderer/' );
		}

		private static function render_editor_preview( $post_type, $attributes ) {
			$object = get_post_type_object( $post_type );
			$label  = $object ? $object->labels->singular_name : $post_type;
			$groups = self::get_field_group_keys_for_post_type( $post_type );

			$out  = '<div class="cat-uacf-editor-preview">';
			$out .= '<strong>' . esc_html__( 'Universal ACF Form', 'cat-uacf' ) . ' — ' . esc_html( $label ) . '</strong>';
			$out .= '<p>' . esc_html__( 'The front end will automatically render the ACF groups assigned to this content type.', 'cat-uacf' ) . '</p>';
			$out .= '<p><small>' . esc_html( sprintf( _n( '%d matching field group', '%d matching field groups', count( $groups ), 'cat-uacf' ), count( $groups ) ) ) . '</small></p>';
			$out .= '</div>';

			return $out;
		}

		private static function editor_script() {
			return <<<'JS'
(function (blocks, blockEditor, components, element, i18n) {
	'use strict';

	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var Notice = components.Notice;
	var __ = i18n.__;

	var postTypes = (window.CatUniversalAcfFormData && window.CatUniversalAcfFormData.postTypes) || [];
	var hasAcf = !!(window.CatUniversalAcfFormData && window.CatUniversalAcfFormData.hasAcf);

	registerBlockType('cat/universal-acf-form', {
		apiVersion: 2,
		title: __('Universal ACF Form', 'cat-uacf'),
		description: __('Creates and edits front-end records using the ACF groups assigned to the selected content type.', 'cat-uacf'),
		icon: 'feedback',
		category: 'widgets',
		keywords: ['acf', 'form', 'front end'],
		attributes: {
			postType: { type: 'string', default: 'equipment' },
			allowEntityParameter: { type: 'boolean', default: true },
			entityParameter: { type: 'string', default: 'entity' },
			formMode: { type: 'string', default: 'auto' },
			recordSource: { type: 'string', default: 'query' },
			recordParameter: { type: 'string', default: 'record_id' },
			postStatus: { type: 'string', default: 'publish' },
			showPostTitle: { type: 'boolean', default: true },
			showPostContent: { type: 'boolean', default: false },
			createLabel: { type: 'string', default: 'Create' },
			updateLabel: { type: 'string', default: 'Update' },
			enableDelete: { type: 'boolean', default: true },
			deleteLabel: { type: 'string', default: 'Delete' },
			deleteConfirmation: { type: 'string', default: 'Are you sure you want to move this record to the trash?' },
			returnUrl: { type: 'string', default: '' },
			deleteReturnUrl: { type: 'string', default: '' },
			loginMessage: { type: 'string', default: 'You must be logged in to manage records.' },
			permissionMessage: { type: 'string', default: 'You do not have permission to perform this action.' },
			emptyEditMessage: { type: 'string', default: 'Choose a record before opening the edit form.' }
		},
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var selected = postTypes.filter(function (item) { return item.value === a.postType; })[0];
			var typeLabel = selected ? selected.label : a.postType;
			var blockProps = useBlockProps({ className: 'cat-uacf-editor-placeholder' });

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Form source', 'cat-uacf'), initialOpen: true },
						el(SelectControl, {
							label: __('Content type', 'cat-uacf'),
							value: a.postType,
							options: postTypes,
							onChange: function (value) { set({ postType: value }); }
						}),
						el(ToggleControl, {
							label: __('Allow content type from URL', 'cat-uacf'),
							help: __('Lets one page serve several forms, for example ?entity=sale.', 'cat-uacf'),
							checked: a.allowEntityParameter,
							onChange: function (value) { set({ allowEntityParameter: value }); }
						}),
						a.allowEntityParameter && el(TextControl, {
							label: __('Content type URL parameter', 'cat-uacf'),
							value: a.entityParameter,
							onChange: function (value) { set({ entityParameter: value }); }
						}),
						el(SelectControl, {
							label: __('Form mode', 'cat-uacf'),
							value: a.formMode,
							options: [
								{ label: __('Automatic: create without ID, edit with ID', 'cat-uacf'), value: 'auto' },
								{ label: __('Create only', 'cat-uacf'), value: 'create' },
								{ label: __('Edit only', 'cat-uacf'), value: 'edit' }
							],
							onChange: function (value) { set({ formMode: value }); }
						}),
						el(SelectControl, {
							label: __('Record source', 'cat-uacf'),
							value: a.recordSource,
							options: [
								{ label: __('URL parameter', 'cat-uacf'), value: 'query' },
								{ label: __('Current record', 'cat-uacf'), value: 'current' }
							],
							onChange: function (value) { set({ recordSource: value }); }
						}),
						a.recordSource === 'query' && el(TextControl, {
							label: __('Record URL parameter', 'cat-uacf'),
							help: __('Example: record_id makes ?record_id=123 open record 123.', 'cat-uacf'),
							value: a.recordParameter,
							onChange: function (value) { set({ recordParameter: value }); }
						}),
						el(SelectControl, {
							label: __('New record status', 'cat-uacf'),
							value: a.postStatus,
							options: [
								{ label: __('Published', 'cat-uacf'), value: 'publish' },
								{ label: __('Draft', 'cat-uacf'), value: 'draft' },
								{ label: __('Pending review', 'cat-uacf'), value: 'pending' },
								{ label: __('Private', 'cat-uacf'), value: 'private' }
							],
							onChange: function (value) { set({ postStatus: value }); }
						}),
						el(ToggleControl, {
							label: __('Show WordPress title field', 'cat-uacf'),
							checked: a.showPostTitle,
							onChange: function (value) { set({ showPostTitle: value }); }
						}),
						el(ToggleControl, {
							label: __('Show WordPress content editor', 'cat-uacf'),
							checked: a.showPostContent,
							onChange: function (value) { set({ showPostContent: value }); }
						})
					),
					el(
						PanelBody,
						{ title: __('Buttons and redirects', 'cat-uacf'), initialOpen: false },
						el(TextControl, { label: __('Create button label', 'cat-uacf'), value: a.createLabel, onChange: function (value) { set({ createLabel: value }); } }),
						el(TextControl, { label: __('Update button label', 'cat-uacf'), value: a.updateLabel, onChange: function (value) { set({ updateLabel: value }); } }),
						el(ToggleControl, { label: __('Allow delete while editing', 'cat-uacf'), checked: a.enableDelete, onChange: function (value) { set({ enableDelete: value }); } }),
						a.enableDelete && el(TextControl, { label: __('Delete button label', 'cat-uacf'), value: a.deleteLabel, onChange: function (value) { set({ deleteLabel: value }); } }),
						a.enableDelete && el(TextControl, { label: __('Delete confirmation', 'cat-uacf'), value: a.deleteConfirmation, onChange: function (value) { set({ deleteConfirmation: value }); } }),
						el(TextControl, { label: __('After-save URL', 'cat-uacf'), help: __('Leave empty to return to this page.', 'cat-uacf'), value: a.returnUrl, onChange: function (value) { set({ returnUrl: value }); } }),
						el(TextControl, { label: __('After-delete URL', 'cat-uacf'), help: __('Leave empty to return to this page without the record ID.', 'cat-uacf'), value: a.deleteReturnUrl, onChange: function (value) { set({ deleteReturnUrl: value }); } })
					),
					el(
						PanelBody,
						{ title: __('Messages', 'cat-uacf'), initialOpen: false },
						el(TextControl, { label: __('Login required', 'cat-uacf'), value: a.loginMessage, onChange: function (value) { set({ loginMessage: value }); } }),
						el(TextControl, { label: __('Permission denied', 'cat-uacf'), value: a.permissionMessage, onChange: function (value) { set({ permissionMessage: value }); } }),
						el(TextControl, { label: __('No edit record selected', 'cat-uacf'), value: a.emptyEditMessage, onChange: function (value) { set({ emptyEditMessage: value }); } })
					)
				),
				el(
					'div',
					blockProps,
					!hasAcf && el(Notice, { status: 'error', isDismissible: false }, __('Advanced Custom Fields is not active.', 'cat-uacf')),
					el('strong', null, __('Universal ACF Form', 'cat-uacf') + ' — ' + typeLabel),
					el('p', null, __('The front end will automatically render the ACF fields assigned to this content type.', 'cat-uacf')),
					el('small', null, __('Mode: ', 'cat-uacf') + a.formMode + ' · ' + __('Entity parameter: ', 'cat-uacf') + a.entityParameter + ' · ' + __('Record parameter: ', 'cat-uacf') + a.recordParameter)
				)
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n));
JS;
		}
	}
}

CAT_Universal_ACF_Form_Block::init();
