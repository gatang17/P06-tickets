<?php
/**
 * Universal ACF Form (v2.0.0) — composable block system for building
 * front-end create/edit forms for any Custom Post Type using Advanced
 * Custom Fields (ACF Free), assembled visually in Gutenberg.
 *
 * ARCHITECTURE (v2.0.0 — replaces the v1.x monolithic single-block form):
 *   uacf/universal-acf-form   Parent. Renders ONE <form>. Provides
 *                             'uacf/postType' as Block Context. Holds
 *                             InnerBlocks — nothing is auto-generated.
 *   uacf/acf-field            Renders exactly ONE ACF field, chosen by
 *                             Field Key, via ACF's own real field-render API.
 *   uacf/taxonomy-field       Renders exactly ONE taxonomy's term picker.
 *   uacf/form-message         Placeholder where validation/status messages
 *                             appear. Optional — the parent still shows a
 *                             fallback notice above the fields if omitted.
 *   uacf/submit-button        Renders the real <button type="submit">.
 *
 * IMPORTANT — HOW TO INSTALL WITH THE "CODE SNIPPETS" PLUGIN:
 *   1. Copy the ENTIRE contents of this file.
 *   2. In Code Snippets → Add New, paste the code BUT REMOVE the first
 *      line "<?php" (Code Snippets already treats the editor as PHP).
 *   3. Save with "Run snippet everywhere" (site-wide) and activate.
 *
 * If used as an mu-plugin/regular plugin file instead, leave the opening
 * "<?php" tag in place.
 *
 * Does not depend on ACF Pro, Composer, Node.js, npm, a CDN, or any build
 * step. All block editor JavaScript is generated and injected from this
 * same file via wp_add_inline_script().
 *
 * Requirements: WordPress with Gutenberg, ACF Free active, PHP 8.1+.
 */

// =============================================================================
// SECTION 1 — CHECKS AND CONSTANTS
// =============================================================================

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UACF_VERSION' ) ) {
	define( 'UACF_VERSION', '2.2.0' );
}

if ( ! defined( 'UACF_NONCE_PREFIX' ) ) {
	define( 'UACF_NONCE_PREFIX', 'uacf_form_' );
}

// =============================================================================
// SECTION 6 — AUTOMATIC PREFIX (kept as a standalone function outside the
// class: the requirement asks for this exact signature).
// =============================================================================

if ( ! function_exists( 'inventory_get_prefix' ) ) {
	/**
	 * Generates a deterministic 3-character prefix from a post type key,
	 * normalizing hyphens, underscores and spaces. No manual lookup table.
	 *
	 * @param string $post_type Post type key (e.g. "stock_record").
	 * @return string Uppercase 3-character prefix (e.g. "SRE").
	 */
	function inventory_get_prefix( $post_type ) {
		$post_type  = (string) $post_type;
		$normalized = str_replace( array( '-', '_' ), ' ', $post_type );
		$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );

		$words = array();
		if ( '' !== $normalized ) {
			foreach ( explode( ' ', $normalized ) as $word ) {
				if ( '' !== $word ) {
					$words[] = $word;
				}
			}
		}

		$count  = count( $words );
		$prefix = '';

		if ( 1 === $count ) {
			$prefix = substr( $words[0], 0, 3 );
		} elseif ( 2 === $count ) {
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 2 );
		} elseif ( $count >= 3 ) {
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 1 ) . substr( $words[2], 0, 1 );
		}

		$prefix = strtoupper( $prefix );

		if ( strlen( $prefix ) < 3 ) {
			$source = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $post_type ) );
			$i      = 0;
			while ( strlen( $prefix ) < 3 ) {
				$prefix .= ( $i < strlen( $source ) ) ? $source[ $i ] : 'X';
				$i++;
			}
		}

		return substr( $prefix, 0, 3 );
	}
}

// =============================================================================
// MAIN CLASS
// =============================================================================

