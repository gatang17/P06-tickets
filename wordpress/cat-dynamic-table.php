<?php
/**
 * CAT — Dynamic Data Table
 *
 * Registers one server-rendered Gutenberg block and one reusable block pattern.
 * Presentation styles intentionally live in the WordPress Customizer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CAT_Dynamic_Data_Table' ) ) {
	final class CAT_Dynamic_Data_Table {
		const BLOCK_NAME = 'cat/dynamic-table';
		const VERSION    = '1.1.0';

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_block' ), 30 );
			add_action( 'init', array( __CLASS__, 'register_pattern' ), 40 );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ), 30 );
			add_action( 'wp_footer', array( __CLASS__, 'render_row_navigation_script' ), 40 );
		}

		public static function register_block() {
			register_block_type(
				self::BLOCK_NAME,
				array(
					'api_version'     => 2,
					'render_callback' => array( __CLASS__, 'render_block' ),
					'attributes'      => array(
						'postType'       => array( 'type' => 'string', 'default' => 'equipment' ),
						'fields'         => array( 'type' => 'array', 'default' => array( 'post_title' ) ),
						'postsPerPage'   => array( 'type' => 'number', 'default' => 20 ),
						'orderBy'        => array( 'type' => 'string', 'default' => 'title' ),
						'order'          => array( 'type' => 'string', 'default' => 'ASC' ),
						'rowAction'      => array( 'type' => 'string', 'default' => 'view' ),
						'emptyMessage'   => array( 'type' => 'string', 'default' => 'No records found.' ),
						'showTableHead'  => array( 'type' => 'boolean', 'default' => true ),
					),
				)
			);
		}

		/**
		 * Gutenberg already loads wp-blocks in the editor. Attaching our block
		 * registration to that real handle avoids the empty-script URL that made
		 * the block invisible in the inserter on some WordPress installations.
		 */
		public static function enqueue_editor_assets() {
			$post_types = self::get_post_type_settings();
			$fields     = array();

			foreach ( array_keys( $post_types ) as $post_type ) {
				$fields[ $post_type ] = self::get_fields_for_post_type( $post_type );
			}

			wp_enqueue_script( 'wp-blocks' );
			wp_enqueue_script( 'wp-element' );
			wp_enqueue_script( 'wp-block-editor' );
			wp_enqueue_script( 'wp-components' );
			wp_enqueue_script( 'wp-i18n' );
			wp_enqueue_script( 'wp-server-side-render' );

			wp_localize_script(
				'wp-server-side-render',
				'CATDynamicTableData',
				array(
					'postTypes' => $post_types,
					'fields'    => $fields,
				)
			);

			wp_add_inline_script( 'wp-server-side-render', self::get_editor_script() );
		}

		public static function register_pattern() {
			if ( ! function_exists( 'register_block_pattern' ) ) {
				return;
			}

			register_block_pattern_category(
				'cat-inventory',
				array( 'label' => 'CAT Inventory' )
			);

			register_block_pattern(
				'cat-inventory/dynamic-data-table',
				array(
					'title'       => 'CAT — Dynamic Data Table',
					'description' => 'A reusable table whose content type and columns are selected in the block sidebar.',
					'categories'  => array( 'cat-inventory' ),
					'content'     => '<!-- wp:cat/dynamic-table {"postType":"equipment","fields":["post_title"],"postsPerPage":20,"rowAction":"view"} /-->',
				)
			);
		}

		private static function get_post_type_settings() {
			$excluded = array(
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
			$objects  = get_post_types( array( 'show_ui' => true ), 'objects' );
			$options  = array();

			foreach ( $objects as $slug => $object ) {
				if ( in_array( $slug, $excluded, true ) ) {
					continue;
				}

				$options[ $slug ] = array(
					'label' => $object->labels->name,
					'slug'  => $slug,
				);
			}

			uasort(
				$options,
				function ( $a, $b ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
			);

			return $options;
		}

		private static function get_fields_for_post_type( $post_type ) {
			$fields = array(
				'post_title' => array( 'key' => 'post_title', 'label' => 'Title', 'type' => 'native' ),
				'post_id'    => array( 'key' => 'post_id', 'label' => 'ID', 'type' => 'native' ),
				'publish_date' => array( 'key' => 'publish_date', 'label' => 'Published', 'type' => 'native' ),
				'modified_date' => array( 'key' => 'modified_date', 'label' => 'Modified', 'type' => 'native' ),
				'author' => array( 'key' => 'author', 'label' => 'Author', 'type' => 'native' ),
				'featured_image' => array( 'key' => 'featured_image', 'label' => 'Image', 'type' => 'native' ),
			);

			$taxonomies = get_object_taxonomies( $post_type, 'objects' );
			foreach ( $taxonomies as $taxonomy ) {
				if ( ! $taxonomy->show_ui ) {
					continue;
				}
				$key            = 'tax:' . $taxonomy->name;
				$fields[ $key ] = array(
					'key'   => $key,
					'label' => $taxonomy->labels->singular_name,
					'type'  => 'taxonomy',
					'name'  => $taxonomy->name,
				);
			}

			if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
				$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );

				foreach ( $groups as $group ) {
					$group_fields = acf_get_fields( $group );
					if ( ! is_array( $group_fields ) ) {
						continue;
					}

					foreach ( $group_fields as $field ) {
						if (
							empty( $field['name'] ) ||
							in_array( $field['type'], array( 'tab', 'accordion', 'message', 'clone', 'group', 'repeater', 'flexible_content' ), true )
						) {
							continue;
						}

						$key            = 'acf:' . $field['name'];
						$fields[ $key ] = array(
							'key'   => $key,
							'name'  => $field['name'],
							'label' => $field['label'] ? $field['label'] : $field['name'],
							'type'  => $field['type'],
						);
					}
				}
			}

			return $fields;
		}

		public static function render_block( $attributes ) {
			$preset_key = self::get_requested_preset();
			$preset     = $preset_key ? self::get_view_presets()[ $preset_key ] : array();
			$post_type  = $preset
				? $preset['post_type']
				: ( isset( $attributes['postType'] ) ? sanitize_key( $attributes['postType'] ) : 'equipment' );
			$object    = get_post_type_object( $post_type );

			if ( ! $object || ! $object->show_ui ) {
				return '<div class="cat-data-table__empty">Select a valid content type.</div>';
			}

			$available_fields = self::get_fields_for_post_type( $post_type );
			$requested_fields = $preset
				? $preset['fields']
				: ( isset( $attributes['fields'] ) && is_array( $attributes['fields'] ) ? $attributes['fields'] : array( 'post_title' ) );
			$selected_fields  = array_values( array_intersect( $requested_fields, array_keys( $available_fields ) ) );

			if ( empty( $selected_fields ) ) {
				$selected_fields = array( 'post_title' );
			}

			$allowed_orderby = array( 'title', 'date', 'modified', 'ID', 'menu_order' );
			$requested_orderby = $preset && ! empty( $preset['order_by'] ) ? $preset['order_by'] : ( $attributes['orderBy'] ?? 'title' );
			$order_by        = in_array( $requested_orderby, $allowed_orderby, true )
				? $requested_orderby
				: 'title';
			$requested_order = $preset && ! empty( $preset['order'] ) ? $preset['order'] : ( $attributes['order'] ?? 'ASC' );
			$order           = 'DESC' === strtoupper( $requested_order ) ? 'DESC' : 'ASC';
			$posts_per_page  = $preset && ! empty( $preset['posts_per_page'] )
				? absint( $preset['posts_per_page'] )
				: ( isset( $attributes['postsPerPage'] ) ? absint( $attributes['postsPerPage'] ) : 20 );
			$posts_per_page  = max( 1, min( 100, $posts_per_page ) );

			$query = new WP_Query(
				array(
					'post_type'           => $post_type,
					'post_status'         => 'publish',
					'posts_per_page'      => $posts_per_page,
					'orderby'             => $order_by,
					'order'               => $order,
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);

			$empty_message = $preset && ! empty( $preset['empty_message'] )
				? $preset['empty_message']
				: ( isset( $attributes['emptyMessage'] ) && trim( $attributes['emptyMessage'] )
				? sanitize_text_field( $attributes['emptyMessage'] )
				: 'No records found.' );

			if ( ! $query->have_posts() ) {
				$html = $preset ? self::render_view_header( $preset_key, $preset ) : '';
				return $html . '<div class="cat-data-table__empty">' . esc_html( $empty_message ) . '</div>';
			}

			$row_action = $preset && ! empty( $preset['row_action'] )
				? $preset['row_action']
				: ( isset( $attributes['rowAction'] ) ? sanitize_key( $attributes['rowAction'] ) : 'view' );
			$show_head  = ! isset( $attributes['showTableHead'] ) || (bool) $attributes['showTableHead'];
			$block_id   = wp_unique_id( 'cat-data-table-' );
			$html       = $preset ? self::render_view_header( $preset_key, $preset ) : '';
			$html      .= '<div id="' . esc_attr( $block_id ) . '" class="cat-data-table-wrap">';
			$html      .= '<div class="cat-data-table-scroll"><table class="cat-data-table">';

			if ( $show_head ) {
				$html .= '<thead><tr>';
				foreach ( $selected_fields as $field_key ) {
					$html .= '<th scope="col">' . esc_html( $available_fields[ $field_key ]['label'] ) . '</th>';
				}
				$html .= '</tr></thead>';
			}

			$html .= '<tbody>';
			foreach ( $query->posts as $post ) {
				$url = self::build_row_url( $row_action, $post, $post_type );

				$row_attributes = '';
				if ( $url ) {
					$row_attributes = ' class="cat-data-row cat-data-row--clickable" data-cat-row-url="' . esc_url( $url ) . '" tabindex="0" role="link"';
				}

				$html .= '<tr' . $row_attributes . '>';
				foreach ( $selected_fields as $field_key ) {
					$field = $available_fields[ $field_key ];
					$value = self::render_field_value( $post, $field );
					$html .= '<td data-label="' . esc_attr( $field['label'] ) . '">' . $value . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div></div>';

			wp_reset_postdata();
			return $html;
		}

		/**
		 * 'view'   -> the post type's own public permalink. Only produces a
		 *             working link for post types that are publicly_queryable
		 *             (e.g. equipment) - useless for the many internal CPTs
		 *             here (loan, sale, maintenance_order, ...) that are
		 *             intentionally not public.
		 * 'edit'   -> the wp-admin post editor, gated by edit_post capability.
		 * 'manage' -> the front-end cat/universal-acf-form page in edit mode
		 *             (?entity=<post_type>&record_id=<id>), so clicking a row
		 *             opens the same Update/Delete form used everywhere else
		 *             on the front end - works for every post type regardless
		 *             of whether it's publicly_queryable, since it's a plain
		 *             query-string URL, not a permalink.
		 * 'none'   -> row stays non-clickable.
		 */
		private static function build_row_url( $row_action, $post, $post_type ) {
			if ( 'view' === $row_action ) {
				return get_permalink( $post );
			}

			if ( 'edit' === $row_action && current_user_can( 'edit_post', $post->ID ) ) {
				return get_edit_post_link( $post->ID, 'raw' );
			}

			if ( 'manage' === $row_action && current_user_can( 'edit_post', $post->ID ) ) {
				return self::build_manage_url( $post_type, $post->ID );
			}

			return '';
		}

		/**
		 * Filterable so a project can point this at a differently-named page,
		 * or use different query-string parameter names, without editing
		 * this file. Defaults match cat-universal-acf-form-block.php's own
		 * defaults (entityParameter: "entity", recordParameter: "record_id")
		 * and this project's actual /manage-record/ page.
		 */
		private static function build_manage_url( $post_type, $post_id ) {
			$manage_page_url = apply_filters( 'cat_dynamic_table_manage_url', home_url( '/manage-record/' ) );
			$entity_param    = apply_filters( 'cat_dynamic_table_entity_param', 'entity' );
			$record_param    = apply_filters( 'cat_dynamic_table_record_param', 'record_id' );

			return add_query_arg(
				array(
					$entity_param => $post_type,
					$record_param => $post_id,
				),
				$manage_page_url
			);
		}

		private static function get_requested_preset() {
			if ( is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
				return '';
			}

			$requested = isset( $_GET['cat_view'] ) ? sanitize_key( wp_unslash( $_GET['cat_view'] ) ) : '';
			$presets   = self::get_view_presets();

			if ( ! $requested ) {
				$requested = 'printers';
			}

			return isset( $presets[ $requested ] ) ? $requested : 'printers';
		}

		private static function get_view_presets() {
			return array(
				'printers' => array(
					'label'          => 'Printers',
					'description'    => 'Printer units, locations, workstations, and current operating status.',
					'post_type'      => 'equipment',
					'fields'         => array( 'post_title', 'acf:inventory_code', 'acf:equipment_model', 'tax:equipment_status', 'acf:current_location', 'acf:current_workstation', 'acf:serial_number' ),
					'order_by'       => 'title',
					'order'          => 'ASC',
					'posts_per_page' => 100,
					'row_action'     => 'manage',
					'empty_message'  => 'No printer records found.',
				),
				'alerts' => array(
					'label'          => 'Notifications',
					'description'    => 'Maintenance records and items that require review or follow-up.',
					'post_type'      => 'maintenance_order',
					'fields'         => array( 'post_title', 'acf:equipment', 'tax:maintenance_type', 'tax:maintenance_priority', 'tax:maintenance_status', 'acf:reported_at', 'acf:scheduled_at' ),
					'order_by'       => 'modified',
					'order'          => 'DESC',
					'posts_per_page' => 100,
					'row_action'     => 'manage',
					'empty_message'  => 'No notifications found.',
				),
				'loans' => array(
					'label'          => 'Loans',
					'description'    => 'Current and historical loans, borrowers, and expected return dates.',
					'post_type'      => 'loan',
					'fields'         => array( 'post_title', 'acf:borrower', 'acf:start_at', 'acf:expected_return_at', 'acf:returned_at', 'acf:loan_status', 'acf:loaned_by' ),
					'order_by'       => 'modified',
					'order'          => 'DESC',
					'posts_per_page' => 100,
					'row_action'     => 'manage',
					'empty_message'  => 'No loan records found.',
				),
				'sales' => array(
					'label'          => 'Sales',
					'description'    => 'Sales activity, totals, balances, and payment status.',
					'post_type'      => 'sale',
					'fields'         => array( 'post_title', 'acf:buyer', 'acf:sold_at', 'acf:total_amount', 'acf:amount_paid', 'acf:balance_due', 'acf:payment_status' ),
					'order_by'       => 'date',
					'order'          => 'DESC',
					'posts_per_page' => 100,
					'row_action'     => 'manage',
					'empty_message'  => 'No sales records found.',
				),
			);
		}

		private static function render_view_header( $active_key, $active_preset ) {
			$base_url = remove_query_arg( 'cat_view' );
			$html     = '<header class="cat-data-view-header">';
			$html    .= '<div class="cat-data-view-heading">';
			$html    .= '<p class="cat-data-view-eyebrow">Inventory records</p>';
			$html    .= '<h1 class="cat-data-view-title">' . esc_html( $active_preset['label'] ) . '</h1>';
			$html    .= '<p class="cat-data-view-description">' . esc_html( $active_preset['description'] ) . '</p>';
			$html    .= '</div><nav class="cat-data-view-tabs" aria-label="Record type">';

			foreach ( self::get_view_presets() as $key => $preset ) {
				$url    = add_query_arg( 'cat_view', $key, $base_url );
				$class  = 'cat-data-view-tab' . ( $active_key === $key ? ' is-active' : '' );
				$current = $active_key === $key ? ' aria-current="page"' : '';
				$html  .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $preset['label'] ) . '</a>';
			}

			$html .= '</nav></header>';
			return $html;
		}

		private static function render_field_value( $post, $field ) {
			$key = $field['key'];

			switch ( $key ) {
				case 'post_title':
					return esc_html( get_the_title( $post ) );
				case 'post_id':
					return esc_html( (string) $post->ID );
				case 'publish_date':
					return esc_html( get_the_date( get_option( 'date_format' ), $post ) );
				case 'modified_date':
					return esc_html( get_the_modified_date( get_option( 'date_format' ), $post ) );
				case 'author':
					return esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) );
				case 'featured_image':
					$image = get_the_post_thumbnail( $post, 'thumbnail', array( 'class' => 'cat-data-table__image' ) );
					return $image ? $image : '<span aria-hidden="true">—</span>';
			}

			if ( 'taxonomy' === $field['type'] ) {
				$terms = get_the_terms( $post, $field['name'] );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					return '<span aria-hidden="true">—</span>';
				}
				return esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
			}

			$value = function_exists( 'get_field' )
				? get_field( $field['name'], $post->ID )
				: get_post_meta( $post->ID, $field['name'], true );

			if ( 'image' === $field['type'] ) {
				return self::render_image_value( $value );
			}

			if ( in_array( $field['type'], array( 'post_object', 'relationship' ), true ) ) {
				return self::render_related_posts( $value );
			}

			if ( 'user' === $field['type'] ) {
				return self::render_user_value( $value );
			}

			if ( 'true_false' === $field['type'] ) {
				return $value ? 'Yes' : 'No';
			}

			if ( 'select' === $field['type'] ) {
				return self::render_select_value( $field['name'], $post->ID, $value );
			}

			if ( is_array( $value ) ) {
				$value = array_filter(
					array_map(
						function ( $item ) {
							if ( is_scalar( $item ) ) {
								return (string) $item;
							}
							if ( is_object( $item ) && isset( $item->post_title ) ) {
								return $item->post_title;
							}
							return '';
						},
						$value
					)
				);
				$value = implode( ', ', $value );
			}

			if ( is_object( $value ) ) {
				$value = isset( $value->post_title ) ? $value->post_title : '';
			}

			if ( '' === (string) $value ) {
				return '<span aria-hidden="true">—</span>';
			}

			if ( 'url' === $field['type'] ) {
				return '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
			}

			if ( 'email' === $field['type'] ) {
				return '<a href="mailto:' . esc_attr( antispambot( $value ) ) . '">' . esc_html( antispambot( $value ) ) . '</a>';
			}

			return esc_html( (string) $value );
		}

		private static function render_image_value( $value ) {
			if ( is_array( $value ) && ! empty( $value['ID'] ) ) {
				return wp_get_attachment_image( (int) $value['ID'], 'thumbnail', false, array( 'class' => 'cat-data-table__image' ) );
			}
			if ( is_numeric( $value ) ) {
				return wp_get_attachment_image( (int) $value, 'thumbnail', false, array( 'class' => 'cat-data-table__image' ) );
			}
			if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return '<img class="cat-data-table__image" src="' . esc_url( $value ) . '" alt="">';
			}
			return '<span aria-hidden="true">—</span>';
		}

		private static function render_related_posts( $value ) {
			$items = is_array( $value ) ? $value : array( $value );
			$names = array();

			foreach ( $items as $item ) {
				$id = is_object( $item ) && isset( $item->ID ) ? $item->ID : absint( $item );
				if ( $id ) {
					$names[] = get_the_title( $id );
				}
			}

			return $names ? esc_html( implode( ', ', $names ) ) : '<span aria-hidden="true">—</span>';
		}

		private static function render_user_value( $value ) {
			$id   = is_object( $value ) && isset( $value->ID ) ? $value->ID : absint( $value );
			$user = $id ? get_userdata( $id ) : false;
			return $user ? esc_html( $user->display_name ) : '<span aria-hidden="true">—</span>';
		}

		/**
		 * ACF 'select' fields in this project use return_format => 'value',
		 * so get_field() returns the raw stored slug (e.g. "open") rather
		 * than its human label ("Open"). get_field_object() exposes the
		 * field's own 'choices' map so the table can show the label instead,
		 * without changing the field's return_format (other code may rely on
		 * getting the raw value).
		 */
		private static function render_select_value( $field_name, $post_id, $value ) {
			if ( '' === (string) $value ) {
				return '<span aria-hidden="true">—</span>';
			}

			if ( function_exists( 'get_field_object' ) ) {
				$field_object = get_field_object( $field_name, $post_id );
				if ( $field_object && isset( $field_object['choices'][ $value ] ) ) {
					return esc_html( $field_object['choices'][ $value ] );
				}
			}

			return esc_html( (string) $value );
		}

		public static function render_row_navigation_script() {
			echo <<<'HTML'
			<script id="cat-data-table-row-navigation">
			(function () {
				function openRow(row) {
					var url = row && row.getAttribute('data-cat-row-url');
					if (url) { window.location.href = url; }
				}
				document.addEventListener('click', function (event) {
					if (event.target.closest('a, button, input, select, textarea')) { return; }
					var row = event.target.closest('.cat-data-row[data-cat-row-url]');
					if (row) { openRow(row); }
				});
				document.addEventListener('keydown', function (event) {
					if (event.key !== 'Enter' && event.key !== ' ') { return; }
					var row = event.target.closest('.cat-data-row[data-cat-row-url]');
					if (row) {
						event.preventDefault();
						openRow(row);
					}
				});
			}());
			</script>
			HTML;
		}

		private static function get_editor_script() {
			return <<<'JS'
(function (wp, config) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
	var __ = wp.i18n.__;

	function postTypeOptions() {
		return Object.keys(config.postTypes || {}).map(function (slug) {
			return { label: config.postTypes[slug].label, value: slug };
		});
	}

	registerBlockType('cat/dynamic-table', {
		apiVersion: 2,
		title: __('CAT Dynamic Data Table', 'cat'),
		description: __('Displays records from the selected content type using the selected columns.', 'cat'),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			postType: { type: 'string', default: 'equipment' },
			fields: { type: 'array', default: ['post_title'] },
			postsPerPage: { type: 'number', default: 20 },
			orderBy: { type: 'string', default: 'title' },
			order: { type: 'string', default: 'ASC' },
			rowAction: { type: 'string', default: 'view' },
			emptyMessage: { type: 'string', default: 'No records found.' },
			showTableHead: { type: 'boolean', default: true }
		},
		edit: function (props) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;
			var available = config.fields[attrs.postType] || {};
			var fieldKeys = Object.keys(available);

			function setPostType(nextType) {
				var nextFields = config.fields[nextType] || {};
				var kept = (attrs.fields || []).filter(function (key) { return !!nextFields[key]; });
				setAttributes({ postType: nextType, fields: kept.length ? kept : ['post_title'] });
			}

			function toggleField(key, checked) {
				var current = (attrs.fields || []).slice();
				if (checked && current.indexOf(key) === -1) { current.push(key); }
				if (!checked) { current = current.filter(function (item) { return item !== key; }); }
				setAttributes({ fields: current.length ? current : ['post_title'] });
			}

			var fieldControls = fieldKeys.map(function (key) {
				return el(CheckboxControl, {
					key: key,
					label: available[key].label,
					checked: (attrs.fields || []).indexOf(key) !== -1,
					onChange: function (checked) { toggleField(key, checked); }
				});
			});

			return el(Fragment, {},
				el(InspectorControls, {},
					el(PanelBody, { title: __('Data Source', 'cat'), initialOpen: true },
						el(SelectControl, {
							label: __('Content Type', 'cat'),
							value: attrs.postType,
							options: postTypeOptions(),
							onChange: setPostType
						}),
						el(RangeControl, {
							label: __('Number of records', 'cat'),
							value: attrs.postsPerPage,
							min: 1,
							max: 100,
							onChange: function (value) { setAttributes({ postsPerPage: value }); }
						})
					),
					el(PanelBody, { title: __('Columns', 'cat'), initialOpen: true }, fieldControls),
					el(PanelBody, { title: __('Ordering and behavior', 'cat'), initialOpen: false },
						el(SelectControl, {
							label: __('Order by', 'cat'),
							value: attrs.orderBy,
							options: [
								{ label: __('Title', 'cat'), value: 'title' },
								{ label: __('Published date', 'cat'), value: 'date' },
								{ label: __('Modified date', 'cat'), value: 'modified' },
								{ label: __('ID', 'cat'), value: 'ID' },
								{ label: __('Menu order', 'cat'), value: 'menu_order' }
							],
							onChange: function (value) { setAttributes({ orderBy: value }); }
						}),
						el(SelectControl, {
							label: __('Direction', 'cat'),
							value: attrs.order,
							options: [
								{ label: __('Ascending', 'cat'), value: 'ASC' },
								{ label: __('Descending', 'cat'), value: 'DESC' }
							],
							onChange: function (value) { setAttributes({ order: value }); }
						}),
						el(SelectControl, {
							label: __('Row action', 'cat'),
							value: attrs.rowAction,
							options: [
								{ label: __('None', 'cat'), value: 'none' },
								{ label: __('View record (public permalink)', 'cat'), value: 'view' },
								{ label: __('Edit in wp-admin', 'cat'), value: 'edit' },
								{ label: __('Manage on the front end (Update/Delete)', 'cat'), value: 'manage' }
							],
							onChange: function (value) { setAttributes({ rowAction: value }); }
						}),
						el(ToggleControl, {
							label: __('Show column headings', 'cat'),
							checked: attrs.showTableHead,
							onChange: function (value) { setAttributes({ showTableHead: value }); }
						}),
						el(TextControl, {
							label: __('Empty message', 'cat'),
							value: attrs.emptyMessage,
							onChange: function (value) { setAttributes({ emptyMessage: value }); }
						})
					)
				),
				el('div', useBlockProps({ className: 'cat-dynamic-table-editor' }),
					el(ServerSideRender, { block: 'cat/dynamic-table', attributes: attrs })
				)
			);
		},
		save: function () { return null; }
	});
}(window.wp, window.CATDynamicTableData || {}));
JS;
		}
	}
}

CAT_Dynamic_Data_Table::init();
