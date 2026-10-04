---
title: Configuration
slug: configuration
order: 20
summary: The settings that change what Joan counts, what it reads, and how long it remembers.
---

The defaults are the right ones for most sites. Everything here lives on **Joan → Settings**,
which is admin-only, and can be set in `config/joan.php` instead if you would rather it were in
version control.

## What gets counted

### Count content usage

**On by default.** Joan counts, per field, how many elements hold a non-empty value.

Turning it off makes every rebuild cheap and every element count unknown — the layout half and
the code half of the inventory still work, and every field's verdict becomes **Not counted**.
It is only worth doing on a site where the content table is genuinely too big to read.

### Count drafts and revisions

**Off by default, and worth leaving off.**

A site keeping fifty revisions per entry will report every field anyone ever used as thoroughly
in use, because the value is still sitting in a revision from 2019. Turning this on answers a
different and much less useful question: has this field *ever* held a value, anywhere, including
in rows nobody will ever look at again.

### Include plugin fields

**Off by default.** Fields belonging to a plugin's own context — Formie's, say — are real fields,
but they are the plugin's business, and a hundred of them buries the twenty that are yours.

### Fields to leave alone

A list of handles Joan never flags, whatever it finds.

This is for the field that is only ever populated by an import script, or read by a service you
have not pointed the code scan at. Every signal Joan has would otherwise call it abandoned, and
an abandoned-looking field that keeps reappearing on the cleanup list is how a real field
eventually gets deleted.

## What gets read

### Paths to scan

`templates`, `modules` and `config` by default, relative to the project root. Add anything else
that could name a field handle — a `src` directory with a custom module in it, a `scripts`
directory full of one-off importers.

Missing directories are skipped quietly, so a shared config listing a path that only exists on
some installs is fine.

Paths must stay inside the project root, because matching lines are shown in the control panel.
A path that resolves outside it, through `../`, an absolute path or a symlink, is skipped and
logged. Symlinked files and dotfiles such as `.env` are never read. Code that lives elsewhere
can be added on purpose with `CodeScan::EVENT_REGISTER_SCAN_PATHS` (see
[Extending](EXTENDING.md)).

### File extensions

`twig`, `html`, `php`, `js`, `vue` by default. The scan reads the file as text and looks for the
handle, so adding an extension costs nothing but time.

### Directories never descended into

`vendor`, `node_modules`, `.git`, `storage` and `cpresources`, at any depth.

`vendor` is the one that matters. A field called `title` or `body` appears a few thousand times
in any vendor tree, and every one of those hits is noise.

### Largest file, and most files

512 KB and 5,000 files by default. A 4 MB minified bundle contributes nothing but time, and
neither does the twelve-thousandth file on a site that has a build directory somewhere Joan was
not told to skip. Set **most files** to `0` for no limit. The ceilings are 4,096 KB per file and
100,000 files.

### References kept per field

50 by default. **The count is always exact** — this only caps how many lines Joan stores to show
you. A field with 400 hits reports 400 and lists the first 50.

## How long it remembers

### Cache duration

900 seconds by default.

The cache key carries Craft's own field version, so **any change to a field or a field layout
invalidates the inventory the moment it is saved**, regardless of this setting. The timer only
exists to catch up with *content* changes, which do not move that version — someone filling in a
field that was empty this morning.

Set it to `0` to rebuild on every page load. Only do that while you are debugging something.

## Config file

Every setting can live in `config/joan.php`. Craft merges it over whatever is stored, so the file
wins — but Joan's settings screen does not mark the fields it has taken over, so if a setting on
that screen does not seem to do anything, look in the file first:

```php
<?php

return [
    'countContent' => true,
    'includeDrafts' => false,
    'scanCode' => true,
    'scanPaths' => ['templates', 'modules', 'config', 'src/importers'],
    'scanExtensions' => ['twig', 'html', 'php', 'js', 'vue'],
    'scanExclude' => ['vendor', 'node_modules', '.git', 'storage', 'cpresources'],
    'maxFileSize' => 512,
    'maxFiles' => 5000,
    'maxRefsPerField' => 50,
    'cacheDuration' => 900,
    'includePluginContexts' => false,
    'ignoredFields' => ['legacyImportRef'],
    'logLevel' => 'info',
];
```

It takes Craft's usual multi-environment shape too, if you want a longer cache in production and
none in dev:

```php
return [
    '*' => [
        'scanPaths' => ['templates', 'modules', 'config'],
    ],
    'dev' => [
        'cacheDuration' => 0,
        'logLevel' => 'debug',
    ],
];
```
