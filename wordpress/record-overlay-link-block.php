<?php
/**
 * Record Overlay Link — a single, fully independent Gutenberg dynamic
 * block: an invisible link that covers only its nearest positioned
 * container and opens that record's own page when clicked.
 *
 * THIS IS A COMPLETELY SEPARATE, STANDALONE SNIPPET. It has no
 * dependency on Universal ACF Form, on Universal Data View, or on ACF
 * itself, and no Custom Post Type is ever hardcoded anywhere in this
 * file. It registers with no 'parent' and no 'ancestor', so it is
 * insertable from the general block inserter into ANY container:
 *
 *   - core/group (and its Row/Stack variations)
 *   - core/columns / core/column
 *   - core Query Loop's Post Template
 *   - a Universal Data View record layout (optional integration, see
 *     below — Universal Data View is not required for this block to
 *     register or to work)
 *   - any WordPress block template
 *   - directly inside a single post/page
 *
 * RECORD ID RESOLUTION (see resolve_overlay_record_id()), in order:
 *   1. uadv/recordId  — Universal Data View's own per-record context,
 *                        used ONLY if that system happens to be active
 *                        and this block happens to be nested inside one
 *                        of its repeated record layouts.
 *   2. postId         — core Gutenberg Query Loop / Post Template
 *                        context.
 *   3. get_the_ID()   — the global "current post" (a single post/page,
 *                        or anywhere inside The Loop).
 *
 * BLOCK: record-overlay/link
 *
 * IMPORTANT — HOW TO INSTALL WITH THE "CODE SNIPPETS" PLUGIN:
 *   1. Copy the ENTIRE contents of this file.
 *   2. In Code Snippets → Add New, paste it and REMOVE the first line
 *      "<?php" (Code Snippets already treats the editor as PHP).
 *   3. Set "Run snippet everywhere" and activate. This can run
 *      completely on its own, or alongside the Universal ACF Form /
 *      Universal Data View snippets — none of the three depend on
 *      each other to register or function.
 *
 * No ACF, Composer, Node.js, npm, CDN, or build step required.
 * Requirements: WordPress with Gutenberg, PHP 8.1+.
 */

// =============================================================================
// SECTION 1 — CHECKS AND CONSTANTS
// =============================================================================

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ROL_VERSION' ) ) {
	define( 'ROL_VERSION', '1.0.0' );
}

// =============================================================================
// MAIN CLASS
// =============================================================================

