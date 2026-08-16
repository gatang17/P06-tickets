# Universal Data View v1.0.0 — installation and usage instructions

File: [`universal-data-view-block.php`](./universal-data-view-block.php)

**This is a separate, independent system from Universal ACF Form.** It only
*reads* data — it never creates, edits, or deletes anything — and uses
entirely different names throughout: class `UADV_System`, block namespace
`uadv/`, CSS/HTML prefix `uadv-`, hooks prefixed `uadv_`. It is meant to be
pasted as its **own** Code Snippets entry, alongside (not replacing) the
Universal ACF Form snippet.

## 1. How to paste it into Code Snippets

1. Copy the **entire** contents of `universal-data-view-block.php`.
2. In **Code Snippets → Add New**, paste it and **remove the first line
   `<?php`** — Code Snippets already treats the editor as PHP.
3. Set **"Run snippet everywhere"** and activate it. Keep your existing
   Universal ACF Form snippet active too; both can run at the same time.

If used as an mu-plugin/plugin file instead, leave the `<?php` tag in.

## 2. The 5 blocks

| Block | Role |
|---|---|
| **Universal Data View** (`uadv/data-view`) | Parent. Picks the CPT, status, ordering, layout and an optional taxonomy/ACF filter. Runs one `WP_Query` and repeats its InnerBlocks once per record. |
| **Universal Data Field** (`uadv/data-field`) | One column/field: native WP data, an ACF field, a taxonomy, or a Relationship Path. |
| **Universal Data Link** (`uadv/data-link`) | One per-record action link: View Details or Edit Record. |
| **Universal Data Empty Message** (`uadv/data-empty-message`) | Shown once, only when the query returns 0 records. |
| **Universal Data Pagination** (`uadv/data-pagination`) | Previous / page numbers / Next, shown once after the list, only if pagination is enabled. |

There is no separate "filter" block: the Query section (taxonomy/ACF field
filter) lives in the parent's own Inspector Controls, since it's a design-
time curation choice (e.g. "only show published records in category X"),
not a front-end search form.

## 3. Building a view: CPT → fields → Table → Cards on mobile

1. Insert a **Universal Data View** block. In its sidebar → **Content**,
   pick the **Content type (CPT)**. Under **Layout**, the defaults are
   already **Desktop Layout: Table** / **Mobile Layout: Cards**.
2. Inside it, insert one **Universal Data Field** block per column you
   want. For each one, use its **Source** panel to pick where the value
   comes from (Post Title, an ACF Field, a Taxonomy, a Relationship Path,
   etc.), then its **Display Options** panel for the column label,
   alignment, prefix/suffix, Display As (Plain/Badge/Image), and its
   **Link** panel for whether/where the value should link.
3. Optionally add a **Universal Data Link** block (View Details / Edit
   Record) as one more "column".
4. Optionally add a **Universal Data Empty Message** and/or a
   **Universal Data Pagination** block anywhere inside the view (they
   don't repeat per record — Empty Message only shows with 0 results,
   Pagination only shows once, after the list, when pagination is on).
5. Publish/update the page. On the front-end you get one real, safe
   `WP_Query`, and on a narrow screen the **same table automatically
   collapses to one card per record** — no separate markup, no
   JavaScript needed for that transformation (see §6).

