# Universal ACF Form — installation and usage instructions

File: [`universal-acf-form-block.php`](./universal-acf-form-block.php)

## What's new in v1.1.0 / v1.1.1

- **Publishing with a real capability check**: if the requested status for a
  new post is `publish`, the system now checks
  `current_user_can($post_type_object->cap->publish_posts)`. If the user can
  create (`create_posts`) but not publish, the record is saved as `draft`.
  No external filter (`uacf_new_post_status`) can bypass this check.
- **Taxonomies with `assign_terms`**: before showing a taxonomy's controls or
  saving its selection, the system verifies
  `current_user_can($tax_object->cap->assign_terms)`. A taxonomy the current
  user lacks that capability for is neither rendered nor saved.
- **`update_field()` with the Field Key**: the `_code` field is now updated
  with `update_field($code_field['key'], $code, $post_id)` (Field Key, not
  Field Name), while still keeping the plain post meta under the Field Name.
- **Redirect after creating (fixed in v1.1.1)**: `wp_safe_redirect()` + `exit`
  inside `acf/save_post` is no longer used (that would abruptly cut off any
  later ACF or third-party callback hooked to the same action). Instead, the
  `return` argument of `acf_form()` for create mode is built with ACF's
  **official** `%post_id%` placeholder
  (`?uacf_status=success&edit_id=%post_id%`), which ACF substitutes with the
  real ID once the *entire* save process has finished, and it's ACF that
  performs the final redirect. Edit mode still uses the real, already-known
  `edit_id`.

## 1. How to paste it into Code Snippets

1. Open the `universal-acf-form-block.php` file and copy **all** of its
   contents.
2. In WordPress, go to **Code Snippets → Add New**.
3. Paste the code into the editor, but **remove the first line `<?php`**.
   Code Snippets already treats the editor as PHP; including the opening
   tag would cause a syntax error.
4. Under "Snippet Settings", choose **"Run snippet everywhere"** (the whole
   site: front-end + admin), since the system needs to run both in the
   Gutenberg editor (admin) and on the front-end.
5. Save and **activate** the snippet.

If instead you prefer to use it as an mu-plugin file
(`wp-content/mu-plugins/universal-acf-form-block.php`), leave the file
as-is, with the `<?php` tag included.

## 2. How to insert and configure the block in Gutenberg

1. Edit any post or page.
2. Add a new block and search for **"Universal ACF Form"**.
3. Insert it. You'll see a placeholder asking you to select a content type.
4. Open the sidebar (Block settings) → **"Universal ACF Form settings"** →
   **"Content type (CPT)"** selector.
5. Choose the Custom Post Type. The editor will automatically show a real
   preview of the form (it uses `ServerSideRender`, so it's the same HTML
   the visitor will see).
6. Publish/update the page. On the front-end, the form:
   - If there's no `?edit_id=` in the URL → **create** mode.
   - If there's `?edit_id=123` in the URL and the user can edit that post →
     **edit** mode, pre-filled with its data.

You can also use the fallback shortcode anywhere shortcodes are accepted:

```
[universal_acf_form post_type="your_post_type_key"]
```

Both (block and shortcode) call the **same PHP function** for rendering
(`UACF_Universal_Form::render_form()`), so there's no duplicated logic.

## 3. How it discovers CPTs and fields (technical summary)

- **CPT**: `get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' )`,
  excluding a fixed list of internal WordPress/ACF/Gutenberg post types
  (`attachment`, `revision`, `nav_menu_item`, `acf-field`, `acf-field-group`,
  `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation`, etc.).
  There is no CPT of your own application hardcoded in the code.
- **ACF groups**: `acf_get_field_groups( array( 'post_type' => $post_type ) )`.
  ACF automatically filters by **active** groups and evaluates the Location
  Rules configured on each group.
- **Fields**: `acf_get_fields( $group )` for each group found. The fields
  are rendered with `acf_form()`, which is what actually interprets field
  type, choices, conditional logic, min/max, return format, etc. — this
  snippet never "reinvents" the rendering of each field type.
- **Taxonomies**: `get_object_taxonomies( $post_type, 'objects' )`, filtered
  to public taxonomies with `show_ui`. Terms are loaded with
  `get_terms( array( 'hide_empty' => false ) )` and saved with
  `wp_set_object_terms()`.
- **Prefix**: `inventory_get_prefix( $post_type )` computes the 3-letter
  prefix from the post type key itself (no manual lookup tables).
- **Code field**: the system looks, among the CPT's top-level fields, for
  the first field of type `text` whose `name` ends in `_code`.
- **Title field**: the system looks for the first required text field (not
  `_code`); if none is required, the first text field (not `_code`); if
  none exists, it uses the CPT's singular label + code (or + ID).