if ( ! class_exists( 'ROL_System' ) ) {

	final class ROL_System {

		// =====================================================================
		// BOOTSTRAP
		// =====================================================================

		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_blocks' ) );
			add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
		}

		// =====================================================================
		// SECURITY — same readability gate used by every other "link to a
		// record" feature in this project: a published post is always
		// readable, anything else only if the current visitor has
		// read_post capability for that specific post. Never assumes a
		// specific CPT or post status list.
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

		// =====================================================================
		// RECORD ID RESOLUTION
		// =====================================================================

		/**
		 * Resolves "the current record" id in priority order:
		 *   1. uadv/recordId — only present if this block happens to be
		 *      nested inside a Universal Data View record layout; this
		 *      file never requires that system to exist for this to work.
		 *   2. postId        — core Query Loop / Post Template context.
		 *   3. get_the_ID()  — the global current post (a single post/
		 *      page, or anywhere inside The Loop).
		 * When a post-type context (uadv/postType or postType) accompanies
		 * the id, the resolved post's actual type is cross-checked against
		 * it — defensive only. No Custom Post Type name appears anywhere
		 * in this function.
		 *
		 * @return int 0 when no valid record id is available.
		 */
		private static function resolve_overlay_record_id( $block ) {
			$context = ( isset( $block->context ) && is_array( $block->context ) ) ? $block->context : array();

			if ( ! empty( $context['uadv/recordId'] ) ) {
				$record_id = absint( $context['uadv/recordId'] );
				if ( $record_id > 0 ) {
					return self::validate_overlay_post_type( $record_id, isset( $context['uadv/postType'] ) ? $context['uadv/postType'] : '' );
				}
			}

			if ( ! empty( $context['postId'] ) ) {
				$record_id = absint( $context['postId'] );
				if ( $record_id > 0 ) {
					return self::validate_overlay_post_type( $record_id, isset( $context['postType'] ) ? $context['postType'] : '' );
				}
			}

			$fallback = get_the_ID();
			return $fallback ? absint( $fallback ) : 0;
		}

		private static function validate_overlay_post_type( $record_id, $expected_post_type ) {
			$expected_post_type = sanitize_key( (string) $expected_post_type );
			if ( '' === $expected_post_type ) {
				return $record_id;
			}
			return ( get_post_type( $record_id ) === $expected_post_type ) ? $record_id : 0;
		}

		// =====================================================================
		// STATIC EDITOR/REST PREVIEW — never touches a real query.
		// =====================================================================

		private static function static_preview_markup( $title, $meta ) {
			return sprintf(
				'<div class="rol-static-preview"><p class="rol-static-preview-title">%s</p><p class="rol-static-preview-meta">%s</p></div>',
				esc_html( $title ),
				esc_html( $meta )
			);
		}

		// =====================================================================
		// RENDER CALLBACK
		// =====================================================================

		/**
		 * Renders a single invisible <a> — never a wrapper around the
		 * whole container, so other links/buttons inside it are never
		 * nested inside another <a>. Positioning (covering only the
		 * nearest positioned ancestor) is done entirely by CSS in
		 * get_frontend_css() against the .record-overlay-link class; no
		 * JS, no onclick, ever.
		 */
		public static function render_record_overlay_link_block( $attributes, $content, $block ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return self::static_preview_markup( __( 'Record Overlay Link', 'rol' ), __( 'Invisible link over its nearest positioned container.', 'rol' ) );
			}

			$record_id = self::resolve_overlay_record_id( $block );
			if ( $record_id <= 0 ) {
				return '';
			}

			$post = get_post( $record_id );
			if ( ! $post ) {
				return '';
			}

			if ( ! self::post_is_readable( $record_id ) ) {
				return '';
			}

			$href = get_permalink( $post );
			if ( ! $href ) {
				return '';
			}

			$classes = array( 'record-overlay-link' );
			if ( ! empty( $attributes['overlayClass'] ) && is_string( $attributes['overlayClass'] ) ) {
				foreach ( preg_split( '/\s+/', trim( $attributes['overlayClass'] ) ) as $token ) {
					$token = sanitize_html_class( $token );
					if ( '' !== $token ) {
						$classes[] = $token;
					}
				}
			}

			$label = ( isset( $attributes['accessibleLabel'] ) && '' !== trim( (string) $attributes['accessibleLabel'] ) )
				? $attributes['accessibleLabel']
				: sprintf( __( 'View %s', 'rol' ), get_the_title( $post ) );

			$target_attr = ! empty( $attributes['openInNewTab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';

			return '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" href="' . esc_url( $href ) . '" aria-label="' . esc_attr( $label ) . '"' . $target_attr . '></a>';
		}

		// =====================================================================
		// BLOCK REGISTRATION — no 'parent', no 'ancestor': registers as a
		// fully independent block usable anywhere the block editor allows.
		// =====================================================================

		public static function register_blocks() {
			if ( ! function_exists( 'register_block_type' ) ) {
				return;
			}

			register_block_type( 'record-overlay/link', array(
				'attributes'      => array(
					'openInNewTab'    => array( 'type' => 'boolean', 'default' => false ),
					'accessibleLabel' => array( 'type' => 'string', 'default' => '' ),
					'overlayClass'    => array( 'type' => 'string', 'default' => '' ),
				),
				'uses_context'    => array( 'uadv/recordId', 'uadv/postType', 'postId', 'postType' ),
				// No spacing/color/typography supports on purpose: this
				// block must never add height, margin, padding, visible
				// text, background or color of its own. 'className' is
				// off too, so the editor doesn't offer a second, redundant
				// "Additional CSS Class(es)" field next to the block's own
				// dedicated Overlay Class control.
				'supports'        => array(
					'className'       => false,
					'customClassName' => false,
					'html'            => false,
					'anchor'          => false,
				),
				'render_callback' => array( __CLASS__, 'render_record_overlay_link_block' ),
			) );
		}

		// =====================================================================
		// EDITOR JAVASCRIPT — no ServerSideRender, no real query anywhere in
		// the editor: the block shows a static, non-interactive Placeholder
		// card. Loaded ONLY in the block editor, never on the front-end.
		// =====================================================================

		public static function enqueue_editor_assets() {
			$handle = 'rol-block-editor';

			wp_register_script(
				$handle,
				false,
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				ROL_VERSION,
				true
			);

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
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var Placeholder = components.Placeholder;

	function blockPropsOf( extra ) {
		return useBlockProps ? useBlockProps( extra || {} ) : ( extra || {} );
	}

	// ---------------------------------------------------------------
	// record-overlay/link — INDEPENDENT block. No parent, no ancestor:
	// insertable from the general inserter into ANY block. Declares
	// usesContext for both Universal Data View's own per-record
	// context (used only if that system is present) and core Query
	// Loop/Post Template's standard context, so it resolves correctly
	// wherever it ends up — see resolve_overlay_record_id() on the PHP
	// side for the full fallback order, down to get_the_ID(). In the
	// editor it is always a plain, normal-flow, selectable Placeholder
	// card — never absolutely positioned, never covering anything — so
	// it can always be selected, moved and deleted like any other
	// block; the overlay positioning only exists in the front-end CSS.
	// ---------------------------------------------------------------
	registerBlockType( 'record-overlay/link', {
		title: __( 'Record Overlay Link', 'rol' ),
		description: __( 'An invisible link that covers only its nearest positioned container and opens that record’s page. Works inside a Query Loop, a single post/page, a Universal Data View record layout if present, or any other block — no ACF or specific content type required.', 'rol' ),
		icon: 'move',
		category: 'widgets',
		attributes: {
			openInNewTab: { type: 'boolean', default: false },
			accessibleLabel: { type: 'string', default: '' },
			overlayClass: { type: 'string', default: '' }
		},
		usesContext: [ 'uadv/recordId', 'uadv/postType', 'postId', 'postType' ],
		supports: {
			className: false,
			customClassName: false,
			html: false,
			anchor: false
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = blockPropsOf();

			var inspector = el( InspectorControls, {},
				el( PanelBody, { title: __( 'Record Overlay Link settings', 'rol' ) },
					el( ToggleControl, {
						label: __( 'Open in New Tab', 'rol' ),
						checked: !! attributes.openInNewTab,
						onChange: function ( v ) { setAttributes( { openInNewTab: v } ); }
					} ),
					el( TextControl, {
						label: __( 'Accessible Label', 'rol' ),
						help: __( 'Leave blank to use "View {Post Title}" automatically.', 'rol' ),
						value: attributes.accessibleLabel,
						onChange: function ( v ) { setAttributes( { accessibleLabel: v } ); }
					} ),
					el( TextControl, {
						label: __( 'Overlay Class', 'rol' ),
						help: __( 'Optional, space-separated extra class(es) for the link element itself.', 'rol' ),
						value: attributes.overlayClass,
						onChange: function ( v ) { setAttributes( { overlayClass: v } ); }
					} )
				)
			);

			var metaParts = [ attributes.openInNewTab ? __( 'Opens in new tab', 'rol' ) : __( 'Same tab', 'rol' ) ];
			if ( attributes.accessibleLabel ) { metaParts.push( attributes.accessibleLabel ); }

			return el( 'div', blockProps, inspector, el( Placeholder, {
				icon: 'move',
				label: __( 'Record Overlay Link', 'rol' ),
				instructions: metaParts.join( ' · ' )
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
		// FUNCTIONAL CSS ONLY — this file's positioning rules are the only
		// thing the block genuinely needs to work; no color, font, border
		// or shadow lives here. Visual design belongs to the theme's own
		// stylesheet or Additional CSS. The only front-end "script" is
		// none at all: this block is pure CSS + a native <a href>, no JS
		// navigation, no onclick.
		// =====================================================================

		public static function enqueue_frontend_styles() {
			$style_handle = 'rol-frontend-style';
			wp_register_style( $style_handle, false, array(), ROL_VERSION );
			wp_add_inline_style( $style_handle, self::get_frontend_css() );
			wp_enqueue_style( $style_handle );
		}

		private static function get_frontend_css() {
			return <<<'CSS'
/* Record Overlay Link (record-overlay/link) — purely functional
   positioning, no color/size/spacing. The author assigns
   "clickable_record" (or any class) to whichever container should
   become clickable, and places a Record Overlay Link block inside it —
   the overlay covers only that nearest positioned ancestor. */
.clickable_record {
	position: relative;
}
.clickable_record > .record-overlay-link,
.clickable_record .record-overlay-link {
	position: absolute;
	inset: 0;
	z-index: 2;
	display: block;
}
.clickable_record .record-interactive {
	position: relative;
	z-index: 3;
}
CSS;
		}

	}

} // class_exists

if ( ! has_action( 'plugins_loaded', array( 'ROL_System', 'init' ) ) ) {
	add_action( 'plugins_loaded', array( 'ROL_System', 'init' ), 20 );
}
