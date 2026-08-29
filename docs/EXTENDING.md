# Extending Joan

Joan has two extension points. Both exist for the same reason: Joan can only measure what it
knows how to look at, and it would rather be told than guess.

## Reporting usage Joan can't see

`Inventory::EVENT_DEFINE_FIELD_USAGE` fires once per field, after Joan has measured it and
before it decides on a verdict.

Craft 5 field types keep their values in one of three places — the content column, the
`relations` table, or nested entries — and Joan counts all three. A field type that keeps its
values somewhere else entirely is reported as **not counted**, and no amount of looking at
the content table will change that. The plugin that owns the field type knows the real
answer.

```php
use justinholtweb\joan\events\DefineFieldUsageEvent;
use justinholtweb\joan\services\Inventory;
use yii\base\Event;

Event::on(
    Inventory::class,
    Inventory::EVENT_DEFINE_FIELD_USAGE,
    function(DefineFieldUsageEvent $event) {
        if (!$event->field instanceof MyField) {
            return;
        }

        // How many elements have a value for this field, however you store it.
        $event->report->usedElements = MyPlugin::getInstance()->storage->countFor($event->field);

        // Optional: the breakdowns Joan shows on the field's detail screen.
        $event->report->byElementType = ['craft\elements\Entry' => 40];
        $event->report->bySite = ['Default' => 40];
    }
);
```

Setting `usedElements` to anything other than `-1` makes the field countable, and the verdict
follows from there — **in use** if the count is above zero, **never filled in** if it's zero.
Leaving it at `-1` keeps the field marked "not counted", which is the honest answer when
nobody has looked.

The report is a plain model, so anything on it can be adjusted. Use that lightly: the
verdicts mean something because they come from measurements, and a plugin that writes
`usedElements = 1` to keep its fields off the cleanup list has made Joan useless.

## Adding scan paths

`CodeScan::EVENT_REGISTER_SCAN_PATHS` fires when the scanner works out which directories to
read, after the configured paths have been resolved.

```php
use justinholtweb\joan\events\RegisterScanPathsEvent;
use justinholtweb\joan\services\CodeScan;
use yii\base\Event;

Event::on(
    CodeScan::class,
    CodeScan::EVENT_REGISTER_SCAN_PATHS,
    function(RegisterScanPathsEvent $event) {
        $event->paths[] = Craft::getAlias('@mymodule/templates');
    }
);
```

Paths must be absolute. Anything that isn't a directory is dropped, so there's no need to
check first. The list can also be filtered — replacing `$event->paths` entirely is legal, and
is how you'd stop Joan reading somewhere it shouldn't.

## Reading the inventory from code

Everything the control panel shows is available from the plugin's services, and none of it
changes anything:

```php
use justinholtweb\joan\Plugin;

$joan = Plugin::getInstance();

$fields = $joan->inventory->fields();          // FieldReport[], keyed by field UID
$report = $joan->inventory->getByHandle('body');
$summary = $joan->inventory->summary();

$layouts = $joan->layouts->all();              // LayoutRef[], keyed by layout UID
$entryTypes = $joan->entryTypes->all();        // EntryTypeReport[]
$nested = $joan->entryTypes->nestedFields();   // NestedFieldReport[]

$rows = $joan->exports->rows('fields');        // the CSV/JSON rows
```

`fields()` builds the whole inventory the first time it's called — a pass over the content
table and a pass over the codebase — and caches it. Pass `true` to force a rebuild. The
cache key carries Craft's field version, so it invalidates itself whenever a field or a
field layout changes.

Two things to know before relying on a count:

- `$report->wasCounted()` is `false` when the field's values couldn't be measured. Its
  `usedElements` is `-1` then, not `0`.
- `$joan->inventory->contentScan()->ran` is `false` when content counting is switched off or
  the scan failed. Every count in the inventory is unknown in that case.
