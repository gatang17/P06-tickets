<?php
/**
 * Universal ACF Form — universal dynamic block for creating/editing records
 * of any Custom Post Type using Advanced Custom Fields (ACF Free).
 *
 * IMPORTANT — HOW TO INSTALL WITH THE "CODE SNIPPETS" PLUGIN:
 *   1. Copy the ENTIRE contents of this file.
 *   2. In Code Snippets → Add New, paste the code BUT REMOVE the first
 *      line "<?php" (Code Snippets already treats the editor as PHP, so
 *      the opening tag must not be included).
 *   3. Save the snippet with the "Run snippet everywhere" setting (site-wide).
 *   4. Activate it.
 *
 * If instead you're going to use this file as an mu-plugin or a regular
 * plugin, leave it as-is (with the "<?php" tag included).
 *
 * Does not depend on ACF Pro, Composer, Node.js, npm, or any build step.
 * All of the block editor JavaScript is generated and injected from this
 * same file via wp_add_inline_script().
 *
 * Requirements: WordPress with Gutenberg, ACF Free active, PHP 8.1+.
 */

// =============================================================================
// SECTION 1 — CHECKS AND CONSTANTS
// =============================================================================

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Security exit: do not allow direct access to the file.
}

if ( ! defined( 'UACF_VERSION' ) ) {
	define( 'UACF_VERSION', '1.2.0' );
}

if ( ! defined( 'UACF_NONCE_PREFIX' ) ) {
	define( 'UACF_NONCE_PREFIX', 'uacf_form_' );
}

// =============================================================================
// SECTION 6 — AUTOMATIC PREFIX (public function, kept outside the class
// because the requirement explicitly asks for this exact signature:
// inventory_get_prefix($post_type)).
// =============================================================================

if ( ! function_exists( 'inventory_get_prefix' ) ) {
	/**
	 * Generates a deterministic 3-character prefix from a post type key.
	 * Uses no manual lookup table: it is calculated purely from the post
	 * type key's own text, normalizing hyphens, underscores, and spaces.
	 *
	 * @param string $post_type Post type key (e.g. "stock_record").
	 * @return string Uppercase 3-character prefix (e.g. "SRE").
	 */
	function inventory_get_prefix( $post_type ) {
		$post_type = (string) $post_type;

		// Normalize separators: hyphen, underscore and space -> single space.
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
			// Single word: first 3 letters.
			$prefix = substr( $words[0], 0, 3 );
		} elseif ( 2 === $count ) {
			// Two words: first letter of the 1st + first 2 letters of the 2nd.
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 2 );
		} elseif ( $count >= 3 ) {
			// Three or more words: first letter of the first 3 words.
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 1 ) . substr( $words[2], 0, 1 );
		}

		$prefix = strtoupper( $prefix );

		// Deterministic padding if the result is shorter than 3 characters
		// (e.g. an extremely short or empty post type key).
		if ( strlen( $prefix ) < 3 ) {
			$source = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $post_type ) );
			$i      = 0;
			while ( strlen( $prefix ) < 3 ) {
				if ( $i < strlen( $source ) ) {
					$prefix .= $source[ $i ];
				} else {
					$prefix .= 'X'; // Fixed, deterministic padding once the source is exhausted.
				}
				$i++;
			}
		}

		return substr( $prefix, 0, 3 );
	}
}

// =============================================================================
// MAIN CLASS — encapsulates the whole system to avoid name collisions with
// other plugins/snippets. Declared exactly once thanks to the
// class_exists() guard.
// =============================================================================

