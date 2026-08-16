# Universal ACF Form — instrucciones de instalación y uso

Archivo: [`universal-acf-form-block.php`](./universal-acf-form-block.php)

## Novedades v1.1.0

- **Publicación con capacidad real**: si el estado solicitado para un post
  nuevo es `publish`, ahora se comprueba `current_user_can($post_type_object->cap->publish_posts)`.
  Si el usuario puede crear (`create_posts`) pero no publicar, el registro se
  guarda como `draft`. Ningún filtro externo (`uacf_new_post_status`) puede
  saltarse esta comprobación.
- **Taxonomías con `assign_terms`**: antes de mostrar los controles de una
  taxonomía o de guardar su selección, se verifica
  `current_user_can($tax_object->cap->assign_terms)`. Una taxonomía sin esa
  capacidad para el usuario actual no se renderiza ni se guarda.
- **`update_field()` con Field Key**: el campo `_code` ahora se actualiza con
  `update_field($code_field['key'], $code, $post_id)` (Field Key, no Field
  Name), conservando además el post meta plano con el Field Name.
- **Redirección tras crear**: al crear un registro nuevo con éxito, el
  sistema redirige automáticamente al mismo formulario con
  `?edit_id=<ID recién creado>`, mostrando el registro guardado en modo
  edición (y sigue evitando reenvíos duplicados del POST).

## 1. Cómo pegarlo en Code Snippets

1. Abre el archivo `universal-acf-form-block.php` y copia **todo** su contenido.
2. En WordPress ve a **Code Snippets → Add New**.
3. Pega el código en el editor, pero **elimina la primera línea `<?php`**.
   Code Snippets ya interpreta el editor como PHP; incluir la etiqueta de
   apertura provocaría un error de sintaxis.
4. En "Ajustes del snippet", elige **"Run snippet everywhere"** (ejecutar en
   todo el sitio: front-end + admin), ya que el sistema necesita ejecutarse
   tanto en el editor de Gutenberg (admin) como en el front-end.
5. Guarda y **activa** el snippet.

Si en lugar de Code Snippets prefieres usarlo como archivo de un mu-plugin
(`wp-content/mu-plugins/universal-acf-form-block.php`), dejá el archivo tal
cual está, con el `<?php` incluido.

## 2. Cómo insertar y configurar el bloque en Gutenberg

1. Edita cualquier entrada o página.
2. Añade un bloque nuevo y busca **"Universal ACF Form"**.
3. Insértalo. Verás un placeholder pidiendo seleccionar un tipo de contenido.
4. Abre el panel lateral (Configuración del bloque) → **"Ajustes de Universal
   ACF Form"** → selector **"Tipo de contenido (CPT)"**.
5. Elige el Custom Post Type. El editor mostrará automáticamente una vista
   previa real del formulario (usa `ServerSideRender`, por lo que es el mismo
   HTML que verá el visitante).
6. Publica/actualiza la página. En el front-end, el formulario:
   - Si no hay `?edit_id=` en la URL → modo **crear**.
   - Si hay `?edit_id=123` en la URL y el usuario puede editar ese post →
     modo **editar**, precargado con sus datos.

También puedes usar el shortcode de respaldo en cualquier lugar que acepte
shortcodes:

```
[universal_acf_form post_type="tu_post_type_key"]
```

Ambos (bloque y shortcode) llaman a la **misma función PHP** de renderizado
(`UACF_Universal_Form::render_form()`), por lo que no hay lógica duplicada.

## 3. Cómo descubre los CPT y los campos (resumen técnico)

- **CPT**: `get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' )`,
  excluyendo una lista fija de post types internos de WordPress/ACF/Gutenberg
  (`attachment`, `revision`, `nav_menu_item`, `acf-field`, `acf-field-group`,
  `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation`, etc.). No hay
  ningún CPT propio de tu aplicación escrito en el código.
- **Grupos ACF**: `acf_get_field_groups( array( 'post_type' => $post_type ) )`.
  ACF ya filtra automáticamente por grupos **activos** y evalúa las Location
  Rules configuradas en cada grupo.
- **Campos**: `acf_get_fields( $group )` para cada grupo encontrado. Los
  campos se renderizan con `acf_form()`, que es quien realmente interpreta
  tipo de campo, choices, condicionales, min/max, return format, etc. — este
  snippet nunca "reinventa" el renderizado de cada tipo de campo.
- **Taxonomías**: `get_object_taxonomies( $post_type, 'objects' )`, filtrando
  a taxonomías públicas con `show_ui`. Los términos se cargan con
  `get_terms( array( 'hide_empty' => false ) )` y se guardan con
  `wp_set_object_terms()`.
- **Prefijo**: `inventory_get_prefix( $post_type )` calcula el prefijo de 3
  letras a partir del propio post type key (sin tablas manuales).
- **Campo de código**: se busca, entre los campos de nivel superior del CPT,
  el primer campo de tipo `text` cuyo `name` termine en `_code`.
- **Campo de título**: se busca el primer campo de texto obligatorio (no
  `_code`); si no hay ninguno obligatorio, el primer campo de texto (no
  `_code`); si no hay ninguno, se usa el label singular del CPT + código (o + ID).

Todo esto se cachea **en memoria durante la petición** (variables estáticas),
nunca en transients persistentes, para que un campo ACF nuevo aparezca de
inmediato sin esperas de caché.

## 4. Pruebas antes de eliminar tus formularios anteriores

Antes de dar de baja cualquier formulario/plugin anterior, comprueba:

