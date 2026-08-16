<?php
/**
 * Universal Data View — read-only Gutenberg block system for listing
 * records of any Custom Post Type (WordPress + ACF Free), assembled
 * visually with composable blocks.
 *
 * This is a SEPARATE, independent system from "Universal ACF Form". It
 * never creates or edits data, uses entirely different names (class
 * UADV_System, block namespace "uadv/", CSS prefix "uadv-", hooks
 * prefixed "uadv_"), and is meant to be pasted as its OWN Code Snippets
 * entry, alongside (not replacing) any existing snippet.
 *
 * BLOCKS:
 *   uadv/data-view             Parent. Runs one WP_Query, repeats its
 *                               InnerBlocks (Field/Link) once per record.
 *   uadv/data-field             One column/field (native WP data, ACF
 *                               field, taxonomy, or a relationship path).
 *   uadv/data-link               One per-record action link (View/Edit).
 *   uadv/data-empty-message      Shown once, only when 0 records match.
 *   uadv/data-pagination          Shown once, after the record list.
 *
 * IMPORTANT — HOW TO INSTALL WITH THE "CODE SNIPPETS" PLUGIN:
 *   1. Copy the ENTIRE contents of this file.
 *   2. In Code Snippets → Add New, paste it and REMOVE the first line
 *      "<?php" (Code Snippets already treats the editor as PHP).
 *   3. Set "Run snippet everywhere" and activate.
 *
 * No ACF Pro, Composer, Node.js, npm, CDN, or build step required.
 * Requirements: WordPress with Gutenberg, ACF Free active, PHP 8.1+.
 */

// =============================================================================
// SECTION 1 — CHECKS AND CONSTANTS
// =============================================================================

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UADV_VERSION' ) ) {
	define( 'UADV_VERSION', '1.0.0' );
}

if ( ! defined( 'UADV_MAX_RELATIONSHIP_DEPTH' ) ) {
	define( 'UADV_MAX_RELATIONSHIP_DEPTH', 5 );
}

// =============================================================================
// MAIN CLASS
// =============================================================================

