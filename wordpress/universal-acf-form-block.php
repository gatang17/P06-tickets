<?php
/**
 * Universal ACF Form — bloque dinámico universal para crear/editar registros
 * de cualquier Custom Post Type usando Advanced Custom Fields (ACF Free).
 *
 * IMPORTANTE — CÓMO INSTALARLO CON EL PLUGIN "CODE SNIPPETS":
 *   1. Copia TODO el contenido de este archivo.
 *   2. En Code Snippets → Add New, pega el código PERO ELIMINA la primera
 *      línea "<?php" (Code Snippets ya interpreta el editor como PHP, por
 *      lo que la etiqueta de apertura no debe incluirse).
 *   3. Guarda el snippet con el ajuste "Run snippet everywhere" (todo el sitio).
 *   4. Actívalo.
 *
 * Si en cambio vas a usar este archivo como mu-plugin o plugin normal,
 * déjalo tal cual está (con la etiqueta "<?php" incluida).
 *
 * No depende de ACF Pro, Composer, Node.js, npm ni de ningún build step.
 * Todo el JavaScript del editor de bloques se genera e inyecta desde este
 * mismo archivo mediante wp_add_inline_script().
 *
 * Requisitos: WordPress con Gutenberg, ACF Free activo, PHP 8.1+.
 */

// =============================================================================
// SECCIÓN 1 — COMPROBACIONES Y CONSTANTES
// =============================================================================

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salida de seguridad: no permitir acceso directo al archivo.
}

if ( ! defined( 'UACF_VERSION' ) ) {
	define( 'UACF_VERSION', '1.1.1' );
}

if ( ! defined( 'UACF_NONCE_PREFIX' ) ) {
	define( 'UACF_NONCE_PREFIX', 'uacf_form_' );
}

// =============================================================================
// SECCIÓN 6 — PREFIJO AUTOMÁTICO (función pública, fuera de la clase porque el
// requisito pide explícitamente esta firma exacta: inventory_get_prefix($post_type)).
// =============================================================================

if ( ! function_exists( 'inventory_get_prefix' ) ) {
	/**
	 * Genera un prefijo determinista de 3 caracteres a partir de un post type key.
	 * No usa ninguna tabla manual: se calcula únicamente a partir del texto del
	 * propio post type key normalizando guiones, guiones bajos y espacios.
	 *
	 * @param string $post_type Post type key (ej. "stock_record").
	 * @return string Prefijo en mayúsculas de 3 caracteres (ej. "SRE").
	 */
	function inventory_get_prefix( $post_type ) {
		$post_type = (string) $post_type;

		// Normalizar separadores: guion, guion bajo y espacio -> espacio único.
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
			// Una sola palabra: primeras 3 letras.
			$prefix = substr( $words[0], 0, 3 );
		} elseif ( 2 === $count ) {
			// Dos palabras: primera letra de la 1ª + primeras 2 de la 2ª.
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 2 );
		} elseif ( $count >= 3 ) {
			// Tres o más palabras: primera letra de las 3 primeras palabras.
			$prefix = substr( $words[0], 0, 1 ) . substr( $words[1], 0, 1 ) . substr( $words[2], 0, 1 );
		}

		$prefix = strtoupper( $prefix );

		// Relleno determinista si el resultado tiene menos de 3 caracteres
		// (por ejemplo, un post type key extremadamente corto o vacío).
		if ( strlen( $prefix ) < 3 ) {
			$source = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $post_type ) );
			$i      = 0;
			while ( strlen( $prefix ) < 3 ) {
				if ( $i < strlen( $source ) ) {
					$prefix .= $source[ $i ];
				} else {
					$prefix .= 'X'; // Relleno fijo y determinista una vez agotada la fuente.
				}
				$i++;
			}
		}

		return substr( $prefix, 0, 3 );
	}
}

// =============================================================================
// CLASE PRINCIPAL — encapsula todo el sistema para evitar colisiones de
// nombres con otros plugins/snippets. Se declara una única vez gracias al
// guard class_exists().
// =============================================================================