if ( ! class_exists( 'UACF_Universal_Form' ) ) {

	final class UACF_Universal_Form {

		// -------------------------------------------------------------------
		// In-memory cache (lasts only for the current request, never
		// persistent transients, so new ACF fields appear instantly).
		// -------------------------------------------------------------------
		private static $post_types_cache = null;
		private static $groups_cache     = array();
		private static $fields_cache     = array();
		private static $taxonomies_cache = array();

		// =====================================================================
		// SECTION 7/8/9 — bootstrap and hook registration
		// =====================================================================

		public static function init() {
			add_action( 'wp', array( __CLASS__, 'prime_form_head' ), 5 );

			add_filter( 'acf/load_field', array( __CLASS__, 'adjust_code_field' ) );
			add_action( 'acf/validate_save_post', array( __CLASS__, 'gate_save_post' ), 5 );
			add_action( 'acf/save_post', array( __CLASS__, 'finalize_save_post' ), 20 );

			add_shortcode( 'universal_acf_form', array( __CLASS__, 'shortcode_callback' ) );

			add_action( 'init', array( __CLASS__, 'register_block' ) );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
		}

		// =====================================================================
		// SECTION 2 — AUTOMATIC CPT DISCOVERY
		// =====================================================================

		/**
		 * Returns every public post type with an admin UI, excluding the
		 * internal WordPress/ACF/Gutenberg ones. No manual list of the
		 * site's own CPTs.
		 *
		 * @return array<string,WP_Post_Type>
		 */
		public static function get_available_post_types() {
			if ( null !== self::$post_types_cache ) {
				return self::$post_types_cache;
			}

			$excluded = array(
				'attachment',
				'revision',
				'nav_menu_item',
				'acf-field',
				'acf-field-group',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				// Other generic WordPress-internal post types (these are not
				// application entities, they are WP core):
				'wp_global_styles',
				'wp_font_family',
				'wp_font_face',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
			);

			/**
			 * Lets the exclusion list be adjusted without touching this snippet.
			 */
			$excluded = apply_filters( 'uacf_excluded_post_types', $excluded );

			$objects = get_post_types( array(
				'public'   => true,
				'show_ui'  => true,
			), 'objects' );

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
		// SECTION 3 — AUTOMATIC DISCOVERY OF ACF GROUPS AND FIELDS
		// =====================================================================

		/**
		 * Active ACF field groups whose Location Rules target this post type.
		 *
		 * @param string $post_type
		 * @return array List of ACF groups (arrays), as returned by ACF.
		 */
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
		 * All top-level fields from the active groups of a CPT, regardless
		 * of the order/position in which they sit inside the group.
		 *
		 * @param string $post_type
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

		// =====================================================================
		// SECTION 4 — PREFIXES AND CODES
		// =====================================================================

		/**
		 * Looks, among a CPT's top-level fields, for the first field of type
		 * "text" whose Field Name ends exactly in "_code".
		 *
		 * @param array $fields
		 * @return array|null
		 */
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

		/**
		 * Generates a unique code PREFIX-001, PREFIX-002... for a post type,
		 * using an atomic counter in wp_options (UPDATE ... = value + 1),
		 * which avoids duplicates even with double submissions or races
		 * between concurrent requests. It also checks against existing
		 * records (deleted records don't affect the numbering either,
		 * because the counter never goes backwards).
		 *
		 * @param string $post_type
		 * @param string $field_name Name of the "_code" field (used to check for duplicates).
		 * @return string
		 */
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
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
						$option_name
					)
				);

				$sequence = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
						$option_name
					)
				);

				if ( $sequence < 1 ) {
					$sequence = 1;
				}

				$code = $prefix . '-' . str_pad( (string) $sequence, 3, '0', STR_PAD_LEFT );
				$attempts++;
			} while ( $attempts < 20 && self::code_exists( $post_type, $field_name, $code ) );

			return $code;
		}

		/**
		 * Checks whether a record of that CPT with that exact code already exists.
		 */
		private static function code_exists( $post_type, $field_name, $code ) {
			$existing = get_posts( array(
				'post_type'               => $post_type,
				'post_status'             => 'any',
				'posts_per_page'          => 1,
				'fields'                  => 'ids',
				'meta_key'                => $field_name,
				'meta_value'              => $code,
				'no_found_rows'           => true,
				'update_post_meta_cache'  => false,
				'update_post_term_cache'  => false,
			) );

			return ! empty( $existing );
		}

		/**
		 * acf/load_field filter: makes readonly and non-required, only on
		 * the front-end (never in wp-admin), any text field whose name ends
		 * in "_code". The actual value is computed in finalize_save_post().
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
		// SECTION 5 — DETECTION OF THE FIELD THAT WILL FORM THE TITLE
		// =====================================================================

		/**
		 * 1) First required text field whose name does not end in "_code".
		 * 2) If none exists, the first text field (not "_code").
		 * 3) If none exists at all, returns null (the caller decides the fallback).
		 */
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

		/**
		 * Computes the final post_title following the generic strategy
		 * described in requirement point 8.
		 */
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

			if ( '' !== $code ) {
				return $singular . ' ' . $code;
			}

			return $singular . ' #' . $post_id;
		}

		// =====================================================================
		// SECTION 6 — TAXONOMIES
		// =====================================================================

		/**
		 * Public taxonomies associated with a CPT (excluding internal ones)
		 * that, in addition, the current user is allowed to assign
		 * (current_user_can($tax_object->cap->assign_terms)). By filtering
		 * here, both rendering (build_after_fields_html) and saving
		 * (save_taxonomies) automatically respect this check: a taxonomy
		 * the user isn't allowed to assign is never shown nor saved.
		 *
		 * @return array<string,WP_Taxonomy>
		 */
		public static function get_taxonomies_for_post_type( $post_type ) {
			if ( isset( self::$taxonomies_cache[ $post_type ] ) ) {
				return self::$taxonomies_cache[ $post_type ];
			}

			$excluded = apply_filters( 'uacf_excluded_taxonomies', array(
				'post_format',
				'nav_menu',
				'link_category',
				'wp_theme',
				'wp_template_part_area',
				'wp_pattern_category',
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
		 * Converts a flat list of terms (get_terms) into a tree ordered by
		 * hierarchy, with the depth of each term.
		 */
		private static function build_term_tree( array $terms, $parent = 0, $depth = 0 ) {
			$branch = array();
			foreach ( $terms as $term ) {
				if ( (int) $term->parent === (int) $parent ) {
					$branch[] = array(
						'term'  => $term,
						'depth' => $depth,
					);
					$branch = array_merge( $branch, self::build_term_tree( $terms, $term->term_id, $depth + 1 ) );
				}
			}
			return $branch;
		}

		/**
		 * Saves the selected taxonomies using wp_set_object_terms(). Only
		 * allows selecting EXISTING terms (never creates new ones): every
		 * value is validated with term_exists() before saving it. Only
		 * iterates taxonomies returned by get_taxonomies_for_post_type(),
		 * which already excludes those the current user lacks
		 * cap->assign_terms for, so wp_set_object_terms() is never called
		 * without that capability having been verified.
		 */
		private static function save_taxonomies( $post_type, $post_id ) {
			foreach ( self::get_taxonomies_for_post_type( $post_type ) as $tax_name => $tax_object ) {
				$field_key = 'uacf_tax_' . $tax_name;

				if ( ! isset( $_POST[ $field_key ] ) ) {
					continue; // The control wasn't even shown (0 terms available).
				}

				$raw = wp_unslash( $_POST[ $field_key ] );
				$raw = is_array( $raw ) ? $raw : array( $raw );

				$term_ids = array();
				foreach ( $raw as $value ) {
					$id = absint( $value );
					if ( $id > 0 && term_exists( $id, $tax_name ) ) {
						$term_ids[] = $id;
					}
				}

				wp_set_object_terms( $post_id, $term_ids, $tax_name, false );
			}
		}

		// =====================================================================
		// SECTION 7 — SECURITY AND PERMISSIONS
		// =====================================================================

		private static function nonce_action( $post_type, $mode ) {
			return UACF_NONCE_PREFIX . $post_type . '_' . $mode;
		}

		/**
		 * Safely validates an edit_id received via GET: checks that the
		 * post exists, that it belongs exactly to the block's CPT, and that
		 * the current user can edit it (native CPT capabilities via
		 * current_user_can('edit_post', $id), not by role name).
		 *
		 * @return WP_Post|null
		 */
		private static function validate_edit_id( $edit_id, $post_type ) {
			if ( $edit_id <= 0 ) {
				return null;
			}
			$post = get_post( $edit_id );
			if ( ! $post ) {
				return null;
			}
			if ( $post->post_type !== $post_type ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $edit_id ) ) {
				return null;
			}
			return $post;
		}

		/**
		 * Resolves the post_status a new record will be created with,
		 * ALWAYS checking the CPT's native publish capability
		 * (cap->publish_posts). An external filter (uacf_new_post_status)
		 * may request 'publish', but if the current user doesn't have that
		 * capability, the record is forcibly downgraded to 'draft': no
		 * filter can bypass this check.
		 *
		 * @param WP_Post_Type $post_type_object
		 * @param mixed        $requested_status
		 * @return string
		 */
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
		 * acf/validate_save_post hook (before any field is saved). Verifies
		 * the logged-in user, our own nonce, the CPT whitelist, and the
		 * CPT's native capabilities. If anything fails, it adds an ACF
		 * validation error, which fully prevents the post from being
		 * created or updated (ACF aborts the save if there are errors).
		 */
		public static function gate_save_post() {
			if ( is_admin() ) {
				return; // Never interfere with normal saving from wp-admin.
			}
			if ( empty( $_POST['uacf_submit'] ) ) {
				return; // Not a submission from this system.
			}

			$post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			$mode      = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : '';
			$nonce     = isset( $_POST['uacf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uacf_nonce'] ) ) : '';

			$error = '';

			if ( ! is_user_logged_in() ) {
				$error = __( 'You must be logged in to submit this form.', 'uacf' );
			} else {
				$available = self::get_available_post_types();

				if ( '' === $post_type || ! isset( $available[ $post_type ] ) ) {
					$error = __( 'Invalid content type.', 'uacf' );
				} elseif ( ! in_array( $mode, array( 'create', 'edit' ), true ) ) {
					$error = __( 'Invalid form request.', 'uacf' );
				} elseif ( ! wp_verify_nonce( $nonce, self::nonce_action( $post_type, $mode ) ) ) {
					$error = __( 'The form session has expired. Reload the page and try again.', 'uacf' );
				} else {
					$post_type_object = $available[ $post_type ];

					if ( 'create' === $mode ) {
						if ( empty( $post_type_object->cap->create_posts ) || ! current_user_can( $post_type_object->cap->create_posts ) ) {
							$error = __( 'You do not have permission to create this content type.', 'uacf' );
						}
					} else {
						$edit_id  = isset( $_POST['uacf_edit_id'] ) ? absint( wp_unslash( $_POST['uacf_edit_id'] ) ) : 0;
						$existing = $edit_id ? get_post( $edit_id ) : null;

						if ( ! $existing || $existing->post_type !== $post_type ) {
							$error = __( 'The record you are trying to edit does not exist or does not match this form.', 'uacf' );
						} elseif ( ! current_user_can( 'edit_post', $edit_id ) ) {
							$error = __( 'You do not have permission to edit this record.', 'uacf' );
						}
					}
				}
			}

			if ( '' !== $error && function_exists( 'acf_add_validation_error' ) ) {
				acf_add_validation_error( '', $error );
			}
		}

		// =====================================================================
		// SECTION 8 — FORM PROCESSING (after ACF's own save)
		// =====================================================================

		/**
		 * Must run before any HTML output. Called on the "wp" hook (early)
		 * for any logged-in visitor on the front-end, since acf_form_head()
		 * needs to process $_POST (validate/save and redirect) before any
		 * HTTP headers are sent.
		 */
		public static function prime_form_head() {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( ! is_user_logged_in() ) {
				return; // Without a session the form can't be submitted; skip the cost.
			}
			if ( ! function_exists( 'acf_form_head' ) ) {
				return; // ACF is not active: render_form() will show this on screen.
			}

			wp_enqueue_media(); // Needed so the "wp" uploader (images/files) works on the front-end.
			acf_form_head();
		}

		/**
		 * acf/save_post hook (priority 20, after ACF has already saved the
		 * fields at its default priority 10). Here we complete what ACF
		 * doesn't handle natively: code, title, and taxonomies.
		 *
		 * This does not cause recursion: this hook is ACF-specific (only
		 * fired from within acf_save_post()), so calling wp_update_post()
		 * here does NOT re-trigger 'acf/save_post'. A static guard is also
		 * added as an extra safeguard.
		 */
		public static function finalize_save_post( $post_id ) {
			if ( is_admin() ) {
				return;
			}
			if ( empty( $_POST['uacf_submit'] ) ) {
				return;
			}
			if ( ! is_numeric( $post_id ) ) {
				return; // acf/save_post also fires for "options", "user_N", etc.
			}

			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$post_type = $post->post_type;
			$available = self::get_available_post_types();
			if ( ! isset( $available[ $post_type ] ) ) {
				return;
			}

			static $processed = array();
			if ( isset( $processed[ $post_id ] ) ) {
				return;
			}
			$processed[ $post_id ] = true;

			$claimed_post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			if ( $claimed_post_type !== $post_type ) {
				return; // gate_save_post() should already have blocked this; extra safeguard.
			}

			$mode = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : 'create';

			$fields     = self::get_fields_for_post_type( $post_type );
			$code_field = self::find_code_field( $fields );
			$code       = '';

			if ( $code_field ) {
				$existing_code = get_post_meta( $post_id, $code_field['name'], true );

				if ( 'edit' === $mode && '' !== $existing_code ) {
					// Editing: keep the existing code, never generate a new one.
					$code = $existing_code;
				} else {
					$code = self::generate_unique_code( $post_type, $code_field['name'] );

					if ( function_exists( 'update_field' ) && ! empty( $code_field['key'] ) ) {
						// update_field() must receive ACF's Field Key (not the
						// Field Name) to resolve the field unambiguously.
						update_field( $code_field['key'], $code, $post_id );
					}
					// Also keep the "plain" post meta under the Field Name,
					// which is the key get_post_meta() is queried with.
					update_post_meta( $post_id, $code_field['name'], $code );
				}
			}

			$title = self::determine_title( $post_type, $post_id, $fields, $code );
			if ( '' !== $title && $title !== $post->post_title ) {
				wp_update_post( array(
					'ID'         => $post_id,
					'post_title' => $title,
				) );
			}

			self::save_taxonomies( $post_type, $post_id );

			// The redirect to "?edit_id=<new ID>" after creating is NOT done
			// here with wp_redirect()/exit: that would abruptly cut off any
			// 'acf/save_post' callback registered after this one (priority >
			// 20), whether from ACF itself or from other plugins. Instead,
			// render_form() already builds acf_form()'s 'return' argument
			// with the official %post_id% placeholder, which ACF substitutes
			// with the real ID once the ENTIRE save process (including this
			// hook) has finished, and it is ACF that performs the final
			// redirect on its own.
		}

		// =====================================================================
		// SECTION 9 — RENDERING (shared by the block and the shortcode)
		// =====================================================================

		private static function notice( $type, $message ) {
			$type = in_array( $type, array( 'info', 'success', 'error' ), true ) ? $type : 'info';
			return sprintf(
				'<div class="uacf-notice uacf-notice-%s">%s</div>',
				esc_attr( $type ),
				wp_kses_post( $message )
			);
		}

		/**
		 * Returns the "clean" URL of the current page/entry (without this
		 * system's parameters), used as the base for the post-save redirect.
		 */
		private static function get_current_clean_url() {
			$queried_id = get_queried_object_id();
			$permalink  = $queried_id ? get_permalink( $queried_id ) : false;

			if ( ! $permalink ) {
				global $wp;
				$permalink = ! empty( $wp->request ) ? home_url( user_trailingslashit( $wp->request ) ) : home_url( '/' );
			}

			return $permalink;
		}

		/**
		 * Builds the URL for acf_form()'s 'return' argument.
		 *
		 * - "edit" mode: keeps the real, already-known edit_id.
		 * - "create" mode: uses acf_form()'s OFFICIAL placeholder, the
		 *   literal string "%post_id%", which ACF substitutes with the
		 *   newly created ID after the entire save process has completed
		 *   (including this system's acf/save_post hooks and any other
		 *   plugin's). The placeholder is appended outside of
		 *   add_query_arg() and is never passed through absint()/sanitize_*()
		 *   or any other sanitization that could alter or strip the "%"
		 *   characters, precisely so ACF can find it and replace it as-is.
		 */
		private static function build_return_url( $mode, $edit_id ) {
			$base = self::get_current_clean_url();
			$args = array( 'uacf_status' => 'success' );

			if ( 'edit' === $mode && $edit_id > 0 ) {
				$args['edit_id'] = $edit_id;
			}

			$url = add_query_arg( $args, $base );

			if ( 'create' === $mode ) {
				// add_query_arg() would urlencode "%post_id%" if we passed it
				// inside $args, breaking ACF's substitution. That's why it's
				// concatenated separately, always as literal text.
				$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
				$url      .= $separator . 'edit_id=%post_id%';
			}

			return esc_url_raw( $url );
		}

		private static function build_before_fields_html( $post_type, $mode, $edit_id ) {
			ob_start();
			echo '<div class="uacf-hidden-fields">';
			wp_nonce_field( self::nonce_action( $post_type, $mode ), 'uacf_nonce', true, true );
			echo '<input type="hidden" name="uacf_post_type" value="' . esc_attr( $post_type ) . '" />';
			echo '<input type="hidden" name="uacf_mode" value="' . esc_attr( $mode ) . '" />';
			echo '<input type="hidden" name="uacf_submit" value="1" />';
			if ( 'edit' === $mode ) {
				echo '<input type="hidden" name="uacf_edit_id" value="' . esc_attr( (string) $edit_id ) . '" />';
			}
			echo '</div>';
			return ob_get_clean();
		}

		private static function build_after_fields_html( $post_type, $editing_post ) {
			$taxonomies = self::get_taxonomies_for_post_type( $post_type );
			if ( empty( $taxonomies ) ) {
				return '';
			}

			$post_id_for_terms = $editing_post ? $editing_post->ID : 0;

			ob_start();
			echo '<div class="uacf-taxonomies">';

			foreach ( $taxonomies as $tax_name => $tax_object ) {
				$terms = get_terms( array(
					'taxonomy'   => $tax_name,
					'hide_empty' => false,
				) );

				if ( is_wp_error( $terms ) ) {
					continue;
				}

				$label = ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : $tax_name;

				echo '<fieldset class="uacf-taxonomy">';
				echo '<legend>' . esc_html( $label ) . '</legend>';

				if ( empty( $terms ) ) {
					echo '<p class="uacf-no-terms">' . esc_html(
						sprintf(
							/* translators: %s: taxonomy label. */
							__( 'No "%s" terms are available yet.', 'uacf' ),
							$label
						)
					) . '</p>';
					echo '</fieldset>';
					continue;
				}

				$selected = array();
				if ( $post_id_for_terms ) {
					$current = wp_get_object_terms( $post_id_for_terms, $tax_name, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $current ) ) {
						$selected = array_map( 'intval', $current );
					}
				}

				$field_name = 'uacf_tax_' . $tax_name;
				// Hidden "primer" input: lets us detect a total deselection
				// (every checkbox unchecked) instead of "field not submitted".
				echo '<input type="hidden" name="' . esc_attr( $field_name ) . '[]" value="" />';

				if ( ! empty( $tax_object->hierarchical ) ) {
					$tree = self::build_term_tree( $terms, 0, 0 );
					foreach ( $tree as $row ) {
						$term  = $row['term'];
						$depth = $row['depth'];
						printf(
							'<label class="uacf-term-checkbox" style="margin-left:%dpx;"><input type="checkbox" name="%s[]" value="%d" %s /> %s</label>',
							(int) $depth * 16,
							esc_attr( $field_name ),
							(int) $term->term_id,
							checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
							esc_html( $term->name )
						);
					}
				} else {
					$size = (int) min( 8, max( 3, count( $terms ) ) );
					printf( '<select name="%s[]" multiple="multiple" size="%d" class="uacf-term-select">', esc_attr( $field_name ), $size );
					foreach ( $terms as $term ) {
						printf(
							'<option value="%d" %s>%s</option>',
							(int) $term->term_id,
							selected( in_array( (int) $term->term_id, $selected, true ), true, false ),
							esc_html( $term->name )
						);
					}
					echo '</select>';
				}

				echo '</fieldset>';
			}

			echo '</div>';
			return ob_get_clean();
		}

		/**
		 * Single renderer used both by the Gutenberg block and by the
		 * [universal_acf_form] shortcode. Receives only the post type key.
		 *
		 * @param string $post_type
		 * @return string HTML of the form or of an error/status message.
		 */
		public static function render_form( $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( ! function_exists( 'acf_form_head' ) || ! function_exists( 'acf_get_field_groups' ) ) {
				return self::notice( 'error', __( 'Advanced Custom Fields (ACF) is not active. Activate it to use this form.', 'uacf' ) );
			}

			if ( '' === $post_type ) {
				return self::notice( 'info', __( 'This block does not have a content type selected yet. Configure it from the editor sidebar.', 'uacf' ) );
			}

			$available = self::get_available_post_types();
			if ( ! isset( $available[ $post_type ] ) ) {
				return self::notice( 'error', __( 'The selected content type does not exist or is not available.', 'uacf' ) );
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

			$post_type_object = $available[ $post_type ];

			$edit_id      = isset( $_GET['edit_id'] ) ? absint( wp_unslash( $_GET['edit_id'] ) ) : 0;
			$editing_post = $edit_id > 0 ? self::validate_edit_id( $edit_id, $post_type ) : null;

			if ( $edit_id > 0 && ! $editing_post ) {
				return self::notice( 'error', __( 'The record you are trying to edit does not exist, does not belong to this form, or you do not have permission to edit it.', 'uacf' ) );
			}

			$mode = $editing_post ? 'edit' : 'create';

			if ( 'create' === $mode ) {
				$can_create = ! empty( $post_type_object->cap->create_posts ) && current_user_can( $post_type_object->cap->create_posts );
				if ( ! $can_create ) {
					return self::notice( 'error', __( 'You do not have permission to create this content type.', 'uacf' ) );
				}
			} elseif ( ! current_user_can( 'edit_post', $edit_id ) ) {
				return self::notice( 'error', __( 'You do not have permission to edit this record.', 'uacf' ) );
			}

			$groups = self::get_field_groups_for_post_type( $post_type );

			$field_group_keys = array();
			foreach ( $groups as $group ) {
				if ( isset( $group['key'] ) ) {
					$field_group_keys[] = $group['key'];
				}
			}

			$singular = ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post_type;

			ob_start();

			echo '<div class="uacf-form-wrap">';

			if ( isset( $_GET['uacf_status'] ) && 'success' === sanitize_key( wp_unslash( $_GET['uacf_status'] ) ) ) {
				echo self::notice( 'success', __( 'Saved successfully.', 'uacf' ) );
			}

			if ( empty( $groups ) ) {
				echo self::notice( 'info', __( 'This content type does not have any ACF field group configured yet. The form will only handle the title and, if any, the taxonomies.', 'uacf' ) );
			}

			// The filter may suggest a status, but resolve_new_post_status()
			// always checks cap->publish_posts before allowing 'publish' (see
			// security point 1); the filter is never trusted blindly.
			$requested_status = apply_filters( 'uacf_new_post_status', 'publish', $post_type );
			$new_post_status  = self::resolve_new_post_status( $post_type_object, $requested_status );

			$form_args = array(
				'id'                => 'uacf-form-' . $post_type,
				'post_id'           => 'edit' === $mode ? $edit_id : 'new_post',
				'new_post'          => array(
					'post_type'   => $post_type,
					'post_status' => $new_post_status,
					/* translators: %s: CPT singular label. */
					'post_title'  => sprintf( __( '%s (draft)', 'uacf' ), $singular ),
				),
				'field_groups'      => $field_group_keys,
				'post_title'        => false,
				'post_content'      => false,
				'submit_value'      => 'edit' === $mode ? __( 'Update', 'uacf' ) : __( 'Create', 'uacf' ),
				'updated_message'   => false,
				'return'            => self::build_return_url( $mode, $edit_id ),
				'html_before_fields' => self::build_before_fields_html( $post_type, $mode, $edit_id ),
				'html_after_fields'  => self::build_after_fields_html( $post_type, $editing_post ),
				'uploader'          => 'wp',
			);

			/**
			 * Lets acf_form()'s arguments be adjusted from outside without
			 * touching this snippet.
			 */
			$form_args = apply_filters( 'uacf_form_args', $form_args, $post_type, $mode, $edit_id );

			acf_form( $form_args );

			echo '</div>';

			return ob_get_clean();
		}

		// =====================================================================
		// SECTION 10 — SHORTCODE
		// =====================================================================

		public static function shortcode_callback( $atts ) {
			$atts = shortcode_atts( array( 'post_type' => '' ), (array) $atts, 'universal_acf_form' );
			return self::render_form( sanitize_key( $atts['post_type'] ) );
		}

		// =====================================================================
		// SECTION 11 — GUTENBERG BLOCK REGISTRATION + EDITOR JAVASCRIPT
		// =====================================================================

		public static function register_block() {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			register_block_type( 'uacf/universal-acf-form', array(
				'attributes'      => array(
					'postType' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'block_render_callback' ),
			) );
		}

		public static function block_render_callback( $attributes ) {
			$post_type = isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : '';

			// Extra safeguard: never render the real ACF form (render_form() /
			// acf_form()) when this callback is invoked through the REST API
			// (e.g. the block editor's block-renderer endpoint). The editor no
			// longer triggers this at all (ServerSideRender was removed from
			// the editor JS below), but this guard stays in place in case
			// anything else — now or in the future — causes a REST-based
			// render of this block, so an empty/invalid front-end submission
			// can never be produced from inside the editor.
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_preview( $post_type );
			}

			return self::render_form( $post_type );
		}

		/**
		 * Non-interactive preview used as a REST-request safeguard for
		 * block_render_callback(). Never touches ACF: no acf_form_head(),
		 * no acf_form(), no field rendering, so ACF's front-end JS
		 * validation can never fire against it.
		 */
		private static function render_static_preview( $post_type ) {
			$post_type = sanitize_key( $post_type );
			$available = self::get_available_post_types();

			if ( '' === $post_type || ! isset( $available[ $post_type ] ) ) {
				$label = __( 'No content type selected', 'uacf' );
			} else {
				$post_type_object = $available[ $post_type ];
				$label            = ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post_type;
			}

			return sprintf(
				'<div class="uacf-static-preview"><p class="uacf-static-preview-title">%1$s</p><p class="uacf-static-preview-meta">%2$s</p><p class="uacf-static-preview-note">%3$s</p></div>',
				esc_html__( 'Universal ACF Form', 'uacf' ),
				esc_html(
					sprintf(
						/* translators: %s: content type label. */
						__( 'Post Type: %s', 'uacf' ),
						$label
					)
				),
				esc_html__( 'The complete form will appear on the front end.', 'uacf' )
			);
		}

		/**
		 * Enqueues the block's JS ONLY in the Gutenberg editor (never on
		 * the front-end), injected as an inline script with no need for a
		 * separate .js file, compatible with Code Snippets. Does not depend
		 * on wp-server-side-render: the editor never renders the real ACF
		 * form, only a static, non-interactive preview built in JS.
		 */
		public static function enqueue_editor_assets() {
			$handle = 'uacf-block-editor';

			wp_register_script(
				$handle,
				false,
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				UACF_VERSION,
				true
			);

			$choices = array();
			foreach ( self::get_available_post_types() as $key => $object ) {
				$choices[] = array(
					'value' => $key,
					'label' => ! empty( $object->labels->singular_name ) ? $object->labels->singular_name : $key,
				);
			}

			wp_localize_script( $handle, 'uacfBlockData', array(
				'postTypes' => $choices,
			) );

			wp_add_inline_script( $handle, self::get_block_editor_js() );

			wp_enqueue_script( $handle );
		}

		private static function get_block_editor_js() {
			return <<<'JS'
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps : null;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;

	var postTypeChoices = [ { value: '', label: __( 'Select a content type…', 'uacf' ) } ];
	if ( window.uacfBlockData && window.uacfBlockData.postTypes ) {
		window.uacfBlockData.postTypes.forEach( function ( item ) {
			postTypeChoices.push( { value: item.value, label: item.label } );
		} );
	}

	function getSelectedLabel( postType ) {
		var label = '';
		postTypeChoices.forEach( function ( item ) {
			if ( item.value === postType ) {
				label = item.label;
			}
		} );
		return label || postType;
	}

	blocks.registerBlockType( 'uacf/universal-acf-form', {
		title: __( 'Universal ACF Form', 'uacf' ),
		description: __( 'Universal front-end form to create or edit records of any Custom Post Type together with its ACF fields.', 'uacf' ),
		icon: 'feedback',
		category: 'widgets',
		attributes: {
			postType: {
				type: 'string',
				default: ''
			}
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var wrapperProps = useBlockProps ? useBlockProps( { className: 'uacf-block-editor-wrap' } ) : { className: 'uacf-block-editor-wrap' };

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

			// Deliberately NOT ServerSideRender / acf_form(): rendering the
			// real ACF form inside the editor made ACF's own front-end
			// validation JS run against the (empty) preview instance, which
			// blocked publishing the page with "Validation failed". The
			// editor now only ever shows this static, non-interactive
			// summary; the real form is rendered exclusively on the
			// front-end via render_callback / the shortcode.
			var instructions = attributes.postType
				? ( __( 'Post Type:', 'uacf' ) + ' ' + getSelectedLabel( attributes.postType ) + '. ' + __( 'The complete form will appear on the front end.', 'uacf' ) )
				: __( 'Select a content type in the sidebar. The complete form will appear on the front end.', 'uacf' );

			var body = el( Placeholder, {
				icon: 'feedback',
				label: __( 'Universal ACF Form', 'uacf' ),
				instructions: instructions
			} );

			return el( 'div', wrapperProps, inspector, body );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
JS;
		}

		// =====================================================================
		// SECTION 12 — MINIMAL STYLES
		// =====================================================================

		public static function enqueue_frontend_styles() {
			$handle = 'uacf-frontend-style';
			wp_register_style( $handle, false, array(), UACF_VERSION );
			wp_add_inline_style( $handle, self::get_frontend_css() );
			wp_enqueue_style( $handle );
		}

		private static function get_frontend_css() {
			return <<<'CSS'
.uacf-form-wrap { max-width: 720px; margin: 0 auto; }
.uacf-notice { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; border: 1px solid transparent; }
.uacf-notice-info { background: #eef6fc; border-color: #b6d9ee; color: #0c5d8f; }
.uacf-notice-success { background: #eafaf0; border-color: #b7e4c7; color: #1a7f4e; }
.uacf-notice-error { background: #fdecea; border-color: #f5c2c0; color: #a12622; }
.uacf-taxonomies { margin: 20px 0; }
.uacf-taxonomy { border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin-bottom: 16px; }
.uacf-taxonomy legend { font-weight: 600; padding: 0 6px; }
.uacf-term-checkbox { display: block; margin: 4px 0; font-weight: normal; }
.uacf-term-select { min-width: 220px; }
.uacf-no-terms { color: #6b6b6b; font-style: italic; margin: 0; }
input[readonly].acf-is-appended,
.uacf-form-wrap input[readonly] { background: #f6f7f7; color: #6b6b6b; }
.uacf-static-preview { border: 1px dashed #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; color: #50575e; }
.uacf-static-preview-title { font-weight: 600; margin: 0 0 4px; }
.uacf-static-preview-meta { margin: 0 0 4px; }
.uacf-static-preview-note { margin: 0; font-style: italic; }
CSS;
		}
	}

} // class_exists

// =============================================================================
// BOOTSTRAP
// =============================================================================

if ( ! has_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ) ) ) {
	add_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ), 20 );
}
