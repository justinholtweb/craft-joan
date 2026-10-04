---
title: FAQ
slug: faq
order: 50
summary: Common questions about auditing a Craft content model with Joan.
---

## Will Joan delete anything?

No. Joan installs no tables, writes nothing, and deletes nothing.

Deleting a field deletes its content for every element, permanently, and that decision belongs on
Craft's own settings screen — with Joan's cleanup list open next to it. A plugin that offered a
**Delete all unused** button would eventually be right about everything except the one field that
mattered.

## What does it cost?

Nothing. Joan is free, one edition, everything included.

It reads. It cannot damage anything, which made it hard to argue anyone should pay to find out
whether it worked on their site.

## Doesn't Craft already tell me this?

It tells you a useful third of it. The field settings screen lists the field layouts a field
appears in — which is real information, and it is where Joan starts too.

What it does not do: count whether a single element has ever had a value in that field, look at
your templates, or identify a field layout it cannot attribute to an element type. For the last
one it says "1 unknown field layout" and leaves you there.

## How is "unused" different from "never filled in"?

**Never filled in** means the field is on a layout, editors can see it, and not one of them has
ever put anything in it. The field is reachable; nobody wants it.

**Unused** means it is on no layout at all, holds no content, and is named nowhere in the scanned
code. Nothing can reach it and nothing would miss it.

The first is a content-model question — why is this on the form? The second is housekeeping.

## What is "stranded" content?

Craft 5 keys stored content by field layout element, not by field. Take a field out of a layout
and its values stay behind in the content column under a key nothing will ever read again.

Joan finds those keys and counts the rows carrying them. It is not a reason to delete the field
faster — it is a reason to check whether the field was removed on purpose. If it was not, putting
it back in the layout brings the content into reach again. Deleting the field destroys it.

## Can I trust the code scan?

Within what a text search can do, and Joan is clear about which is which. It classifies every hit
— `entry.myField` is worth more than the word `myField` in a comment — and shows you the file,
the line number and the source line, so you can read the evidence rather than the conclusion.

What it cannot see: a handle built at runtime. `entry[someVariable]` is invisible to any scanner.
That is what the **leave alone** list is for.

## Does it find fields renamed by a field layout?

Yes, and this is the part most searches get wrong. Craft 5 lets a layout override a field's handle
for itself, so `heroImage` in one section and `image` in another can be the same field. Joan
reports every handle a field answers to and scans the codebase for all of them.

## Will it slow my site down?

No. Joan runs in the control panel and in the console, and the inventory is cached against
Craft's own field version.

The one expensive thing is a rebuild — four passes over the database and the codebase — and it is
behind its own permission for that reason. Nothing in Joan runs on a front-end request unless you
call `craft.joan` from a template yourself.

## Does it work on a big site?

Yes, and there is a switch for when it doesn't. **Count content usage** can be turned off, which
makes every rebuild cheap and every element count unknown. The layout attribution and the code
scan still work.

That is the honest trade. Most sites never need it.

## Does it cover Matrix?

Craft 5 turned Matrix block types into entry types, so they share a screen with everything else.
Joan reports which sections use each entry type, which fields nest it, how many entries exist,
and — the question nothing else answers — which of the block types a Matrix field allows anyone
has ever actually picked.

## Can I run it in CI?

```sh
php craft joan/unused --fail-on=stranded
```

`--fail-on` takes `unused`, `stranded` or `any`. On an existing site start with `stranded`;
`unused` is the stricter gate and is more useful once the first run's backlog is cleared.

## Does it work with PostgreSQL?

Yes. MySQL and PostgreSQL are both supported.

## How does it get on with Microscope?

Fine, and neither needs the other. [Microscope](https://craft-microscope.com) has a check that
counts unused fields as one line in a performance audit, and its own advice is to search the
codebase before deleting anything.

Joan is that search, and the rest of the answer.

## My field type stores its values somewhere else

Then Joan reports it as **Not counted**, and the plugin that owns the field type can report the
real number instead. Two events are documented in
[EXTENDING.md](https://github.com/justinholtweb/craft-joan/blob/main/docs/EXTENDING.md):

- `Inventory::EVENT_DEFINE_FIELD_USAGE` — for a field type whose values Joan cannot see.
- `CodeScan::EVENT_REGISTER_SCAN_PATHS` — for code living outside the configured paths.

## What happens if I uninstall it?

Nothing is left behind. There are no tables to drop and no content to migrate out, because Joan
never put any in.