if ( ! class_exists( 'UADV_System' ) ) {

	final class UADV_System {

		// In-memory-only caches (never persistent transients, so a new ACF
		// field/group shows up immediately).
		private static $post_types_cache      = array();
		private static $groups_cache          = array();
		private static $fields_cache          = array();
		private static $taxonomies_cache      = array();
		private static $relationship_cache    = array();
		private static $view_instance_counter = 0;

		// =====================================================================
		// BOOTSTRAP
		// =====================================================================

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_blocks' ) );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
		}

		// =====================================================================
		// SECTION 2 — AUTOMATIC CPT DISCOVERY
		// =====================================================================

		/**
		 * @return array<string,WP_Post_Type>
		 */
		public static function get_available_post_types() {
			if ( ! empty( self::$post_types_cache ) ) {
				return self::$post_types_cache;
			}

			$excluded = array(
				'attachment', 'revision', 'nav_menu_item', 'acf-field', 'acf-field-group',
				'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation',
				'wp_global_styles', 'wp_font_family', 'wp_font_face', 'custom_css',
				'customize_changeset', 'oembed_cache', 'user_request',
			);
			$excluded = apply_filters( 'uadv_excluded_post_types', $excluded );

			$objects = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );

			$result = array();
			foreach ( $objects as $key => $object ) {
				if ( in_array( $key, $excluded, true ) ) {
					continue;
				}
				$result[ $key ] = $object;
			}

			self::$post_types_cache = apply_filters( 'uadv_available_post_types', $result );
			return self::$post_types_cache;
		}

		public static function is_valid_post_type( $post_type ) {
			return isset( self::get_available_post_types()[ $post_type ] );
		}

		// =====================================================================
		// SECTION 3 — AUTOMATIC DISCOVERY OF ACF GROUPS AND FIELDS
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
		 * Flat, top-level ACF fields for a CPT's active groups, each
		 * annotated with 'relational' (true for post_object/relationship)
		 * and 'related_post_types' (the field's configured allowed target
		 * post types; an empty array means "any post type").
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
							$field['relational']         = in_array( $field['type'], array( 'post_object', 'relationship' ), true );
							$field['related_post_types']  = array();
							if ( $field['relational'] && ! empty( $field['post_type'] ) ) {
								$field['related_post_types'] = (array) $field['post_type'];
							}
							$fields[] = $field;
						}
					}
				}
			}
			self::$fields_cache[ $post_type ] = $fields;
			return $fields;
		}

		/**
		 * Authoritative whitelist check: does this Field Key genuinely
		 * belong to this CPT's discovered fields? Used everywhere a field
		 * key comes from a block attribute (editor-authored content), to
		 * make it impossible to display — or leak the existence of — a
		 * field belonging to an unrelated CPT via a manipulated attribute.
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
		// TAXONOMY DISCOVERY (read-only context: no assign_terms capability
		// check needed here, unlike a form — only public/browsable ones).
		// =====================================================================

		/**
		 * @return array<string,WP_Taxonomy>
		 */
		public static function get_taxonomies_for_post_type( $post_type ) {
			if ( isset( self::$taxonomies_cache[ $post_type ] ) ) {
				return self::$taxonomies_cache[ $post_type ];
			}

			$excluded = apply_filters( 'uadv_excluded_taxonomies', array(
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
				$result[ $tax_name ] = $tax_object;
			}

			self::$taxonomies_cache[ $post_type ] = $result;
			return $result;
		}

		public static function get_taxonomy_by_key( $post_type, $taxonomy ) {
			$taxonomies = self::get_taxonomies_for_post_type( $post_type );
			return isset( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : null;
		}

		// =====================================================================
		// SECTION — RELATIONSHIP PATH RESOLVER
		// =====================================================================

		public static function post_is_readable( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return false;
			}
			if ( 'publish' === $post->post_status ) {
				return true;
			}
			return current_user_can( 'read_post', $post_id );
		}

		private static function extract_first_related_id( $raw ) {
			if ( empty( $raw ) ) {
				return 0;
			}
			if ( is_array( $raw ) ) {
				$raw = reset( $raw );
			}
			if ( $raw instanceof WP_Post ) {
				return (int) $raw->ID;
			}
			if ( is_array( $raw ) && isset( $raw['ID'] ) ) {
				return (int) $raw['ID'];
			}
			if ( is_numeric( $raw ) ) {
				return (int) $raw;
			}
			return 0;
		}

		/**
		 * Follows a structured relationship path — an ordered list of ACF
		 * Field Keys, never hand-written PHP — starting from one record,
		 * hopping through Post Object/Relationship fields, and returns the
		 * FINAL field's formatted value. Every Field Key is validated
		 * against the CPT it's actually being looked up on at that point in
		 * the chain (get_field_by_key()), so a key belonging to an
		 * unrelated post type can never be resolved. Depth is capped at
		 * UADV_MAX_RELATIONSHIP_DEPTH. A missing/unreadable relation at any
		 * point yields an empty result rather than an error.
		 *
		 * @param int    $start_post_id
		 * @param string $start_post_type
		 * @param array  $path Ordered list of Field Keys.
		 * @param array  $display_opts Passed through to format_field_value().
		 * @return string Safe, already-escaped HTML (or '').
		 */
		public static function resolve_relationship_path( $start_post_id, $start_post_type, array $path, array $display_opts = array() ) {
			$path = array_values( array_filter(
				array_map( 'sanitize_text_field', $path ),
				static function ( $v ) { return '' !== $v; }
			) );

			if ( empty( $path ) ) {
				return '';
			}
			if ( count( $path ) > UADV_MAX_RELATIONSHIP_DEPTH ) {
				$path = array_slice( $path, 0, UADV_MAX_RELATIONSHIP_DEPTH );
			}

			$cache_key = $start_post_id . '|' . $start_post_type . '|' . implode( '>', $path ) . '|' . md5( (string) wp_json_encode( $display_opts ) );
			if ( array_key_exists( $cache_key, self::$relationship_cache ) ) {
				return self::$relationship_cache[ $cache_key ];
			}

			$current_id   = (int) $start_post_id;
			$current_type = $start_post_type;
			$result       = '';
			$last_index   = count( $path ) - 1;

			foreach ( $path as $index => $field_key ) {
				$field = self::get_field_by_key( $current_type, $field_key );
				if ( ! $field ) {
					$result = '';
					break;
				}

				if ( $index < $last_index ) {
					if ( empty( $field['relational'] ) ) {
						$result = '';
						break;
					}
					$raw     = get_field( $field['key'], $current_id );
					$next_id = self::extract_first_related_id( $raw );
					$next_type = $next_id ? get_post_type( $next_id ) : '';
					if ( ! $next_id || ! $next_type || ! self::is_valid_post_type( $next_type ) || ! self::post_is_readable( $next_id ) ) {
						$result = '';
						break;
					}
					$current_id   = $next_id;
					$current_type = $next_type;
				} else {
					$raw = get_field( $field['key'], $current_id );
					if ( isset( $display_opts['linkDestination'] ) && 'related' === $display_opts['linkDestination']
						&& in_array( $field['type'], array( 'post_object', 'relationship' ), true ) ) {
						$result = self::format_related_links( $raw, $display_opts );
					} else {
						$result = self::format_field_value( $field, $raw, $display_opts );
					}
				}
			}

			self::$relationship_cache[ $cache_key ] = $result;
			return $result;
		}

		// =====================================================================
		// FIELD VALUE FORMATTING — never prints "Array", "Object" or
		// "undefined"; every branch returns already-escaped, safe HTML.
		// =====================================================================

		private static function is_empty_value( $value ) {
			return ( null === $value || '' === $value || array() === $value );
		}

		private static function stringify_unknown( $value, $separator = ', ' ) {
			if ( is_scalar( $value ) ) {
				return esc_html( (string) $value );
			}
			if ( $value instanceof WP_Post ) {
				return esc_html( get_the_title( $value ) );
			}
			if ( is_array( $value ) ) {
				$parts = array();
				foreach ( $value as $item ) {
					$s = self::stringify_unknown( $item, $separator );
					if ( '' !== $s ) {
						$parts[] = $s;
					}
				}
				return implode( esc_html( $separator ), $parts );
			}
			if ( is_object( $value ) ) {
				if ( method_exists( $value, '__toString' ) ) {
					return esc_html( (string) $value );
				}
				if ( isset( $value->name ) ) {
					return esc_html( (string) $value->name );
				}
				if ( isset( $value->title ) ) {
					return esc_html( (string) $value->title );
				}
			}
			return '';
		}

		private static function extract_related_ids( $value, $context = 'post' ) {
			$items = is_array( $value ) ? $value : array( $value );
			$ids   = array();
			foreach ( $items as $item ) {
				if ( 'user' === $context && $item instanceof WP_User ) {
					$ids[] = (int) $item->ID;
				} elseif ( $item instanceof WP_Post ) {
					$ids[] = (int) $item->ID;
				} elseif ( is_array( $item ) && isset( $item['ID'] ) ) {
					$ids[] = (int) $item['ID'];
				} elseif ( is_numeric( $item ) ) {
					$ids[] = (int) $item;
				}
			}
			return array_filter( $ids );
		}

		private static function format_image_value( $value, $size ) {
			$attachment_id = 0;
			$fallback_url  = '';

			if ( is_numeric( $value ) ) {
				$attachment_id = (int) $value;
			} elseif ( is_array( $value ) ) {
				if ( ! empty( $value['ID'] ) ) {
					$attachment_id = (int) $value['ID'];
				} elseif ( ! empty( $value['id'] ) ) {
					$attachment_id = (int) $value['id'];
				} elseif ( ! empty( $value['url'] ) ) {
					$fallback_url = $value['url'];
				}
			} elseif ( is_string( $value ) ) {
				$fallback_url = $value;
			}

			if ( $attachment_id > 0 ) {
				$html = wp_get_attachment_image( $attachment_id, $size ? $size : 'thumbnail' );
				if ( $html ) {
					return $html;
				}
			}

			if ( $fallback_url ) {
				return '<img src="' . esc_url( $fallback_url ) . '" alt="" loading="lazy" />';
			}

			return '';
		}

		private static function format_file_value( $value ) {
			$attachment_id = 0;
			$url           = '';

			if ( is_numeric( $value ) ) {
				$attachment_id = (int) $value;
			} elseif ( is_array( $value ) ) {
				if ( ! empty( $value['ID'] ) ) {
					$attachment_id = (int) $value['ID'];
				} elseif ( ! empty( $value['url'] ) ) {
					$url = $value['url'];
				}
			} elseif ( is_string( $value ) ) {
				$url = $value;
			}

			if ( $attachment_id > 0 ) {
				$real_url = wp_get_attachment_url( $attachment_id );
				if ( $real_url ) {
					$title = get_the_title( $attachment_id );
					return '<a href="' . esc_url( $real_url ) . '">' . esc_html( $title ? $title : basename( $real_url ) ) . '</a>';
				}
			}

			if ( $url ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $path ? basename( $path ) : $url ) . '</a>';
			}

			return '';
		}

		/**
		 * @param array $field ACF field definition.
		 * @param mixed $value Raw value as returned by get_field().
		 * @param array $opts  separator, decimals, dateFormat, imageSize,
		 *                     trueLabel, falseLabel (all optional).
		 * @return string Already-escaped, safe HTML.
		 */
		public static function format_field_value( array $field, $value, array $opts = array() ) {
			if ( self::is_empty_value( $value ) ) {
				return '';
			}

			$separator = ( isset( $opts['separator'] ) && '' !== $opts['separator'] ) ? $opts['separator'] : ', ';

			switch ( $field['type'] ) {

				case 'number':
					$decimals = ( isset( $opts['decimals'] ) && $opts['decimals'] >= 0 ) ? (int) $opts['decimals'] : 0;
					return esc_html( number_format_i18n( (float) $value, $decimals ) );

				case 'email':
					$value = sanitize_email( (string) $value );
					return $value ? '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>' : '';

				case 'url':
					$value = esc_url_raw( (string) $value );
					return $value ? '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>' : '';

				case 'textarea':
					return nl2br( esc_html( (string) $value ) );

				case 'select':
				case 'radio':
				case 'button_group':
				case 'checkbox':
					$choices = ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) ? $field['choices'] : array();
					$values  = is_array( $value ) ? $value : array( $value );
					$labels  = array();
					foreach ( $values as $v ) {
						if ( is_scalar( $v ) ) {
							$labels[] = esc_html( isset( $choices[ $v ] ) ? (string) $choices[ $v ] : (string) $v );
						}
					}
					return implode( esc_html( $separator ), $labels );

				case 'true_false':
					$true_label  = ( isset( $opts['trueLabel'] ) && '' !== $opts['trueLabel'] ) ? $opts['trueLabel'] : __( 'Yes', 'uadv' );
					$false_label = ( isset( $opts['falseLabel'] ) && '' !== $opts['falseLabel'] ) ? $opts['falseLabel'] : __( 'No', 'uadv' );
					return esc_html( $value ? $true_label : $false_label );

				case 'date_picker':
				case 'date_time_picker':
					$format    = ( isset( $opts['dateFormat'] ) && '' !== $opts['dateFormat'] ) ? $opts['dateFormat'] : get_option( 'date_format' );
					$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
					if ( ! $timestamp ) {
						return esc_html( (string) $value );
					}
					return esc_html( date_i18n( $format, $timestamp ) );

				case 'image':
					return self::format_image_value( $value, isset( $opts['imageSize'] ) ? $opts['imageSize'] : 'thumbnail' );

				case 'file':
					return self::format_file_value( $value );

				case 'post_object':
				case 'relationship':
					$ids = self::extract_related_ids( $value );
					$out = array();
					foreach ( $ids as $id ) {
						if ( self::post_is_readable( $id ) ) {
							$title = get_the_title( $id );
							if ( '' !== $title ) {
								$out[] = esc_html( $title );
							}
						}
					}
					return implode( esc_html( $separator ), $out );

				case 'user':
					$ids = self::extract_related_ids( $value, 'user' );
					$out = array();
					foreach ( $ids as $id ) {
						$user = get_userdata( $id );
						if ( $user ) {
							$out[] = esc_html( $user->display_name );
						}
					}
					return implode( esc_html( $separator ), $out );

				case 'taxonomy':
					$items = is_array( $value ) ? $value : array( $value );
					$out   = array();
					foreach ( $items as $item ) {
						$term = null;
						if ( is_numeric( $item ) ) {
							$term = get_term( (int) $item );
						} elseif ( is_object( $item ) && isset( $item->name ) ) {
							$term = $item;
						}
						if ( $term && ! is_wp_error( $term ) ) {
							$out[] = esc_html( $term->name );
						}
					}
					return implode( esc_html( $separator ), $out );

				case 'text':
				default:
					return self::stringify_unknown( $value, $separator );
			}
		}

		// =====================================================================
		// SECTION — SECURE QUERY BUILDING (whitelisted parameters only; no
		// attribute value is ever passed to WP_Query unvalidated).
		// =====================================================================

		/**
		 * @return string[] A safe subset of registered post statuses.
		 */
		public static function sanitize_post_status_list( $post_type, array $statuses ) {
			$valid = get_post_stati( array(), 'names' );
			$valid = array_diff( $valid, array( 'trash', 'auto-draft' ) );
			$valid = apply_filters( 'uadv_allowed_post_statuses', $valid, $post_type );

			$result = array();
			foreach ( $statuses as $status ) {
				$status = sanitize_key( $status );
				if ( in_array( $status, $valid, true ) ) {
					$result[] = $status;
				}
			}
			return ! empty( $result ) ? $result : array( 'publish' );
		}

		/**
		 * @return array WP_Query 'orderby'/'meta_key' arguments.
		 */
		public static function resolve_orderby( $post_type, $order_by_attr ) {
			$map = array(
				'title'      => array( 'orderby' => 'title' ),
				'date'       => array( 'orderby' => 'date' ),
				'modified'   => array( 'orderby' => 'modified' ),
				'menu_order' => array( 'orderby' => 'menu_order' ),
			);

			if ( isset( $map[ $order_by_attr ] ) ) {
				return $map[ $order_by_attr ];
			}

			if ( 0 === strpos( (string) $order_by_attr, 'acf:' ) ) {
				$field = self::get_field_by_key( $post_type, substr( $order_by_attr, 4 ) );
				if ( $field ) {
					return array(
						'orderby'  => ( 'number' === $field['type'] ) ? 'meta_value_num' : 'meta_value',
						'meta_key' => $field['name'],
					);
				}
			}

			return array( 'orderby' => 'date' );
		}

		/**
		 * @return array A tax_query array, or [] when the taxonomy/terms are invalid.
		 */
		public static function build_tax_query( $post_type, $taxonomy, array $term_ids ) {
			if ( ! self::get_taxonomy_by_key( $post_type, $taxonomy ) ) {
				return array();
			}
			$valid_ids = array();
			foreach ( $term_ids as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id > 0 && term_exists( $term_id, $taxonomy ) ) {
					$valid_ids[] = $term_id;
				}
			}
			if ( empty( $valid_ids ) ) {
				return array();
			}
			return array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $valid_ids,
				),
			);
		}

		/**
		 * @return array A meta_query array, or [] when the field/value are invalid.
		 *               The meta key always comes from the validated field's
		 *               own 'name' — never directly from client input.
		 */
		public static function build_meta_query( $post_type, $field_key, $value, $compare ) {
			$field = self::get_field_by_key( $post_type, $field_key );
			if ( ! $field || '' === (string) $value ) {
				return array();
			}
			$allowed_compare = array( '=', '!=', 'LIKE' );
			$compare         = in_array( $compare, $allowed_compare, true ) ? $compare : '=';
			return array(
				array(
					'key'     => $field['name'],
					'value'   => sanitize_text_field( $value ),
					'compare' => $compare,
				),
			);
		}

		// =====================================================================
		// SECTION — ATTRIBUTE DEFAULTS (mirrors each block's PHP
		// register_block_type() schema; used to reconstruct the full,
		// defaulted attribute set from RAW parsed innerBlocks — the parent
		// reads these directly, without going through WP_Block, purely for
		// layout decisions like the table header/data-label text).
		// =====================================================================

		private static function field_block_defaults() {
			return array(
				'sourceType'         => 'post_title',
				'fieldKey'           => '',
				'taxonomy'           => '',
				'relationshipPath'   => array(),
				'columnLabel'        => '',
				'showLabelDesktop'   => true,
				'showLabelMobile'    => true,
				'hideEmptyValue'     => false,
				'emptyValueText'     => '',
				'textAlign'          => '',
				'verticalAlign'      => '',
				'linkDestination'    => 'none',
				'linkCustomFieldKey' => '',
				'linkCustomUrl'      => '',
				'linkTarget'         => '_self',
				'prefix'             => '',
				'suffix'             => '',
				'separator'          => ', ',
				'imageSize'          => 'thumbnail',
				'dateFormat'         => '',
				'numberDecimals'     => -1,
				'displayAs'          => 'plain',
				'linkTaxonomyTerms'  => false,
				'trueLabel'          => '',
				'falseLabel'         => '',
			);
		}

		private static function link_block_defaults() {
			return array(
				'actionType'       => 'view',
				'customLabel'      => '',
				'icon'             => '',
				'editPageUrl'      => '',
				'openInNewTab'     => false,
				'columnLabel'      => '',
				'showLabelDesktop' => true,
				'showLabelMobile'  => true,
				'textAlign'        => '',
				'verticalAlign'    => '',
			);
		}

		private static function empty_message_defaults() {
			return array( 'message' => '' );
		}

		private static function pagination_defaults() {
			return array(
				'prevLabel'       => '',
				'nextLabel'       => '',
				'showPageNumbers' => true,
			);
		}

		// =====================================================================
		// SECTION — SMALL SHARED HELPERS
		// =====================================================================

		private static function notice( $message ) {
			return '<div class="uadv-notice">' . wp_kses_post( $message ) . '</div>';
		}

		private static function get_current_url() {
			$queried_id = get_queried_object_id();
			$permalink  = $queried_id ? get_permalink( $queried_id ) : false;
			if ( ! $permalink ) {
				global $wp;
				$permalink = ! empty( $wp->request ) ? home_url( user_trailingslashit( $wp->request ) ) : home_url( '/' );
			}
			return $permalink;
		}

		/**
		 * Manually renders one raw parsed inner block with a hand-built
		 * context array. This is the real, documented mechanism core's own
		 * Query Loop block uses to repeat its InnerBlocks once per queried
		 * post: a fresh WP_Block instance per repetition, constructed with
		 * whatever context THIS system chooses to hand it (which may
		 * include runtime-computed values like the current record's ID —
		 * something Block Context's automatic top-down propagation could
		 * never carry, since that only ever forwards a parent's own
		 * attribute values). The child block still declares those extra
		 * keys via usesContext, same as any Block-Context-consuming block.
		 */
		private static function render_inner_block( array $parsed_block, array $context ) {
			if ( empty( $parsed_block['blockName'] ) ) {
				return '';
			}
			$wp_block = new WP_Block( $parsed_block, $context );
			return $wp_block->render();
		}

		private static function merged_cell_attrs( array $parsed_block ) {
			$raw      = ( isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ) ? $parsed_block['attrs'] : array();
			$name     = isset( $parsed_block['blockName'] ) ? $parsed_block['blockName'] : '';
			$defaults = ( 'uadv/data-link' === $name ) ? self::link_block_defaults() : self::field_block_defaults();
			return wp_parse_args( $raw, $defaults );
		}

		private static function resolve_field_label( array $attrs, $post_type ) {
			if ( isset( $attrs['columnLabel'] ) && '' !== trim( (string) $attrs['columnLabel'] ) ) {
				return $attrs['columnLabel'];
			}
			if ( ! isset( $attrs['sourceType'] ) ) {
				return __( 'Actions', 'uadv' ); // uadv/data-link entries.
			}
			switch ( $attrs['sourceType'] ) {
				case 'post_title':      return __( 'Title', 'uadv' );
				case 'post_id':         return __( 'ID', 'uadv' );
				case 'publish_date':    return __( 'Date', 'uadv' );
				case 'modified_date':   return __( 'Modified', 'uadv' );
				case 'author':          return __( 'Author', 'uadv' );
				case 'featured_image':  return __( 'Image', 'uadv' );
				case 'permalink':       return __( 'Link', 'uadv' );
				case 'taxonomy':
					$tax = self::get_taxonomy_by_key( $post_type, $attrs['taxonomy'] );
					return $tax ? $tax->labels->name : __( 'Taxonomy', 'uadv' );
				case 'relationship_path':
					return __( 'Related field', 'uadv' );
				case 'acf_field':
				default:
					$field = self::get_field_by_key( $post_type, $attrs['fieldKey'] );
					return $field ? $field['label'] : __( 'Field', 'uadv' );
			}
		}

		/**
		 * data-label + show/hide-label classes + text/vertical align, for
		 * the structural <td>/.uadv-field-slot wrapper the PARENT builds
		 * around each repeated cell's own rendered output.
		 */
		private static function build_cell_wrapper_attrs( array $attrs, $post_type ) {
			$label = self::resolve_field_label( $attrs, $post_type );

			$classes = array();
			if ( empty( $attrs['showLabelDesktop'] ) ) {
				$classes[] = 'uadv-hide-desktop-label';
			}
			if ( empty( $attrs['showLabelMobile'] ) ) {
				$classes[] = 'uadv-hide-mobile-label';
			}

			$styles = array();
			if ( ! empty( $attrs['textAlign'] ) && in_array( $attrs['textAlign'], array( 'left', 'center', 'right' ), true ) ) {
				$styles[] = 'text-align:' . $attrs['textAlign'];
			}
			if ( ! empty( $attrs['verticalAlign'] ) && in_array( $attrs['verticalAlign'], array( 'top', 'middle', 'bottom' ), true ) ) {
				$styles[] = 'vertical-align:' . $attrs['verticalAlign'];
			}

			$out = ' data-label="' . esc_attr( $label ) . '"';
			if ( ! empty( $classes ) ) {
				$out .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
			}
			if ( ! empty( $styles ) ) {
				$out .= ' style="' . esc_attr( implode( ';', $styles ) ) . '"';
			}
			return $out;
		}

		// =====================================================================
		// SECTION — LINK DESTINATION (Universal ACF Field's Link Destination:
		// None / Current Record / Related Record / Custom URL)
		// =====================================================================

		/**
		 * "Related Record": each related post gets its OWN <a> (never one
		 * link wrapping a joined string of several titles), and only after
		 * confirming the related post still exists and is readable by the
		 * current visitor.
		 */
		private static function format_related_links( $raw, array $opts ) {
			$ids       = self::extract_related_ids( $raw );
			$separator = ( isset( $opts['separator'] ) && '' !== $opts['separator'] ) ? $opts['separator'] : ', ';
			$target    = ( isset( $opts['linkTarget'] ) && '_blank' === $opts['linkTarget'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';

			$out = array();
			foreach ( $ids as $id ) {
				if ( ! self::post_is_readable( $id ) ) {
					continue;
				}
				$title = get_the_title( $id );
				if ( '' === $title ) {
					continue;
				}
				$out[] = '<a href="' . esc_url( get_permalink( $id ) ) . '"' . $target . '>' . esc_html( $title ) . '</a>';
			}
			return implode( esc_html( $separator ), $out );
		}

		/**
		 * "Custom URL": either another ACF URL-type field on the SAME
		 * record (validated against the CPT like any other Field Key), or
		 * an editor-configured fixed URL — never anything visitor-supplied.
		 */
		private static function resolve_custom_link( array $attrs, $post_type, WP_Post $post ) {
			$field_key = isset( $attrs['linkCustomFieldKey'] ) ? $attrs['linkCustomFieldKey'] : '';
			if ( '' !== $field_key ) {
				$field = self::get_field_by_key( $post_type, $field_key );
				if ( $field && 'url' === $field['type'] ) {
					$url = get_field( $field['key'], $post->ID );
					return is_string( $url ) ? esc_url_raw( $url ) : '';
				}
				return '';
			}
			$custom_url = isset( $attrs['linkCustomUrl'] ) ? $attrs['linkCustomUrl'] : '';
			return ( '' !== $custom_url ) ? esc_url_raw( $custom_url ) : '';
		}

		private static function force_image_display( $source_type, array $attrs, WP_Post $post, $post_type ) {
			if ( 'acf_field' === $source_type ) {
				$field = self::get_field_by_key( $post_type, $attrs['fieldKey'] );
				if ( $field ) {
					$raw = get_field( $field['key'], $post->ID );
					return self::format_image_value( $raw, $attrs['imageSize'] ? $attrs['imageSize'] : 'thumbnail' );
				}
			}
			return '';
		}

		private static function compute_taxonomy_cell( array $attrs, $post_type, WP_Post $post ) {
			$taxonomy   = sanitize_key( $attrs['taxonomy'] );
			$tax_object = self::get_taxonomy_by_key( $post_type, $taxonomy );
			if ( ! $tax_object ) {
				return '';
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) ) {
				return '';
			}
			$parts = array();
			foreach ( $terms as $term ) {
				$label = esc_html( $term->name );
				if ( ! empty( $attrs['linkTaxonomyTerms'] ) && ! empty( $tax_object->public ) ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$label = '<a href="' . esc_url( $link ) . '">' . $label . '</a>';
					}
				}
				$parts[] = $label;
			}
			$separator = ( '' !== $attrs['separator'] ) ? $attrs['separator'] : ', ';
			return implode( esc_html( $separator ), $parts );
		}

		/**
		 * Computes ONE cell's final, fully-formatted, safe HTML value for
		 * ONE record — source-type dispatch, Display As, and Link
		 * Destination, in that order. Link Destination "related" embeds its
		 * own per-item <a> tags (see format_related_links()) and is never
		 * additionally wrapped by "current"/"custom" linking, so a link
		 * never ends up nested inside another link.
		 */
		private static function compute_field_cell_value( array $attrs, $post_type, WP_Post $post ) {
			$source_type       = sanitize_key( $attrs['sourceType'] );
			$link_destination  = isset( $attrs['linkDestination'] ) ? sanitize_key( $attrs['linkDestination'] ) : 'none';
			$value_html        = '';
			$raw_scalar        = null;
			$already_linked    = false;

			$opts = array(
				'separator'       => $attrs['separator'],
				'decimals'        => $attrs['numberDecimals'],
				'dateFormat'      => $attrs['dateFormat'],
				'imageSize'       => $attrs['imageSize'],
				'trueLabel'       => $attrs['trueLabel'],
				'falseLabel'      => $attrs['falseLabel'],
				'linkDestination' => $link_destination,
				'linkTarget'      => $attrs['linkTarget'],
			);

			switch ( $source_type ) {
				case 'post_id':
					$raw_scalar = $post->ID;
					$value_html = esc_html( (string) $post->ID );
					break;

				case 'publish_date':
					$format     = ( '' !== $attrs['dateFormat'] ) ? $attrs['dateFormat'] : get_option( 'date_format' );
					$value_html = esc_html( get_the_date( $format, $post ) );
					break;

				case 'modified_date':
					$format     = ( '' !== $attrs['dateFormat'] ) ? $attrs['dateFormat'] : get_option( 'date_format' );
					$value_html = esc_html( get_the_modified_date( $format, $post ) );
					break;

				case 'author':
					$raw_scalar = get_the_author_meta( 'display_name', $post->post_author );
					$value_html = esc_html( (string) $raw_scalar );
					break;

				case 'featured_image':
					$value_html = has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, $attrs['imageSize'] ? $attrs['imageSize'] : 'thumbnail' ) : '';
					break;

				case 'permalink':
					$raw_scalar = get_permalink( $post );
					$value_html = esc_html( (string) $raw_scalar );
					break;

				case 'taxonomy':
					$value_html = self::compute_taxonomy_cell( $attrs, $post_type, $post );
					break;

				case 'relationship_path':
					$value_html     = self::resolve_relationship_path( $post->ID, $post_type, (array) $attrs['relationshipPath'], $opts );
					$already_linked = ( 'related' === $link_destination );
					break;

				case 'acf_field':
					$field = self::get_field_by_key( $post_type, $attrs['fieldKey'] );
					if ( $field ) {
						$raw = get_field( $field['key'], $post->ID );
						if ( is_scalar( $raw ) ) {
							$raw_scalar = $raw;
						}
						if ( 'related' === $link_destination && in_array( $field['type'], array( 'post_object', 'relationship' ), true ) ) {
							$value_html     = self::format_related_links( $raw, $opts );
							$already_linked = true;
						} else {
							$value_html = self::format_field_value( $field, $raw, $opts );
						}
					}
					break;

				case 'post_title':
				default:
					$raw_scalar = get_the_title( $post );
					$value_html = esc_html( (string) $raw_scalar );
					break;
			}

			if ( 'image' === $attrs['displayAs'] && 'featured_image' !== $source_type ) {
				$value_html = self::force_image_display( $source_type, $attrs, $post, $post_type );
			}

			$is_empty = ( '' === trim( wp_strip_all_tags( $value_html ) ) );
			if ( $is_empty ) {
				$value_html     = ! empty( $attrs['hideEmptyValue'] ) ? '' : esc_html( '' !== $attrs['emptyValueText'] ? $attrs['emptyValueText'] : '—' );
				$already_linked = false;
			} else {
				$value_html = ( '' !== $attrs['prefix'] ? esc_html( $attrs['prefix'] ) : '' ) . $value_html . ( '' !== $attrs['suffix'] ? esc_html( $attrs['suffix'] ) : '' );
			}

			if ( 'badge' === $attrs['displayAs'] && '' !== $value_html && ! $already_linked ) {
				$slug_source = ( null !== $raw_scalar ) ? (string) $raw_scalar : wp_strip_all_tags( $value_html );
				$badge_class = 'uadv-value-' . sanitize_html_class( sanitize_title( $slug_source ) );
				$value_html  = '<span class="uadv-badge ' . esc_attr( $badge_class ) . '">' . $value_html . '</span>';
			}

			if ( ! $already_linked && '' !== $value_html && in_array( $link_destination, array( 'current', 'custom' ), true ) ) {
				$href = ( 'current' === $link_destination ) ? get_permalink( $post ) : self::resolve_custom_link( $attrs, $post_type, $post );
				if ( $href ) {
					$target     = ( '_blank' === $attrs['linkTarget'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
					$value_html = '<a href="' . esc_url( $href ) . '"' . $target . '>' . $value_html . '</a>';
				}
			}

			return $value_html;
		}

		// =====================================================================
		// BLOCK RENDER CALLBACKS — data-field / data-link (repeated once per
		// record, via manually-constructed WP_Block instances) and
		// data-empty-message / data-pagination (rendered once).
		// =====================================================================

		public static function render_data_field_block( $attributes, $content, $block ) {
			$post_type = isset( $block->context['uadv/postType'] ) ? sanitize_key( $block->context['uadv/postType'] ) : '';
			$record_id = isset( $block->context['uadv/recordId'] ) ? absint( $block->context['uadv/recordId'] ) : 0;

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_field_preview( $post_type, $attributes );
			}
			if ( '' === $post_type || ! $record_id ) {
				return ''; // Orphaned, or the one harmless pre-pass WP performs while building $content for the parent (never used — see render_data_view_block()).
			}
			$post = get_post( $record_id );
			if ( ! $post || $post->post_type !== $post_type ) {
				return '';
			}

			$value_html = self::compute_field_cell_value( $attributes, $post_type, $post );

			$classes = array( 'uadv-field-value' );
			if ( ! empty( $attributes['textAlign'] ) ) {
				$classes[] = 'uadv-align-' . sanitize_html_class( $attributes['textAlign'] );
			}
			$wrapper = get_block_wrapper_attributes( array( 'class' => implode( ' ', $classes ) ) );

			return '<span ' . $wrapper . '>' . $value_html . '</span>';
		}

		public static function render_data_link_block( $attributes, $content, $block ) {
			$post_type = isset( $block->context['uadv/postType'] ) ? sanitize_key( $block->context['uadv/postType'] ) : '';
			$record_id = isset( $block->context['uadv/recordId'] ) ? absint( $block->context['uadv/recordId'] ) : 0;

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_link_preview( $attributes );
			}
			if ( '' === $post_type || ! $record_id ) {
				return '';
			}
			$post = get_post( $record_id );
			if ( ! $post || $post->post_type !== $post_type ) {
				return '';
			}

			$action = sanitize_key( $attributes['actionType'] );

			if ( 'edit' === $action && ! current_user_can( 'edit_post', $record_id ) ) {
				return '';
			}

			if ( 'edit' === $action ) {
				$base  = ( '' !== $attributes['editPageUrl'] ) ? esc_url_raw( $attributes['editPageUrl'] ) : self::get_current_url();
				$href  = add_query_arg( array( 'edit_id' => $record_id ), $base );
				$label = ( '' !== $attributes['customLabel'] ) ? $attributes['customLabel'] : __( 'Edit', 'uadv' );
			} else {
				$href  = get_permalink( $post );
				$label = ( '' !== $attributes['customLabel'] ) ? $attributes['customLabel'] : __( 'View Details', 'uadv' );
			}

			$target_attr = ! empty( $attributes['openInNewTab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';
			$icon_html   = ( '' !== $attributes['icon'] ) ? '<span class="dashicons dashicons-' . esc_attr( sanitize_html_class( $attributes['icon'] ) ) . '" aria-hidden="true"></span> ' : '';

			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uadv-record-link' ) );

			return '<a ' . $wrapper . ' href="' . esc_url( $href ) . '"' . $target_attr . '>' . $icon_html . esc_html( $label ) . '</a>';
		}

		public static function render_data_empty_message_block( $attributes ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::static_preview_markup( __( 'Universal Data Empty Message', 'uadv' ), __( 'Shown only when a view has 0 records.', 'uadv' ) );
			}
			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uadv-empty-message' ) );
			$message = ( '' !== trim( wp_strip_all_tags( (string) $attributes['message'] ) ) )
				? wp_kses_post( $attributes['message'] )
				: esc_html__( 'No records found.', 'uadv' );
			return '<div ' . $wrapper . '>' . $message . '</div>';
		}

		private static function render_default_empty_message() {
			return '<div class="uadv-empty-message">' . esc_html__( 'No records found.', 'uadv' ) . '</div>';
		}

		public static function render_data_pagination_block( $attributes, $content, $block ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::static_preview_markup( __( 'Universal Data Pagination', 'uadv' ), __( 'Previous / page numbers / Next.', 'uadv' ) );
			}

			$current_page = isset( $block->context['uadv/currentPage'] ) ? absint( $block->context['uadv/currentPage'] ) : 1;
			$total_pages  = isset( $block->context['uadv/totalPages'] ) ? absint( $block->context['uadv/totalPages'] ) : 1;
			$query_var    = isset( $block->context['uadv/pageQueryVar'] ) ? sanitize_key( $block->context['uadv/pageQueryVar'] ) : '';

			if ( $total_pages < 2 || '' === $query_var ) {
				return '';
			}

			$wrapper = get_block_wrapper_attributes( array( 'class' => 'uadv-pagination' ) );
			return self::build_pagination_html(
				$attributes['prevLabel'],
				$attributes['nextLabel'],
				! empty( $attributes['showPageNumbers'] ),
				$current_page,
				$total_pages,
				$query_var,
				'<nav ' . $wrapper . ' aria-label="' . esc_attr__( 'Pagination', 'uadv' ) . '">',
				'</nav>'
			);
		}

		private static function render_default_pagination( $current_page, $total_pages, $query_var ) {
			if ( $total_pages < 2 ) {
				return '';
			}
			return self::build_pagination_html(
				'', '', true, $current_page, $total_pages, $query_var,
				'<nav class="uadv-pagination" aria-label="' . esc_attr__( 'Pagination', 'uadv' ) . '">',
				'</nav>'
			);
		}

		private static function build_pagination_html( $prev_label, $next_label, $show_numbers, $current_page, $total_pages, $query_var, $open_tag, $close_tag ) {
			$prev_label = ( '' !== $prev_label ) ? $prev_label : __( 'Previous', 'uadv' );
			$next_label = ( '' !== $next_label ) ? $next_label : __( 'Next', 'uadv' );
			$base_url   = self::get_current_url();

			$html = $open_tag . '<ul class="uadv-pagination-list">';

			if ( $current_page > 1 ) {
				$html .= '<li><a class="uadv-page-link uadv-page-prev" href="' . esc_url( add_query_arg( $query_var, $current_page - 1, $base_url ) ) . '">' . esc_html( $prev_label ) . '</a></li>';
			} else {
				$html .= '<li><span class="uadv-page-link uadv-page-prev uadv-disabled" aria-disabled="true">' . esc_html( $prev_label ) . '</span></li>';
			}

			if ( $show_numbers ) {
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					if ( $i === $current_page ) {
						$html .= '<li><span class="uadv-page-link uadv-page-current" aria-current="page">' . esc_html( (string) $i ) . '</span></li>';
					} else {
						$html .= '<li><a class="uadv-page-link" href="' . esc_url( add_query_arg( $query_var, $i, $base_url ) ) . '">' . esc_html( (string) $i ) . '</a></li>';
					}
				}
			}

			if ( $current_page < $total_pages ) {
				$html .= '<li><a class="uadv-page-link uadv-page-next" href="' . esc_url( add_query_arg( $query_var, $current_page + 1, $base_url ) ) . '">' . esc_html( $next_label ) . '</a></li>';
			} else {
				$html .= '<li><span class="uadv-page-link uadv-page-next uadv-disabled" aria-disabled="true">' . esc_html( $next_label ) . '</span></li>';
			}

			$html .= '</ul>' . $close_tag;
			return $html;
		}

		// =====================================================================
		// STATIC, NON-INTERACTIVE EDITOR/REST PREVIEWS — never touch a real
		// query or a real ACF field.
		// =====================================================================

		private static function static_preview_markup( $title, $meta ) {
			return sprintf(
				'<div class="uadv-static-preview"><p class="uadv-static-preview-title">%s</p><p class="uadv-static-preview-meta">%s</p></div>',
				esc_html( $title ),
				esc_html( $meta )
			);
		}

		private static function render_static_view_preview( array $attributes ) {
			$post_type = isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : '';
			$available = self::get_available_post_types();
			$meta      = ( isset( $available[ $post_type ] ) && ! empty( $available[ $post_type ]->labels->singular_name ) )
				? sprintf( __( 'Post Type: %s', 'uadv' ), $available[ $post_type ]->labels->singular_name )
				: __( 'No content type selected', 'uadv' );
			return self::static_preview_markup( __( 'Universal Data View', 'uadv' ), $meta );
		}

		private static function render_static_field_preview( $post_type, array $attributes ) {
			$source = isset( $attributes['sourceType'] ) ? $attributes['sourceType'] : 'post_title';
			if ( 'acf_field' === $source ) {
				$field = self::get_field_by_key( $post_type, isset( $attributes['fieldKey'] ) ? $attributes['fieldKey'] : '' );
				$meta  = $field ? ( $field['label'] . ' · ' . $field['type'] ) : __( 'No field selected', 'uadv' );
			} elseif ( 'taxonomy' === $source ) {
				$tax  = self::get_taxonomy_by_key( $post_type, isset( $attributes['taxonomy'] ) ? $attributes['taxonomy'] : '' );
				$meta = $tax ? $tax->labels->name : __( 'No taxonomy selected', 'uadv' );
			} elseif ( 'relationship_path' === $source ) {
				$meta = __( 'Relationship path', 'uadv' );
			} else {
				$meta = $source;
			}
			return self::static_preview_markup( __( 'Data Field', 'uadv' ), $meta );
		}

		private static function render_static_link_preview( array $attributes ) {
			$action = isset( $attributes['actionType'] ) ? $attributes['actionType'] : 'view';
			return self::static_preview_markup( __( 'Data Link', 'uadv' ), $action );
		}

		// =====================================================================
		// PER-INSTANCE RESPONSIVE STYLE — the mobile breakpoint, grid column
		// counts and gap are configurable per Data View instance, so they
		// can't live in the single shared stylesheet; this generates a tiny
		// scoped <style> block (unique per instance via its own class) with
		// nothing but the functional table<->cards mechanics.
		// =====================================================================

		private static function instance_breakpoint_style( $instance_index, $breakpoint ) {
			$selector = '.uadv-view-' . (int) $instance_index;

			// Specificity note: every "hide the label" rule below intentionally
			// repeats the SAME attribute/class selectors as its matching
			// "show the label" rule, plus one more class — so it always wins
			// on specificity, regardless of stylesheet order, rather than
			// depending on a source-order race against the shared stylesheet.
			$template = <<<'CSS'
@media (max-width: __BP__px) {
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] table.uadv-table,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] table.uadv-table,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] table.uadv-table tbody,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] table.uadv-table tbody,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] table.uadv-table tr,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] table.uadv-table tr,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] table.uadv-table td,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] table.uadv-table td { display: block; width: auto; }
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] table.uadv-table thead,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] table.uadv-table thead { display: none; }
	__SEL__[data-mobile-layout="scroll-table"] .uadv-table-scroll { overflow-x: auto; }
	__SEL__ .uadv-grid { grid-template-columns: 1fr; }
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] td[data-label]::before,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] td[data-label]::before { content: attr(data-label) ": "; font-weight: 600; display: block; }
	__SEL__[data-desktop-layout="table"][data-mobile-layout="cards"] td[data-label].uadv-hide-mobile-label::before,
	__SEL__[data-desktop-layout="table"][data-mobile-layout="list"] td[data-label].uadv-hide-mobile-label::before { content: none; }
	__SEL__ .uadv-field-slot[data-label].uadv-hide-mobile-label::before { content: none; }
}
@media (min-width: __BPPLUS__px) {
	__SEL__ .uadv-field-slot[data-label].uadv-hide-desktop-label::before { content: none; }
}
CSS;

			$css = str_replace(
				array( '__BP__', '__BPPLUS__', '__SEL__' ),
				array( (int) $breakpoint, (int) $breakpoint + 1, $selector ),
				$template
			);

			return '<style>' . $css . '</style>';
		}

		// =====================================================================
		// PARENT RENDER CALLBACK — runs ONE secure WP_Query, then repeats
		// the record-level InnerBlocks (Field/Link) once per result via
		// manually-constructed WP_Block instances (render_inner_block()).
		// =====================================================================

		public static function render_data_view_block( $attributes, $content, $block ) {
			$post_type = isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : '';

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::render_static_view_preview( $attributes );
			}

			if ( ! function_exists( 'acf_get_field_groups' ) ) {
				return self::notice( __( 'Advanced Custom Fields (ACF) is not active.', 'uadv' ) );
			}

			if ( '' === $post_type || ! self::is_valid_post_type( $post_type ) ) {
				return self::notice( __( 'This block does not have a valid content type selected yet.', 'uadv' ) );
			}

			$instance_index = self::$view_instance_counter++;
			$anchor         = ! empty( $attributes['anchor'] ) ? sanitize_html_class( $attributes['anchor'] ) : '';
			$query_var      = 'uadv_pg_' . ( '' !== $anchor ? $anchor : (string) $instance_index );

			$enable_pagination = ! empty( $attributes['enablePagination'] );
			$records_per_page  = max( 1, absint( $attributes['recordsPerPage'] ) );
			$number_of_records = (int) $attributes['numberOfRecords'];

			$current_page = 1;
			if ( $enable_pagination && isset( $_GET[ $query_var ] ) ) {
				$current_page = max( 1, absint( wp_unslash( $_GET[ $query_var ] ) ) );
			}

			$order = self::resolve_orderby( $post_type, isset( $attributes['orderBy'] ) ? $attributes['orderBy'] : 'date' );

			$query_args = array_merge( array(
				'post_type'              => $post_type,
				'post_status'            => self::sanitize_post_status_list( $post_type, (array) $attributes['postStatus'] ),
				'perm'                   => 'readable',
				'order'                  => ( 'ASC' === strtoupper( (string) $attributes['orderDirection'] ) ) ? 'ASC' : 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => ! $enable_pagination,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			), $order );

			if ( $enable_pagination ) {
				$query_args['posts_per_page'] = $records_per_page;
				$query_args['paged']          = $current_page;
			} else {
				$query_args['posts_per_page'] = ( -1 === $number_of_records ) ? -1 : max( 1, $number_of_records );
			}

			if ( ! empty( $attributes['filterTaxonomy'] ) && ! empty( $attributes['filterTerms'] ) ) {
				$tax_query = self::build_tax_query( $post_type, sanitize_key( $attributes['filterTaxonomy'] ), (array) $attributes['filterTerms'] );
				if ( ! empty( $tax_query ) ) {
					$query_args['tax_query'] = $tax_query;
				}
			}

			if ( ! empty( $attributes['filterAcfFieldKey'] ) && '' !== (string) $attributes['filterAcfValue'] ) {
				$meta_query = self::build_meta_query(
					$post_type,
					$attributes['filterAcfFieldKey'],
					$attributes['filterAcfValue'],
					isset( $attributes['filterAcfCompare'] ) ? $attributes['filterAcfCompare'] : '='
				);
				if ( ! empty( $meta_query ) ) {
					$query_args['meta_query'] = $meta_query;
				}
			}

			/**
			 * Lets the query be adjusted from outside this file without
			 * touching it — still runs through WP_Query, never raw SQL.
			 */
			$query_args = apply_filters( 'uadv_query_args', $query_args, $post_type, $attributes );

			$query = new WP_Query( $query_args );

			$parsed_inner = ( isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] ) )
				? $block->parsed_block['innerBlocks']
				: array();

			$cell_blocks       = array();
			$empty_message_raw = null;
			$pagination_raw    = null;

			foreach ( $parsed_inner as $inner ) {
				$name = isset( $inner['blockName'] ) ? $inner['blockName'] : '';
				if ( in_array( $name, array( 'uadv/data-field', 'uadv/data-link' ), true ) ) {
					$cell_blocks[] = $inner;
				} elseif ( 'uadv/data-empty-message' === $name && null === $empty_message_raw ) {
					$empty_message_raw = $inner;
				} elseif ( 'uadv/data-pagination' === $name && null === $pagination_raw ) {
					$pagination_raw = $inner;
				}
			}

			$desktop_layout     = in_array( $attributes['desktopLayout'], array( 'table', 'grid', 'list' ), true ) ? $attributes['desktopLayout'] : 'table';
			$mobile_layout      = in_array( $attributes['mobileLayout'], array( 'cards', 'list', 'scroll-table' ), true ) ? $attributes['mobileLayout'] : 'cards';
			$breakpoint         = max( 320, absint( $attributes['mobileBreakpoint'] ) );
			$grid_desktop_cols  = max( 1, absint( $attributes['gridColumnsDesktop'] ) );
			$grid_tablet_cols   = max( 1, absint( $attributes['gridColumnsTablet'] ) );
			$gap                = max( 0, absint( $attributes['gap'] ) );
			$entire_clickable   = ! empty( $attributes['entireRecordClickable'] );

			$instance_class  = 'uadv-view-' . $instance_index . ( '' !== $anchor ? ' ' . $anchor : '' );
			$wrapper_classes = array( 'uadv-data-view', $instance_class );
			if ( ! empty( $attributes['zebraRows'] ) ) {
				$wrapper_classes[] = 'uadv-zebra';
			}

			$style_vars = sprintf(
				'--uadv-grid-cols-desktop:%d;--uadv-grid-cols-tablet:%d;--uadv-gap:%dpx;',
				$grid_desktop_cols,
				$grid_tablet_cols,
				$gap
			);

			$wrapper = get_block_wrapper_attributes( array(
				'class' => implode( ' ', $wrapper_classes ),
				'style' => $style_vars,
			) );

			$out  = self::instance_breakpoint_style( $instance_index, $breakpoint );
			$out .= '<div ' . $wrapper . ' data-desktop-layout="' . esc_attr( $desktop_layout ) . '" data-mobile-layout="' . esc_attr( $mobile_layout ) . '">';

			if ( ! $query->have_posts() ) {
				$out .= $empty_message_raw
					? self::render_inner_block( $empty_message_raw, array( 'uadv/postType' => $post_type ) )
					: self::render_default_empty_message();
				$out .= '</div>';
				return $out;
			}

			$is_table = ( 'table' === $desktop_layout );

			if ( $is_table ) {
				$out .= '<div class="uadv-table-scroll"><table class="uadv-table">';
				if ( ! empty( $attributes['showTableHeader'] ) ) {
					$out .= '<thead><tr>';
					foreach ( $cell_blocks as $inner ) {
						$inner_attrs = self::merged_cell_attrs( $inner );
						$out        .= '<th>' . esc_html( self::resolve_field_label( $inner_attrs, $post_type ) ) . '</th>';
					}
					$out .= '</tr></thead>';
				}
				$out .= '<tbody>';
			} else {
				$out .= '<div class="uadv-grid">';
			}

			while ( $query->have_posts() ) {
				$query->the_post();
				$record = get_post();

				$child_context = array(
					'uadv/postType' => $post_type,
					'uadv/recordId' => $record->ID,
				);

				$record_link = $entire_clickable ? get_permalink( $record ) : '';

				if ( $is_table ) {
					$out .= '<tr class="uadv-row"' . ( $record_link ? ( ' data-record-url="' . esc_url( $record_link ) . '" tabindex="0" aria-label="' . esc_attr( sprintf( __( 'View details for %s', 'uadv' ), get_the_title( $record ) ) ) . '"' ) : '' ) . '>';
					foreach ( $cell_blocks as $inner ) {
						$inner_attrs = self::merged_cell_attrs( $inner );
						$cell_html   = self::render_inner_block( $inner, $child_context );
						$td_attrs    = self::build_cell_wrapper_attrs( $inner_attrs, $post_type );
						$out        .= '<td' . $td_attrs . '>' . $cell_html . '</td>';
					}
					$out .= '</tr>';
				} else {
					$out .= '<div class="uadv-card">';
					if ( $record_link ) {
						// A stretched overlay link, not a wrapper: this keeps
						// the whole card clickable WITHOUT nesting any of the
						// cells' own real <a> tags (Related/Current/Custom
						// Link Destination) inside another <a>. CSS gives the
						// cells' own links a higher stacking order so they
						// stay independently clickable (see get_frontend_css()).
						$out .= '<a class="uadv-card-link-overlay" href="' . esc_url( $record_link ) . '" aria-label="' . esc_attr( sprintf( __( 'View details for %s', 'uadv' ), get_the_title( $record ) ) ) . '"></a>';
					}
					foreach ( $cell_blocks as $inner ) {
						$inner_attrs = self::merged_cell_attrs( $inner );
						$cell_html   = self::render_inner_block( $inner, $child_context );
						$slot_attrs  = self::build_cell_wrapper_attrs( $inner_attrs, $post_type );
						$out        .= '<div class="uadv-field-slot"' . $slot_attrs . '>' . $cell_html . '</div>';
					}
					$out .= '</div>';
				}
			}
			wp_reset_postdata();

			$out .= $is_table ? '</tbody></table></div>' : '</div>';

			$total_pages = 1;
			if ( $enable_pagination ) {
				$total_pages = max( 1, (int) $query->max_num_pages );
			}

			if ( $enable_pagination && $total_pages > 1 ) {
				$out .= $pagination_raw
					? self::render_inner_block( $pagination_raw, array(
						'uadv/postType'     => $post_type,
						'uadv/currentPage'  => $current_page,
						'uadv/totalPages'   => $total_pages,
						'uadv/pageQueryVar' => $query_var,
					) )
					: self::render_default_pagination( $current_page, $total_pages, $query_var );
			}

			$out .= '</div>';

			return $out;
		}

		// =====================================================================
		// BLOCK REGISTRATION (PHP side — authoritative for attributes,
		// context and rendering; mirrored in the editor JS for the editing UI).
		// =====================================================================

		private static function block_supports( array $extra = array() ) {
			$base = array(
				'customClassName' => true,
				'anchor'          => true,
				'spacing'         => array( 'margin' => true, 'padding' => true ),
				'color'           => array( 'background' => true, 'text' => true ),
			);
			return array_merge( $base, $extra );
		}

		private static function typography_supports() {
			return array(
				'fontSize'       => true,
				'lineHeight'     => true,
				'fontWeight'     => true,
				'fontStyle'      => true,
				'textTransform'  => true,
				'textDecoration' => true,
			);
		}

		public static function register_blocks() {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			register_block_type( 'uadv/data-view', array(
				'attributes'        => array(
					'postType'              => array( 'type' => 'string', 'default' => '' ),
					'postStatus'            => array( 'type' => 'array', 'default' => array( 'publish' ) ),
					'numberOfRecords'       => array( 'type' => 'number', 'default' => 10 ),
					'enablePagination'      => array( 'type' => 'boolean', 'default' => false ),
					'recordsPerPage'        => array( 'type' => 'number', 'default' => 10 ),
					'orderBy'               => array( 'type' => 'string', 'default' => 'date' ),
					'orderDirection'        => array( 'type' => 'string', 'default' => 'DESC' ),
					'desktopLayout'         => array( 'type' => 'string', 'default' => 'table' ),
					'mobileLayout'          => array( 'type' => 'string', 'default' => 'cards' ),
					'mobileBreakpoint'      => array( 'type' => 'number', 'default' => 782 ),
					'gridColumnsDesktop'    => array( 'type' => 'number', 'default' => 3 ),
					'gridColumnsTablet'     => array( 'type' => 'number', 'default' => 2 ),
					'gap'                   => array( 'type' => 'number', 'default' => 16 ),
					'showTableHeader'       => array( 'type' => 'boolean', 'default' => true ),
					'zebraRows'             => array( 'type' => 'boolean', 'default' => false ),
					'entireRecordClickable' => array( 'type' => 'boolean', 'default' => false ),
					'filterTaxonomy'        => array( 'type' => 'string', 'default' => '' ),
					'filterTerms'           => array( 'type' => 'array', 'default' => array() ),
					'filterAcfFieldKey'     => array( 'type' => 'string', 'default' => '' ),
					'filterAcfValue'        => array( 'type' => 'string', 'default' => '' ),
					'filterAcfCompare'      => array( 'type' => 'string', 'default' => '=' ),
				),
				'provides_context'  => array( 'uadv/postType' => 'postType' ),
				'supports'          => self::block_supports(),
				'render_callback'   => array( __CLASS__, 'render_data_view_block' ),
			) );

			register_block_type( 'uadv/data-field', array(
				'attributes'      => array(
					'sourceType'         => array( 'type' => 'string', 'default' => 'post_title' ),
					'fieldKey'           => array( 'type' => 'string', 'default' => '' ),
					'taxonomy'           => array( 'type' => 'string', 'default' => '' ),
					'relationshipPath'   => array( 'type' => 'array', 'default' => array() ),
					'columnLabel'        => array( 'type' => 'string', 'default' => '' ),
					'showLabelDesktop'   => array( 'type' => 'boolean', 'default' => true ),
					'showLabelMobile'    => array( 'type' => 'boolean', 'default' => true ),
					'hideEmptyValue'     => array( 'type' => 'boolean', 'default' => false ),
					'emptyValueText'     => array( 'type' => 'string', 'default' => '' ),
					'textAlign'          => array( 'type' => 'string', 'default' => '' ),
					'verticalAlign'      => array( 'type' => 'string', 'default' => '' ),
					'linkDestination'    => array( 'type' => 'string', 'default' => 'none' ),
					'linkCustomFieldKey' => array( 'type' => 'string', 'default' => '' ),
					'linkCustomUrl'      => array( 'type' => 'string', 'default' => '' ),
					'linkTarget'         => array( 'type' => 'string', 'default' => '_self' ),
					'prefix'             => array( 'type' => 'string', 'default' => '' ),
					'suffix'             => array( 'type' => 'string', 'default' => '' ),
					'separator'          => array( 'type' => 'string', 'default' => ', ' ),
					'imageSize'          => array( 'type' => 'string', 'default' => 'thumbnail' ),
					'dateFormat'         => array( 'type' => 'string', 'default' => '' ),
					'numberDecimals'     => array( 'type' => 'number', 'default' => -1 ),
					'displayAs'          => array( 'type' => 'string', 'default' => 'plain' ),
					'linkTaxonomyTerms'  => array( 'type' => 'boolean', 'default' => false ),
					'trueLabel'          => array( 'type' => 'string', 'default' => '' ),
					'falseLabel'         => array( 'type' => 'string', 'default' => '' ),
				),
				'uses_context'    => array( 'uadv/postType', 'uadv/recordId' ),
				'supports'        => self::block_supports( array( 'typography' => self::typography_supports() ) ),
				'render_callback' => array( __CLASS__, 'render_data_field_block' ),
			) );

			register_block_type( 'uadv/data-link', array(
				'attributes'      => array(
					'actionType'       => array( 'type' => 'string', 'default' => 'view' ),
					'customLabel'      => array( 'type' => 'string', 'default' => '' ),
					'icon'             => array( 'type' => 'string', 'default' => '' ),
					'editPageUrl'      => array( 'type' => 'string', 'default' => '' ),
					'openInNewTab'     => array( 'type' => 'boolean', 'default' => false ),
					'columnLabel'      => array( 'type' => 'string', 'default' => '' ),
					'showLabelDesktop' => array( 'type' => 'boolean', 'default' => true ),
					'showLabelMobile'  => array( 'type' => 'boolean', 'default' => true ),
					'textAlign'        => array( 'type' => 'string', 'default' => '' ),
					'verticalAlign'    => array( 'type' => 'string', 'default' => '' ),
				),
				'uses_context'    => array( 'uadv/postType', 'uadv/recordId' ),
				'supports'        => self::block_supports( array( 'typography' => self::typography_supports() ) ),
				'render_callback' => array( __CLASS__, 'render_data_link_block' ),
			) );

			register_block_type( 'uadv/data-empty-message', array(
				'attributes'      => array(
					'message' => array( 'type' => 'string', 'default' => '' ),
				),
				'uses_context'    => array( 'uadv/postType' ),
				'supports'        => self::block_supports( array( 'typography' => self::typography_supports() ) ),
				'render_callback' => array( __CLASS__, 'render_data_empty_message_block' ),
			) );

			register_block_type( 'uadv/data-pagination', array(
				'attributes'      => array(
					'prevLabel'       => array( 'type' => 'string', 'default' => '' ),
					'nextLabel'       => array( 'type' => 'string', 'default' => '' ),
					'showPageNumbers' => array( 'type' => 'boolean', 'default' => true ),
				),
				'uses_context'    => array( 'uadv/postType', 'uadv/currentPage', 'uadv/totalPages', 'uadv/pageQueryVar' ),
				'supports'        => self::block_supports( array( 'typography' => self::typography_supports() ) ),
				'render_callback' => array( __CLASS__, 'render_data_pagination_block' ),
			) );
		}

		// =====================================================================
		// EDITOR JAVASCRIPT — registers all 5 blocks. No ServerSideRender, no
		// real query, no real ACF field anywhere in the editor: every block
		// shows a static, non-interactive card. Loaded ONLY in the block
		// editor (enqueue_block_editor_assets), never on the front-end.
		// =====================================================================

		public static function enqueue_editor_assets() {
			$handle = 'uadv-block-editor';

			wp_register_script(
				$handle,
				false,
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				UADV_VERSION,
				true
			);

			$post_type_choices  = array();
			$fields_by_type     = array();
			$taxonomies_by_type = array();
			$terms_by_taxonomy  = array();

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
						'key'              => $field['key'],
						'name'             => isset( $field['name'] ) ? $field['name'] : '',
						'label'            => isset( $field['label'] ) ? $field['label'] : $field['key'],
						'type'             => isset( $field['type'] ) ? $field['type'] : '',
						'relational'       => ! empty( $field['relational'] ),
						'relatedPostTypes' => isset( $field['related_post_types'] ) ? array_values( $field['related_post_types'] ) : array(),
					);
				}
				$fields_by_type[ $key ] = $fields;

				$taxonomies = array();
				foreach ( self::get_taxonomies_for_post_type( $key ) as $tax_name => $tax_object ) {
					$taxonomies[] = array(
						'value' => $tax_name,
						'label' => ! empty( $tax_object->labels->name ) ? $tax_object->labels->name : $tax_name,
					);

					if ( ! isset( $terms_by_taxonomy[ $tax_name ] ) ) {
						$terms = get_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false ) );
						$list  = array();
						if ( ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								$list[] = array( 'value' => $term->term_id, 'label' => $term->name );
							}
						}
						$terms_by_taxonomy[ $tax_name ] = $list;
					}
				}
				$taxonomies_by_type[ $key ] = $taxonomies;
			}

			wp_localize_script( $handle, 'uadvBlockData', array(
				'postTypes'  => $post_type_choices,
				'fields'     => $fields_by_type,
				'taxonomies' => $taxonomies_by_type,
				'terms'      => $terms_by_taxonomy,
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

	var DATA = window.uadvBlockData || { postTypes: [], fields: {}, taxonomies: {}, terms: {} };

	var UADV_BLOCK_SUPPORTS = {
		customClassName: true,
		anchor: true,
		spacing: { margin: true, padding: true },
		color: { background: true, text: true }
	};

	var ALLOWED_BLOCKS = [ 'uadv/data-field', 'uadv/data-link', 'uadv/data-empty-message', 'uadv/data-pagination' ];

	var postTypeChoices = [ { value: '', label: __( 'Select a content type…', 'uadv' ) } ];
	( DATA.postTypes || [] ).forEach( function ( item ) { postTypeChoices.push( item ); } );

	function getPostTypeLabel( value ) {
		var label = value;
		postTypeChoices.forEach( function ( item ) { if ( item.value === value ) { label = item.label; } } );
		return label;
	}

	function fieldsForType( postType ) {
		return ( DATA.fields && DATA.fields[ postType ] ) || [];
	}

	function taxonomiesForType( postType ) {
		return ( DATA.taxonomies && DATA.taxonomies[ postType ] ) || [];
	}

	function termsForTaxonomy( taxonomy ) {
		return ( DATA.terms && DATA.terms[ taxonomy ] ) || [];
	}

	function blockPropsOf( extra ) {
		return useBlockProps ? useBlockProps( extra || {} ) : ( extra || {} );
	}

	var IMAGE_SIZE_OPTIONS = [
		{ value: 'thumbnail', label: __( 'Thumbnail', 'uadv' ) },
		{ value: 'medium', label: __( 'Medium', 'uadv' ) },
		{ value: 'large', label: __( 'Large', 'uadv' ) },
		{ value: 'full', label: __( 'Full size', 'uadv' ) }
	];

	// ---------------------------------------------------------------
	// Relationship Path picker: one SelectControl per step. A step
	// offers every field of "the current post type in the chain" plus
	// a marker (→) on relational ones. Picking a relational field opens
	// the next step for whatever post type IT is configured to relate
	// to; picking a non-relational field ends the path there. Capped at
	// 5 steps, matching the PHP resolver's own depth limit. When a
	// relational field allows more than one (or zero = "any") target
	// post type, the next step falls back to offering the union of
	// every discovered post type's fields, each labelled with its own
	// post type — the PHP resolver still validates the actual chosen
	// Field Key against whatever post type a given record's relation
	// really points to, so an inapplicable choice simply yields no
	// value for that record rather than an error.
	// ---------------------------------------------------------------
	function unionFieldsAcrossAllTypes() {
		var all = [];
		Object.keys( DATA.fields || {} ).forEach( function ( pt ) {
			var ptLabel = getPostTypeLabel( pt );
			fieldsForType( pt ).forEach( function ( f ) {
				all.push( { key: f.key, name: f.name, label: f.label + ' — ' + ptLabel, type: f.type, relational: f.relational, relatedPostTypes: f.relatedPostTypes } );
			} );
		} );
		return all;
	}

	function renderRelationshipPathPicker( rootPostType, path, onChange ) {
		var steps = [];
		var currentType = rootPostType;
		var ambiguous = false;

		for ( var i = 0; i < 5; i++ ) {
			var fieldsForCurrent = ambiguous ? unionFieldsAcrossAllTypes() : fieldsForType( currentType );
			var chosenKey = path[ i ] || '';
			steps.push( { fields: fieldsForCurrent, chosenKey: chosenKey, index: i } );

			var chosenField = null;
			fieldsForCurrent.forEach( function ( f ) { if ( f.key === chosenKey ) { chosenField = f; } } );

			if ( ! chosenField || ! chosenField.relational ) {
				break;
			}

			var related = chosenField.relatedPostTypes || [];
			if ( 1 === related.length ) {
				currentType = related[ 0 ];
				ambiguous = false;
			} else {
				ambiguous = true;
			}
		}

		return el( 'div', { className: 'uadv-relationship-path' },
			steps.map( function ( step, idx ) {
				var options = [ { value: '', label: __( 'Select a field…', 'uadv' ) } ];
				step.fields.forEach( function ( f ) {
					options.push( { value: f.key, label: f.label + ( f.relational ? ' →' : '' ) } );
				} );
				return el( SelectControl, {
					key: 'uadv-path-step-' + idx,
					label: __( 'Step', 'uadv' ) + ' ' + ( idx + 1 ),
					value: step.chosenKey,
					options: options,
					onChange: function ( value ) {
						var next = path.slice( 0, idx );
						next.push( value );
						onChange( next );
					}
				} );
			} )
		);
	}

	// ---------------------------------------------------------------
	// uadv/data-view — parent. Provides 'uadv/postType' as context.
	// ---------------------------------------------------------------
	registerBlockType( 'uadv/data-view', {
		title: __( 'Universal Data View', 'uadv' ),
		description: __( 'Lists records of any content type. Add Universal Data Field / Universal Data Link blocks inside to choose which columns appear.', 'uadv' ),
		icon: 'list-view',
		category: 'widgets',
		attributes: {
			postType: { type: 'string', default: '' },
			postStatus: { type: 'array', default: [ 'publish' ] },
			numberOfRecords: { type: 'number', default: 10 },
			enablePagination: { type: 'boolean', default: false },
			recordsPerPage: { type: 'number', default: 10 },
			orderBy: { type: 'string', default: 'date' },
			orderDirection: { type: 'string', default: 'DESC' },
			desktopLayout: { type: 'string', default: 'table' },
			mobileLayout: { type: 'string', default: 'cards' },
			mobileBreakpoint: { type: 'number', default: 782 },
			gridColumnsDesktop: { type: 'number', default: 3 },
			gridColumnsTablet: { type: 'number', default: 2 },
			gap: { type: 'number', default: 16 },
			showTableHeader: { type: 'boolean', default: true },
			zebraRows: { type: 'boolean', default: false },
			entireRecordClickable: { type: 'boolean', default: false },
			filterTaxonomy: { type: 'string', default: '' },
			filterTerms: { type: 'array', default: [] },
			filterAcfFieldKey: { type: 'string', default: '' },
			filterAcfValue: { type: 'string', default: '' },
			filterAcfCompare: { type: 'string', default: '=' }
		},
		providesContext: { 'uadv/postType': 'postType' },
		supports: UADV_BLOCK_SUPPORTS,
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockPropsOf( { className: 'uadv-view-editor-wrap' } );

			var postStatusOptions = [
				{ value: 'publish', label: __( 'Published', 'uadv' ) },
				{ value: 'pending', label: __( 'Pending', 'uadv' ) },
				{ value: 'draft', label: __( 'Draft', 'uadv' ) },
				{ value: 'private', label: __( 'Private', 'uadv' ) },
				{ value: 'future', label: __( 'Scheduled', 'uadv' ) }
			];

			var orderByOptions = [
				{ value: 'title', label: __( 'Title', 'uadv' ) },
				{ value: 'date', label: __( 'Publish Date', 'uadv' ) },
				{ value: 'modified', label: __( 'Modified Date', 'uadv' ) },
				{ value: 'menu_order', label: __( 'Menu Order', 'uadv' ) }
			];
			fieldsForType( attributes.postType ).forEach( function ( f ) {
				orderByOptions.push( { value: 'acf:' + f.key, label: f.label + ' (' + f.name + ')' } );
			} );

			var taxOptions = [ { value: '', label: __( 'None', 'uadv' ) } ];
			taxonomiesForType( attributes.postType ).forEach( function ( t ) { taxOptions.push( t ); } );

			var termChoices = attributes.filterTaxonomy ? termsForTaxonomy( attributes.filterTaxonomy ) : [];

			var fieldChoicesForFilter = [ { value: '', label: __( 'None', 'uadv' ) } ];
			fieldsForType( attributes.postType ).forEach( function ( f ) {
				fieldChoicesForFilter.push( { value: f.key, label: f.label + ' (' + f.name + ')' } );
			} );

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Content', 'uadv' ) },
					el( SelectControl, {
						label: __( 'Content type (CPT)', 'uadv' ),
						value: attributes.postType,
						options: postTypeChoices,
						onChange: function ( value ) { setAttributes( { postType: value, orderBy: 'date', filterTaxonomy: '', filterTerms: [], filterAcfFieldKey: '' } ); }
					} ),
					el( SelectControl, {
						label: __( 'Post Status', 'uadv' ),
						multiple: true,
						value: attributes.postStatus,
						options: postStatusOptions,
						onChange: function ( values ) { setAttributes( { postStatus: values } ); }
					} ),
					el( TextControl, {
						label: __( 'Number of Records', 'uadv' ),
						type: 'number',
						value: attributes.numberOfRecords,
						help: __( 'Use -1 to show all matching records.', 'uadv' ),
						onChange: function ( value ) { setAttributes( { numberOfRecords: parseInt( value, 10 ) || 0 } ); }
					} ),
					el( ToggleControl, {
						label: __( 'Enable Pagination', 'uadv' ),
						checked: !! attributes.enablePagination,
						onChange: function ( value ) { setAttributes( { enablePagination: value } ); }
					} ),
					attributes.enablePagination ? el( TextControl, {
						label: __( 'Records per Page', 'uadv' ),
						type: 'number',
						value: attributes.recordsPerPage,
						onChange: function ( value ) { setAttributes( { recordsPerPage: Math.max( 1, parseInt( value, 10 ) || 1 ) } ); }
					} ) : null
				),
				el( PanelBody, { title: __( 'Ordering', 'uadv' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Order By', 'uadv' ),
						value: attributes.orderBy,
						options: orderByOptions,
						onChange: function ( value ) { setAttributes( { orderBy: value } ); }
					} ),
					el( SelectControl, {
						label: __( 'Direction', 'uadv' ),
						value: attributes.orderDirection,
						options: [
							{ value: 'ASC', label: __( 'Ascending', 'uadv' ) },
							{ value: 'DESC', label: __( 'Descending', 'uadv' ) }
						],
						onChange: function ( value ) { setAttributes( { orderDirection: value } ); }
					} )
				),
				el( PanelBody, { title: __( 'Layout', 'uadv' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Desktop Layout', 'uadv' ),
						value: attributes.desktopLayout,
						options: [
							{ value: 'table', label: __( 'Table', 'uadv' ) },
							{ value: 'grid', label: __( 'Grid', 'uadv' ) },
							{ value: 'list', label: __( 'List', 'uadv' ) }
						],
						onChange: function ( value ) { setAttributes( { desktopLayout: value } ); }
					} ),
					el( SelectControl, {
						label: __( 'Mobile Layout', 'uadv' ),
						value: attributes.mobileLayout,
						options: [
							{ value: 'cards', label: __( 'Cards', 'uadv' ) },
							{ value: 'list', label: __( 'List', 'uadv' ) },
							{ value: 'scroll-table', label: __( 'Horizontal Scroll Table', 'uadv' ) }
						],
						onChange: function ( value ) { setAttributes( { mobileLayout: value } ); }
					} ),
					el( TextControl, {
						label: __( 'Mobile Breakpoint (px)', 'uadv' ),
						type: 'number',
						value: attributes.mobileBreakpoint,
						onChange: function ( value ) { setAttributes( { mobileBreakpoint: parseInt( value, 10 ) || 782 } ); }
					} ),
					( 'grid' === attributes.desktopLayout ) ? el( TextControl, {
						label: __( 'Grid Columns (Desktop)', 'uadv' ),
						type: 'number',
						value: attributes.gridColumnsDesktop,
						onChange: function ( value ) { setAttributes( { gridColumnsDesktop: Math.max( 1, parseInt( value, 10 ) || 1 ) } ); }
					} ) : null,
					( 'grid' === attributes.desktopLayout ) ? el( TextControl, {
						label: __( 'Grid Columns (Tablet)', 'uadv' ),
						type: 'number',
						value: attributes.gridColumnsTablet,
						onChange: function ( value ) { setAttributes( { gridColumnsTablet: Math.max( 1, parseInt( value, 10 ) || 1 ) } ); }
					} ) : null,
					el( TextControl, {
						label: __( 'Gap (px)', 'uadv' ),
						type: 'number',
						value: attributes.gap,
						onChange: function ( value ) { setAttributes( { gap: Math.max( 0, parseInt( value, 10 ) || 0 ) } ); }
					} ),
					( 'table' === attributes.desktopLayout ) ? el( ToggleControl, {
						label: __( 'Show Table Header', 'uadv' ),
						checked: !! attributes.showTableHeader,
						onChange: function ( value ) { setAttributes( { showTableHeader: value } ); }
					} ) : null,
					el( ToggleControl, {
						label: __( 'Zebra Rows', 'uadv' ),
						checked: !! attributes.zebraRows,
						onChange: function ( value ) { setAttributes( { zebraRows: value } ); }
					} ),
					el( ToggleControl, {
						label: __( 'Make Entire Record Clickable', 'uadv' ),
						checked: !! attributes.entireRecordClickable,
						onChange: function ( value ) { setAttributes( { entireRecordClickable: value } ); }
					} )
				),
				el( PanelBody, { title: __( 'Query', 'uadv' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Filter by Taxonomy', 'uadv' ),
						value: attributes.filterTaxonomy,
						options: taxOptions,
						onChange: function ( value ) { setAttributes( { filterTaxonomy: value, filterTerms: [] } ); }
					} ),
					attributes.filterTaxonomy ? el( SelectControl, {
						label: __( 'Terms', 'uadv' ),
						multiple: true,
						value: attributes.filterTerms.map( function ( v ) { return String( v ); } ),
						options: termChoices.map( function ( t ) { return { value: String( t.value ), label: t.label }; } ),
						onChange: function ( values ) { setAttributes( { filterTerms: values.map( function ( v ) { return parseInt( v, 10 ); } ) } ); }
					} ) : null,
					el( SelectControl, {
						label: __( 'Filter by ACF Field', 'uadv' ),
						value: attributes.filterAcfFieldKey,
						options: fieldChoicesForFilter,
						onChange: function ( value ) { setAttributes( { filterAcfFieldKey: value } ); }
					} ),
					attributes.filterAcfFieldKey ? el( TextControl, {
						label: __( 'Value', 'uadv' ),
						value: attributes.filterAcfValue,
						onChange: function ( value ) { setAttributes( { filterAcfValue: value } ); }
					} ) : null,
					attributes.filterAcfFieldKey ? el( SelectControl, {
						label: __( 'Compare', 'uadv' ),
						value: attributes.filterAcfCompare,
						options: [
							{ value: '=', label: __( 'Equals', 'uadv' ) },
							{ value: '!=', label: __( 'Not equals', 'uadv' ) },
							{ value: 'LIKE', label: __( 'Contains', 'uadv' ) }
						],
						onChange: function ( value ) { setAttributes( { filterAcfCompare: value } ); }
					} ) : null
				)
			);

			var header = el( 'p', { className: 'uadv-view-editor-label' },
				__( 'Universal Data View', 'uadv' ) + ' — ' + ( attributes.postType ? ( getPostTypeLabel( attributes.postType ) + ' · ' + attributes.desktopLayout ) : __( 'No content type selected', 'uadv' ) )
			);

			var body;
			if ( useInnerBlocksProps ) {
				var innerBlocksProps = useInnerBlocksProps(
					{ className: 'uadv-view-editor-body' },
					{ allowedBlocks: ALLOWED_BLOCKS, renderAppender: InnerBlocks.ButtonBlockAppender }
				);
				body = el( 'div', innerBlocksProps );
			} else {
				body = el( 'div', { className: 'uadv-view-editor-body' },
					el( InnerBlocks, { allowedBlocks: ALLOWED_BLOCKS, renderAppender: InnerBlocks.ButtonBlockAppender } )
				);
			}

			return el( 'div', blockProps, inspector, header, body );
		},
		save: function () {
			return el( InnerBlocks.Content );
		}
	} );

	// ---------------------------------------------------------------
	// uadv/data-field
	// ---------------------------------------------------------------
	registerBlockType( 'uadv/data-field', {
		title: __( 'Universal Data Field', 'uadv' ),
		description: __( 'Renders one column/field for each record in the parent Universal Data View.', 'uadv' ),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			sourceType: { type: 'string', default: 'post_title' },
			fieldKey: { type: 'string', default: '' },
			taxonomy: { type: 'string', default: '' },
			relationshipPath: { type: 'array', default: [] },
			columnLabel: { type: 'string', default: '' },
			showLabelDesktop: { type: 'boolean', default: true },
			showLabelMobile: { type: 'boolean', default: true },
			hideEmptyValue: { type: 'boolean', default: false },
			emptyValueText: { type: 'string', default: '' },
			textAlign: { type: 'string', default: '' },
			verticalAlign: { type: 'string', default: '' },
			linkDestination: { type: 'string', default: 'none' },
			linkCustomFieldKey: { type: 'string', default: '' },
			linkCustomUrl: { type: 'string', default: '' },
			linkTarget: { type: 'string', default: '_self' },
			prefix: { type: 'string', default: '' },
			suffix: { type: 'string', default: '' },
			separator: { type: 'string', default: ', ' },
			imageSize: { type: 'string', default: 'thumbnail' },
			dateFormat: { type: 'string', default: '' },
			numberDecimals: { type: 'number', default: -1 },
			displayAs: { type: 'string', default: 'plain' },
			linkTaxonomyTerms: { type: 'boolean', default: false },
			trueLabel: { type: 'string', default: '' },
			falseLabel: { type: 'string', default: '' }
		},
		usesContext: [ 'uadv/postType' ],
		supports: Object.assign( {}, UADV_BLOCK_SUPPORTS, {
			typography: { fontSize: true, lineHeight: true, fontWeight: true, fontStyle: true, textTransform: true, textDecoration: true }
		} ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var context = props.context || {};
			var postType = context[ 'uadv/postType' ] || '';
			var blockProps = blockPropsOf();

			if ( ! postType ) {
				return el( 'div', blockProps, el( Placeholder, {
					icon: 'editor-table',
					label: __( 'Universal Data Field', 'uadv' ),
					instructions: __( 'Place this block inside a Universal Data View block.', 'uadv' )
				} ) );
			}

			var sourceOptions = [
				{ value: 'post_title', label: __( 'Post Title', 'uadv' ) },
				{ value: 'post_id', label: __( 'Post ID', 'uadv' ) },
				{ value: 'publish_date', label: __( 'Publish Date', 'uadv' ) },
				{ value: 'modified_date', label: __( 'Modified Date', 'uadv' ) },
				{ value: 'author', label: __( 'Author', 'uadv' ) },
				{ value: 'featured_image', label: __( 'Featured Image', 'uadv' ) },
				{ value: 'permalink', label: __( 'Permalink', 'uadv' ) },
				{ value: 'acf_field', label: __( 'ACF Field', 'uadv' ) },
				{ value: 'taxonomy', label: __( 'Taxonomy', 'uadv' ) },
				{ value: 'relationship_path', label: __( 'Relationship Path', 'uadv' ) }
			];

			var fieldChoices = [ { value: '', label: __( 'Select a field…', 'uadv' ) } ];
			var selectedField = null;
			fieldsForType( postType ).forEach( function ( f ) {
				fieldChoices.push( { value: f.key, label: f.label + ' (' + f.name + ') · ' + f.type } );
				if ( f.key === attributes.fieldKey ) { selectedField = f; }
			} );

			var taxChoices = [ { value: '', label: __( 'Select a taxonomy…', 'uadv' ) } ];
			taxonomiesForType( postType ).forEach( function ( t ) { taxChoices.push( t ); } );

			var urlFieldChoices = [ { value: '', label: __( 'None — use Custom URL below', 'uadv' ) } ];
			fieldsForType( postType ).forEach( function ( f ) {
				if ( 'url' === f.type ) { urlFieldChoices.push( { value: f.key, label: f.label } ); }
			} );

			var sourcePanelChildren = [
				el( SelectControl, {
					key: 'source-type',
					label: __( 'Source Type', 'uadv' ),
					value: attributes.sourceType,
					options: sourceOptions,
					onChange: function ( value ) { setAttributes( { sourceType: value, fieldKey: '', taxonomy: '', relationshipPath: [] } ); }
				} )
			];

			if ( 'acf_field' === attributes.sourceType ) {
				sourcePanelChildren.push( el( SelectControl, {
					key: 'field-key',
					label: __( 'Field', 'uadv' ),
					value: attributes.fieldKey,
					options: fieldChoices,
					onChange: function ( value ) { setAttributes( { fieldKey: value } ); }
				} ) );
			} else if ( 'taxonomy' === attributes.sourceType ) {
				sourcePanelChildren.push( el( SelectControl, {
					key: 'taxonomy',
					label: __( 'Taxonomy', 'uadv' ),
					value: attributes.taxonomy,
					options: taxChoices,
					onChange: function ( value ) { setAttributes( { taxonomy: value } ); }
				} ) );
				sourcePanelChildren.push( el( ToggleControl, {
					key: 'link-terms',
					label: __( 'Link terms to their archive', 'uadv' ),
					checked: !! attributes.linkTaxonomyTerms,
					onChange: function ( value ) { setAttributes( { linkTaxonomyTerms: value } ); }
				} ) );
			} else if ( 'relationship_path' === attributes.sourceType ) {
				sourcePanelChildren.push( renderRelationshipPathPicker( postType, attributes.relationshipPath, function ( next ) { setAttributes( { relationshipPath: next } ); } ) );
			}

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Source', 'uadv' ) }, sourcePanelChildren ),
				el( PanelBody, { title: __( 'Display Options', 'uadv' ), initialOpen: false },
					el( TextControl, { label: __( 'Column Label', 'uadv' ), value: attributes.columnLabel, onChange: function ( v ) { setAttributes( { columnLabel: v } ); } } ),
					el( ToggleControl, { label: __( 'Show Label on Desktop', 'uadv' ), checked: !! attributes.showLabelDesktop, onChange: function ( v ) { setAttributes( { showLabelDesktop: v } ); } } ),
					el( ToggleControl, { label: __( 'Show Label on Mobile', 'uadv' ), checked: !! attributes.showLabelMobile, onChange: function ( v ) { setAttributes( { showLabelMobile: v } ); } } ),
					el( ToggleControl, { label: __( 'Hide Empty Value', 'uadv' ), checked: !! attributes.hideEmptyValue, onChange: function ( v ) { setAttributes( { hideEmptyValue: v } ); } } ),
					attributes.hideEmptyValue ? null : el( TextControl, { label: __( 'Empty Value Text', 'uadv' ), value: attributes.emptyValueText, placeholder: '—', onChange: function ( v ) { setAttributes( { emptyValueText: v } ); } } ),
					el( SelectControl, { label: __( 'Text Alignment', 'uadv' ), value: attributes.textAlign, options: [ { value: '', label: __( 'Default', 'uadv' ) }, { value: 'left', label: __( 'Left', 'uadv' ) }, { value: 'center', label: __( 'Center', 'uadv' ) }, { value: 'right', label: __( 'Right', 'uadv' ) } ], onChange: function ( v ) { setAttributes( { textAlign: v } ); } } ),
					el( SelectControl, { label: __( 'Vertical Alignment', 'uadv' ), value: attributes.verticalAlign, options: [ { value: '', label: __( 'Default', 'uadv' ) }, { value: 'top', label: __( 'Top', 'uadv' ) }, { value: 'middle', label: __( 'Middle', 'uadv' ) }, { value: 'bottom', label: __( 'Bottom', 'uadv' ) } ], onChange: function ( v ) { setAttributes( { verticalAlign: v } ); } } ),
					el( SelectControl, { label: __( 'Display As', 'uadv' ), value: attributes.displayAs, options: [ { value: 'plain', label: __( 'Plain Text', 'uadv' ) }, { value: 'badge', label: __( 'Badge', 'uadv' ) }, { value: 'image', label: __( 'Image', 'uadv' ) } ], onChange: function ( v ) { setAttributes( { displayAs: v } ); } } ),
					el( TextControl, { label: __( 'Prefix', 'uadv' ), value: attributes.prefix, onChange: function ( v ) { setAttributes( { prefix: v } ); } } ),
					el( TextControl, { label: __( 'Suffix', 'uadv' ), value: attributes.suffix, onChange: function ( v ) { setAttributes( { suffix: v } ); } } ),
					el( TextControl, { label: __( 'Separator (multiple values)', 'uadv' ), value: attributes.separator, onChange: function ( v ) { setAttributes( { separator: v } ); } } ),
					el( SelectControl, { label: __( 'Image Size', 'uadv' ), value: attributes.imageSize, options: IMAGE_SIZE_OPTIONS, onChange: function ( v ) { setAttributes( { imageSize: v } ); } } ),
					el( TextControl, { label: __( 'Date Format', 'uadv' ), value: attributes.dateFormat, placeholder: __( 'e.g. F j, Y', 'uadv' ), onChange: function ( v ) { setAttributes( { dateFormat: v } ); } } ),
					el( TextControl, { label: __( 'Number Decimals', 'uadv' ), type: 'number', value: attributes.numberDecimals, help: __( '-1 = automatic', 'uadv' ), onChange: function ( v ) { setAttributes( { numberDecimals: parseInt( v, 10 ) } ); } } ),
					el( TextControl, { label: __( 'True/False: "Yes" label', 'uadv' ), value: attributes.trueLabel, placeholder: __( 'Yes', 'uadv' ), onChange: function ( v ) { setAttributes( { trueLabel: v } ); } } ),
					el( TextControl, { label: __( 'True/False: "No" label', 'uadv' ), value: attributes.falseLabel, placeholder: __( 'No', 'uadv' ), onChange: function ( v ) { setAttributes( { falseLabel: v } ); } } )
				),
				el( PanelBody, { title: __( 'Link', 'uadv' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Link Destination', 'uadv' ),
						value: attributes.linkDestination,
						options: [
							{ value: 'none', label: __( 'None', 'uadv' ) },
							{ value: 'current', label: __( 'Current Record', 'uadv' ) },
							{ value: 'related', label: __( 'Related Record', 'uadv' ) },
							{ value: 'custom', label: __( 'Custom URL', 'uadv' ) }
						],
						onChange: function ( v ) { setAttributes( { linkDestination: v } ); }
					} ),
					( 'related' === attributes.linkDestination ) ? el( 'p', { className: 'uadv-help-text' }, __( 'Only applies to Post Object/Relationship fields (or a Relationship Path ending in one). Each related item gets its own link.', 'uadv' ) ) : null,
					( 'custom' === attributes.linkDestination ) ? el( SelectControl, { label: __( 'Link to Field (URL type)', 'uadv' ), value: attributes.linkCustomFieldKey, options: urlFieldChoices, onChange: function ( v ) { setAttributes( { linkCustomFieldKey: v } ); } } ) : null,
					( 'custom' === attributes.linkDestination && ! attributes.linkCustomFieldKey ) ? el( TextControl, { label: __( 'Custom URL', 'uadv' ), value: attributes.linkCustomUrl, onChange: function ( v ) { setAttributes( { linkCustomUrl: v } ); } } ) : null,
					( 'none' !== attributes.linkDestination ) ? el( SelectControl, { label: __( 'Link Target', 'uadv' ), value: attributes.linkTarget, options: [ { value: '_self', label: __( 'Same Tab', 'uadv' ) }, { value: '_blank', label: __( 'New Tab', 'uadv' ) } ], onChange: function ( v ) { setAttributes( { linkTarget: v } ); } } ) : null
				)
			);

			var metaParts = [];
			if ( 'acf_field' === attributes.sourceType ) {
				metaParts.push( selectedField ? ( selectedField.label + ' · ' + selectedField.type ) : __( 'No field selected', 'uadv' ) );
			} else if ( 'taxonomy' === attributes.sourceType ) {
				var taxLabel = __( 'No taxonomy selected', 'uadv' );
				taxonomiesForType( postType ).forEach( function ( t ) { if ( t.value === attributes.taxonomy ) { taxLabel = t.label; } } );
				metaParts.push( taxLabel );
			} else if ( 'relationship_path' === attributes.sourceType ) {
				metaParts.push( attributes.relationshipPath.length ? ( attributes.relationshipPath.length + ' ' + __( 'step(s)', 'uadv' ) ) : __( 'Not configured yet', 'uadv' ) );
			} else {
				sourceOptions.forEach( function ( o ) { if ( o.value === attributes.sourceType ) { metaParts.push( o.label ); } } );
			}

			var preview = el( Placeholder, {
				icon: 'editor-table',
				label: attributes.columnLabel || __( 'Data Field', 'uadv' ),
				instructions: metaParts.join( ' · ' )
			} );

			return el( 'div', blockProps, inspector, preview );
		},
		save: function () { return null; }
	} );

	// ---------------------------------------------------------------
	// uadv/data-link
	// ---------------------------------------------------------------
	registerBlockType( 'uadv/data-link', {
		title: __( 'Universal Data Link', 'uadv' ),
		description: __( 'A per-record action link: View Details or Edit Record.', 'uadv' ),
		icon: 'admin-links',
		category: 'widgets',
		attributes: {
			actionType: { type: 'string', default: 'view' },
			customLabel: { type: 'string', default: '' },
			icon: { type: 'string', default: '' },
			editPageUrl: { type: 'string', default: '' },
			openInNewTab: { type: 'boolean', default: false },
			columnLabel: { type: 'string', default: '' },
			showLabelDesktop: { type: 'boolean', default: true },
			showLabelMobile: { type: 'boolean', default: true },
			textAlign: { type: 'string', default: '' },
			verticalAlign: { type: 'string', default: '' }
		},
		usesContext: [ 'uadv/postType' ],
		supports: Object.assign( {}, UADV_BLOCK_SUPPORTS, {
			typography: { fontSize: true, lineHeight: true, fontWeight: true, fontStyle: true, textTransform: true, textDecoration: true }
		} ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var context = props.context || {};
			var postType = context[ 'uadv/postType' ] || '';
			var blockProps = blockPropsOf();

			if ( ! postType ) {
				return el( 'div', blockProps, el( Placeholder, {
					icon: 'admin-links',
					label: __( 'Universal Data Link', 'uadv' ),
					instructions: __( 'Place this block inside a Universal Data View block.', 'uadv' )
				} ) );
			}

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Universal Data Link settings', 'uadv' ) },
					el( SelectControl, {
						label: __( 'Action', 'uadv' ),
						value: attributes.actionType,
						options: [
							{ value: 'view', label: __( 'View Details', 'uadv' ) },
							{ value: 'edit', label: __( 'Edit Record', 'uadv' ) },
							{ value: 'custom', label: __( 'Custom Label', 'uadv' ) }
						],
						onChange: function ( v ) { setAttributes( { actionType: v } ); }
					} ),
					el( TextControl, { label: __( 'Custom Label', 'uadv' ), value: attributes.customLabel, onChange: function ( v ) { setAttributes( { customLabel: v } ); } } ),
					el( TextControl, { label: __( 'Icon (dashicon slug)', 'uadv' ), value: attributes.icon, placeholder: __( 'e.g. visibility, edit', 'uadv' ), onChange: function ( v ) { setAttributes( { icon: v } ); } } ),
					( 'edit' === attributes.actionType ) ? el( TextControl, { label: __( 'Edit Page URL', 'uadv' ), help: __( 'Page containing the edit form. Leave blank to use the current page.', 'uadv' ), value: attributes.editPageUrl, onChange: function ( v ) { setAttributes( { editPageUrl: v } ); } } ) : null,
					( 'edit' === attributes.actionType ) ? el( 'p', { className: 'uadv-help-text' }, __( 'Only shown to users who can edit that specific record.', 'uadv' ) ) : null,
					el( ToggleControl, { label: __( 'Open in New Tab', 'uadv' ), checked: !! attributes.openInNewTab, onChange: function ( v ) { setAttributes( { openInNewTab: v } ); } } )
				)
			);

			var label = attributes.customLabel || ( 'edit' === attributes.actionType ? __( 'Edit', 'uadv' ) : __( 'View Details', 'uadv' ) );

			return el( 'div', blockProps, inspector, el( Placeholder, {
				icon: 'admin-links',
				label: __( 'Data Link', 'uadv' ),
				instructions: attributes.actionType + ' · ' + label
			} ) );
		},
		save: function () { return null; }
	} );

	// ---------------------------------------------------------------
	// uadv/data-empty-message
	// ---------------------------------------------------------------
	registerBlockType( 'uadv/data-empty-message', {
		title: __( 'Universal Data Empty Message', 'uadv' ),
		description: __( 'Shown only when a Universal Data View has zero matching records.', 'uadv' ),
		icon: 'info-outline',
		category: 'widgets',
		attributes: { message: { type: 'string', default: '' } },
		supports: Object.assign( {}, UADV_BLOCK_SUPPORTS, {
			typography: { fontSize: true, lineHeight: true, fontWeight: true, fontStyle: true, textTransform: true, textDecoration: true }
		} ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockPropsOf();

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Universal Data Empty Message settings', 'uadv' ) },
					el( TextControl, {
						label: __( 'Message', 'uadv' ),
						value: attributes.message,
						placeholder: __( 'No records found.', 'uadv' ),
						onChange: function ( v ) { setAttributes( { message: v } ); }
					} )
				)
			);

			return el( 'div', blockProps, inspector, el( Placeholder, {
				icon: 'info-outline',
				label: __( 'Universal Data Empty Message', 'uadv' ),
				instructions: attributes.message || __( 'No records found.', 'uadv' )
			} ) );
		},
		save: function () { return null; }
	} );

	// ---------------------------------------------------------------
	// uadv/data-pagination
	// ---------------------------------------------------------------
	registerBlockType( 'uadv/data-pagination', {
		title: __( 'Universal Data Pagination', 'uadv' ),
		description: __( 'Previous / page numbers / Next for the parent Universal Data View.', 'uadv' ),
		icon: 'controls-forward',
		category: 'widgets',
		attributes: {
			prevLabel: { type: 'string', default: '' },
			nextLabel: { type: 'string', default: '' },
			showPageNumbers: { type: 'boolean', default: true }
		},
		supports: Object.assign( {}, UADV_BLOCK_SUPPORTS, {
			typography: { fontSize: true, lineHeight: true, fontWeight: true, fontStyle: true, textTransform: true, textDecoration: true }
		} ),
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockPropsOf();

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Universal Data Pagination settings', 'uadv' ) },
					el( TextControl, { label: __( 'Previous Label', 'uadv' ), value: attributes.prevLabel, placeholder: __( 'Previous', 'uadv' ), onChange: function ( v ) { setAttributes( { prevLabel: v } ); } } ),
					el( TextControl, { label: __( 'Next Label', 'uadv' ), value: attributes.nextLabel, placeholder: __( 'Next', 'uadv' ), onChange: function ( v ) { setAttributes( { nextLabel: v } ); } } ),
					el( ToggleControl, { label: __( 'Show Page Numbers', 'uadv' ), checked: !! attributes.showPageNumbers, onChange: function ( v ) { setAttributes( { showPageNumbers: v } ); } } )
				)
			);

			return el( 'div', blockProps, inspector, el( Placeholder, {
				icon: 'controls-forward',
				label: __( 'Universal Data Pagination', 'uadv' ),
				instructions: ( attributes.prevLabel || __( 'Previous', 'uadv' ) ) + ' · 1 2 3 · ' + ( attributes.nextLabel || __( 'Next', 'uadv' ) )
			} ) );
		},
		save: function () { return null; }
	} );

} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
JS;
		}

		// =====================================================================
		// FRONT-END ASSETS.
		//
		// FUNCTIONAL CSS ONLY — same discipline as the rest of this project's
		// snippets: table<->cards mechanics, the clickable-card overlay
		// z-index layering, and the grid column/gap custom properties. No
		// color, font, border, or shadow lives here on purpose, so nobody is
		// ever tempted to open this PHP file to restyle a listing — one
		// stray character here would break the snippet for the whole site.
		// Visual design belongs to each block's own Color/Spacing/Typography
		// Styles panel (already wired via block supports) and to "Additional
		// CSS class(es)" + the theme's own stylesheet or Appearance →
		// Customize → Additional CSS — never this file.
		//
		// The only front-end JS is a small, optional enhancement for "Make
		// Entire Record Clickable" on TABLE layout specifically: browsers
		// don't allow an <a> as a direct child of a <tr> (only <td>/<th>
		// are valid there), so the CSS-only stretched-link trick used for
		// Grid/List cards can't reach across an entire table row. This is a
		// best-effort, mouse-and-keyboard enhancement, not a replacement for
		// a real Universal Data Link block, which is fully keyboard/screen-
		// reader accessible on its own with zero JS.
		// =====================================================================

		public static function enqueue_frontend_styles() {
			$style_handle = 'uadv-frontend-style';
			wp_register_style( $style_handle, false, array(), UADV_VERSION );
			wp_add_inline_style( $style_handle, self::get_frontend_css() );
			wp_enqueue_style( $style_handle );

			$script_handle = 'uadv-frontend-clickable-row';
			wp_register_script( $script_handle, false, array(), UADV_VERSION, true );
			wp_add_inline_script( $script_handle, self::get_frontend_clickable_row_js() );
			wp_enqueue_script( $script_handle );
		}

		private static function get_frontend_clickable_row_js() {
			return <<<'JS'
( function () {
	function activate( tr ) {
		var url = tr.getAttribute( 'data-record-url' );
		if ( url ) {
			window.location.assign( url );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		// Never hijack a click meant for a real interactive element inside
		// the row (a Universal Data Link, a "Related Record" link, etc.).
		if ( event.target.closest( 'a, button, input, select, textarea, label' ) ) {
			return;
		}
		var tr = event.target.closest( 'tr[data-record-url]' );
		if ( tr ) {
			activate( tr );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}
		var tr = event.target.closest( 'tr[data-record-url]' );
		if ( tr && event.target === tr ) {
			event.preventDefault();
			activate( tr );
		}
	} );
} )();
JS;
		}

		private static function get_frontend_css() {
			return <<<'CSS'
.uadv-table { width: 100%; border-collapse: collapse; }
.uadv-grid { display: grid; grid-template-columns: repeat(var(--uadv-grid-cols-desktop, 3), 1fr); gap: var(--uadv-gap, 16px); }
@media (max-width: 1024px) and (min-width: 783px) {
	.uadv-grid { grid-template-columns: repeat(var(--uadv-grid-cols-tablet, 2), 1fr); }
}
.uadv-card { position: relative; min-width: 0; }
.uadv-card-link-overlay { position: absolute; inset: 0; z-index: 1; }
.uadv-card .uadv-field-value a,
.uadv-card .uadv-record-link { position: relative; z-index: 2; }
.uadv-field-slot { min-width: 0; }
.uadv-grid .uadv-field-slot[data-label]::before { content: attr(data-label) ": "; font-weight: 600; }
.uadv-row[data-record-url] { cursor: pointer; }
.uadv-pagination-list { display: flex; list-style: none; margin: 0; padding: 0; gap: 4px; flex-wrap: wrap; }
CSS;
		}

	}

} // class_exists

if ( ! has_action( 'plugins_loaded', array( 'UADV_System', 'init' ) ) ) {
	add_action( 'plugins_loaded', array( 'UADV_System', 'init' ), 20 );
}
