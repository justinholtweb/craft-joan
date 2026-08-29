# Joan

A complete inventory of your content model — every field, entry type, Matrix block and field
layout, what actually uses it, and what nothing does.

Every Craft site of any age accumulates content model it no longer needs. A field made for a
section that got redesigned. A Matrix block type nobody has picked in three years. Six entry
types left behind by a plugin that was uninstalled. None of it does much harm, and all of it
makes the site harder to work in — and none of it can be safely removed, because nobody can
prove it isn't used.

Craft won't tell you. Its field settings screen lists the field layouts a field appears in,
which is a useful third of the answer. It doesn't know whether a single element has ever had
a value in that field, it doesn't look at your templates, and when it can't identify who
owns a layout it says "1 unknown field layout" and leaves you there.

Joan answers the whole question. Then it stands out of the way: it installs no tables, it
writes nothing, and it deletes nothing.

## What it does

**Counts what's actually in each field.** Not "is it in a layout" — how many elements hold a
non-empty value, broken down by element type and by site. Drafts and revisions are excluded
by default, because a site keeping fifty revisions per entry will otherwise report every
abandoned field as thoroughly in use.

**Reads your codebase.** A field can be in no layout and hold no content and still be
referenced — by a template that populates it on save, by a module, by a migration. Joan
looks, classifies what it finds (`entry.myField` is worth more than the word `myField` in a
comment), and shows you the lines, so "safe to delete" means something.

**Attributes every field layout.** Including the ones no element type claims — Hyper's link
types are the usual example — and the ones nothing claims at all, which are usually left
behind by an uninstalled plugin.

**Covers entry types and Matrix.** Craft 5 turned Matrix block types into entry types, so
they share a screen: which sections use each one, which fields nest it, how many entries
exist, and — the question nothing else answers — which of the eight block types a Matrix
field allows anyone has ever actually used.

**Finds content that has nowhere to live.** Craft 5 keys stored content by field layout
element, not by field. Take a field out of a layout and its values stay behind in the content
column under a key nothing will ever read again. Joan finds those keys and tells you how many
rows are carrying them.

## Requirements

Craft CMS 5.3.0 or later, PHP 8.2 or later. MySQL and PostgreSQL are both supported.

## Installation

```sh
composer require justinholtweb/craft-joan
php craft plugin/install joan
```

## Using it

Joan adds a **Joan** item to the control panel:

- **Overview** — the size of the content model and how much of it nothing is using.
- **Fields** — every field, filterable, with a drill-down per field.
- **Entry types** — every entry type, and what points at it.
- **Matrix** — every nesting field and what's actually inside it.
- **Layouts** — every field layout and its owner.
- **Cleanup** — everything worth a look, in one list.
- **Settings** — admin only.

### Verdicts

Every field gets one of six verdicts. They're the point of the plugin, so they're worth
reading once:

| Verdict | Means |
| --- | --- |
| **In use** | In a field layout, and elements hold values for it. |
| **Never filled in** | In a field layout, and not one element has ever been given a value. Either it isn't needed, or nobody knows what it's for. |
| **Stranded** | In no field layout — but content rows still carry values. Nothing renders them and no editor can reach them, and deleting the field destroys them. |
| **Code only** | In no layout and holding no content, but the codebase still names the handle. |
| **Unused** | In no layout, no content, named nowhere in the scanned code. Nothing would miss it. |
| **Not counted** | Used somewhere Joan can see, but somewhere it can't count values — a field type that stores its data elsewhere, or a layout belonging to a plugin that keeps its own. |

That last one matters more than it looks. A field Joan can't measure is reported as unknown,
never as zero, because a zero is what gets a field deleted.

### Where the numbers come from

Three kinds of field storage, counted three different ways — get this wrong and the report is
worse than useless:

- **Most fields** store their value in `elements_sites.content`, keyed by the field layout
  element's UID. Joan reads that table once per rebuild and tallies per field.