if ( ! class_exists( 'UACF_Universal_Form' ) ) {

	final class UACF_Universal_Form {

		// In-memory-only caches (never persistent transients).
		private static $post_types_cache  = null;
		private static $groups_cache      = array();
		private static $fields_cache      = array();
		private static $taxonomies_cache  = array();
		private static $edit_context_cache = array();
		private static $submission_errors  = array();
		private static $submission_handled = false;

		// =====================================================================
		// BOOTSTRAP
		// =====================================================================

		public static function init() {
			add_action( 'wp', array( __CLASS__, 'prime_request' ), 5 );

			add_filter( 'acf/load_field', array( __CLASS__, 'adjust_code_field' ) );
			add_action( 'acf/validate_save_post', array( __CLASS__, 'gate_save_post' ), 5 );
			add_action( 'acf/save_post', array( __CLASS__, 'finalize_save_post' ), 20 );

			add_action( 'init', array( __CLASS__, 'register_blocks' ) );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		}

		// =====================================================================
		// SECTION 2 — AUTOMATIC CPT DISCOVERY (unchanged from v1.x)
		// =====================================================================

		public static function get_available_post_types() {
			if ( null !== self::$post_types_cache ) {
				return self::$post_types_cache;
			}

			$excluded = array(
				'attachment', 'revision', 'nav_menu_item', 'acf-field', 'acf-field-group',
				'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation',
				'wp_global_styles', 'wp_font_family', 'wp_font_face', 'custom_css',
				'customize_changeset', 'oembed_cache', 'user_request',
			);
			$excluded = apply_filters( 'uacf_excluded_post_types', $excluded );

			$objects = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );

			$result = array();
			foreach ( $objects as $key => $object ) {
				if ( in_array( $key, $excluded, true ) ) {
					continue;
				}
				$result[ $key ] = $object;
			}

			self::$post_types_cache = apply_filters( 'uacf_available_post_types', $result );
			return self::$post_types_cache;
		}

		// =====================================================================
		// SECTION 3 — AUTOMATIC DISCOVERY OF ACF GROUPS AND FIELDS (unchanged)
		// =====================================================================

		public static function get_field_groups_for_post_type( $post_type ) {
			if ( isset( self::$groups_cache[ $post_type ] ) ) {
				return self::$groups_cache[ $post_type ];
			}
			$groups = array();
			if ( function_exists( 'acf_get_field_groups' ) ) {
				$found = acf_get_field_groups( array( 'post_type' => $post_type ) );
				if ( is_array( $found ) ) {
					$groups = $found;
				}
			}
			self::$groups_cache[ $post_type ] = $groups;
			return $groups;
		}

		/**
		 * Flat, top-level fields for a CPT's active groups.
		 *
		 * @return array Flat list of ACF field arrays.
		 */
		public static function get_fields_for_post_type( $post_type ) {
			if ( isset( self::$fields_cache[ $post_type ] ) ) {
				return self::$fields_cache[ $post_type ];
			}
			$fields = array();
			if ( function_exists( 'acf_get_fields' ) ) {
				foreach ( self::get_field_groups_for_post_type( $post_type ) as $group ) {
					$group_fields = acf_get_fields( $group );
					if ( is_array( $group_fields ) ) {
						foreach ( $group_fields as $field ) {
							$fields[] = $field;
						}
					}
				}
			}
			self::$fields_cache[ $post_type ] = $fields;
			return $fields;
		}

		/**
		 * Looks up ONE field belonging to a CPT by its Field Key. This is the
		 * authoritative whitelist check used both when a single Universal ACF
		 * Field block renders (so it can only ever show a field that really
		 * belongs to the CPT selected in its parent form) and, more
		 * critically, when the submission is saved (so a Field Key belonging
		 * to a different CPT can never be persisted).
		 *
		 * @return array|null
		 */
		public static function get_field_by_key( $post_type, $field_key ) {
			if ( '' === (string) $field_key ) {
				return null;
			}
			foreach ( self::get_fields_for_post_type( $post_type ) as $field ) {
				if ( isset( $field['key'] ) && $field['key'] === $field_key ) {
					return $field;
				}
			}
			return null;
		}

		// =====================================================================
		// SECTION 4 — PREFIXES AND CODES (unchanged)
		// =====================================================================

		public static function find_code_field( array $fields ) {
			foreach ( $fields as $field ) {
				if ( empty( $field['type'] ) || 'text' !== $field['type'] ) {
					continue;
				}
				$name = isset( $field['name'] ) ? $field['name'] : '';
				if ( '' !== $name && '_code' === substr( $name, -5 ) ) {
					return $field;
				}
			}
			return null;
		}

		public static function generate_unique_code( $post_type, $field_name ) {
			global $wpdb;

			$prefix      = inventory_get_prefix( $post_type );
			$option_name = 'uacf_code_seq_' . $post_type;

			if ( false === get_option( $option_name, false ) ) {
				add_option( $option_name, 0, '', false );
			}

			$code     = '';
			$attempts = 0;

			do {
				$wpdb->query(
					$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option_name )
				);
				$sequence = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name )
				);
				if ( $sequence < 1 ) {
					$sequence = 1;
				}
				$code = $prefix . '-' . str_pad( (string) $sequence, 3, '0', STR_PAD_LEFT );
				$attempts++;
			} while ( $attempts < 20 && self::code_exists( $post_type, $field_name, $code ) );

			return $code;
		}

		private static function code_exists( $post_type, $field_name, $code ) {
			$existing = get_posts( array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => $field_name,
				'meta_value'             => $code,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			return ! empty( $existing );
		}

		/**
		 * acf/load_field filter: makes readonly + non-required, front-end
		 * only, any text field whose name ends in "_code". Applies whether
		 * ACF renders the field via acf_render_field_wrap() (our per-field
		 * rendering) or any other path, since it hooks ACF's own field
		 * loading, not our rendering code.
		 */
		public static function adjust_code_field( $field ) {
			if ( is_admin() ) {
				return $field;
			}
			if ( empty( $field['type'] ) || 'text' !== $field['type'] ) {
				return $field;
			}
			if ( empty( $field['name'] ) || '_code' !== substr( $field['name'], -5 ) ) {
				return $field;
			}
			$field['readonly'] = 1;
			$field['required'] = 0;
			if ( empty( $field['placeholder'] ) ) {
				$field['placeholder'] = __( 'Will be generated automatically on save', 'uacf' );
			}
			return $field;
		}

		// =====================================================================
		// SECTION 5 — TITLE FIELD DETECTION (unchanged)
		// =====================================================================

		public static function find_title_field( array $fields ) {
			$first_text = null;
			foreach ( $fields as $field ) {
				if ( empty( $field['type'] ) || 'text' !== $field['type'] ) {
					continue;
				}
				$name = isset( $field['name'] ) ? $field['name'] : '';
				if ( '' === $name || '_code' === substr( $name, -5 ) ) {
					continue;
				}
				if ( null === $first_text ) {
					$first_text = $field;
				}
				if ( ! empty( $field['required'] ) ) {
					return $field;
				}
			}
			return $first_text;
		}

		private static function determine_title( $post_type, $post_id, array $fields, $code ) {
			$title_field = self::find_title_field( $fields );

			if ( $title_field ) {
				$raw = '';
				if ( isset( $_POST['acf'][ $title_field['key'] ] ) ) {
					$raw = wp_unslash( $_POST['acf'][ $title_field['key'] ] );
				}
				$value = sanitize_text_field( is_array( $raw ) ? '' : $raw );
				if ( '' !== $value ) {
					return $value;
				}
			}

			$post_type_object = get_post_type_object( $post_type );
			$singular          = $post_type_object && ! empty( $post_type_object->labels->singular_name )
				? $post_type_object->labels->singular_name
				: $post_type;

			return ( '' !== $code ) ? ( $singular . ' ' . $code ) : ( $singular . ' #' . $post_id );
		}

		// =====================================================================
		// SECTION 6 — TAXONOMIES (discovery unchanged; rendering is new,
		// added in the next part of this file)
		// =====================================================================

		/**
		 * Public taxonomies for a CPT that the current user may assign
		 * (current_user_can($tax_object->cap->assign_terms)).
		 *
		 * @return array<string,WP_Taxonomy>
		 */
		public static function get_taxonomies_for_post_type( $post_type ) {
			if ( isset( self::$taxonomies_cache[ $post_type ] ) ) {
				return self::$taxonomies_cache[ $post_type ];
			}

			$excluded = apply_filters( 'uacf_excluded_taxonomies', array(
				'post_format', 'nav_menu', 'link_category', 'wp_theme',
				'wp_template_part_area', 'wp_pattern_category',
			) );

			$taxonomies = get_object_taxonomies( $post_type, 'objects' );
			$result     = array();

			foreach ( $taxonomies as $tax_name => $tax_object ) {
				if ( in_array( $tax_name, $excluded, true ) ) {
					continue;
				}
				if ( empty( $tax_object->public ) || empty( $tax_object->show_ui ) ) {
					continue;
				}
				if ( empty( $tax_object->cap->assign_terms ) || ! current_user_can( $tax_object->cap->assign_terms ) ) {
					continue;
				}
				$result[ $tax_name ] = $tax_object;
			}

			self::$taxonomies_cache[ $post_type ] = $result;
			return $result;
		}

		/**
		 * Authoritative whitelist check for ONE taxonomy of a CPT (same role
		 * as get_field_by_key(), for taxonomies).
		 *
		 * @return WP_Taxonomy|null
		 */
		public static function get_taxonomy_by_key( $post_type, $taxonomy ) {
			$taxonomies = self::get_taxonomies_for_post_type( $post_type );
			return isset( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : null;
		}

		private static function build_term_tree( array $terms, $parent = 0, $depth = 0 ) {
			$branch = array();
			foreach ( $terms as $term ) {
				if ( (int) $term->parent === (int) $parent ) {
					$branch[] = array( 'term' => $term, 'depth' => $depth );
					$branch   = array_merge( $branch, self::build_term_tree( $terms, $term->term_id, $depth + 1 ) );
				}
			}
			return $branch;
		}

		/**
		 * Resolves a taxonomy field's stored Display Style against its
		 * Selection Mode, since only 4 combinations are meaningful:
		 * single+dropdown, single+radio, multiple+checkboxes, multiple+dropdown.
		 * An inconsistent stored combo (e.g. an old attribute value) falls
		 * back to the closest valid style for the given mode.
		 */
		private static function resolve_display_style( $selection_mode, $display_style ) {
			$selection_mode = ( 'multiple' === $selection_mode ) ? 'multiple' : 'single';

			if ( 'single' === $selection_mode ) {
				return ( 'radio' === $display_style ) ? 'radio' : 'dropdown';
			}

			return ( 'checkboxes' === $display_style ) ? 'checkboxes' : 'dropdown';
		}

		// =====================================================================
		// SECURITY — shared context resolution (Block Context can only carry
		// a parent block's OWN attributes, e.g. postType; it cannot carry a
		// value computed later inside a render_callback. The resolved
		// create/edit mode and post ID are therefore NOT passed via Block
		// Context — every block that needs them re-derives them from the
		// same sanitized $_GET['edit_id'] + permission checks, through this
		// single cached helper, so the logic itself is never duplicated.
		// =====================================================================

		private static function nonce_action( $post_type, $mode ) {
			return UACF_NONCE_PREFIX . $post_type . '_' . $mode;
		}

		private static function validate_edit_id( $edit_id, $post_type ) {
			if ( $edit_id <= 0 ) {
				return null;
			}
			$post = get_post( $edit_id );
			if ( ! $post || $post->post_type !== $post_type ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $edit_id ) ) {
				return null;
			}
			return $post;
		}

		private static function resolve_new_post_status( $post_type_object, $requested_status ) {
			$status = ( is_string( $requested_status ) && '' !== $requested_status ) ? $requested_status : 'draft';
			if ( 'publish' === $status ) {
				$can_publish = ! empty( $post_type_object->cap->publish_posts ) && current_user_can( $post_type_object->cap->publish_posts );
				if ( ! $can_publish ) {
					return 'draft';
				}
			}
			return $status;
		}

		/**
		 * Resolves, for the CURRENT request, whether the form for a given
		 * post type is in "create" or "edit" mode, validating ?edit_id=
		 * exactly as before (post exists, correct CPT, current_user_can
		 * ('edit_post', $id)). Cached per post type for the duration of the
		 * request since neither $_GET nor the current user change mid-request.
		 *
		 * @return array{mode:string,post_id:int,post:WP_Post|null,post_type_object:WP_Post_Type|null,error:string}
		 */
		public static function resolve_edit_context( $post_type ) {
			if ( isset( self::$edit_context_cache[ $post_type ] ) ) {
				return self::$edit_context_cache[ $post_type ];
			}

			$available         = self::get_available_post_types();
			$post_type_object  = isset( $available[ $post_type ] ) ? $available[ $post_type ] : null;
			$context           = array(
				'mode'             => 'create',
				'post_id'          => 0,
				'post'             => null,
				'post_type_object' => $post_type_object,
				'error'            => '',
			);

			// Single exit point below (no repeated cache-and-return per
			// branch): every branch only ever sets $context, never returns
			// early.
			if ( ! $post_type_object ) {
				$context['error'] = __( 'The selected content type does not exist or is not available.', 'uacf' );
			} else {
				$edit_id = isset( $_GET['edit_id'] ) ? absint( wp_unslash( $_GET['edit_id'] ) ) : 0;

				if ( $edit_id > 0 ) {
					$post = self::validate_edit_id( $edit_id, $post_type );
					if ( $post ) {
						$context['mode']    = 'edit';
						$context['post_id'] = $post->ID;
						$context['post']    = $post;
					} else {
						$context['error'] = __( 'The record you are trying to edit does not exist, does not belong to this form, or you do not have permission to edit it.', 'uacf' );
					}
				} else {
					$can_create = ! empty( $post_type_object->cap->create_posts ) && current_user_can( $post_type_object->cap->create_posts );
					if ( ! $can_create ) {
						$context['error'] = __( 'You do not have permission to create this content type.', 'uacf' );
					}
				}
			}

			self::$edit_context_cache[ $post_type ] = $context;
			return $context;
		}

		/**
		 * Shared permission/nonce/whitelist gate, used both synchronously by
		 * process_submission() (the primary check, run before anything is
		 * saved) and, as defense in depth, by gate_save_post() below (in
		 * case acf_save_post()/acf_validate_save_post() is ever reached
		 * through any other path with this system's $_POST data present).
		 *
		 * @return string Empty string when valid, otherwise an error message.
		 */
		private static function validate_submission_permissions( $post_type, $mode ) {
			if ( ! is_user_logged_in() ) {
				return __( 'You must be logged in to submit this form.', 'uacf' );
			}

			$available = self::get_available_post_types();
			if ( '' === $post_type || ! isset( $available[ $post_type ] ) ) {
				return __( 'Invalid content type.', 'uacf' );
			}
			if ( ! in_array( $mode, array( 'create', 'edit' ), true ) ) {
				return __( 'Invalid form request.', 'uacf' );
			}

			$post_type_object = $available[ $post_type ];

			if ( 'create' === $mode ) {
				if ( empty( $post_type_object->cap->create_posts ) || ! current_user_can( $post_type_object->cap->create_posts ) ) {
					return __( 'You do not have permission to create this content type.', 'uacf' );
				}
				return '';
			}

			$edit_id  = isset( $_POST['uacf_edit_id'] ) ? absint( wp_unslash( $_POST['uacf_edit_id'] ) ) : 0;
			$existing = $edit_id ? get_post( $edit_id ) : null;

			if ( ! $existing || $existing->post_type !== $post_type ) {
				return __( 'The record you are trying to edit does not exist or does not match this form.', 'uacf' );
			}
			if ( ! current_user_can( 'edit_post', $edit_id ) ) {
				return __( 'You do not have permission to edit this record.', 'uacf' );
			}
			return '';
		}

		/**
		 * acf/validate_save_post defense-in-depth hook (see docblock above
		 * validate_submission_permissions()). Not the primary gate anymore —
		 * process_submission() below checks permissions BEFORE ever calling
		 * ACF's validate/save functions.
		 */
		public static function gate_save_post() {
			if ( is_admin() || empty( $_POST['uacf_submit'] ) ) {
				return;
			}
			$post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			$mode      = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : '';
			$nonce     = isset( $_POST['uacf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uacf_nonce'] ) ) : '';

			$error = '';
			if ( ! wp_verify_nonce( $nonce, self::nonce_action( $post_type, $mode ) ) ) {
				$error = __( 'The form session has expired. Reload the page and try again.', 'uacf' );
			} else {
				$error = self::validate_submission_permissions( $post_type, $mode );
			}

			if ( '' !== $error && function_exists( 'acf_add_validation_error' ) ) {
				acf_add_validation_error( '', $error );
			}
		}

		/**
		 * acf/save_post hook (priority 20, after ACF's own field save at
		 * priority 10). Completes what ACF doesn't handle natively: code,
		 * title, taxonomies. No recursion risk: this is ACF's own action,
		 * not WordPress core's 'save_post', so wp_update_post() here does
		 * not re-trigger it. No exit()/redirect happens in this hook — see
		 * process_submission() for why.
		 */
		public static function finalize_save_post( $post_id ) {
			if ( is_admin() || empty( $_POST['uacf_submit'] ) || ! is_numeric( $post_id ) ) {
				return;
			}
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return;
			}
			$post_type = $post->post_type;
			if ( ! isset( self::get_available_post_types()[ $post_type ] ) ) {
				return;
			}

			static $processed = array();
			if ( isset( $processed[ $post_id ] ) ) {
				return;
			}
			$processed[ $post_id ] = true;

			$claimed_post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			if ( $claimed_post_type !== $post_type ) {
				return;
			}

			$mode = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : 'create';

			$fields     = self::get_fields_for_post_type( $post_type );
			$code_field = self::find_code_field( $fields );
			$code       = '';

			if ( $code_field ) {
				$existing_code = get_post_meta( $post_id, $code_field['name'], true );
				if ( 'edit' === $mode && '' !== $existing_code ) {
					$code = $existing_code;
				} else {
					$code = self::generate_unique_code( $post_type, $code_field['name'] );
					if ( function_exists( 'update_field' ) && ! empty( $code_field['key'] ) ) {
						update_field( $code_field['key'], $code, $post_id );
					}
					update_post_meta( $post_id, $code_field['name'], $code );
				}
			}

			$title = self::determine_title( $post_type, $post_id, $fields, $code );
			if ( '' !== $title && $title !== $post->post_title ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
			}

			self::save_taxonomies( $post_type, $post_id );
		}

		/**
		 * Saves only the taxonomies that were actually rendered by a
		 * Universal Taxonomy Field block on THIS specific form (their hidden
		 * field simply won't exist in $_POST otherwise), further restricted
		 * to taxonomies the current user may assign (already enforced by
		 * get_taxonomies_for_post_type()). Only existing term IDs are ever
		 * accepted (term_exists()); wp_set_object_terms() never creates
		 * new terms.
		 */
		private static function save_taxonomies( $post_type, $post_id ) {
			foreach ( self::get_taxonomies_for_post_type( $post_type ) as $tax_name => $tax_object ) {
				$single_key = 'uacf_tax_' . $tax_name;
				$multi_key  = $single_key . '_multi';

				if ( isset( $_POST[ $multi_key ] ) ) {
					$raw      = wp_unslash( $_POST[ $multi_key ] );
					$raw      = is_array( $raw ) ? $raw : array( $raw );
					$term_ids = array();
					foreach ( $raw as $value ) {
						$id = absint( $value );
						if ( $id > 0 && term_exists( $id, $tax_name ) ) {
							$term_ids[] = $id;
						}
					}
					wp_set_object_terms( $post_id, $term_ids, $tax_name, false );
				} elseif ( isset( $_POST[ $single_key ] ) ) {
					$id = absint( wp_unslash( $_POST[ $single_key ] ) );
					if ( $id > 0 && term_exists( $id, $tax_name ) ) {
						wp_set_object_terms( $post_id, array( $id ), $tax_name, false );
					} else {
						wp_set_object_terms( $post_id, array(), $tax_name, false );
					}
				}
			}
		}

		// =====================================================================
		// SUBMISSION PIPELINE — runs on the "wp" hook, before any HTML is
		// sent. Replaces the old acf_form()/acf_form_head()-driven save: we
		// now call ACF's own real, lower-level APIs directly, because
		// acf_form() would print every field itself (exactly what this
		// architecture must avoid).
		// =====================================================================

		public static function prime_request() {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}

			if ( function_exists( 'acf_form_head' ) && is_user_logged_in() ) {
				// acf_form_head() is still called for its asset-enqueuing role
				// (acf-input.js/css, needed for conditional logic, the media
				// uploader, relationship/post_object search, date pickers,
				// etc. on the individually-rendered fields). We never call
				// acf_form() itself, so its automatic $_POST['_acf_form']
				// detection has nothing to act on — our own process_submission()
				// below owns the entire save flow.
				wp_enqueue_media();
				if ( function_exists( 'acf_update_setting' ) ) {
					acf_update_setting( 'uploader', 'wp' );
				}
				acf_form_head();
			}

			self::process_submission();
		}

		/**
		 * Restricts $_POST['acf'] to Field Keys that genuinely belong to the
		 * given CPT's discovered ACF fields, BEFORE ACF's own validate/save
		 * functions ever see it. This is the authoritative protection
		 * against a Field Key belonging to a different CPT being submitted:
		 * regardless of what a Universal ACF Field block rendered (or a
		 * forged raw POST request), only whitelisted keys survive.
		 */
		private static function sanitize_submitted_acf_data( $post_type ) {
			if ( ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
				$_POST['acf'] = array();
				return;
			}

			$allowed = array();
			foreach ( self::get_fields_for_post_type( $post_type ) as $field ) {
				if ( ! empty( $field['key'] ) ) {
					$allowed[ $field['key'] ] = true;
				}
			}

			foreach ( array_keys( $_POST['acf'] ) as $key ) {
				if ( ! isset( $allowed[ $key ] ) ) {
					unset( $_POST['acf'][ $key ] );
				}
			}
		}

		/**
		 * Orchestrates one submission end-to-end: nonce + permissions
		 * (before anything is touched) → create the post (create mode) or
		 * resolve the existing one (edit mode) → ACF's own real
		 * acf_validate_save_post() → ACF's own real acf_save_post()
		 * (this is what fires 'acf/save_post', so finalize_save_post() and
		 * any third-party plugin's own callback on that action still run
		 * exactly as before) → redirect.
		 *
		 * The redirect happens HERE, after acf_save_post() has fully
		 * returned — i.e. OUTSIDE of the 'acf/save_post' action itself — so
		 * no later-priority callback on that hook (ACF's own or a third
		 * party's) is ever cut off, unlike calling wp_redirect()/exit from
		 * within the hook.
		 */
		public static function process_submission() {
			if ( self::$submission_handled || empty( $_POST['uacf_submit'] ) ) {
				return;
			}
			self::$submission_handled = true;

			$post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			$mode      = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : '';
			$nonce     = isset( $_POST['uacf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uacf_nonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, self::nonce_action( $post_type, $mode ) ) ) {
				self::$submission_errors[] = __( 'The form session has expired. Reload the page and try again.', 'uacf' );
				return;
			}

			$permission_error = self::validate_submission_permissions( $post_type, $mode );
			if ( '' !== $permission_error ) {
				self::$submission_errors[] = $permission_error;
				return;
			}

			if ( ! function_exists( 'acf_validate_save_post' ) || ! function_exists( 'acf_save_post' ) ) {
				self::$submission_errors[] = __( 'Advanced Custom Fields (ACF) is not active. Activate it to save this form.', 'uacf' );
				return;
			}

			self::sanitize_submitted_acf_data( $post_type );

			$post_type_object = self::get_available_post_types()[ $post_type ];

			// Required-fields-present check (create mode only — see
			// find_missing_required_fields() docblock for why edit mode is
			// exempt). ACF's own validation only ever looks at keys that
			// ARE present in $_POST['acf']; it has no way to notice a
			// required field whose Universal ACF Field block was never
			// added to the form at all, so this system must catch that
			// case itself, BEFORE anything is created.
			if ( 'create' === $mode ) {
				$missing_required = self::find_missing_required_fields( $post_type );
				if ( ! empty( $missing_required ) ) {
					foreach ( $missing_required as $missing_field ) {
						self::$submission_errors[] = sprintf(
							/* translators: %s: field label. */
							__( 'The form is missing the required field "%s".', 'uacf' ),
							$missing_field['label']
						);
					}
					return;
				}
			}

			// ACF's own real validation, for exactly the (whitelisted) keys
			// present in $_POST['acf'] — no invented API, and no post has
			// been created yet: this mirrors ACF's own internal order when
			// acf_form() is used with a 'new_post' config (validate first,
			// only create the post once validation has already passed).
			if ( ! acf_validate_save_post( false ) ) {
				$errors = function_exists( 'acf_get_validation_errors' ) ? acf_get_validation_errors() : array();
				foreach ( (array) $errors as $error ) {
					if ( ! empty( $error['message'] ) ) {
						self::$submission_errors[] = wp_strip_all_tags( $error['message'] );
					}
				}
				if ( empty( self::$submission_errors ) ) {
					self::$submission_errors[] = __( 'One or more fields are invalid. Please review the form.', 'uacf' );
				}
				return; // Nothing was created — there is nothing to roll back.
			}

			if ( 'edit' === $mode ) {
				$post_id = isset( $_POST['uacf_edit_id'] ) ? absint( wp_unslash( $_POST['uacf_edit_id'] ) ) : 0;
			} else {
				$requested_status = apply_filters( 'uacf_new_post_status', 'publish', $post_type );
				$new_post_status  = self::resolve_new_post_status( $post_type_object, $requested_status );
				$singular         = ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post_type;

				$post_id = wp_insert_post( array(
					'post_type'   => $post_type,
					'post_status' => $new_post_status,
					/* translators: %s: CPT singular label. */
					'post_title'  => sprintf( __( '%s (draft)', 'uacf' ), $singular ),
				), true );

				if ( is_wp_error( $post_id ) ) {
					self::$submission_errors[] = __( 'The record could not be created. Please try again.', 'uacf' );
					return;
				}
			}

			// ACF's own real save — the same core function acf_form() itself
			// calls, so it fires 'acf/save_post' exactly as always (this is
			// where finalize_save_post() completes code/title/taxonomies).
			acf_save_post( $post_id );

			wp_safe_redirect( self::build_redirect_url( $post_type, $post_id ) );
			exit;
		}

		/**
		 * Required ACF fields (top-level, for the given CPT) whose Field Key
		 * is entirely absent from $_POST['acf'] — i.e. no Universal ACF
		 * Field block was placed for them at all. ACF's own
		 * acf_validate_save_post() cannot catch this by itself: it only
		 * validates keys that WERE submitted.
		 *
		 * Two exceptions, matching ACF's/this system's own semantics:
		 *   - a "_code" text field: server-generated, never expected in the
		 *     submission (adjust_code_field() already marks it non-required
		 *     on the front-end for the same reason);
		 *   - a field with conditional_logic configured: whether it's
		 *     currently required depends on other submitted values, and
		 *     re-evaluating ACF's own conditional logic engine server-side
		 *     is out of scope here (documented limitation) — such a field
		 *     is still validated by ACF itself whenever it IS present.
		 *
		 * @return array List of the missing field definitions.
		 */
		private static function find_missing_required_fields( $post_type ) {
			$missing   = array();
			$submitted = ( isset( $_POST['acf'] ) && is_array( $_POST['acf'] ) ) ? $_POST['acf'] : array();

			foreach ( self::get_fields_for_post_type( $post_type ) as $field ) {
				if ( empty( $field['required'] ) || empty( $field['key'] ) ) {
					continue;
				}
				if ( ! empty( $field['name'] ) && 'text' === $field['type'] && '_code' === substr( $field['name'], -5 ) ) {
					continue;
				}
				if ( ! empty( $field['conditional_logic'] ) ) {
					continue;
				}
				if ( ! array_key_exists( $field['key'], $submitted ) ) {
					$missing[] = $field;
				}
			}

			return $missing;
		}

		// =====================================================================
		// RENDERING HELPERS shared by every block's render_callback.
		// =====================================================================

		private static function notice( $type, $message ) {
			$type = in_array( $type, array( 'info', 'success', 'error' ), true ) ? $type : 'info';
			return sprintf(
				'<div class="uacf-notice uacf-notice-%s">%s</div>',
				esc_attr( $type ),
				wp_kses_post( $message )
			);
		}

		private static function has_submission_errors() {
			return ! empty( self::$submission_errors );
		}

		private static function render_messages_markup() {
			$html = '';

			if ( isset( $_GET['uacf_status'] ) && 'success' === sanitize_key( wp_unslash( $_GET['uacf_status'] ) ) ) {
				$html .= self::notice( 'success', __( 'Saved successfully.', 'uacf' ) );
			}

			foreach ( self::$submission_errors as $error ) {
				$html .= self::notice( 'error', $error );
			}

			return $html;
		}

		private static function get_current_clean_url() {
			$queried_id = get_queried_object_id();
			$permalink  = $queried_id ? get_permalink( $queried_id ) : false;

			if ( ! $permalink ) {
				global $wp;
				$permalink = ! empty( $wp->request ) ? home_url( user_trailingslashit( $wp->request ) ) : home_url( '/' );
			}

			return $permalink;
		}

		private static function build_form_action_url( $mode, $post_id ) {
			$base = self::get_current_clean_url();
			if ( 'edit' === $mode && $post_id > 0 ) {
				return esc_url( add_query_arg( array( 'edit_id' => $post_id ), $base ) );
			}
			return esc_url( $base );
		}

		/**
		 * Builds the post-save redirect URL. Unlike the previous
		 * architecture (which relied on acf_form()'s deferred "%post_id%"
		 * placeholder because the real ID wasn't known until later inside
		 * ACF's own internals), process_submission() already has the real,
		 * final $post_id in hand at this point, so the URL can be built
		 * directly — simpler and equally safe.
		 */
		private static function build_redirect_url( $post_type, $post_id ) {
			$base = self::get_current_clean_url();
			$args = array(
				'uacf_status' => 'success',
				'edit_id'     => $post_id,
			);
			return esc_url_raw( add_query_arg( $args, $base ) );
		}

		// =====================================================================
		// BLOCK RENDER CALLBACKS
		// =====================================================================

		/**
		 * uacf/universal-acf-form — the only block that opens/closes the
		 * <form> tag. Renders exactly the InnerBlocks the user placed
		 * ($content) — no field or button is generated automatically.
		 */
		public static function render_form_block( $attributes, $content, $block ) {
			$post_type = isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : '';

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_form_preview( $post_type );
			}

			if ( ! function_exists( 'acf_form_head' ) ) {
				return self::notice( 'error', __( 'Advanced Custom Fields (ACF) is not active. Activate it to use this form.', 'uacf' ) );
			}

			if ( '' === $post_type ) {
				return self::notice( 'info', __( 'This block does not have a content type selected yet. Configure it from the editor sidebar.', 'uacf' ) );
			}

			if ( ! is_user_logged_in() ) {
				return self::notice(
					'info',
					sprintf(
						/* translators: %s: login URL. */
						__( 'You must <a href="%s">log in</a> to use this form.', 'uacf' ),
						esc_url( wp_login_url( self::get_current_clean_url() ) )
					)
				);
			}

			$edit_context = self::resolve_edit_context( $post_type );

			if ( '' !== $edit_context['error'] ) {
				return self::notice( 'error', $edit_context['error'] );
			}

			$mode    = $edit_context['mode'];
			$post_id = $edit_context['post_id'];

			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uacf-form-wrap' ) );

			$hidden  = wp_nonce_field( self::nonce_action( $post_type, $mode ), 'uacf_nonce', true, false );
			$hidden .= '<input type="hidden" name="uacf_post_type" value="' . esc_attr( $post_type ) . '" />';
			$hidden .= '<input type="hidden" name="uacf_mode" value="' . esc_attr( $mode ) . '" />';
			$hidden .= '<input type="hidden" name="uacf_submit" value="1" />';
			if ( 'edit' === $mode ) {
				$hidden .= '<input type="hidden" name="uacf_edit_id" value="' . esc_attr( (string) $post_id ) . '" />';
			}

			$has_message_block = ( false !== strpos( (string) $content, 'uacf-form-message' ) );
			$fallback_messages = $has_message_block ? '' : self::render_messages_markup();

			return '<form ' . $wrapper . ' method="post" enctype="multipart/form-data" action="' . self::build_form_action_url( $mode, $post_id ) . '">'
				. $hidden
				. $fallback_messages
				. (string) $content
				. '</form>';
		}

		/**
		 * uacf/acf-field — renders exactly ONE ACF field, via ACF's own real
		 * field-rendering API (acf_render_field_wrap()), never a manually
		 * built <input>.
		 */
		public static function render_acf_field_block( $attributes, $content, $block ) {
			$post_type = isset( $block->context['uacf/postType'] ) ? sanitize_key( $block->context['uacf/postType'] ) : '';
			$field_key = isset( $attributes['fieldKey'] ) ? sanitize_text_field( $attributes['fieldKey'] ) : '';

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_field_preview( $post_type, $field_key );
			}

			if ( '' === $post_type ) {
				return self::notice( 'error', __( 'This field must be placed inside a Universal ACF Form block.', 'uacf' ) );
			}

			$field = self::get_field_by_key( $post_type, $field_key );
			if ( ! $field ) {
				return self::notice( 'info', __( 'Select a field for this block in the sidebar.', 'uacf' ) );
			}

			if ( ! function_exists( 'acf_render_field_wrap' ) ) {
				return self::notice( 'error', __( 'Advanced Custom Fields (ACF) is not active.', 'uacf' ) );
			}

			$edit_context = self::resolve_edit_context( $post_type );
			$post_id      = ( 'edit' === $edit_context['mode'] ) ? $edit_context['post_id'] : 0;

			if ( self::has_submission_errors() && array_key_exists( $field['key'], (array) ( $_POST['acf'] ?? array() ) ) ) {
				// A submission was attempted and rejected THIS request (see
				// process_submission()): redisplay exactly what the user
				// submitted so a validation error never wipes the form.
				// Passed through as-is (only wp_unslash(), no
				// sanitize_text_field() or similar) because ACF's own value
				// shape varies by type — arrays for
				// Checkbox/Relationship/Post Object/Select(multiple), an
				// attachment ID for Image/File, etc. — and mangling that
				// shape here would break acf_render_field_wrap() for those
				// types. ACF re-validates/sanitizes again on the next submit
				// regardless; nothing is persisted from this value at this
				// point.
				$field['value'] = wp_unslash( $_POST['acf'][ $field['key'] ] );
			} elseif ( $post_id > 0 && function_exists( 'acf_get_value' ) ) {
				$field['value'] = acf_get_value( $post_id, $field );
			} elseif ( isset( $field['default_value'] ) ) {
				$field['value'] = $field['default_value'];
			}

			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uacf-field-wrap' ) );

			ob_start();
			echo '<div ' . $wrapper . '>';
			acf_render_field_wrap( $field );
			echo '</div>';
			return ob_get_clean();
		}

		/**
		 * uacf/taxonomy-field — renders exactly ONE taxonomy's term picker.
		 */
		public static function render_taxonomy_field_block( $attributes, $content, $block ) {
			$post_type = isset( $block->context['uacf/postType'] ) ? sanitize_key( $block->context['uacf/postType'] ) : '';
			$taxonomy  = isset( $attributes['taxonomy'] ) ? sanitize_key( $attributes['taxonomy'] ) : '';

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_taxonomy_preview( $post_type, $taxonomy );
			}

			if ( '' === $post_type ) {
				return self::notice( 'error', __( 'This field must be placed inside a Universal ACF Form block.', 'uacf' ) );
			}

			$tax_object = self::get_taxonomy_by_key( $post_type, $taxonomy );
			if ( ! $tax_object ) {
				return self::notice( 'info', __( 'Select a taxonomy for this block in the sidebar.', 'uacf' ) );
			}

			$selection_mode = ( isset( $attributes['selectionMode'] ) && 'multiple' === $attributes['selectionMode'] ) ? 'multiple' : 'single';
			$display_style  = self::resolve_display_style( $selection_mode, isset( $attributes['displayStyle'] ) ? $attributes['displayStyle'] : 'dropdown' );

			$edit_context = self::resolve_edit_context( $post_type );
			$post_id      = ( 'edit' === $edit_context['mode'] ) ? $edit_context['post_id'] : 0;

			$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uacf-taxonomy-wrap' ) );

			ob_start();
			echo '<div ' . $wrapper . '>';

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$label = ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : $taxonomy;
				printf(
					'<p class="uacf-no-terms">%s</p>',
					esc_html( sprintf(
						/* translators: %s: taxonomy label. */
						__( 'No "%s" terms are available yet.', 'uacf' ),
						$label
					) )
				);
			} else {
				$selected = array();
				if ( $post_id ) {
					$current = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $current ) ) {
						$selected = array_map( 'intval', $current );
					}
				}
				self::render_taxonomy_control( $taxonomy, $tax_object, $terms, $selected, $selection_mode, $display_style );
			}

			echo '</div>';
			return ob_get_clean();
		}

		/**
		 * Renders one of the 4 valid Selection Mode × Display Style
		 * combinations (see resolve_display_style()):
		 *   single + dropdown   -> closed <select>, one <option> per term.
		 *   single + radio      -> radio buttons, one name, no [].
		 *   multiple + checkboxes -> a plain visible checkbox list.
		 *   multiple + dropdown -> an accessible disclosure button + panel
		 *     of real checkboxes (progressively enhanced by JS; see
		 *     get_frontend_taxonomy_js()). No <select multiple>, ever.
		 */
		private static function render_taxonomy_control( $taxonomy, $tax_object, array $terms, array $selected, $selection_mode, $display_style ) {
			$label = ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : $taxonomy;
			$tree  = self::build_term_tree( $terms, 0, 0 );

			if ( 'single' === $selection_mode ) {
				$field_name = 'uacf_tax_' . $taxonomy;

				if ( 'radio' === $display_style ) {
					echo '<fieldset class="uacf-tax-radio"><legend>' . esc_html( $label ) . '</legend>';
					// A real "None" option, checked by default when nothing
					// is selected, guarantees the browser ALWAYS submits this
					// field (radio groups submit nothing at all if no radio
					// in them is checked). That lets a previously selected
					// term be explicitly cleared when editing, and lets
					// save_taxonomies() tell "no selection" apart from
					// "this taxonomy's block isn't on the page at all".
					printf(
						'<label class="uacf-term-radio"><input type="radio" name="%s" value="" %s /> %s</label>',
						esc_attr( $field_name ),
						checked( empty( $selected ), true, false ),
						esc_html__( 'None', 'uacf' )
					);
					foreach ( $tree as $row ) {
						$term = $row['term'];
						printf(
							'<label class="uacf-term-radio" style="margin-left:%dpx;"><input type="radio" name="%s" value="%d" %s /> %s</label>',
							(int) $row['depth'] * 16,
							esc_attr( $field_name ),
							(int) $term->term_id,
							checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
							esc_html( $term->name )
						);
					}
					echo '</fieldset>';
					return;
				}

				echo '<label class="uacf-tax-label" for="' . esc_attr( $field_name ) . '">' . esc_html( $label ) . '</label>';
				echo '<select name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $field_name ) . '" class="uacf-term-select">';
				echo '<option value="">' . esc_html__( 'Select…', 'uacf' ) . '</option>';
				foreach ( $tree as $row ) {
					$term   = $row['term'];
					$indent = str_repeat( '&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth'] );
					printf(
						'<option value="%d" %s>%s%s</option>',
						(int) $term->term_id,
						selected( in_array( (int) $term->term_id, $selected, true ), true, false ),
						$indent,
						esc_html( $term->name )
					);
				}
				echo '</select>';
				return;
			}

			// Multiple.
			$field_name = 'uacf_tax_' . $taxonomy . '_multi';
			echo '<input type="hidden" name="' . esc_attr( $field_name ) . '[]" value="" />';

			if ( 'checkboxes' === $display_style ) {
				echo '<fieldset class="uacf-tax-checkboxes"><legend>' . esc_html( $label ) . '</legend>';
				foreach ( $tree as $row ) {
					$term = $row['term'];
					printf(
						'<label class="uacf-term-checkbox" style="margin-left:%dpx;"><input type="checkbox" name="%s[]" value="%d" %s /> %s</label>',
						(int) $row['depth'] * 16,
						esc_attr( $field_name ),
						(int) $term->term_id,
						checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
						esc_html( $term->name )
					);
				}
				echo '</fieldset>';
				return;
			}

			// Multiple + dropdown.
			$selected_count = count( $selected );
			$toggle_text    = __( 'Select…', 'uacf' );
			if ( 1 === $selected_count ) {
				foreach ( $terms as $term ) {
					if ( (int) $term->term_id === (int) $selected[0] ) {
						$toggle_text = $term->name;
						break;
					}
				}
			} elseif ( $selected_count > 1 ) {
				$toggle_text = sprintf(
					/* translators: %d: number of selected terms. */
					_n( '%d term selected', '%d terms selected', $selected_count, 'uacf' ),
					$selected_count
				);
			}

			printf(
				'<div class="uacf-term-dropdown" data-empty-label="%s" data-multi-template="%s"><span class="uacf-tax-label">%s</span>',
				esc_attr__( 'Select…', 'uacf' ),
				esc_attr__( '%d terms selected', 'uacf' ),
				esc_html( $label )
			);
			// No "hidden" attribute and no aria-expanded here on purpose: the
			// panel is a plain, fully usable, always-visible checkbox list
			// until front-end JS (get_frontend_taxonomy_js()) turns it into
			// a closed dropdown on load. Without JS, this degrades to an
			// open checkbox list instead of becoming unreachable.
			printf(
				'<button type="button" class="uacf-term-dropdown-toggle" aria-haspopup="true"><span class="uacf-term-dropdown-label">%s</span></button>',
				esc_html( $toggle_text )
			);
			echo '<div class="uacf-term-dropdown-panel">';
			foreach ( $tree as $row ) {
				$term = $row['term'];
				printf(
					'<label class="uacf-term-checkbox" style="margin-left:%dpx;"><input type="checkbox" name="%s[]" value="%d" %s /> %s</label>',
					(int) $row['depth'] * 16,
					esc_attr( $field_name ),
					(int) $term->term_id,
					checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
					esc_html( $term->name )
				);
			}
			echo '</div></div>';
		}

		/**
		 * uacf/form-message — placeholder for validation/status messages.
		 * Always outputs the wrapper (even empty), so render_form_block()
		 * can detect its presence in $content and skip the fallback notice.
		 */
		public static function render_message_block( $attributes ) {
			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uacf-form-message' ) );
			return '<div ' . $wrapper . '>' . self::render_messages_markup() . '</div>';
		}

		/**
		 * uacf/submit-button — the real, single <button type="submit">.
		 */
		public static function render_submit_button_block( $attributes, $content, $block ) {
			$post_type = isset( $block->context['uacf/postType'] ) ? sanitize_key( $block->context['uacf/postType'] ) : '';
			$mode      = 'create';
			if ( '' !== $post_type ) {
				$mode = self::resolve_edit_context( $post_type )['mode'];
			}

			$create_label = ( isset( $attributes['createLabel'] ) && '' !== trim( (string) $attributes['createLabel'] ) ) ? $attributes['createLabel'] : __( 'Create', 'uacf' );
			$update_label = ( isset( $attributes['updateLabel'] ) && '' !== trim( (string) $attributes['updateLabel'] ) ) ? $attributes['updateLabel'] : __( 'Update', 'uacf' );
			$label        = ( 'edit' === $mode ) ? $update_label : $create_label;

			$classes = array( 'uacf-submit-button' );
			if ( ! empty( $attributes['fullWidth'] ) ) {
				$classes[] = 'uacf-submit-button-full';
			}
			if ( ! empty( $attributes['align'] ) ) {
				$classes[] = 'align' . sanitize_html_class( $attributes['align'] );
			}

			$wrapper = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );

			return sprintf( '<button type="submit" %s>%s</button>', $wrapper, esc_html( $label ) );
		}

		// =====================================================================
		// Static, non-interactive editor/REST previews. Never touch ACF.
		// =====================================================================

		private static function static_preview_markup( $title, $meta ) {
			return sprintf(
				'<div class="uacf-static-preview"><p class="uacf-static-preview-title">%s</p><p class="uacf-static-preview-meta">%s</p></div>',
				esc_html( $title ),
				esc_html( $meta )
			);
		}

		private static function render_static_form_preview( $post_type ) {
			$available = self::get_available_post_types();
			$meta      = isset( $available[ $post_type ] ) && ! empty( $available[ $post_type ]->labels->singular_name )
				? sprintf( __( 'Post Type: %s', 'uacf' ), $available[ $post_type ]->labels->singular_name )
				: __( 'No content type selected', 'uacf' );
			return self::static_preview_markup( __( 'Universal ACF Form', 'uacf' ), $meta );
		}

		private static function render_static_field_preview( $post_type, $field_key ) {
			$field = self::get_field_by_key( $post_type, $field_key );
			$meta  = $field
				? sprintf( '%s · %s%s', $field['label'], $field['type'], ! empty( $field['required'] ) ? ' · ' . __( 'Required', 'uacf' ) : '' )
				: __( 'No field selected', 'uacf' );
			return self::static_preview_markup( __( 'ACF Field', 'uacf' ), $meta );
		}

		private static function render_static_taxonomy_preview( $post_type, $taxonomy ) {
			$tax_object = self::get_taxonomy_by_key( $post_type, $taxonomy );
			$meta       = $tax_object ? $tax_object->labels->name : __( 'No taxonomy selected', 'uacf' );
			return self::static_preview_markup( __( 'Taxonomy Field', 'uacf' ), $meta );
		}

		// =====================================================================
		// BLOCK REGISTRATION (PHP side — authoritative for attributes,
		// context and rendering; mirrored in the editor JS below for the
		// editing UI).
		// =====================================================================

		private static function block_supports( $with_anchor = true ) {
			$supports = array(
				'customClassName' => true,
				'spacing'         => array( 'margin' => true, 'padding' => true ),
				'color'           => array( 'background' => true, 'text' => true ),
			);
			if ( $with_anchor ) {
				$supports['anchor'] = true;
			}
			return $supports;
		}

		public static function register_blocks() {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			register_block_type( 'uacf/universal-acf-form', array(
				'attributes'       => array(
					'postType' => array( 'type' => 'string', 'default' => '' ),
				),
				'provides_context' => array( 'uacf/postType' => 'postType' ),
				'supports'         => self::block_supports( true ),
				'render_callback'  => array( __CLASS__, 'render_form_block' ),
			) );

			register_block_type( 'uacf/acf-field', array(
				'attributes'      => array(
					'fieldKey' => array( 'type' => 'string', 'default' => '' ),
				),
				'uses_context'    => array( 'uacf/postType' ),
				'supports'        => self::block_supports( true ),
				'render_callback' => array( __CLASS__, 'render_acf_field_block' ),
			) );

			register_block_type( 'uacf/taxonomy-field', array(
				'attributes'      => array(
					'taxonomy'      => array( 'type' => 'string', 'default' => '' ),
					'selectionMode' => array( 'type' => 'string', 'default' => 'single' ),
					'displayStyle'  => array( 'type' => 'string', 'default' => 'dropdown' ),
				),
				'uses_context'    => array( 'uacf/postType' ),
				'supports'        => self::block_supports( true ),
				'render_callback' => array( __CLASS__, 'render_taxonomy_field_block' ),
			) );

			register_block_type( 'uacf/form-message', array(
				'supports'        => self::block_supports( true ),
				'render_callback' => array( __CLASS__, 'render_message_block' ),
			) );

			register_block_type( 'uacf/submit-button', array(
				'attributes'      => array(
					'createLabel' => array( 'type' => 'string', 'default' => '' ),
					'updateLabel' => array( 'type' => 'string', 'default' => '' ),
					'align'       => array( 'type' => 'string', 'default' => '' ),
					'fullWidth'   => array( 'type' => 'boolean', 'default' => false ),
				),
				'uses_context'    => array( 'uacf/postType' ),
				'supports'        => self::block_supports( true ),
				'render_callback' => array( __CLASS__, 'render_submit_button_block' ),
			) );
		}

		// =====================================================================
		// EDITOR JAVASCRIPT — registers all 5 blocks. No ServerSideRender
		// anywhere: every block shows a static, non-interactive preview in
		// the editor, built entirely in JS. Loaded ONLY in the block editor
		// (enqueue_block_editor_assets), never on the front-end.
		// =====================================================================

		public static function enqueue_editor_assets() {
			$handle = 'uacf-block-editor';

			wp_register_script(
				$handle,
				false,
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data' ),
				UACF_VERSION,
				true
			);

			$post_type_choices = array();
			$fields_by_type     = array();
			$taxonomies_by_type = array();

			foreach ( self::get_available_post_types() as $key => $object ) {
				$post_type_choices[] = array(
					'value' => $key,
					'label' => ! empty( $object->labels->singular_name ) ? $object->labels->singular_name : $key,
				);

				$fields = array();
				foreach ( self::get_fields_for_post_type( $key ) as $field ) {
					if ( empty( $field['key'] ) ) {
						continue;
					}
					$fields[] = array(
						'key'      => $field['key'],
						'name'     => isset( $field['name'] ) ? $field['name'] : '',
						'label'    => isset( $field['label'] ) ? $field['label'] : $field['key'],
						'type'     => isset( $field['type'] ) ? $field['type'] : '',
						'required' => ! empty( $field['required'] ),
					);
				}
				$fields_by_type[ $key ] = $fields;

				$taxonomies = array();
				foreach ( self::get_taxonomies_for_post_type( $key ) as $tax_name => $tax_object ) {
					$taxonomies[] = array(
						'value' => $tax_name,
						'label' => ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : $tax_name,
					);
				}
				$taxonomies_by_type[ $key ] = $taxonomies;
			}

			wp_localize_script( $handle, 'uacfBlockData', array(
				'postTypes'  => $post_type_choices,
				'fields'     => $fields_by_type,
				'taxonomies' => $taxonomies_by_type,
			) );

			wp_add_inline_script( $handle, self::get_block_editor_js() );
			wp_enqueue_script( $handle );
		}

		private static function get_block_editor_js() {
			return <<<'JS'
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps;
	var useInnerBlocksProps = blockEditor.useInnerBlocksProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var Placeholder = components.Placeholder;
	var Notice = components.Notice;

	var ALLOWED_BLOCKS = [
		'uacf/acf-field',
		'uacf/taxonomy-field',
		'uacf/form-message',
		'uacf/submit-button',
		'core/group',
		'core/columns',
		'core/column',
		'core/heading',
		'core/paragraph',
		'core/spacer'
	];

	// Mirrors block_supports() on the PHP side (customClassName, anchor,
	// spacing.margin/padding, color.text/background) so the same controls
	// that register_block_type() enables for server-side rendering also
	// actually appear in the editor's own Advanced/Styles panels — PHP
	// alone is not enough for that.
	var UACF_BLOCK_SUPPORTS = {
		customClassName: true,
		anchor: true,
		spacing: { margin: true, padding: true },
		color: { background: true, text: true }
	};

	var postTypeChoices = [ { value: '', label: __( 'Select a content type…', 'uacf' ) } ];
	if ( window.uacfBlockData && window.uacfBlockData.postTypes ) {
		window.uacfBlockData.postTypes.forEach( function ( item ) {
			postTypeChoices.push( item );
		} );
	}

	function getPostTypeLabel( value ) {
		var label = value;
		postTypeChoices.forEach( function ( item ) {
			if ( item.value === value ) {
				label = item.label;
			}
		} );
		return label;
	}

	function blockPropsOf( extra ) {
		return useBlockProps ? useBlockProps( extra || {} ) : ( extra || {} );
	}

	// ---------------------------------------------------------------
	// uacf/universal-acf-form — parent. Provides 'uacf/postType' as
	// context to every descendant, at any nesting depth (core/group,
	// core/columns, core/column included). Renders only what the user
	// places inside; nothing is auto-generated.
	// ---------------------------------------------------------------
	registerBlockType( 'uacf/universal-acf-form', {
		title: __( 'Universal ACF Form', 'uacf' ),
		description: __( 'Container for a front-end ACF create/edit form. Add Universal ACF Field, Universal Taxonomy Field, Universal Form Message and Universal Submit Button blocks inside it.', 'uacf' ),
		icon: 'feedback',
		category: 'widgets',
		attributes: {
			postType: { type: 'string', default: '' }
		},
		providesContext: {
			'uacf/postType': 'postType'
		},
		supports: Object.assign( {}, UACF_BLOCK_SUPPORTS, { html: false } ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var clientId = props.clientId;
			var blockProps = blockPropsOf( { className: 'uacf-form-editor-wrap' } );

			// Called unconditionally on every render (never inside an "if"),
			// to respect the Rules of Hooks — it simply returns an empty map
			// when wp-data's block-editor selectors aren't available.
			var presentFieldKeys = ( window.wp && wp.data && wp.data.useSelect ) ? wp.data.useSelect( function ( select ) {
				var editor = select( 'core/block-editor' );
				var keys = {};
				if ( editor && editor.getClientIdsOfDescendants ) {
					editor.getClientIdsOfDescendants( [ clientId ] ).forEach( function ( id ) {
						var block = editor.getBlock( id );
						if ( block && 'uacf/acf-field' === block.name && block.attributes && block.attributes.fieldKey ) {
							keys[ block.attributes.fieldKey ] = true;
						}
					} );
				}
				return keys;
			}, [ clientId ] ) : {};

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Universal ACF Form settings', 'uacf' ) },
					el( SelectControl, {
						label: __( 'Content type (CPT)', 'uacf' ),
						value: attributes.postType,
						options: postTypeChoices,
						onChange: function ( value ) {
							setAttributes( { postType: value } );
						}
					} )
				)
			);

			var header = el(
				'p',
				{ className: 'uacf-form-editor-label' },
				__( 'Universal ACF Form', 'uacf' ) + ' — ' + ( attributes.postType ? getPostTypeLabel( attributes.postType ) : __( 'No content type selected', 'uacf' ) )
			);

			// "Si es posible" warning: which required ACF fields for this
			// CPT have no Universal ACF Field block anywhere inside this
			// form yet. Best-effort only — it can't see conditional logic
			// either, same documented limitation as the server-side check.
			var missingWarning = null;
			var fieldsForType = ( window.uacfBlockData && window.uacfBlockData.fields && window.uacfBlockData.fields[ attributes.postType ] ) || [];
			var missingRequired = fieldsForType.filter( function ( f ) {
				return f.required && ! presentFieldKeys[ f.key ];
			} );
			if ( attributes.postType && missingRequired.length && Notice ) {
				var missingNames = missingRequired.map( function ( f ) { return f.label; } ).join( ', ' );
				missingWarning = el(
					Notice,
					{ status: 'warning', isDismissible: false, className: 'uacf-missing-required-notice' },
					__( 'This form is missing required field(s):', 'uacf' ) + ' ' + missingNames
				);
			}

			var body;
			if ( useInnerBlocksProps ) {
				var innerBlocksProps = useInnerBlocksProps(
					{ className: 'uacf-form-editor-body' },
					{ allowedBlocks: ALLOWED_BLOCKS, renderAppender: InnerBlocks.ButtonBlockAppender }
				);
				body = el( 'div', innerBlocksProps );
			} else {
				body = el(
					'div',
					{ className: 'uacf-form-editor-body' },
					el( InnerBlocks, { allowedBlocks: ALLOWED_BLOCKS, renderAppender: InnerBlocks.ButtonBlockAppender } )
				);
			}

			return el( 'div', blockProps, inspector, header, missingWarning, body );
		},
		save: function () {
			return el( InnerBlocks.Content );
		}
	} );

	// ---------------------------------------------------------------
	// uacf/acf-field — one ACF field. No real ACF input is ever
	// rendered in the editor, only a static card, so ACF's front-end
	// validation JS can never run against the editor.
	// ---------------------------------------------------------------
	registerBlockType( 'uacf/acf-field', {
		title: __( 'Universal ACF Field', 'uacf' ),
		description: __( 'Renders exactly one ACF field from the parent form’s content type.', 'uacf' ),
		icon: 'forms',
		category: 'widgets',
		attributes: {
			fieldKey: { type: 'string', default: '' }
		},
		usesContext: [ 'uacf/postType' ],
		supports: UACF_BLOCK_SUPPORTS,
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var context = props.context || {};
			var blockProps = blockPropsOf();
			var postType = context[ 'uacf/postType' ] || '';

			if ( ! postType ) {
				return el( 'div', blockProps, el( Placeholder, {
					icon: 'forms',
					label: __( 'Universal ACF Field', 'uacf' ),
					instructions: __( 'Place this block inside a Universal ACF Form block.', 'uacf' )
				} ) );
			}

			var fieldChoices = [ { value: '', label: __( 'Select a field…', 'uacf' ) } ];
			var fieldsForType = ( window.uacfBlockData && window.uacfBlockData.fields && window.uacfBlockData.fields[ postType ] ) || [];
			var selected = null;
			fieldsForType.forEach( function ( f ) {
				fieldChoices.push( { value: f.key, label: f.label + ' (' + f.name + ')' } );
				if ( f.key === attributes.fieldKey ) {
					selected = f;
				}
			} );

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Universal ACF Field settings', 'uacf' ) },
					el( SelectControl, {
						label: __( 'Field', 'uacf' ),
						value: attributes.fieldKey,
						options: fieldChoices,
						onChange: function ( value ) {
							setAttributes( { fieldKey: value } );
						}
					} )
				)
			);

			var meta = selected
				? ( selected.label + ' · ' + selected.type + ( selected.required ? ' · ' + __( 'Required', 'uacf' ) : '' ) )
				: __( 'No field selected', 'uacf' );

			var preview = el( Placeholder, {
				icon: 'forms',
				label: __( 'ACF Field', 'uacf' ),
				instructions: meta
			} );

			return el( 'div', blockProps, inspector, preview );
		},
		save: function () {
			return null;
		}
	} );

	// ---------------------------------------------------------------
	// uacf/taxonomy-field — one taxonomy's term picker.
	// ---------------------------------------------------------------
	registerBlockType( 'uacf/taxonomy-field', {
		title: __( 'Universal Taxonomy Field', 'uacf' ),
		description: __( 'Renders exactly one taxonomy’s term picker for the parent form’s content type.', 'uacf' ),
		icon: 'category',
		category: 'widgets',
		attributes: {
			taxonomy: { type: 'string', default: '' },
			selectionMode: { type: 'string', default: 'single' },
			displayStyle: { type: 'string', default: 'dropdown' }
		},
		usesContext: [ 'uacf/postType' ],
		supports: UACF_BLOCK_SUPPORTS,
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var context = props.context || {};
			var blockProps = blockPropsOf();
			var postType = context[ 'uacf/postType' ] || '';

			if ( ! postType ) {
				return el( 'div', blockProps, el( Placeholder, {
					icon: 'category',
					label: __( 'Universal Taxonomy Field', 'uacf' ),
					instructions: __( 'Place this block inside a Universal ACF Form block.', 'uacf' )
				} ) );
			}

			var taxChoices = [ { value: '', label: __( 'Select a taxonomy…', 'uacf' ) } ];
			var taxForType = ( window.uacfBlockData && window.uacfBlockData.taxonomies && window.uacfBlockData.taxonomies[ postType ] ) || [];
			var selectedLabel = '';
			taxForType.forEach( function ( t ) {
				taxChoices.push( { value: t.value, label: t.label } );
				if ( t.value === attributes.taxonomy ) {
					selectedLabel = t.label;
				}
			} );

			var selectionModeOptions = [
				{ value: 'single', label: __( 'Single', 'uacf' ) },
				{ value: 'multiple', label: __( 'Multiple', 'uacf' ) }
			];
			var displayStyleOptions = ( 'multiple' === attributes.selectionMode )
				? [ { value: 'dropdown', label: __( 'Dropdown', 'uacf' ) }, { value: 'checkboxes', label: __( 'Checkboxes', 'uacf' ) } ]
				: [ { value: 'dropdown', label: __( 'Dropdown', 'uacf' ) }, { value: 'radio', label: __( 'Radio Buttons', 'uacf' ) } ];

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Universal Taxonomy Field settings', 'uacf' ) },
					el( SelectControl, {
						label: __( 'Taxonomy', 'uacf' ),
						value: attributes.taxonomy,
						options: taxChoices,
						onChange: function ( value ) {
							setAttributes( { taxonomy: value } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Selection Mode', 'uacf' ),
						value: attributes.selectionMode,
						options: selectionModeOptions,
						onChange: function ( value ) {
							var nextStyle = attributes.displayStyle;
							if ( 'single' === value && 'checkboxes' === nextStyle ) {
								nextStyle = 'dropdown';
							}
							if ( 'multiple' === value && 'radio' === nextStyle ) {
								nextStyle = 'dropdown';
							}
							setAttributes( { selectionMode: value, displayStyle: nextStyle } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Display Style', 'uacf' ),
						value: attributes.displayStyle,
						options: displayStyleOptions,
						onChange: function ( value ) {
							setAttributes( { displayStyle: value } );
						}
					} )
				)
			);

			var meta = selectedLabel
				? ( selectedLabel + ' · ' + attributes.selectionMode + ' · ' + attributes.displayStyle )
				: __( 'No taxonomy selected', 'uacf' );

			var preview = el( Placeholder, {
				icon: 'category',
				label: __( 'Taxonomy Field', 'uacf' ),
				instructions: meta
			} );

			return el( 'div', blockProps, inspector, preview );
		},
		save: function () {
			return null;
		}
	} );

	// ---------------------------------------------------------------
	// uacf/form-message
	// ---------------------------------------------------------------
	registerBlockType( 'uacf/form-message', {
		title: __( 'Universal Form Message', 'uacf' ),
		description: __( 'Placeholder where form validation errors and save confirmations appear.', 'uacf' ),
		icon: 'megaphone',
		category: 'widgets',
		supports: UACF_BLOCK_SUPPORTS,
		edit: function () {
			var blockProps = blockPropsOf();
			return el( 'div', blockProps, el( Placeholder, {
				icon: 'megaphone',
				label: __( 'Universal Form Message', 'uacf' ),
				instructions: __( 'Validation errors and save confirmations will appear here on the front end.', 'uacf' )
			} ) );
		},
		save: function () {
			return null;
		}
	} );

	// ---------------------------------------------------------------
	// uacf/submit-button
	// ---------------------------------------------------------------
	registerBlockType( 'uacf/submit-button', {
		title: __( 'Universal Submit Button', 'uacf' ),
		description: __( 'The form’s real <button type="submit">.', 'uacf' ),
		icon: 'button',
		category: 'widgets',
		attributes: {
			createLabel: { type: 'string', default: '' },
			updateLabel: { type: 'string', default: '' },
			align: { type: 'string', default: '' },
			fullWidth: { type: 'boolean', default: false }
		},
		usesContext: [ 'uacf/postType' ],
		supports: UACF_BLOCK_SUPPORTS,
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockPropsOf();

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Universal Submit Button settings', 'uacf' ) },
					el( TextControl, {
						label: __( 'Create label', 'uacf' ),
						value: attributes.createLabel,
						placeholder: __( 'Create', 'uacf' ),
						onChange: function ( value ) {
							setAttributes( { createLabel: value } );
						}
					} ),
					el( TextControl, {
						label: __( 'Update label', 'uacf' ),
						value: attributes.updateLabel,
						placeholder: __( 'Update', 'uacf' ),
						onChange: function ( value ) {
							setAttributes( { updateLabel: value } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Alignment', 'uacf' ),
						value: attributes.align,
						options: [
							{ value: '', label: __( 'None', 'uacf' ) },
							{ value: 'left', label: __( 'Left', 'uacf' ) },
							{ value: 'center', label: __( 'Center', 'uacf' ) },
							{ value: 'right', label: __( 'Right', 'uacf' ) }
						],
						onChange: function ( value ) {
							setAttributes( { align: value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Full width', 'uacf' ),
						checked: !! attributes.fullWidth,
						onChange: function ( value ) {
							setAttributes( { fullWidth: value } );
						}
					} )
				)
			);

			var label = attributes.createLabel || __( 'Create', 'uacf' );
			var previewClass = 'uacf-submit-button-preview' + ( attributes.fullWidth ? ' uacf-submit-button-full' : '' ) + ( attributes.align ? ' align' + attributes.align : '' );

			return el( 'div', blockProps, inspector, el( 'button', { type: 'button', className: previewClass, disabled: true }, label ) );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
JS;
		}

		// =====================================================================
		// FRONT-END ASSETS — minimal CSS plus the accessible, dependency-free
		// "Multiple + Dropdown" taxonomy widget script.
		// =====================================================================

		public static function enqueue_frontend_assets() {
			$style_handle = 'uacf-frontend-style';
			wp_register_style( $style_handle, false, array(), UACF_VERSION );
			wp_add_inline_style( $style_handle, self::get_frontend_css() );
			wp_enqueue_style( $style_handle );

			$script_handle = 'uacf-frontend-taxonomy';
			wp_register_script( $script_handle, false, array(), UACF_VERSION, true );
			wp_add_inline_script( $script_handle, self::get_frontend_taxonomy_js() );
			wp_enqueue_script( $script_handle );
		}

		private static function get_frontend_taxonomy_js() {
			return <<<'JS'
( function () {
	function closeDropdown( wrap ) {
		var toggle = wrap.querySelector( '.uacf-term-dropdown-toggle' );
		var panel = wrap.querySelector( '.uacf-term-dropdown-panel' );
		wrap.classList.remove( 'is-open' );
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
		if ( panel ) { panel.setAttribute( 'hidden', 'hidden' ); }
	}

	function openDropdown( wrap ) {
		document.querySelectorAll( '.uacf-term-dropdown.is-open' ).forEach( function ( other ) {
			if ( other !== wrap ) { closeDropdown( other ); }
		} );
		var toggle = wrap.querySelector( '.uacf-term-dropdown-toggle' );
		var panel = wrap.querySelector( '.uacf-term-dropdown-panel' );
		wrap.classList.add( 'is-open' );
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'true' ); }
		if ( panel ) { panel.removeAttribute( 'hidden' ); }
	}

	function updateLabel( wrap ) {
		var labelEl = wrap.querySelector( '.uacf-term-dropdown-label' );
		if ( ! labelEl ) { return; }
		var checked = wrap.querySelectorAll( '.uacf-term-dropdown-panel input[type="checkbox"]:checked' );
		var emptyLabel = wrap.getAttribute( 'data-empty-label' ) || '';
		var multiTemplate = wrap.getAttribute( 'data-multi-template' ) || '%d';

		if ( 0 === checked.length ) {
			labelEl.textContent = emptyLabel;
		} else if ( 1 === checked.length ) {
			var parentLabel = checked[ 0 ].closest( 'label' );
			labelEl.textContent = parentLabel ? parentLabel.textContent.trim() : emptyLabel;
		} else {
			labelEl.textContent = multiTemplate.replace( '%d', String( checked.length ) );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wraps = document.querySelectorAll( '.uacf-term-dropdown' );

		// Progressive enhancement: the panel is a plain, always-visible
		// checkbox list in the raw HTML (fully usable without JS). Only
		// once JS has confirmed it can run do we collapse it into a
		// closed, accessible dropdown.
		wraps.forEach( function ( wrap ) {
			var toggle = wrap.querySelector( '.uacf-term-dropdown-toggle' );
			var panel = wrap.querySelector( '.uacf-term-dropdown-panel' );
			if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
			if ( panel ) { panel.setAttribute( 'hidden', 'hidden' ); }

			if ( toggle ) {
				toggle.addEventListener( 'click', function () {
					if ( wrap.classList.contains( 'is-open' ) ) {
						closeDropdown( wrap );
					} else {
						openDropdown( wrap );
					}
				} );
			}

			wrap.querySelectorAll( '.uacf-term-dropdown-panel input[type="checkbox"]' ).forEach( function ( checkbox ) {
				checkbox.addEventListener( 'change', function () { updateLabel( wrap ); } );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var openWrap = document.querySelector( '.uacf-term-dropdown.is-open' );
			if ( openWrap && ! openWrap.contains( event.target ) ) {
				closeDropdown( openWrap );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key ) { return; }
			var openWrap = document.querySelector( '.uacf-term-dropdown.is-open' );
			if ( openWrap ) {
				var toggle = openWrap.querySelector( '.uacf-term-dropdown-toggle' );
				closeDropdown( openWrap );
				if ( toggle ) { toggle.focus(); }
			}
		} );
	} );
} )();
JS;
		}

		/**
		 * FUNCTIONAL CSS ONLY — deliberately not a place to "design" this
		 * form. Every rule here exists because the widget genuinely
		 * misbehaves without it (the taxonomy dropdown must be an
		 * absolutely-positioned overlay with a scrollable max-height, radio/
		 * checkbox lists must stack instead of running inline, etc.). There
		 * is no color, font, border, shadow, or width choice in this
		 * function — those are exactly the kind of edits someone could make
		 * here by hand and, one stray quote later, break this entire PHP
		 * snippet for the whole site.
		 *
		 * Anyone who wants to change how the form LOOKS should do it
		 * without ever opening this file:
		 *   - Select the block (form, field, taxonomy field, message,
		 *     button) in Gutenberg and use its own "Styles" panel — Color
		 *     (text/background) and Spacing (margin/padding) are already
		 *     wired to every one of the 5 blocks, no code required.
		 *   - Give a block an "Additional CSS class(es)" name (Advanced
		 *     panel) and write the actual visual rule for that class in
		 *     Appearance → Customize → Additional CSS, or in the active
		 *     theme's own stylesheet — never in this file. A mistake there
		 *     can, at worst, break how the page looks; it can never break
		 *     the PHP running this system.
		 *
		 * A developer who genuinely needs to extend this functional layer
		 * (e.g. a different dropdown max-height) can do so from outside
		 * this file too, via the 'uacf_frontend_css' filter, instead of
		 * editing this function.
		 */
		private static function get_frontend_css() {
			$css = <<<'CSS'
.uacf-tax-radio, .uacf-tax-checkboxes { border: 0; padding: 0; margin: 0; }
.uacf-term-radio, .uacf-term-checkbox { display: block; }
.uacf-term-dropdown { position: relative; }
.uacf-term-dropdown-toggle { width: 100%; text-align: left; }
.uacf-term-dropdown-panel { margin-top: 4px; }
.uacf-term-dropdown.is-open .uacf-term-dropdown-panel { position: absolute; z-index: 10; left: 0; right: 0; max-height: 240px; overflow-y: auto; }
.uacf-submit-button-full { display: block; width: 100%; }
CSS;

			return apply_filters( 'uacf_frontend_css', $css );
		}

	}

} // class_exists

if ( ! has_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ) ) ) {
	add_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ), 20 );
}
