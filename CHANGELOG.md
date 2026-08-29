# Release Notes for Joan

## 5.0.0 - 2026-08-28

Initial release.

### Added

- A field inventory covering every custom field: where it's used, how much content is in it,
  what the codebase says about it, and a verdict on whether anything would miss it.
- Content usage counted from `elements_sites.content`, the `relations` table and nested
  entries — the three places Craft 5 field types actually keep their values. Drafts and
  revisions are excluded by default.
- Field layout attribution for every layout on the site, including layouts belonging to
  something that isn't a registered element type, and layouts nothing claims at all.
- A codebase scan for field handles, classified by how much each hit is worth, covering every
  handle a field answers to — including the ones a field layout renamed.
- Entry type reports covering sections, nesting fields and entry counts, with Matrix block
  types in the same list, since Craft 5 made them the same thing.
- Per-nesting-field block breakdowns, so block types nobody has ever picked are visible.
- Detection of content keys belonging to no field layout — values left behind when a field is
  taken out of a layout.
- A cleanup screen gathering everything worth a look, and CSV/JSON exports of every report.
- Console commands: `joan/fields`, `joan/fields/show`, `joan/entry-types`,
  `joan/entry-types/nested`, `joan/unused` (with `--fail-on` for CI) and `joan/export`.
- A `craft.joan` Twig variable.
- `Inventory::EVENT_DEFINE_FIELD_USAGE` and `CodeScan::EVENT_REGISTER_SCAN_PATHS`.