All of this is cached **in memory for the duration of the request** (static
variables), never in persistent transients, so a new ACF field shows up
immediately with no caching delay.

## 4. Tests to run before removing your previous forms

Before decommissioning any previous form/plugin, check:

1. **CPT discovery**: the block's selector shows all your public CPTs and
   no internal WordPress post type.
2. **Creation**: as a user with permission to create that CPT, create a new
   record. Verify the post is created with the expected `post_status` and
   a correctly generated title.
3. **`_code` field**: if the CPT has a `..._code` field, verify it's
   generated automatically in the `PREFIX-001` format, is read-only in the
   form, and cannot be edited manually from the browser (inspect the HTML:
   it must carry the `readonly` attribute).
4. **Duplicates**: submit the creation form twice in a row (use the
   browser's back button and resubmit, or open two tabs) and confirm that
   two records with the same code are never generated.
5. **Numbering per CPT**: create records in two different CPTs and confirm
   each one has its own independent numbering.
6. **Editing**: edit an existing record via `?edit_id=ID` and confirm that:
   - The fields are pre-filled with the saved values.
   - The code **does not change** when saving again.
   - The title updates if the field it's derived from changes.
7. **`edit_id` security**:
   - Try an `edit_id` belonging to a post of **another CPT** → it must be
     rejected with a clear message, the form must not be shown.
   - Try a non-existent `edit_id` → it must be rejected with a clear
     message.
   - With a user who lacks permission to edit that specific post → it must
     be rejected (`current_user_can( 'edit_post', $id )`).
8. **Creation permissions**: with a user who lacks the capability to create
   that CPT, confirm the form shows a "you don't have permission" message
   and `acf_form()` is **not** rendered at all.
9. **Unauthenticated user**: visit the page without a logged-in session and
   confirm a login link is shown, not the form.
10. **Taxonomies**:
    - A taxonomy with no terms shows the "No terms available..." message
      instead of an empty selector.
    - Select/deselect terms and confirm they save correctly, and that a
      full deselection clears the taxonomy.
    - Confirm there is **no** way to create new terms.
11. **Images and files**: if any ACF group has an Image or File field,
    confirm the media picker (WordPress modal) opens and works on the
    front-end, and the file is saved correctly on the post.
12. **Conditional Logic**: if any field has conditional logic configured in
    ACF, confirm it behaves the same on the front-end as in the admin.
13. **New CPT with no snippet changes**: create a brand-new CPT (with its
    ACF group) from the admin and confirm it automatically appears in the
    block's selector **without modifying this file**.
14. **New ACF field with no snippet changes**: add a new field to an
    existing ACF group and confirm it automatically appears in the form.
15. **ACF deactivated**: temporarily deactivate ACF (in a test environment)
    and confirm the block shows a clear message instead of a blank screen
    or a fatal error.

## 5. Real technical limitations

- **ACF Free vs. Pro**: the Repeater, Flexible Content, Gallery, and Clone
  field types, plus options pages, are exclusive to ACF Pro and are not
  covered by (nor needed for) this system.
- **Nested fields**: the automatic detection of the "code" and "title"
  fields only examines each ACF group's **top-level** fields (regardless of
  the order they're placed in). If a text field is nested inside a "Group"
  field type, it is not considered for this automatic detection (it is
  still rendered in the form normally, via `acf_form()`).
- **Editor preview**: `ServerSideRender` makes a real REST API request to
  render the block via `render_callback`, so the preview may show
  permission/status messages depending on the user logged into the editor
  (expected behavior, not a bug).
- **Blocks inside patterns/reusable blocks**: `acf_form_head()` runs for any
  logged-in user on any front-end page (it isn't limited to pages that
  "appear" to contain the block), to guarantee that saving also works
  inside patterns, reusable blocks, or block-theme templates where
  detecting the block ahead of time isn't reliable. It's a lightweight,
  non-blocking call, but it does mean ACF's assets get enqueued on every
  page viewed by a logged-in user.
- **Code numbering**: based on an atomic counter stored in `wp_options`
  (`UPDATE ... = value + 1`), not on a third-party plugin or a custom
  table. This avoids duplicates from race conditions or deleted records,
  but it means the counter never "reuses" numbers even if records are
  deleted (intentional behavior).
- **Redirect after saving**: uses `acf_form()`'s native `return` parameter
  pointing back to the page's own URL (with `?uacf_status=success` and, for
  new records, ACF's official `%post_id%` placeholder resolved into
  `edit_id`), avoiding duplicate resubmits on refresh. No undocumented ACF
  parameter/placeholder is used.
- **Multisite / full-page caching**: if the site uses a full-page caching
  plugin, make sure to exclude pages containing this block from the cache
  for logged-in users (a standard recommendation for any dynamic WordPress
  form).