if ( ! class_exists( 'UACF_Universal_Form' ) ) {

	final class UACF_Universal_Form {

		// -------------------------------------------------------------------
		// Caché en memoria (solo dura la petición actual, nunca transients
		// persistentes, para que los campos ACF nuevos aparezcan al instante).
		// -------------------------------------------------------------------
		private static $post_types_cache = null;
		private static $groups_cache     = array();
		private static $fields_cache     = array();
		private static $taxonomies_cache = array();

		// =====================================================================
		// SECCIÓN 7/8/9 — arranque y registro de hooks
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
		// SECCIÓN 2 — DESCUBRIMIENTO AUTOMÁTICO DE CPT
		// =====================================================================

		/**
		 * Devuelve todos los post types públicos con interfaz de administración,
		 * excluyendo los internos de WordPress/ACF/Gutenberg. Sin listas manuales
		 * de CPT propios del sitio.
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
				// Otros post types internos genéricos de WordPress (no son
				// entidades de la aplicación, son núcleo de WP):
				'wp_global_styles',
				'wp_font_family',
				'wp_font_face',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
			);

			/**
			 * Permite ajustar la lista de exclusión sin tocar este snippet.
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
		// SECCIÓN 3 — DESCUBRIMIENTO AUTOMÁTICO DE GRUPOS Y CAMPOS ACF
		// =====================================================================

		/**
		 * Grupos ACF activos cuyas Location Rules apuntan a este post type.
		 *
		 * @param string $post_type
		 * @return array Lista de grupos ACF (arrays), tal como los devuelve ACF.
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
		 * Todos los campos (nivel superior) de los grupos activos de un CPT,
		 * sin importar el orden/posición en el que estén dentro del grupo.
		 *
		 * @param string $post_type
		 * @return array Lista plana de arrays de campo ACF.
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
		// SECCIÓN 4 — PREFIJOS Y CÓDIGOS
		// =====================================================================

		/**
		 * Busca, entre los campos de nivel superior de un CPT, el primer campo
		 * de tipo "text" cuyo Field Name termine exactamente en "_code".
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
		 * Genera un código único PREFIX-001, PREFIX-002... para un post type,
		 * usando un contador atómico en wp_options (UPDATE ... = valor + 1),
		 * lo que evita duplicados incluso ante envíos dobles o carreras entre
		 * peticiones concurrentes. Además verifica contra los registros
		 * existentes (incluidos los ya eliminados no afectan la numeración,
		 * porque el contador nunca retrocede).
		 *
		 * @param string $post_type
		 * @param string $field_name Nombre del campo "_code" (para comprobar duplicados).
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
		 * Comprueba si ya existe un registro de ese CPT con ese código exacto.
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
		 * Filtro acf/load_field: hace readonly y no-obligatorio, solo en el
		 * front-end (nunca en wp-admin), cualquier campo de texto cuyo nombre
		 * termine en "_code". El valor real se calcula en finalize_save_post().
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
				$field['placeholder'] = __( 'Se generará automáticamente al guardar', 'uacf' );
			}

			return $field;
		}

		// =====================================================================
		// SECCIÓN 5 — DETECCIÓN DEL CAMPO QUE FORMARÁ EL TÍTULO
		// =====================================================================

		/**
		 * 1) Primer campo de texto obligatorio cuyo nombre no termine en "_code".
		 * 2) Si no existe, el primer campo de texto (no "_code").
		 * 3) Si no existe ninguno, devuelve null (el llamador decide el fallback).
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
		 * Calcula el post_title final siguiendo la estrategia genérica descrita
		 * en el punto 8 de los requisitos.
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
		// SECCIÓN 6 — TAXONOMÍAS
		// =====================================================================

		/**
		 * Taxonomías públicas asociadas a un CPT (excluyendo internas) que,
		 * además, el usuario actual tiene permiso de asignar
		 * (current_user_can($tax_object->cap->assign_terms)). Al filtrar aquí,
		 * tanto el renderizado (build_after_fields_html) como el guardado
		 * (save_taxonomies) respetan automáticamente esta comprobación: una
		 * taxonomía sin permiso de asignación nunca se muestra ni se guarda.
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
		 * Convierte una lista plana de términos (get_terms) en un árbol
		 * ordenado por jerarquía, con la profundidad de cada término.
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
		 * Guarda las taxonomías seleccionadas usando wp_set_object_terms().
		 * Solo permite seleccionar términos EXISTENTES (nunca crea términos
		 * nuevos): cada valor se valida con term_exists() antes de guardarlo.
		 * Solo itera taxonomías devueltas por get_taxonomies_for_post_type(),
		 * que ya excluye aquellas para las que el usuario actual no tiene
		 * cap->assign_terms, así que wp_set_object_terms() nunca se llama sin
		 * esa capacidad verificada.
		 */
		private static function save_taxonomies( $post_type, $post_id ) {
			foreach ( self::get_taxonomies_for_post_type( $post_type ) as $tax_name => $tax_object ) {
				$field_key = 'uacf_tax_' . $tax_name;

				if ( ! isset( $_POST[ $field_key ] ) ) {
					continue; // El control ni siquiera se mostró (0 términos disponibles).
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
		// SECCIÓN 7 — SEGURIDAD Y PERMISOS
		// =====================================================================

		private static function nonce_action( $post_type, $mode ) {
			return UACF_NONCE_PREFIX . $post_type . '_' . $mode;
		}

		/**
		 * Valida un edit_id recibido por GET de forma segura: comprueba que el
		 * post existe, que pertenece exactamente al CPT del bloque y que el
		 * usuario actual puede editarlo (capacidades nativas del CPT vía
		 * current_user_can('edit_post', $id), no por nombre de rol).
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
		 * Resuelve el post_status con el que se creará un registro nuevo,
		 * comprobando SIEMPRE la capacidad nativa de publicación del CPT
		 * (cap->publish_posts). Un filtro externo (uacf_new_post_status)
		 * puede pedir 'publish', pero si el usuario actual no tiene esa
		 * capacidad, el registro se degrada a 'draft' de forma obligatoria:
		 * ningún filtro puede saltarse esta comprobación.
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
		 * Hook acf/validate_save_post (antes de guardar cualquier campo).
		 * Verifica usuario autenticado, nonce propio, whitelist de CPT y
		 * capacidades nativas del CPT. Si algo falla, añade un error de
		 * validación de ACF, lo que impide por completo que se cree o
		 * actualice el post (ACF aborta el guardado si hay errores).
		 */
		public static function gate_save_post() {
			if ( is_admin() ) {
				return; // No interferir nunca con el guardado normal desde wp-admin.
			}
			if ( empty( $_POST['uacf_submit'] ) ) {
				return; // No es un envío de este sistema.
			}

			$post_type = isset( $_POST['uacf_post_type'] ) ? sanitize_key( wp_unslash( $_POST['uacf_post_type'] ) ) : '';
			$mode      = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : '';
			$nonce     = isset( $_POST['uacf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uacf_nonce'] ) ) : '';

			$error = '';

			if ( ! is_user_logged_in() ) {
				$error = __( 'Debes iniciar sesión para enviar este formulario.', 'uacf' );
			} else {
				$available = self::get_available_post_types();

				if ( '' === $post_type || ! isset( $available[ $post_type ] ) ) {
					$error = __( 'Tipo de contenido no válido.', 'uacf' );
				} elseif ( ! in_array( $mode, array( 'create', 'edit' ), true ) ) {
					$error = __( 'Solicitud de formulario no válida.', 'uacf' );
				} elseif ( ! wp_verify_nonce( $nonce, self::nonce_action( $post_type, $mode ) ) ) {
					$error = __( 'La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.', 'uacf' );
				} else {
					$post_type_object = $available[ $post_type ];

					if ( 'create' === $mode ) {
						if ( empty( $post_type_object->cap->create_posts ) || ! current_user_can( $post_type_object->cap->create_posts ) ) {
							$error = __( 'No tienes permisos para crear este tipo de contenido.', 'uacf' );
						}
					} else {
						$edit_id  = isset( $_POST['uacf_edit_id'] ) ? absint( wp_unslash( $_POST['uacf_edit_id'] ) ) : 0;
						$existing = $edit_id ? get_post( $edit_id ) : null;

						if ( ! $existing || $existing->post_type !== $post_type ) {
							$error = __( 'El registro que intentas editar no existe o no coincide con este formulario.', 'uacf' );
						} elseif ( ! current_user_can( 'edit_post', $edit_id ) ) {
							$error = __( 'No tienes permisos para editar este registro.', 'uacf' );
						}
					}
				}
			}

			if ( '' !== $error && function_exists( 'acf_add_validation_error' ) ) {
				acf_add_validation_error( '', $error );
			}
		}

		// =====================================================================
		// SECCIÓN 8 — PROCESAMIENTO DEL FORMULARIO (tras el guardado de ACF)
		// =====================================================================

		/**
		 * Debe ejecutarse antes de cualquier salida HTML. Se llama en el hook
		 * "wp" (temprano) para cualquier visitante autenticado en el front-end,
		 * ya que acf_form_head() necesita procesar el $_POST (validar/guardar
		 * y redirigir) antes de que se envíen cabeceras HTTP.
		 */
		public static function prime_form_head() {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( ! is_user_logged_in() ) {
				return; // Sin sesión no se puede enviar el formulario; evitamos el coste.
			}
			if ( ! function_exists( 'acf_form_head' ) ) {
				return; // ACF no está activo: nos lo indicará render_form() en pantalla.
			}

			wp_enqueue_media(); // Necesario para que el uploader "wp" (imágenes/archivos) funcione en el front-end.
			acf_form_head();
		}

		/**
		 * Hook acf/save_post (prioridad 20, después de que ACF ya haya guardado
		 * los campos con su prioridad por defecto 10). Aquí completamos lo que
		 * ACF no gestiona de forma nativa: código, título y taxonomías.
		 *
		 * No genera recursión: este hook es específico de ACF (solo se dispara
		 * dentro de acf_save_post()), por lo que llamar aquí a wp_update_post()
		 * NO vuelve a disparar 'acf/save_post'. Además se añade una guarda
		 * estática adicional como defensa extra.
		 */
		public static function finalize_save_post( $post_id ) {
			if ( is_admin() ) {
				return;
			}
			if ( empty( $_POST['uacf_submit'] ) ) {
				return;
			}
			if ( ! is_numeric( $post_id ) ) {
				return; // acf/save_post también se dispara para "options", "user_N", etc.
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
				return; // gate_save_post() ya debería haber bloqueado esto; defensa extra.
			}

			$mode = isset( $_POST['uacf_mode'] ) ? sanitize_key( wp_unslash( $_POST['uacf_mode'] ) ) : 'create';

			$fields     = self::get_fields_for_post_type( $post_type );
			$code_field = self::find_code_field( $fields );
			$code       = '';

			if ( $code_field ) {
				$existing_code = get_post_meta( $post_id, $code_field['name'], true );

				if ( 'edit' === $mode && '' !== $existing_code ) {
					// Editar: conservar el código existente, nunca generar uno nuevo.
					$code = $existing_code;
				} else {
					$code = self::generate_unique_code( $post_type, $code_field['name'] );

					if ( function_exists( 'update_field' ) && ! empty( $code_field['key'] ) ) {
						// update_field() debe recibir el Field Key de ACF (no el
						// Field Name) para resolver el campo de forma inequívoca.
						update_field( $code_field['key'], $code, $post_id );
					}
					// Conservamos también el post meta "plano" con el Field Name,
					// que es la clave bajo la que se consulta con get_post_meta().
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

			// La redirección a "?edit_id=<nuevo ID>" tras crear NO se hace aquí
			// con wp_redirect()/exit: eso cortaría en seco cualquier callback de
			// 'acf/save_post' registrado después de este (prioridad > 20) tanto
			// de ACF como de otros plugins. En su lugar, render_form() ya monta
			// el argumento 'return' de acf_form() con el placeholder oficial
			// %post_id%, que ACF sustituye por el ID real una vez que TODO el
			// proceso de guardado (incluido este hook) ha terminado, y es ACF
			// quien realiza el redirect final por su cuenta.
		}

		// =====================================================================
		// SECCIÓN 9 — RENDERIZADO (compartido por bloque y shortcode)
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
		 * Devuelve la URL "limpia" de la página/entrada actual (sin parámetros
		 * de este sistema), usada como base para el redirect tras guardar.
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
		 * Construye la URL del argumento 'return' de acf_form().
		 *
		 * - Modo "edit": conserva el edit_id real ya conocido.
		 * - Modo "create": usa el placeholder OFICIAL de acf_form(), literal
		 *   "%post_id%", que ACF sustituye por el ID recién creado después de
		 *   completar todo el proceso de guardado (incluidos los hooks
		 *   acf/save_post de este sistema y de cualquier otro plugin). El
		 *   placeholder se añade fuera de add_query_arg() y nunca se pasa por
		 *   absint()/sanitize_*() ni por ninguna otra sanitización que pudiera
		 *   alterar o eliminar los caracteres "%", precisamente para que ACF
		 *   pueda encontrarlo y reemplazarlo tal cual.
		 */
		private static function build_return_url( $mode, $edit_id ) {
			$base = self::get_current_clean_url();
			$args = array( 'uacf_status' => 'success' );

			if ( 'edit' === $mode && $edit_id > 0 ) {
				$args['edit_id'] = $edit_id;
			}

			$url = add_query_arg( $args, $base );

			if ( 'create' === $mode ) {
				// add_query_arg() urlencodearía "%post_id%" si lo pasáramos
				// dentro de $args, rompiendo la sustitución de ACF. Por eso se
				// concatena aparte, siempre en texto literal.
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
							/* translators: %s: nombre de la taxonomía. */
							__( 'No hay términos de "%s" disponibles todavía.', 'uacf' ),
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
				// Input "primer" oculto: permite detectar una deselección total
				// (todas las casillas desmarcadas) en lugar de "campo no enviado".
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
		 * Renderizador único usado tanto por el bloque de Gutenberg como por
		 * el shortcode [universal_acf_form]. Recibe solo el post type key.
		 *
		 * @param string $post_type
		 * @return string HTML del formulario o de un mensaje de error/estado.
		 */
		public static function render_form( $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( ! function_exists( 'acf_form_head' ) || ! function_exists( 'acf_get_field_groups' ) ) {
				return self::notice( 'error', __( 'Advanced Custom Fields (ACF) no está activo. Actívalo para poder usar este formulario.', 'uacf' ) );
			}

			if ( '' === $post_type ) {
				return self::notice( 'info', __( 'Este bloque todavía no tiene un tipo de contenido seleccionado. Configúralo desde el panel lateral del editor.', 'uacf' ) );
			}

			$available = self::get_available_post_types();
			if ( ! isset( $available[ $post_type ] ) ) {
				return self::notice( 'error', __( 'El tipo de contenido seleccionado no existe o no está disponible.', 'uacf' ) );
			}

			if ( ! is_user_logged_in() ) {
				return self::notice(
					'info',
					sprintf(
						/* translators: %s: URL de inicio de sesión. */
						__( 'Debes <a href="%s">iniciar sesión</a> para usar este formulario.', 'uacf' ),
						esc_url( wp_login_url( self::get_current_clean_url() ) )
					)
				);
			}

			$post_type_object = $available[ $post_type ];

			$edit_id      = isset( $_GET['edit_id'] ) ? absint( wp_unslash( $_GET['edit_id'] ) ) : 0;
			$editing_post = $edit_id > 0 ? self::validate_edit_id( $edit_id, $post_type ) : null;

			if ( $edit_id > 0 && ! $editing_post ) {
				return self::notice( 'error', __( 'El registro que intentas editar no existe, no pertenece a este formulario o no tienes permiso para editarlo.', 'uacf' ) );
			}

			$mode = $editing_post ? 'edit' : 'create';

			if ( 'create' === $mode ) {
				$can_create = ! empty( $post_type_object->cap->create_posts ) && current_user_can( $post_type_object->cap->create_posts );
				if ( ! $can_create ) {
					return self::notice( 'error', __( 'No tienes permisos para crear este tipo de contenido.', 'uacf' ) );
				}
			} elseif ( ! current_user_can( 'edit_post', $edit_id ) ) {
				return self::notice( 'error', __( 'No tienes permisos para editar este registro.', 'uacf' ) );
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
				echo self::notice( 'success', __( 'Guardado correctamente.', 'uacf' ) );
			}

			if ( empty( $groups ) ) {
				echo self::notice( 'info', __( 'Este tipo de contenido todavía no tiene ningún grupo de campos ACF configurado. El formulario solo gestionará el título y, si existen, las taxonomías.', 'uacf' ) );
			}

			// El filtro puede sugerir un estado, pero resolve_new_post_status()
			// siempre verifica cap->publish_posts antes de permitir 'publish'
			// (ver punto 1 de seguridad); nunca se confía ciegamente en el filtro.
			$requested_status = apply_filters( 'uacf_new_post_status', 'publish', $post_type );
			$new_post_status  = self::resolve_new_post_status( $post_type_object, $requested_status );

			$form_args = array(
				'id'                => 'uacf-form-' . $post_type,
				'post_id'           => 'edit' === $mode ? $edit_id : 'new_post',
				'new_post'          => array(
					'post_type'   => $post_type,
					'post_status' => $new_post_status,
					/* translators: %s: nombre singular del CPT. */
					'post_title'  => sprintf( __( '%s (borrador)', 'uacf' ), $singular ),
				),
				'field_groups'      => $field_group_keys,
				'post_title'        => false,
				'post_content'      => false,
				'submit_value'      => 'edit' === $mode ? __( 'Actualizar', 'uacf' ) : __( 'Crear', 'uacf' ),
				'updated_message'   => false,
				'return'            => self::build_return_url( $mode, $edit_id ),
				'html_before_fields' => self::build_before_fields_html( $post_type, $mode, $edit_id ),
				'html_after_fields'  => self::build_after_fields_html( $post_type, $editing_post ),
				'uploader'          => 'wp',
			);

			/**
			 * Permite ajustar los argumentos de acf_form() desde fuera sin
			 * tocar este snippet.
			 */
			$form_args = apply_filters( 'uacf_form_args', $form_args, $post_type, $mode, $edit_id );

			acf_form( $form_args );

			echo '</div>';

			return ob_get_clean();
		}

		// =====================================================================
		// SECCIÓN 10 — SHORTCODE
		// =====================================================================

		public static function shortcode_callback( $atts ) {
			$atts = shortcode_atts( array( 'post_type' => '' ), (array) $atts, 'universal_acf_form' );
			return self::render_form( sanitize_key( $atts['post_type'] ) );
		}

		// =====================================================================
		// SECCIÓN 11 — BLOQUE DE GUTENBERG + JAVASCRIPT DEL EDITOR
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
			return self::render_form( $post_type );
		}

		/**
		 * Encola el JS del bloque SOLO en el editor de Gutenberg (nunca en el
		 * front-end), inyectado como script en línea sin necesidad de un
		 * archivo .js aparte, compatible con Code Snippets.
		 */
		public static function enqueue_editor_assets() {
			$handle = 'uacf-block-editor';

			wp_register_script(
				$handle,
				false,
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
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
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps : null;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var ServerSideRender = serverSideRender;

	var postTypeChoices = [ { value: '', label: __( 'Selecciona un tipo de contenido…', 'uacf' ) } ];
	if ( window.uacfBlockData && window.uacfBlockData.postTypes ) {
		window.uacfBlockData.postTypes.forEach( function ( item ) {
			postTypeChoices.push( { value: item.value, label: item.label } );
		} );
	}

	blocks.registerBlockType( 'uacf/universal-acf-form', {
		title: __( 'Universal ACF Form', 'uacf' ),
		description: __( 'Formulario front-end universal para crear o editar registros de cualquier Custom Post Type con sus campos ACF.', 'uacf' ),
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
					{ title: __( 'Ajustes de Universal ACF Form', 'uacf' ) },
					el( SelectControl, {
						label: __( 'Tipo de contenido (CPT)', 'uacf' ),
						value: attributes.postType,
						options: postTypeChoices,
						onChange: function ( value ) {
							setAttributes( { postType: value } );
						}
					} )
				)
			);

			var body;
			if ( ! attributes.postType ) {
				body = el( Placeholder, {
					icon: 'feedback',
					label: __( 'Universal ACF Form', 'uacf' ),
					instructions: __( 'Selecciona un tipo de contenido en el panel lateral para previsualizar el formulario.', 'uacf' )
				} );
			} else if ( ServerSideRender ) {
				body = el( ServerSideRender, {
					block: 'uacf/universal-acf-form',
					attributes: attributes
				} );
			} else {
				body = el( 'p', {}, __( 'Vista previa no disponible: falta el componente ServerSideRender.', 'uacf' ) );
			}

			return el( 'div', wrapperProps, inspector, body );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender );
JS;
		}

		// =====================================================================
		// SECCIÓN 12 — ESTILOS MÍNIMOS
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
CSS;
		}
	}

} // class_exists

// =============================================================================
// ARRANQUE
// =============================================================================

if ( ! has_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ) ) ) {
	add_action( 'plugins_loaded', array( 'UACF_Universal_Form', 'init' ), 20 );
}
