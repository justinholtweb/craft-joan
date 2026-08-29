# Testing Joan

Two suites, because Joan has two halves.

## Unit suite

```sh
composer install
composer test
```

Plain PHP: no Craft application, no database. It covers the parts of Joan that are
deliberately pure — how a verdict reads, what counts as a strong code reference, how a
textarea full of paths becomes a list, how the counts come back out of a scan.

## Integration checks

Everything interesting about Joan is a question asked of a live site, and none of it can be
meaningfully faked. The integration checks run inside a real Craft install with Joan
installed, against whatever content is there.

`tests/` is `export-ignore`d, so this script isn't in the Composer package — copy it in from
a clone of the repo and run it from the Craft project root:

```sh
php checks.php                        # from a Craft project root, with Joan installed
CRAFT_BASE_PATH=/var/www/html php checks.php   # …or point it at one
```

They write nothing. Settings are changed in memory only and put back, because a script that
persists settings loses races with the queue runner.

What they're really for is the handful of claims that would make Joan dangerous if they were
wrong:

- Content counts match a count taken independently, without any of Joan's machinery.
- Relation and nested-block counts match the `relations` and `entries` tables.
- Drafts and revisions are genuinely excluded, and including them can only increase a count.
- Every row in `fieldlayouts` is accounted for exactly once.
- Joan finds every usage Craft's own `findFieldUsages()` finds — it may find more, never
  fewer.
- Content keys reported as stranded really do appear in no layout config anywhere.
- A field that couldn't be measured reports `-1`, and is never described as unused.
- "Unused" is never reported when the code scan didn't run.

If the harness has no content of a given shape — no revisions, no nesting fields, no layouts
belonging to a non-element owner — the checks that need it pass trivially rather than
failing. Run them against a site with a real content model.