Each block (view, field, link, message, pagination) supports
`customClassName`, `anchor`, Spacing (margin/padding) and Color (text/
background) from its own **Advanced**/**Styles** panels — Field/Link/
Message/Pagination also get Typography (font size, weight, style, line
height, text transform/decoration). Style there or via the theme's
Additional CSS — never in the PHP file (see §8).

## 4. Selecting a Relationship Path

On a **Universal Data Field** block, set **Source Type → Relationship
Path**. A "Step 1" dropdown appears, listing every field of the view's own
CPT — Post Object/Relationship fields are marked with a `→`. Pick one:

- If it's **not** relational, that's the final value shown (no more steps).
- If it **is** relational, a "Step 2" dropdown appears for whatever CPT
  that field is configured to relate to (or, if the field allows more than
  one — or any — target CPT, the union of every discovered CPT's fields,
  each labeled with its own content type, since which one actually applies
  can only be known per-record at render time).

Keep picking relational fields to go deeper (up to 5 steps), and stop on a
displayable field. The path is stored as a plain list of Field Keys —
never hand-written PHP — and resolved fresh for each record at render
time by `UADV_System::resolve_relationship_path()`, which validates every
Field Key against whatever CPT it's actually being looked up on at that
point in the chain, follows Post Object/Relationship hops safely, and
returns an empty value (never an error) if a relation doesn't exist or
isn't visible to the current viewer.

## 5. Link Destination (Universal Data Field → Link panel)

- **None** — plain value, no link.
- **Current Record** — the whole formatted value links to the row/card's
  own permalink.
- **Related Record** — only meaningful for a Post Object/Relationship
  field (or a Relationship Path ending on one): **each** related item gets
  its **own** link to its own permalink, never one link wrapping a joined
  list of several titles. Existence and read-visibility are checked before
  a link is ever produced.
- **Custom URL** — either another ACF field of type URL on the same
  record, or a fixed URL you type in.

**Make Entire Record Clickable** (view's Layout panel) never nests an
`<a>` inside another `<a>`:
- On **Grid/List** layout, the whole card gets a real, JS-free, fully
  keyboard-accessible `<a>` overlay; any real link produced by a field's
  own Link Destination sits visually above it (via `z-index`) and stays
  independently clickable.
- On **Table** layout, browsers don't allow an `<a>` as a direct child of
  `<tr>` (only `<td>`/`<th>` are valid there), so a pure-CSS whole-row
  overlay isn't achievable. This case uses a small, optional front-end
  script instead: clicking anywhere in the row that isn't a real link
  navigates to the record; a click that lands on/inside any real `<a>`
  is explicitly left alone. See §9 for the honest accessibility trade-off
  this implies, and why a Universal Data Link block is still recommended.

## 6. How the responsive table→cards transform works

The exact same `<table>` markup is used for every viewport — there's no
separate "mobile HTML". Each `<td>` carries a `data-label="Column Label"`
attribute (sourced from each field block's own configuration, never a
hardcoded string). Below the configured **Mobile Breakpoint**, the shared
CSS switches the table/rows/cells to `display: block` (removing the table
layout constraints entirely — no inherited desktop `min-width`, no forced
horizontal scroll) and shows each cell's `data-label` via a small
`::before` pseudo-element instead of the (now-hidden) header row. Choosing
**Horizontal Scroll Table** as the Mobile Layout skips this transform
entirely and instead makes the table's outer wrapper horizontally
scrollable at that breakpoint, keeping the literal table layout intact.

## 7. Tests to run

1. **CPT/field/taxonomy discovery**: the pickers show every public CPT,
   every ACF field of the selected CPT (with type shown), and every public
   taxonomy — with nothing hardcoded, and nothing internal to WordPress/
   ACF/Gutenberg listed.
2. **Repetition**: with 3+ records and 2+ Universal ACF Field blocks in
   the view, confirm every record gets its own row/card and every field
   block's value actually changes per record (not the same value repeated).
3. **Field Key isolation**: confirm a Universal ACF Field block only ever
   offers fields belonging to the CPT selected in its parent view; try
   editing the block's raw attributes (or the page's HTML block source) to
   set a Field Key from a *different* CPT and confirm the front-end simply
   shows nothing for that cell rather than leaking the other CPT's data.
4. **Formatting**: check Text, Number, Email, URL, Select, Checkbox, True/
   False, Date, Image, File, Post Object, Relationship, User, and ACF
   Taxonomy fields — none should ever print `Array`, `Object`, or
   `undefined`; empty values should show your configured Empty Value Text
   (or nothing, if Hide Empty Value is on).
5. **Relationship Path**: build a 2–3 step path, confirm the final value
   matches the related record's real field value, and that editing the
   related record updates the displayed value on next page load.
6. **Link Destination**: test None/Current/Related/Custom on a Relationship
   field with multiple related posts — confirm each related title is its
   own separate `<a>`, and view source to confirm no `<a>` is nested
   inside another `<a>` anywhere on the page.
7. **Entire Record Clickable**: on Grid/List, click a nested link inside a
   card and confirm it navigates to *that* link's target, not the card's
   own permalink; click empty card space and confirm it navigates to the
   record. Repeat on Table layout (mouse click, then Tab+Enter on a
   focused row).
8. **Responsive collapse**: resize the browser across the configured
   Mobile Breakpoint and confirm the desktop header disappears completely,
   each record becomes a compact "Label: value" block (not a giant
   vertical header), and there is no horizontal scrollbar unless Mobile
   Layout is explicitly set to Horizontal Scroll Table.
9. **Pagination**: with two Universal Data View instances with pagination
   enabled on the same page, confirm paging one doesn't affect the other
   (distinct query-var names), and that Previous/Next/page-number links
   preserve the view's own configured filters.
10. **Permissions**: as a logged-out visitor or a user without `edit_post`
    on a given record, confirm Universal Data Link's "Edit Record" option
    doesn't appear for that record; confirm draft/private posts never
    appear in a public listing unless the current user can actually read
    them (`perm => 'readable'`).
11. **Query safety**: try manipulating the page URL's pagination query
    var with a non-numeric or huge value and confirm it's safely clamped,
    never causing a fatal error or an arbitrary query.
12. **No fatal errors**: temporarily deactivate ACF and confirm every
    Universal Data View on the site shows a clear notice instead of a
    white screen; reactivate and confirm everything resumes.

## 8. Where to style this (read before touching the file)

Same discipline as Universal ACF Form: `get_frontend_css()` only contains
what the widgets genuinely need to *work* (table↔cards mechanics, the
clickable-card z-index layering, grid column/gap custom properties) — no
color, font, border, or shadow. Style visually via each block's own
Styles panel (Color/Spacing/Typography, already wired) or via Additional
CSS class(es) + the theme's stylesheet/Customizer. For Badges specifically:
`displayAs: Badge` wraps the value in
`<span class="uadv-badge uadv-value-{sanitized-value}">` (e.g.
`uadv-value-operational`) — no color is assumed; define
`.uadv-value-operational { ... }` etc. yourself, wherever you keep your
site's CSS.

## 9. Real technical limitations

- **Relationship Path + ambiguous target CPT**: when a Post Object/
  Relationship field allows more than one (or any) target post type, the
  editor's next-step picker offers every discovered CPT's fields together;
  the *actual* per-record resolution still only succeeds when that
  specific record's real related post genuinely has the chosen Field Key
  — otherwise it safely resolves to empty for that record. This is
  inherent to letting the field's target vary per record, not a bug.
- **Table "Make Entire Record Clickable"** relies on a small optional
  front-end script, not a pure-CSS/HTML technique — real semantic
  `<table>` markup does not allow an `<a>` to span an entire `<tr>`
  (browsers only accept `<td>`/`<th>` as direct row children). Grid/List
  layout doesn't have this limitation (a real, always keyboard-accessible
  `<a>` overlay). For guaranteed keyboard/screen-reader navigation on
  Table layout specifically, add a Universal Data Link block.
- **Show Label on Desktop/Mobile** for Grid/List layout only takes effect
  starting from the mobile breakpoint boundary generated per view
  instance; combining it with a per-field custom CSS `display` override
  could interact unexpectedly — this is an edge case, not the default path.
- **Grid tablet column count** applies within a fixed 783–1024px window in
  the shared stylesheet, independent of a custom Mobile Breakpoint value;
  if you set an unusual breakpoint, the tablet tier may not line up
  exactly — override via your own CSS if needed.
- **ACF Free vs. Pro**: Repeater, Flexible Content, Gallery, Clone, and
  options pages are out of scope, same as Universal ACF Form.
- **Meta-based filtering/sorting** (the Query panel's ACF field filter,
  and ACF-field ordering) works with `meta_query`/`meta_key` against the
  field's real stored meta value — best suited to scalar field types
  (text, number, select, true/false); it is not a substitute for a real
  search index for complex/relational fields.
- **Full-page caching**: exclude pages containing these blocks from cache
  for logged-in users, or clear the cache when linked records change,
  same recommendation as any dynamic WordPress listing.
