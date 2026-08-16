# Universal ACF Form v2.1.0 — installation and usage instructions

File: [`universal-acf-form-block.php`](./universal-acf-form-block.php)

## What changed in v2.1.0 (fixes on top of the v2.0.0 architecture)

- **Block supports now also declared in JS**, not just PHP's
  `register_block_type()`: all 5 blocks share a `UACF_BLOCK_SUPPORTS`
  object (`customClassName`, `anchor`, `spacing.margin/padding`,
  `color.text/background`) in `registerBlockType()`, so the Advanced/
  Styles controls actually appear in the editor (PHP-side `supports`
  alone only affects server-side rendering, not the editor UI). The
  parent form block keeps `html: false` in addition.
- **Validate before creating.** `process_submission()` no longer calls
  `wp_insert_post()` and then deletes it again on a validation failure.
  The order is now: nonce → permissions → Field Key whitelist → required-
  fields-present check → `acf_validate_save_post()` → **only if valid**,
  `wp_insert_post()` (create mode) or resolve `edit_id` (edit mode) →
  `acf_save_post()` → redirect. Nothing is ever created-then-deleted.
- **Submitted values survive a validation error.** Universal ACF Field
  now redisplays `$_POST['acf'][$field_key]` (via `wp_unslash()` only —
  never `sanitize_text_field()` or similar, since that would corrupt
  array-shaped values like Checkbox/Relationship/Post Object/Select-
  multiple, or an Image/File field's attachment ID) whenever this
  request's submission was rejected, instead of falling back to the
  stored/default value and forcing the user to start over.
- **Required ACF fields are enforced against the form's actual blocks.**
  A required field whose Field Key never appears in `$_POST['acf']` — i.e.
  no Universal ACF Field block was added for it — now blocks creation
  with a clear error, with documented exceptions for `_code` fields
  (server-generated) and fields with conditional logic (can't be reliably
  re-evaluated server-side without duplicating ACF's own engine). The
  parent block's editor view also shows a best-effort warning listing any
  required field with no matching block anywhere inside the form yet.
- **Taxonomy radio buttons get a real "None" option**, checked by default
  when nothing is selected. Without it, an entirely-unchecked native radio
  group submits nothing at all, so a previously selected term could never
  be cleared when editing; the fix guarantees the field is always present
  in `$_POST`, letting `wp_set_object_terms()` clear the relationship.
- Minor cleanup: `resolve_edit_context()` now has a single cache-and-
  return point instead of repeating it per branch.

## What changed in v2.0.0 (breaking architecture change)

v1.x was a single monolithic block: pick a CPT, and it printed every ACF
field (plus every taxonomy, plus the submit button) automatically, one
under the other. v2.0.0 replaces that entirely with **5 composable
blocks** so each field can be positioned, styled and given its own CSS
class from Gutenberg:

| Block | Role |
|---|---|
| **Universal ACF Form** (`uacf/universal-acf-form`) | Container. Opens/closes the single `<form>`. Picks the CPT. Provides it as Block Context to everything inside. |
| **Universal ACF Field** (`uacf/acf-field`) | Renders exactly one ACF field, chosen by Field Key. |
| **Universal Taxonomy Field** (`uacf/taxonomy-field`) | Renders exactly one taxonomy's term picker. |
| **Universal Form Message** (`uacf/form-message`) | Where validation/status messages appear. Optional. |
| **Universal Submit Button** (`uacf/submit-button`) | The real `<button type="submit">`. |

**This is a replacement, not an addition.** The old monolithic block, its
`[universal_acf_form]` shortcode, and the old `acf_form()`-based save
pipeline are gone — there is only one system now, so a form can never be
saved twice through two different code paths. See "What was replaced"
below for the full list.

## 1. How to paste it into Code Snippets

1. Copy the **entire** contents of `universal-acf-form-block.php`.
2. In **Code Snippets → Add New**, paste it and **remove the first line
   `<?php`** — Code Snippets already treats the editor as PHP.
3. Set the snippet to **"Run snippet everywhere"** and activate it.

If used as an mu-plugin/plugin file instead, leave the `<?php` tag in.

## 2. Building a form with the new blocks

Example — a form with two columns, a notes field, a category, a message
area and a submit button, matching the layout from the request:

1. Insert a **Universal ACF Form** block. In its sidebar, pick the
   **Content type (CPT)**.
2. Inside it, insert a **Columns** block (a native Gutenberg block — it
   works because `core/columns`/`core/column` are on the form's allowed
   inner blocks list, and Block Context flows through them automatically).
3. In the first **Column**, insert two **Universal ACF Field** blocks; for
   each, use its own sidebar to pick the ACF **Field** (shown as
   `Label (field_name)`) — the block stores the **Field Key** internally,
   never the name.
4. In the second **Column**, insert another **Universal ACF Field** and a
   **Universal Taxonomy Field**; pick its **Taxonomy**, **Selection Mode**
   and **Display Style** in the sidebar.
5. Below the Columns block (still inside the form), insert one more
   **Universal ACF Field**, a **Universal Form Message**, and a
   **Universal Submit Button**.
6. Publish/update the page. On the front-end you get one `<form
   method="post" enctype="multipart/form-data">` containing only the
   fields you placed, wherever you placed them — nested inside Columns,
   Group, Row, Stack (all of which are just `core/group`/`core/columns`
   under the hood) or not.

`?edit_id=123` in the URL still switches the whole form to edit mode
(subject to the same permission checks as before): every Universal ACF
Field and Universal Taxonomy Field block on the page automatically loads
that record's current values.

## 3. Assigning a CSS class to each field

Every block (form, field, taxonomy field, message, submit button) supports
`customClassName`, so select any of them and, in the sidebar, open
**Advanced → Additional CSS class(es)** and type a class name — it's added
to that field's own wrapper `<div>` (via WordPress's real
`get_block_wrapper_attributes()` API), independently of every other field.
The same panel also exposes **spacing** (margin/padding) and **color**
(text/background) controls per block, and the form/field/message/button
blocks additionally support **anchor** (an HTML `id`).

## 4. Taxonomy picker: Selection Mode × Display Style

Configured independently in the Universal Taxonomy Field's sidebar. Only
the 4 combinations below are offered (the Display Style options shown
change based on the Selection Mode, so nonsensical combos like
"Single + Checkboxes" simply aren't selectable):

| Selection Mode | Display Style | Renders |
|---|---|---|
| Single (default) | Dropdown (default) | A plain, closed `<select name="uacf_tax_X">` |
| Single | Radio Buttons | Radio inputs, one shared `name`, no `[]` |
| Multiple | Checkboxes | A plain, always-visible checkbox list, `name="uacf_tax_X_multi[]"` |
| Multiple | Dropdown | A closed toggle button + panel of real checkboxes (see below) |

**No `<select multiple>` is used anywhere** — a fully custom, dependency-
free widget replaces it for "Multiple + Dropdown":

- The panel of checkboxes is **plain, always-visible HTML** on first
  render (no `hidden` attribute server-side) — fully usable even if
  JavaScript never loads.
- A small inline script (no external library, no CDN) then, on page load,
  hides the panel and turns the wrapper into an accessible disclosure:
  a real `<button type="button">` toggles it, `aria-expanded` is kept in
  sync, the panel closes on outside click and on <kbd>Escape</kbd>
  (returning focus to the button), and keyboard users can Tab into the
  checkboxes normally once the panel is open.
- The toggle button's text shows "Select…" (no selection), the single
  term's name (one selected), or "N terms selected" (several) — computed
  live from which checkboxes are checked.
- The checkboxes are always real `<input type="checkbox">` elements with
  `name="...[]"`, so the browser submits real term IDs regardless of
  whether the JS ran; every submitted ID is validated with `term_exists()`
  before saving.

## 5. How discovery still works (unchanged principles)

CPTs, ACF field groups/fields, and taxonomies are still discovered
automatically from WordPress/ACF (`get_post_types()`,
`acf_get_field_groups()`, `acf_get_fields()`,
`get_object_taxonomies()` filtered by `cap->assign_terms`) — no CPT,
field, or taxonomy name is hardcoded anywhere. A **Universal ACF Field**
block's Field picker and a **Universal Taxonomy Field** block's Taxonomy
picker are populated from exactly this discovery, scoped to whatever CPT
the block's Block Context resolves to.

## 6. How saving works now (no more `acf_form()`)

`acf_form()` always prints every field of the groups you hand it — the
opposite of what this architecture needs — so it is no longer called at
all. Instead, `process_submission()` (run on the `wp` hook, before any
HTML output) drives ACF's own **lower-level, real APIs** directly:

1. Verify nonce, logged-in user, CPT whitelist, and the CPT's native
   `create_posts`/`edit_post` capability — **before** touching anything.
2. Restrict `$_POST['acf']` to Field Keys that genuinely belong to the
   submitted CPT's discovered fields (`get_field_by_key()`) — a Field Key
   belonging to a different CPT is silently dropped here, so it can never
   be saved, regardless of what was rendered or forged.
3. Create the post (`wp_insert_post()`, create mode) or use the already-
   validated `edit_id` (edit mode).
4. `acf_validate_save_post( false )` — ACF's own real validation function,
   working correctly against a **partial** set of fields (exactly the ones
   actually placed as blocks). On failure: a freshly-created post is
   deleted, errors are collected and shown by the Universal Form Message
   block (or the form's own fallback notice if that block wasn't added).
5. `acf_save_post( $post_id )` — ACF's own real save function. Because
   it's the exact same core function `acf_form()` itself calls, it fires
   `'acf/save_post'` exactly as before, so this system's own code (and
   any third-party plugin's callback on that action) keeps working.
6. The `'acf/save_post'` hook still runs the code/title/taxonomy logic
   (generation of the `_code` field, title derivation, taxonomy saving) —
   unchanged from before.
7. **Redirect.** `process_submission()` now knows the real `$post_id`
   directly (it created it, or received it as `edit_id`), so it builds the
   redirect URL immediately, no placeholder needed. The `wp_safe_redirect()`
   + `exit` call happens **after** `acf_save_post()` has fully returned —
   i.e. outside of the `'acf/save_post'` action — so it never cuts off a
   later-priority callback on that hook, exactly as required.

Individual fields are rendered with ACF's own real per-field API:
`acf_get_field()`-equivalent lookup (via the cached field list),
`acf_get_value( $post_id, $field )` to load the current value, and
`acf_render_field_wrap( $field )` — the same internal function ACF's own
admin screens and `acf_form()` use to render one field's label,
instructions, required marker, conditional-logic markup and input,
respecting every native ACF setting (choices, return format, min/max,
multiple, uploader, Relationship/Post Object/Select/Checkbox/Radio/
Date/Number/URL/Email/Text/Text Area, etc.) — no field type is ever hand-
built as a raw `<input>`.

## 7. Security (unchanged guarantees, now enforced earlier)

Authenticated user, nonce, `create_posts`, `publish_posts` (only if
requested `publish` and the user actually has that capability — otherwise
forced to `draft`), `edit_post`, `assign_terms`, CPT whitelist, automatic
code generation with duplicate prevention, automatic title derivation, and
recursion prevention are all unchanged in behavior. **New**: Field Key
whitelisting (a submitted Field Key belonging to another CPT is stripped
before ACF ever sees it) and the same for taxonomies (a taxonomy the
current user can't `assign_terms` on is never rendered nor saved, and only
taxonomies whose Universal Taxonomy Field block was actually placed are
ever processed at all).

## 8. What was replaced

- The v1.x monolithic `uacf/universal-acf-form` block that auto-rendered
  every field — **replaced** by the 5-block system above. The block name
  itself is reused for the new parent/container block, so existing
  content keeps a valid `postType` attribute, but its form body will be
  empty until you manually add child blocks inside it (there was nothing
  to auto-migrate: v1.x never stored individual field placement).
- The `[universal_acf_form post_type="..."]` shortcode — **removed**. A
  shortcode cannot represent arbitrary nested block composition, so
  keeping it would have meant a second, parallel way to submit the same
  form; the request explicitly asked to avoid that.
- `acf_form()` / `acf_form_head()`'s automatic submission handling —
  **replaced** by `process_submission()` calling ACF's own
  `acf_validate_save_post()` / `acf_save_post()` directly (see section 6).
  `acf_form_head()` is still called (for its front-end asset-enqueuing
  role only).
- The `%post_id%` return placeholder — **replaced** by a direct redirect
  URL, since the real post ID is now known immediately (see section 6,
  step 7).
- `<select multiple size="...">` for taxonomies — **replaced** by the
  accessible custom dropdown widget described in section 4.

## 9. Real technical limitations

- **Block Context cannot carry a value computed inside a render_callback**
  — only a parent block's own *attribute* (e.g. `postType`). The resolved
  create/edit mode and the resolved post ID are **not** passed via Block
  Context (there is no real Gutenberg API to do that); instead, every
  block that needs them calls the same cached `resolve_edit_context()`
  helper, which independently re-derives them from the same sanitized
  `?edit_id=` + permission checks used everywhere else. This is a
  documented, deliberate design choice, not an oversight — Block Context
  is used for the one thing it can correctly carry (`uacf/postType`).
- **ACF Free vs. Pro**: Repeater, Flexible Content, Gallery, Clone, and
  options pages remain out of scope (ACF Pro-only), same as before.
- **Conditional Logic** depends on ACF's front-end JS finding both the
  triggering and the dependent field's markup inside the same `<form>` in
  the DOM. If a conditionally-dependent field's Universal ACF Field block
  is never added to the page, its condition simply has nothing to react
  to — this is a natural consequence of letting you omit fields, not a
  bug to work around.
- **Editor preview is always static** for every block (no
  `ServerSideRender`, no REST render of any block, and the parent block's
  own `render_callback` returns a static preview if it's ever hit through
  `REST_REQUEST`) — exactly to prevent ACF's own front-end validation from
  ever running inside the editor and blocking page publishing.
- **Orphaned field/taxonomy blocks**: if a Universal ACF Field or
  Universal Taxonomy Field block is placed outside of a Universal ACF Form
  block, it renders a small notice instead of a field ("This field must be
  placed inside a Universal ACF Form block") rather than crashing.
- **Full-page caching**: as before, exclude pages containing these blocks
  from full-page cache for logged-in users.