1. **Descubrimiento de CPT**: el selector del bloque muestra todos tus CPT
   públicos y ningún post type interno de WordPress.
2. **Creación**: como usuario con capacidad de crear ese CPT, crea un
   registro nuevo. Verifica que el post se crea, con el `post_status`
   esperado y el título generado correctamente.
3. **Campo `_code`**: si el CPT tiene un campo `..._code`, comprueba que se
   genera automáticamente con el formato `PREFIJO-001`, es de solo lectura en
   el formulario, y que no puede editarse manualmente desde el navegador
   (inspecciona el HTML: debe llevar el atributo `readonly`).
4. **Duplicados**: envía el formulario de creación dos veces seguidas (usa el
   botón atrás del navegador y reenvía, o abre dos pestañas) y confirma que
   nunca se generan dos registros con el mismo código.
5. **Numeración por CPT**: crea registros en dos CPT distintos y confirma que
   cada uno lleva su propia numeración independiente.
6. **Edición**: edita un registro existente vía `?edit_id=ID` y confirma que:
   - Los campos se precargan con los valores guardados.
   - El código **no cambia** al guardar de nuevo.
   - El título se actualiza si cambia el campo del que se deriva.
7. **Seguridad de `edit_id`**:
   - Prueba un `edit_id` de un post de **otro CPT** → debe rechazar con un
     mensaje claro, no debe mostrar el formulario.
   - Prueba un `edit_id` inexistente → debe rechazar con mensaje claro.
   - Con un usuario sin permiso para editar ese post concreto → debe
     rechazar (`current_user_can( 'edit_post', $id )`).
8. **Permisos de creación**: con un usuario sin capacidad de creación para
   ese CPT, confirma que el formulario muestra un mensaje de "no tienes
   permisos" y **no** se llega a renderizar `acf_form()`.
9. **Usuario no autenticado**: visita la página sin sesión iniciada y
   confirma que se muestra un enlace de inicio de sesión, no el formulario.
10. **Taxonomías**:
    - Una taxonomía sin términos muestra el mensaje "No hay términos... "
      en lugar de un selector vacío.
    - Selecciona/deselecciona términos y confirma que se guardan
      correctamente y que una deselección total limpia la taxonomía.
    - Confirma que **no** aparece ninguna forma de crear términos nuevos.
11. **Imágenes y archivos**: si algún grupo ACF tiene un campo Image o File,
    confirma que el selector de medios (modal de WordPress) abre y funciona
    en el front-end, y que el archivo se guarda correctamente en el post.
12. **Condicional Logic**: si algún campo tiene lógica condicional
    configurada en ACF, confirma que se comporta igual en el front-end que
    en el admin.
13. **CPT nuevo sin tocar el snippet**: crea un CPT completamente nuevo (con
    su grupo ACF) desde el admin y confirma que aparece automáticamente en
    el selector del bloque **sin modificar este archivo**.
14. **Campo ACF nuevo sin tocar el snippet**: añade un campo nuevo a un grupo
    ACF existente y confirma que aparece automáticamente en el formulario.
15. **ACF desactivado**: desactiva ACF temporalmente (en un entorno de
    pruebas) y confirma que el bloque muestra un mensaje claro en vez de una
    pantalla en blanco o un error fatal.

## 5. Limitaciones técnicas reales

- **ACF Free vs. Pro**: los tipos de campo Repeater, Flexible Content,
  Gallery, Clone y las páginas de opciones son exclusivos de ACF Pro y no
  están contemplados (ni son necesarios) en este sistema.
- **Campos anidados**: la detección automática de los campos "código" y
  "título" solo examina los campos de **nivel superior** de cada grupo ACF
  (independientemente del orden en que estén colocados). Si un campo de
  texto está anidado dentro de un campo de tipo "Group", no se tiene en
  cuenta para esta detección automática (sí se renderiza igualmente en el
  formulario, vía `acf_form()`).
- **Vista previa en el editor**: `ServerSideRender` hace una petición real a
  la REST API para renderizar el bloque con `render_callback`, por lo que la
  vista previa puede mostrar mensajes de permisos/estado según el usuario
  conectado al editor (comportamiento esperado, no es un error).
- **Bloques dentro de patrones/bloques reutilizables**: `acf_form_head()` se
  ejecuta para cualquier usuario autenticado en cualquier página del
  front-end (no se limita a páginas que "parezcan" contener el bloque), para
  garantizar que el guardado funcione también dentro de patrones, bloques
  reutilizables o plantillas de tema de bloques donde detectar el bloque de
  antemano no es fiable. Es una llamada ligera (no bloqueante) pero implica
  encolar los assets de ACF en cada página vista por un usuario conectado.
- **Numeración de códigos**: se basa en un contador atómico guardado en
  `wp_options` (`UPDATE ... = valor + 1`), no en un plugin de terceros ni en
  una tabla personalizada. Esto evita duplicados por condiciones de carrera
  o registros eliminados, pero significa que el contador nunca "reutiliza"
  números aunque se borren registros (comportamiento intencional).
- **Redirect tras guardar**: usa el parámetro nativo `return` de `acf_form()`
  hacia la propia URL de la página (con `?uacf_status=success`), evitando así
  reenvíos duplicados al refrescar. No usamos ningún parámetro/placeholder de
  ACF no documentado.
- **Multisite / caché de página completa**: si el sitio usa un plugin de
  caché de página completa, asegúrate de excluir de la caché las páginas que
  contengan este bloque para usuarios autenticados (recomendación estándar
  para cualquier formulario dinámico en WordPress).
