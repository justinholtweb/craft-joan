---
title: Usage
slug: usage
order: 30
summary: The six verdicts, the screens, where the numbers come from, and the console and Twig APIs.
---

## The verdicts

Every field gets one of six. They are the point of the plugin, so they are worth reading once.

| Verdict | Means |
| --- | --- |
| **In use** | In a field layout, and elements hold values for it. |
| **Never filled in** | In a field layout, and not one element has ever been given a value. Either it isn't needed, or nobody knows what it's for. |
| **Stranded** | In no field layout — but content rows still carry values. Nothing renders them and no editor can reach them, and deleting the field destroys them. |
| **Code only** | In no layout and holding no content, but the codebase still names the handle. |
| **Unused** | In no layout, no content, named nowhere in the scanned code. Nothing would miss it. |
| **Not counted** | Used somewhere Joan can see, but somewhere it can't count values — a field type that stores its data elsewhere, or a layout belonging to a plugin that keeps its own. |

That last one matters more than it looks. **A field Joan cannot measure is reported as unknown,
never as zero**, because a zero is what gets a field deleted.

**Unused** is the considered answer. **Stranded** is not a faster route to deleting something —
it means there is content in there that nothing can reach, which is a reason to go and look.

## The screens

- **Overview** — the size of the content model and how much of it nothing is using, plus the
  fields worth a look and the fields carrying the most content.
- **Fields** — every field, filterable by verdict and field type, with a drill-down per field.
- **Entry types** — every entry type and what points at it.
- **Matrix** — every nesting field and what is actually inside it.
- **Layouts** — every field layout and its owner.
- **Cleanup** — everything worth a look, in one list.
- **Settings** — admin only.

### A field's own page

Clicking a field gives you the whole answer in one screen: the verdict, the layouts it appears in
and the handle it answers to in each, how many elements hold a value broken down by element type
and by site, and every line in the codebase that names it — file, line number and the source line
itself.

That last section is what makes "safe to delete" mean something. A field can be in no layout and
hold no content and still be referenced by a template that populates it on save.

## Where the numbers come from

Three kinds of field storage, counted three different ways. Get this wrong and the report is
worse than useless:

- **Most fields** store their value in `elements_sites.content`, keyed by the field layout
  element's UID. Joan reads that table once per rebuild and tallies per field.
- **Relational fields** — Entries, Assets, Categories, Tags, Users — also have rows in
  `relations`. Joan counts both and takes the larger, which matters on a site upgraded from
  Craft 4 whose content was never resaved.
- **Nested-element fields** — Matrix, and CKEditor when it nests entries — own entries of their
  own, joined by `entries.fieldId`, and store nothing in the content column at all.

### What counts as a value

A value counts when *something was entered*. `0` and a switched-off lightswitch are values — an
editor made that choice.

A Table field holding one row of empty cells is not: something was stored, and nothing was
entered. The same goes for a Money field with a currency and no amount, and for any other
composite value made up entirely of blanks.

### Handles that get renamed

Craft 5 lets a field layout override a field's handle for itself, so `heroImage` in one section
and `image` in another can be the same field.

Joan reports every handle a field answers to, and scans the codebase for all of them. A search
for only the field's own handle would miss half its uses — and a field whose every use is under
an overridden handle would come back looking abandoned.

### What the code scan is worth

Joan classifies what it finds rather than counting raw string matches. `entry.myField` is a real
use; the word `myField` inside a comment is not. Both are shown, labelled, so you can see what
the verdict was based on instead of taking its word for it.

## Content with nowhere to live

Craft 5 keys stored content by field layout element, not by field. Take a field out of a layout
and its values stay behind in the content column under a key nothing will ever read again.

Joan finds those keys and tells you how many rows are carrying them. This is the **Stranded**
verdict, and it is the one thing on these screens that is not really about disk space: if a field
was removed from a layout by accident, the content is still there and putting the field back
brings it into reach again.

## From the command line

```sh
php craft joan/fields                      # the inventory
php craft joan/fields --verdict=unused     # just the abandoned ones
php craft joan/fields --verbose            # with where each field is used
php craft joan/fields/show heroImage       # everything about one field
php craft joan/entry-types                 # entry types and what points at them
php craft joan/entry-types/nested          # what's actually inside each Matrix field
php craft joan/unused                      # everything worth a look
```

`--verdict` takes `inUse`, `empty`, `stranded`, `codeOnly`, `unused` or `uncountable`.

Every command takes `--refresh`, which rereads the content table and the codebase instead of
using the cached inventory. It defaults to off everywhere except `joan/unused`, which defaults it
**on** — a build step that fails on stale numbers is worse than no build step.

### Exports

```sh
php craft joan/export fields --format=json --path=storage/joan.json
php craft joan/export cleanup --format=csv --path=storage/cleanup.csv
php craft joan/export nested                      # to stdout
```

Reports: `fields`, `instances`, `entry-types`, `nested`, `layouts`, `cleanup`. Formats: `csv` and
`json`. Omit `--path` and it prints to stdout, which is what you want in a pipe.

The **Fields** and **Cleanup** screens have an **Export CSV** button that produces the same thing.

### In a build

`joan/unused` takes `--fail-on` so it can sit in CI:

```sh
php craft joan/unused --fail-on=unused     # fail if anything is used by nothing
php craft joan/unused --fail-on=stranded   # …or if content has been left with nowhere to live
php craft joan/unused --fail-on=any        # …or on anything at all, including never-filled-in fields
```

Start with `--fail-on=stranded` on an existing site. `unused` is the stricter gate and is most
useful once you have cleared the backlog the first run finds.

## From a template

```twig
{% set summary = craft.joan.summary() %}
{{ summary.unused }} unused fields

{% for field in craft.joan.unusedFields() %}
    {{ field.name }} ({{ field.handle }})
{% endfor %}

{% set body = craft.joan.field('body') %}
{{ body.verdict }}                 {# the raw key: inUse, empty, stranded, codeOnly, unused, uncountable #}
{{ body.usedElements }} elements have a value
{{ body.codeRefTotal }} lines of code name it

{% for layout in craft.joan.usedIn('body') %}
    {{ layout }}
{% endfor %}
```

Also available: `craft.joan.fields()`, `craft.joan.entryTypes()`, `craft.joan.nestedFields()` and
`craft.joan.layouts()`.

A field report answers `isDeletable()` — true only for **Unused** fields that are not on the
leave-alone list — and `wasCounted()`, which is false for the ones Joan could not measure. Check
`wasCounted()` before you believe a `usedElements` of `-1`; that is the "unknown" value, not zero.

These read the same cached inventory the control panel does. Calling them from a front-end
template on a cold cache will build it, so keep them to the control panel and to console-driven
pages unless you know the cache is warm.

## Deleting things

Joan doesn't.

Deleting a field deletes its content for every element, permanently, and that decision belongs on
Craft's own settings screen — with Joan's cleanup list open next to it. Before you start:

```sh
php craft db/backup
```

And read the verdict rather than the number.