- **Relational fields** — Entries, Assets, Categories, Tags, Users — also have rows in
  `relations`. Joan counts both and takes the larger, which matters on a site upgraded from
  Craft 4 whose content was never resaved.
- **Nested-element fields** — Matrix, and CKEditor when it nests entries — own entries of
  their own, joined by `entries.fieldId`, and store nothing in the content column at all.

A value counts when something was entered. `0` and a switched-off lightswitch are values —
an editor made that choice. A Table field holding one row of empty cells is not: something
was stored, and nothing was entered. The same goes for a Money field with a currency and no
amount, and for any other composite value made up entirely of blanks.

### Handles that get renamed

Craft 5 lets a field layout override a field's handle for itself, so `heroImage` in one
section and `image` in another can be the same field. Joan reports every handle a field
answers to, and scans the codebase for all of them — a search for only the field's own handle
would miss half its uses.

### From the command line

```sh
php craft joan/fields                      # the inventory
php craft joan/fields --verdict=unused     # just the abandoned ones
php craft joan/fields --verbose            # with where each field is used
php craft joan/fields/show heroImage       # everything about one field
php craft joan/entry-types                 # entry types and what points at them
php craft joan/entry-types/nested          # what's actually inside each Matrix field
php craft joan/unused                      # everything worth a look
php craft joan/export fields --format=json --path=storage/joan.json
```

`joan/unused` takes a `--fail-on` flag so it can sit in a build:

```sh
php craft joan/unused --fail-on=unused     # fail if anything is used by nothing
php craft joan/unused --fail-on=stranded   # …or if content has been left with nowhere to live
php craft joan/unused --fail-on=any        # …or on anything at all, including never-filled-in fields
```

### From a template

```twig
{% set summary = craft.joan.summary() %}
{{ summary.unused }} unused fields

{% for field in craft.joan.unusedFields() %}
    {{ field.name }} ({{ field.handle }})
{% endfor %}

{% set body = craft.joan.field('body') %}
{{ body.usedElements }} elements have a value
```

## Deleting things

Joan doesn't. Deleting a field deletes its content for every element, permanently, and that
decision belongs on Craft's own settings screen — with Joan's cleanup list open next to it.

Before you do:

```sh
php craft db/backup
```

And read the verdict rather than the number. **Unused** is Joan's considered answer.
**Stranded** means there is content in there that nothing can reach — which is a reason to
look at it, not a reason to delete it faster.

## Settings

The defaults are the right ones for most sites. The ones worth knowing about:

- **Count content usage** — off makes rebuilds cheap and every element count unknown. Only
  worth turning off on a site where the content table is too big to read.
- **Count drafts and revisions** — off by default, and worth leaving off.
- **Paths to scan** — `templates`, `modules` and `config` by default. Add anything else that
  could name a field handle.
- **Fields to leave alone** — for the field only ever populated by an import script, which
  every signal Joan has would otherwise call abandoned.

Joan caches the whole inventory against Craft's own field version, so any change to a field
or a field layout invalidates it the moment it's saved. The cache duration only exists to
catch up with *content* changes, which don't move that version.

## Permissions

- **View the content model inventory** — read every screen.
- **Rebuild the inventory** — nested under it. Rebuilding rereads the content table and the
  codebase, so it's the one thing here that costs anything.

Settings are admin-only.

## Alongside Microscope

[Microscope](https://craft-microscope.com) has a check that counts unused fields, as one line
in a performance audit, and its own advice is to search the codebase before deleting
anything. Joan is that search, and the rest of the answer. Neither needs the other.

## Extending it

Two events, both documented in [docs/EXTENDING.md](docs/EXTENDING.md):

- `Inventory::EVENT_DEFINE_FIELD_USAGE` — for a field type that stores its values somewhere
  Joan can't see, so it can report its own count instead of being marked "not counted".
- `CodeScan::EVENT_REGISTER_SCAN_PATHS` — for code that lives outside the configured paths.

## Licence

[The Craft Licence](LICENSE.md). Joan is free.
