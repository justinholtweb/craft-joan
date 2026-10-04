---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Numbers that look wrong, fields that keep reappearing, and rebuilds that take too long.
---

## Everything says "Not counted"

**Count content usage** is off. Turn it back on in **Joan → Settings**, or check `config/joan.php`
for a `countContent` set to `false` in this environment.

## One field says "Not counted"

That is the honest answer, not a bug. It happens for two reasons:

- **The field type keeps its values somewhere Joan cannot see.** A field type with its own table
  contributes nothing to `elements_sites.content`, `relations` or `entries`, so there is nothing
  to count. The plugin that owns the field type knows the real number, and can report it — see
  [Extending Joan](https://github.com/justinholtweb/craft-joan/blob/main/docs/EXTENDING.md).
- **The field is in a layout belonging to something that keeps its own content.** The layout is
  real and Joan can attribute it; the values behind it are not in the tables Joan reads.

A field Joan cannot measure is never reported as zero. Zero is what gets a field deleted.

## A field I know is used says "Unused"

Work down this list:

1. **Is it populated by something outside the scanned paths?** An importer in `src/`, a
   deployment script, a queue job in a directory Joan was not told about. Add the path in
   **Settings → Paths to scan**.
2. **Is it named indirectly?** `entry[handle]` with a variable handle, or a field set from a
   mapping array, cannot be found by a text search for the handle. Nothing can find those —
   put the field on the **leave alone** list so it stops coming back.
3. **Is the file bigger than the size limit?** 512 KB by default. A generated template can
   exceed it.
4. **Did you hit the file limit?** 5,000 by default. The scan notes on each screen say how many
   files were read; if that number is exactly your limit, raise it or add an exclusion.
5. **Is it only used in a draft or a revision?** Those are excluded by default, deliberately.

## The same field keeps coming back on the cleanup list

Put it on **Fields to leave alone**. That list exists for exactly this: the field every signal
Joan has says is abandoned and you know is not.

If you find yourself adding a third and fourth handle to it, check whether they share a source —
usually an importer in a directory that should be in **Paths to scan** instead.

## The numbers are out of date

The inventory is cached for fifteen minutes by default, keyed on Craft's field version. Any
change to a field or a field layout invalidates it immediately; *content* changes do not.

So if you have just filled in a field and Joan still says nobody has, press **Rebuild**. If that
is a constant annoyance on a dev site, set `cacheDuration` to `0` in `config/joan.php` for that
environment.

## Rebuilding is slow

A rebuild is four passes: the content table, the relations table, the entries table and the
codebase. In that order of likely cost.

- **If the content table is the problem**, the answer is **Count content usage → off**. You lose
  every element count and keep the layout and code halves of the inventory. It is a real loss;
  take it only on a site where the first rebuild is measured in minutes.
- **If the code scan is the problem**, look at what it is reading. `vendor` and `node_modules`
  are excluded by default at any depth, but a build output directory, a `public/dist`, or a
  vendored copy of a framework under a different name will not be. Add it to **Directories never
  descended into** and the scan usually drops by an order of magnitude.
- **Lower "most files"** to put a hard ceiling on the scan. The screens tell you how many files
  were actually read, so you can see when you are hitting it.

Either way, rebuild from the console rather than the browser:

```sh
php craft joan/fields --refresh
```

## "1 unknown field layout" — but Joan shows one too

Joan attributes far more layouts than Craft's own screens do, including the ones belonging to
things that are not registered element types. It does not attribute *all* of them.

A layout nothing claims at all is usually left behind by a plugin that was uninstalled without
cleaning up. That is worth knowing and is exactly what the Layouts screen is for — but Joan will
not invent an owner it cannot find.

## Stranded content — what do I actually do?

Nothing, until you have looked at it.

Stranded means the field was taken out of a layout and the values stayed behind in the content
column under a key nothing reads. Two cases, and they have opposite answers:

- **It was removed on purpose.** The rows are dead weight. Deleting the field clears them.
- **It was removed by accident,** or by a layout edit nobody meant to make. **The content is
  still there.** Put the field back in the layout and it comes back into reach. Delete the field
  and it is gone permanently.

Take a backup before you decide:

```sh
php craft db/backup
```

## Element counts jumped after an upgrade

On a site upgraded from Craft 4 whose content was never resaved, relational fields have rows in
`relations` but nothing in the content column. Joan counts both and takes the larger, so the
number it reports is the true one either way — but it will not match a count you took from the
content table by hand.

## Nothing appears at all

Check the permission. **View the content model inventory** is what puts Joan in the nav; without
it there is nothing to see, and admins have it implicitly so it is easy to miss when testing as
someone else.
