<?php

/**
 * Joan's integration checks.
 *
 * These run inside a real Craft install against real content — the unit suite covers the
 * pure parts, and everything interesting about Joan is the opposite of pure. Run it from
 * the project root of a Craft site with Joan installed, or point it at one:
 *
 *     php checks.php
 *     CRAFT_BASE_PATH=/var/www/html php checks.php
 *
 * Nothing here writes to the site. Settings changes are made in memory only — this harness
 * has contended project config, and a script that persists settings loses races with the
 * queue runner.
 */

use craft\base\ElementContainerFieldInterface;
use craft\base\RelationalFieldInterface;
use craft\db\Query;
use craft\helpers\FileHelper;
use justinholtweb\joan\models\CodeReference;
use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\models\LayoutRef;
use justinholtweb\joan\Plugin;
use justinholtweb\joan\services\CodeScan;
use justinholtweb\joan\services\Exports;
use yii\base\Event;

$root = getenv('CRAFT_BASE_PATH') ?: null;

if ($root === null) {
    // Walk up from the working directory looking for a Craft install, so this runs from
    // wherever it happens to have been dropped.
    $candidate = getcwd() ?: __DIR__;

    while ($candidate !== '' && $candidate !== '/' && $candidate !== dirname($candidate)) {
        if (is_file($candidate . '/vendor/craftcms/cms/bootstrap/console.php')) {
            $root = $candidate;
            break;
        }

        $candidate = dirname($candidate);
    }
}

if ($root === null || !is_file($root . '/vendor/craftcms/cms/bootstrap/console.php')) {
    fwrite(STDERR, "Couldn't find a Craft install. Run this from a Craft project root, or set CRAFT_BASE_PATH.\n");
    exit(1);
}

define('CRAFT_ENVIRONMENT', getenv('CRAFT_ENVIRONMENT') ?: 'dev');
require $root . '/vendor/autoload.php';
require $root . '/vendor/craftcms/cms/bootstrap/console.php';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, callable $test): void
{
    global $passed, $failed, $failures;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = sprintf('threw %s: %s (%s:%s)', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
    }

    if ($result === true) {
        $passed++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }

    $failed++;
    $failures[] = $label;
    printf("  \033[31m✗\033[0m %s\n      %s\n", $label, is_string($result) ? $result : 'returned false');
}

function section(string $name): void
{
    printf("\n\033[36m%s\033[0m\n", $name);
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    fwrite(STDERR, "Joan isn't installed on this site.\n");
    exit(1);
}

$plugin->inventory->invalidate();
$fields = $plugin->inventory->fields(true);
$layouts = $plugin->layouts->all();
$instances = $plugin->layouts->instancesByFieldUid();
$entryTypes = $plugin->entryTypes->all();
$nested = $plugin->entryTypes->nestedFields();
$scan = $plugin->inventory->contentScan();

section('Wiring');

check('the plugin exposes all six services', fn() => $plugin->inventory && $plugin->layouts && $plugin->usage
    && $plugin->codeScan && $plugin->entryTypes && $plugin->exports ?: 'a service is missing');

check('the inventory is not empty', fn() => $fields !== [] ?: 'no fields were inventoried');

check('Joan installs no tables of its own', function() {
    $tables = Craft::$app->getDb()->getSchema()->getTableNames('', true);
    $ours = array_filter($tables, fn(string $t) => str_starts_with($t, 'joan_'));

    return $ours === [] ?: 'found: ' . implode(', ', $ours);
});

check('the Twig variable answers', function() {
    $variable = new justinholtweb\joan\variables\JoanVariable();

    return is_array($variable->summary()) && is_array($variable->fields()) ?: 'variable returned a non-array';
});

section('Field reports');

check('every field has a known verdict', function() use ($fields) {
    $known = [
        FieldReport::VERDICT_IN_USE, FieldReport::VERDICT_EMPTY, FieldReport::VERDICT_STRANDED,
        FieldReport::VERDICT_CODE_ONLY, FieldReport::VERDICT_UNUSED, FieldReport::VERDICT_UNCOUNTABLE,
    ];

    foreach ($fields as $field) {
        if (!in_array($field->verdict, $known, true)) {
            return "$field->handle has verdict “$field->verdict”";
        }
    }

    return true;
});

check('every field report carries the field it describes', function() use ($fields) {
    foreach ($fields as $uid => $field) {
        if ($field->uid !== $uid || $field->id <= 0 || $field->handle === '') {
            return "report keyed $uid describes “$field->handle” (#$field->id)";
        }
    }

    return true;
});

check('reports are keyed by UID and unique by ID', function() use ($fields) {
    $ids = array_map(fn(FieldReport $f) => $f->id, $fields);

    return count($ids) === count(array_unique($ids)) ?: 'two reports share a field ID';
});

check('a field in no layout is never reported as in use', function() use ($fields) {
    foreach ($fields as $field) {
        if ($field->instances === [] && $field->verdict === FieldReport::VERDICT_IN_USE) {
            return "$field->handle";
        }
    }

    return true;
});

check('a field with content is never reported as unused', function() use ($fields) {
    foreach ($fields as $field) {
        if ($field->usedElements > 0 && $field->verdict === FieldReport::VERDICT_UNUSED) {
            return "$field->handle has $field->usedElements elements";
        }
    }

    return true;
});

check('“unused” is never reported without a code scan', function() use ($fields, $plugin) {
    if ($plugin->inventory->codeStats()['ran']) {
        return true;
    }

    foreach ($fields as $field) {
        if ($field->verdict === FieldReport::VERDICT_UNUSED) {
            return "$field->handle was called unused with no code scan";
        }
    }

    return true;
});

check('an uncounted field reports -1, not 0', function() use ($fields) {
    foreach ($fields as $field) {
        if (!$field->wasCounted() && $field->usedElements !== -1) {
            return "$field->handle reports $field->usedElements";
        }
    }

    return true;
});

check('every field names at least one storage strategy', function() use ($fields) {
    foreach ($fields as $field) {
        if ($field->strategies === []) {
            return "$field->handle has none";
        }
    }

    return true;
});

check('strategies match what the field type actually does', function() use ($fields) {
    foreach ($fields as $field) {
        $instance = Craft::$app->getFields()->getFieldByUid($field->uid);

        if ($instance === null) {
            continue;
        }

        $expectsNested = $instance instanceof ElementContainerFieldInterface;
        $hasNested = in_array(FieldReport::STRATEGY_NESTED, $field->strategies, true);

        if ($expectsNested !== $hasNested) {
            return "$field->handle: nested strategy " . ($hasNested ? 'claimed' : 'missing');
        }

        $expectsRelations = $instance instanceof RelationalFieldInterface;
        $hasRelations = in_array(FieldReport::STRATEGY_RELATIONS, $field->strategies, true);

        if ($expectsRelations !== $hasRelations) {
            return "$field->handle: relations strategy " . ($hasRelations ? 'claimed' : 'missing');
        }
    }

    return true;
});

section('Content counting');

check('the content scan ran', fn() => $scan->ran ?: 'the scan did not run');

check('content counts match an independent count', function() use ($fields, $plugin) {
    // Recount from scratch, without any of Joan's machinery, for every field that only
    // stores content. If these ever disagree, the scan is lying and nothing else here
    // matters.
    //
    // The emptiness rule is written out again here rather than borrowed from Joan, because
    // a check that calls the code it's checking proves nothing. It's the documented rule:
    // a value is empty when it is null, a blank string, or a structure containing nothing
    // but those — a Table field holding one row of empty cells has not been filled in.
    // `0` and `false` are values.
    $isEmpty = function(mixed $value) use (&$isEmpty): bool {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return in_array(trim($value), ['', '[]', '{}', 'null'], true);
        }

        if (is_array($value)) {
            foreach ($value as $part) {
                if (!$isEmpty($part)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    };
    $rows = (new Query())
        ->select(['elementId' => 'es.elementId', 'content' => 'es.content'])
        ->from(['es' => 'elements_sites'])
        ->innerJoin(['e' => 'elements'], '[[e.id]] = [[es.elementId]]')
        ->where(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
        ->all();

    $checked = 0;

    foreach ($fields as $field) {
        if ($field->strategies !== [FieldReport::STRATEGY_CONTENT] || $field->instances === []) {
            continue;
        }

        $keys = array_map(fn($i) => $i->elementUid, array_filter($field->instances, fn($i) => $i->layout->isElementLayout));
        $seen = [];

        foreach ($rows as $row) {
            $content = json_decode((string)$row['content'], true);

            if (!is_array($content)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!$isEmpty($content[$key] ?? null)) {
                    $seen[$row['elementId']] = true;
                    break;
                }
            }
        }

        if ($field->usedElements !== count($seen)) {
            return sprintf('%s: Joan says %s, independent count says %s', $field->handle, $field->usedElements, count($seen));
        }

        $checked++;
    }

    return $checked > 0 ?: 'no content-only fields to check';
});

check('a value made only of blanks is not a value', function() use ($plugin) {
    // The case this exists for: an editor adds a Table row and types nothing into it, or a
    // Money field is saved with a currency and no amount. Something is stored, and nothing
    // was entered. Counting those inflates every "in use" figure on a real site.
    $usage = $plugin->usage;
    $method = new ReflectionMethod($usage, 'isEmpty');
    $method->setAccessible(true);

    $cases = [
        [[['col1' => '', 'col2' => '']], true],
        [[['col1' => 'Apple', 'col2' => '3']], false],
        [['amount' => null, 'currency' => 'USD'], false],
        [['amount' => null, 'currency' => null], true],
        [[['a' => ['b' => '']]], true],
        [[['a' => ['b' => 0]]], false],
    ];

    foreach ($cases as $i => [$value, $expected]) {
        if ($method->invoke($usage, $value) !== $expected) {
            return "case $i: " . json_encode($value) . ' was called ' . ($expected ? 'a value' : 'empty');
        }
    }

    return true;
});

check('relation counts match the relations table', function() use ($fields) {
    foreach ($fields as $field) {
        if (!in_array(FieldReport::STRATEGY_RELATIONS, $field->strategies, true) || $field->relatedElements === 0) {
            continue;
        }

        $expected = (int)(new Query())
            ->from(['r' => 'relations'])
            ->innerJoin(['e' => 'elements'], '[[e.id]] = [[r.sourceId]]')
            ->where(['r.fieldId' => $field->id, 'e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->count('DISTINCT [[r.sourceId]]');

        if ($field->relatedElements !== $expected) {
            return sprintf('%s: Joan says %s, relations table says %s', $field->handle, $field->relatedElements, $expected);
        }
    }

    return true;
});

check('nested block counts match the entries table', function() use ($fields) {
    foreach ($fields as $field) {
        if (!in_array(FieldReport::STRATEGY_NESTED, $field->strategies, true)) {
            continue;
        }

        $expected = (int)(new Query())
            ->from(['en' => 'entries'])
            ->innerJoin(['e' => 'elements'], '[[e.id]] = [[en.id]]')
            ->where(['en.fieldId' => $field->id, 'e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->count('*');

        if ($field->nestedElements !== $expected) {
            return sprintf('%s: Joan says %s blocks, entries table says %s', $field->handle, $field->nestedElements, $expected);
        }
    }

    return true;
});

check('drafts and revisions are excluded by default', function() {
    $revisions = (int)(new Query())->from(['elements'])->where(['not', ['revisionId' => null]])->count('*');

    if ($revisions === 0) {
        return true;
    }

    // With revisions on the site, a scan that included them would have read more rows than
    // there are canonical element/site pairs.
    $canonical = (int)(new Query())
        ->from(['es' => 'elements_sites'])
        ->innerJoin(['e' => 'elements'], '[[e.id]] = [[es.elementId]]')
        ->where(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
        ->andWhere(['not', ['es.content' => null]])
        ->andWhere(['not', ['es.content' => ['', '[]', '{}']]])
        ->count('*');

    $scanned = Plugin::getInstance()->inventory->contentScan()->rowsScanned;

    return $scanned === $canonical ?: "scanned $scanned rows, expected $canonical canonical rows";
});

check('counting drafts can only ever increase a count', function() use ($plugin, $fields) {
    $settings = $plugin->getSettings();
    $original = $settings->includeDrafts;
    $settings->includeDrafts = true;
    $plugin->inventory->invalidate();
    $withDrafts = $plugin->inventory->fields(true);
    $settings->includeDrafts = $original;
    $plugin->inventory->invalidate();
    $plugin->inventory->fields(true);

    foreach ($fields as $uid => $field) {
        $other = $withDrafts[$uid] ?? null;

        if ($other === null || !$field->wasCounted() || !$other->wasCounted()) {
            continue;
        }

        if ($other->usedElements < $field->usedElements) {
            return sprintf('%s: %s without drafts, %s with', $field->handle, $field->usedElements, $other->usedElements);
        }
    }

    return true;
});

check('per-element-type counts never exceed the total', function() use ($fields) {
    foreach ($fields as $field) {
        if (!$field->wasCounted()) {
            continue;
        }

        $sum = array_sum($field->byElementType);

        if ($sum > $field->usedElements) {
            return "$field->handle: types sum to $sum, total is $field->usedElements";
        }
    }

    return true;
});

check('per-site counts are at least the element total', function() use ($fields) {
    // An element with a value in three sites is one element and three site rows, so the
    // site figures sum to at least the element count — never less.
    foreach ($fields as $field) {
        if (!$field->wasCounted() || $field->bySite === [] || $field->usedElements === 0) {
            continue;
        }

        if (array_sum($field->bySite) < $field->usedElements) {
            return sprintf('%s: sites sum to %s, elements are %s', $field->handle, array_sum($field->bySite), $field->usedElements);
        }
    }

    return true;
});

check('zero and false count as values, blanks do not', function() {
    $usage = Plugin::getInstance()->usage;
    $method = new ReflectionMethod($usage, 'isEmpty');
    $method->setAccessible(true);
    $cases = [
        [null, true], ['', true], [[], true], ['   ', true], ['null', true], ['[]', true],
        [0, false], [false, false], ['0', false], [['a' => null, 'b' => ''], true],
        [['a' => null, 'b' => 'x'], false],
    ];

    foreach ($cases as [$value, $expected]) {
        if ($method->invoke($usage, $value) !== $expected) {
            return 'wrong for ' . var_export($value, true);
        }
    }

    return true;
});

section('Field layouts');

check('every layout row is accounted for exactly once', function() use ($layouts) {
    $expected = (int)(new Query())->from(['fieldlayouts'])->where(['dateDeleted' => null])->count('*');

    return count($layouts) === $expected ?: sprintf('Joan has %s layouts, the table has %s', count($layouts), $expected);
});

check('every layout has a label and a kind', function() use ($layouts) {
    $kinds = [LayoutRef::KIND_ELEMENT, LayoutRef::KIND_OTHER, LayoutRef::KIND_UNATTRIBUTED];

    foreach ($layouts as $layout) {
        if ($layout->label === '' || !in_array($layout->kind, $kinds, true)) {
            return sprintf('layout #%s: label “%s”, kind “%s”', $layout->id, $layout->label, $layout->kind);
        }
    }

    return true;
});

check('entry type layouts are attributed to their entry type', function() use ($layouts) {
    $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();
    $checked = 0;

    foreach ($entryTypes as $entryType) {
        if ($entryType->fieldLayoutId === null) {
            continue;
        }

        $match = null;

        foreach ($layouts as $layout) {
            if ($layout->id === $entryType->fieldLayoutId) {
                $match = $layout;
                break;
            }
        }

        if ($match === null) {
            return "no layout found for entry type $entryType->handle";
        }

        if ($match->kind !== LayoutRef::KIND_ELEMENT) {
            return "entry type $entryType->handle's layout was classified $match->kind";
        }

        $checked++;
    }

    return $checked > 0 ?: 'no entry types with layouts';
});

check('content keys are unique across layouts', function() use ($instances) {
    $seen = [];

    foreach ($instances as $fieldUid => $fieldInstances) {
        foreach ($fieldInstances as $instance) {
            if ($instance->elementUid === '') {
                continue;
            }

            if (isset($seen[$instance->elementUid])) {
                return "key $instance->elementUid is claimed by two layout elements";
            }

            $seen[$instance->elementUid] = $fieldUid;
        }
    }

    return true;
});

check('Joan finds every usage Craft finds', function() use ($fields) {
    // Craft's own findFieldUsages() is the baseline. Joan must never find fewer — if it
    // does, its layout walk has a hole in it.
    $service = Craft::$app->getFields();

    foreach ($fields as $report) {
        $field = $service->getFieldByUid($report->uid);

        if ($field === null) {
            continue;
        }

        $craftLayouts = array_map(fn($l) => $l->uid, $service->findFieldUsages($field));
        $joanLayouts = array_map(fn($i) => $i->layout->uid, $report->instances);

        foreach (array_unique($craftLayouts) as $uid) {
            if (!in_array($uid, $joanLayouts, true)) {
                return "$report->handle: Craft found layout $uid, Joan didn't";
            }
        }
    }

    return true;
});

check('stranded content keys really belong to no layout', function() use ($scan, $instances) {
    if ($scan->strandedKeys === []) {
        return true;
    }

    $known = [];

    foreach ($instances as $fieldInstances) {
        foreach ($fieldInstances as $instance) {
            $known[$instance->elementUid] = true;
        }
    }

    foreach (array_keys($scan->strandedKeys) as $key) {
        if (isset($known[$key])) {
            return "$key is in a layout after all";
        }

        $inConfig = (int)(new Query())
            ->from(['fieldlayouts'])
            ->where(['like', 'config', $key])
            ->count('*');

        if ($inConfig > 0) {
            return "$key appears in $inConfig layout config(s)";
        }
    }

    return true;
});

check('layouts on non-element types are included, and Craft would skip them', function() use ($layouts) {
    // Layouts in this bucket belong to something no registered element type claims —
    // Hyper's link types, most often. Craft's own "Used by" panel renders these as a bare
    // tally with no name and no link, because it can only label a layout whose element type
    // hands it a provider. Joan names them, and marks their content as uncountable rather
    // than reporting zero.
    $others = array_filter($layouts, fn(LayoutRef $l) => $l->kind === LayoutRef::KIND_OTHER);

    if ($others === []) {
        return true;
    }

    $registered = Craft::$app->getElements()->getAllElementTypes();

    foreach ($others as $layout) {
        if ($layout->isElementLayout || $layout->storesElementContent()) {
            return "$layout->label is treated as an element layout";
        }

        if (in_array($layout->type, $registered, true)) {
            return "$layout->type is a registered element type after all";
        }

        if ($layout->label === '' || str_contains($layout->label, '\\')) {
            return "$layout->type got no readable label";
        }
    }

    return true;
});

check('a field renamed in a layout reports both handles', function() use ($fields) {
    foreach ($fields as $field) {
        if (!$field->hasHandleOverrides()) {
            continue;
        }

        $handles = $field->getAllHandles();

        if (count($handles) < 2 || !in_array($field->handle, $handles, true)) {
            return "$field->handle reports " . implode(', ', $handles);
        }
    }

    return true;
});

section('Entry types and nesting');

check('every entry type is reported', function() use ($entryTypes) {
    $expected = count(Craft::$app->getEntries()->getAllEntryTypes());

    return count($entryTypes) === $expected ?: sprintf('Joan has %s, Craft has %s', count($entryTypes), $expected);
});

check('entry counts split cleanly into top-level and nested', function() use ($entryTypes) {
    foreach ($entryTypes as $report) {
        $expected = (int)(new Query())
            ->from(['en' => 'entries'])
            ->innerJoin(['e' => 'elements'], '[[e.id]] = [[en.id]]')
            ->where(['en.typeId' => $report->id, 'e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->count('*');

        if ($report->getTotalEntries() !== $expected) {
            return sprintf('%s: Joan says %s, entries table says %s', $report->handle, $report->getTotalEntries(), $expected);
        }
    }

    return true;
});

check('an entry type used by nothing is never reported as in use', function() use ($entryTypes) {
    foreach ($entryTypes as $report) {
        if ($report->getUsageCount() === 0 && $report->verdict === EntryTypeReport::VERDICT_IN_USE) {
            return $report->handle;
        }
    }

    return true;
});

check('a nested entry type names the field nesting it', function() use ($entryTypes) {
    foreach ($entryTypes as $report) {
        if ($report->nestedEntries > 0 && $report->nestingFields === [] && $report->sections === []) {
            return "$report->handle has nested entries but no nesting field";
        }
    }

    return true;
});

check('every nesting field is a container field', function() use ($nested) {
    foreach ($nested as $report) {
        $field = Craft::$app->getFields()->getFieldByUid($report->uid);

        if (!$field instanceof ElementContainerFieldInterface) {
            return "$report->handle isn't a container field";
        }
    }

    return true;
});

check('block counts per entry type sum to the field total', function() use ($nested) {
    foreach ($nested as $report) {
        $sum = array_sum(array_column($report->entryTypes, 'entries'));

        if ($sum !== $report->totalBlocks) {
            return sprintf('%s: types sum to %s, field total is %s', $report->handle, $sum, $report->totalBlocks);
        }
    }

    return true;
});

check('a field with blocks has at least one owner', function() use ($nested) {
    foreach ($nested as $report) {
        if ($report->totalBlocks > 0 && $report->owners === 0) {
            return "$report->handle has {$report->totalBlocks} blocks and no owner";
        }
    }

    return true;
});

section('Code scanning');

check('the code scan read something', function() use ($plugin) {
    $stats = $plugin->inventory->codeStats();

    if (!$stats['ran']) {
        return 'the scan did not run';
    }

    return $stats['files'] > 0 ?: 'the scan read no files';
});

check('scan roots all exist', function() use ($plugin) {
    foreach ($plugin->codeScan->roots() as $root) {
        if (!is_dir($root)) {
            return "$root is not a directory";
        }
    }

    return true;
});

check('a handle written into a template is found', function() use ($plugin) {
    $dir = Craft::$app->getPath()->getSiteTemplatesPath() . '/_joan-check';
    $file = $dir . '/probe.twig';
    FileHelper::writeToFile($file, "{{ entry.joanProbeHandle }}\n{{ 'joanQuotedHandle' }}\n// joanBareHandle\n");

    try {
        $scanner = new CodeScan();
        $refs = $scanner->scan([
            'joanProbeHandle' => ['a'],
            'joanQuotedHandle' => ['b'],
            'joanBareHandle' => ['c'],
            'joanAbsentHandle' => ['d'],
        ]);

        if (!isset($refs['a'], $refs['b'], $refs['c'])) {
            return 'a planted handle was missed: found ' . implode(',', array_keys($refs));
        }

        if (isset($refs['d'])) {
            return 'a handle that appears nowhere was reported';
        }

        if ($refs['a'][0]->context !== CodeReference::CONTEXT_PROPERTY) {
            return "entry.joanProbeHandle was classified {$refs['a'][0]->context}";
        }

        if ($refs['b'][0]->context !== CodeReference::CONTEXT_QUOTED) {
            return "'joanQuotedHandle' was classified {$refs['b'][0]->context}";
        }

        if ($refs['c'][0]->context !== CodeReference::CONTEXT_MENTION) {
            return "a bare mention was classified {$refs['c'][0]->context}";
        }

        return true;
    } finally {
        FileHelper::removeDirectory($dir);
    }
});

check('a substring of a longer word is not a match', function() {
    $dir = Craft::$app->getPath()->getSiteTemplatesPath() . '/_joan-check';
    FileHelper::writeToFile($dir . '/probe.twig', "{{ entry.joanProbeHandleExtra }} joanProbeHandle_suffix\n");

    try {
        $refs = (new CodeScan())->scan(['joanProbeHandle' => ['a']]);

        return !isset($refs['a']) ?: 'matched inside a longer identifier';
    } finally {
        FileHelper::removeDirectory($dir);
    }
});

check('only a strong reference counts against deletion', function() {
    $strong = new CodeReference(['context' => CodeReference::CONTEXT_PROPERTY]);
    $quoted = new CodeReference(['context' => CodeReference::CONTEXT_QUOTED]);
    $weak = new CodeReference(['context' => CodeReference::CONTEXT_MENTION]);

    return $strong->isStrong() && $quoted->isStrong() && !$weak->isStrong() ?: 'strength is wrong';
});

check('common handles are flagged as ambiguous', function() {
    return CodeScan::isAmbiguousHandle('title')
        && CodeScan::isAmbiguousHandle('Body')
        && !CodeScan::isAmbiguousHandle('heroCalloutText')
        ?: 'ambiguity detection is wrong';
});

check('the reference count survives the per-field cap', function() use ($fields, $plugin) {
    foreach ($fields as $field) {
        if ($field->codeRefTotal > 0 && count($field->codeRefs) > $field->codeRefTotal) {
            return "$field->handle keeps more references than it counted";
        }

        if (count($field->codeRefs) > $plugin->getSettings()->maxRefsPerField) {
            return "$field->handle kept more than the cap";
        }
    }

    return true;
});

check('a plugin can declare usage Joan cannot see', function() use ($plugin) {
    // The escape hatch for a field type that stores its values somewhere else entirely.
    // Without it, "not counted" would be the last word on such a field forever.
    $target = null;

    foreach ($plugin->inventory->fields() as $field) {
        if (!$field->wasCounted()) {
            $target = $field->handle;
            break;
        }
    }

    if ($target === null) {
        return true;
    }

    $handler = function(justinholtweb\joan\events\DefineFieldUsageEvent $event) use ($target) {
        if ($event->field->handle === $target) {
            $event->report->usedElements = 42;
        }
    };

    Event::on(justinholtweb\joan\services\Inventory::class, justinholtweb\joan\services\Inventory::EVENT_DEFINE_FIELD_USAGE, $handler);
    $plugin->inventory->invalidate();
    $report = $plugin->inventory->fields(true)[array_key_first(array_filter(
        $plugin->inventory->fields(),
        fn($f) => $f->handle === $target,
    ))] ?? null;
    Event::off(justinholtweb\joan\services\Inventory::class, justinholtweb\joan\services\Inventory::EVENT_DEFINE_FIELD_USAGE, $handler);

    $verdict = $report?->verdict;
    $counted = $report?->usedElements;
    $plugin->inventory->invalidate();
    $plugin->inventory->fields(true);

    if ($counted !== 42) {
        return "the event was ignored (count is $counted)";
    }

    return $verdict !== FieldReport::VERDICT_UNCOUNTABLE ?: 'the field is still uncountable';
});

check('a registered scan path is honoured', function() {
    $scanner = new CodeScan();
    $extra = Craft::$app->getPath()->getTempPath() . '/joan-scan-check';
    FileHelper::createDirectory($extra);

    $handler = function(justinholtweb\joan\events\RegisterScanPathsEvent $event) use ($extra) {
        $event->paths[] = $extra;
    };

    Event::on(CodeScan::class, CodeScan::EVENT_REGISTER_SCAN_PATHS, $handler);
    $roots = $scanner->roots();
    Event::off(CodeScan::class, CodeScan::EVENT_REGISTER_SCAN_PATHS, $handler);
    FileHelper::removeDirectory($extra);

    return in_array($extra, $roots, true) ?: 'the added path was dropped';
});

check('a registered path that is not a directory is dropped', function() {
    $scanner = new CodeScan();

    $handler = function(justinholtweb\joan\events\RegisterScanPathsEvent $event) {
        $event->paths[] = '/definitely/not/a/real/directory';
    };

    Event::on(CodeScan::class, CodeScan::EVENT_REGISTER_SCAN_PATHS, $handler);
    $roots = $scanner->roots();
    Event::off(CodeScan::class, CodeScan::EVENT_REGISTER_SCAN_PATHS, $handler);

    return !in_array('/definitely/not/a/real/directory', $roots, true) ?: 'a non-directory was kept';
});

section('Exports');

foreach (Exports::reports() as $report) {
    check("the $report report renders as CSV", function() use ($plugin, $report) {
        $csv = $plugin->exports->render($report, Exports::FORMAT_CSV);

        if ($csv === '') {
            return true; // an empty report is legitimately empty
        }

        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0], escape: '');

        return count($header) > 1 ?: 'the header has one column';
    });

    check("the $report report renders as JSON", function() use ($plugin, $report) {
        $json = json_decode($plugin->exports->render($report, Exports::FORMAT_JSON), true);

        return is_array($json) ?: 'did not decode to an array';
    });
}

check('an unknown report is refused', function() use ($plugin) {
    try {
        $plugin->exports->rows('not-a-report');
    } catch (yii\base\InvalidArgumentException) {
        return true;
    }

    return 'no exception was thrown';
});

check('CSV rows all have the same shape', function() use ($plugin) {
    foreach (Exports::reports() as $report) {
        $rows = $plugin->exports->rows($report);

        if ($rows === []) {
            continue;
        }

        $keys = array_keys($rows[0]);

        foreach ($rows as $i => $row) {
            if (array_keys($row) !== $keys) {
                return "$report row $i has different columns";
            }
        }
    }

    return true;
});

section('Settings and caching');

check('settings validate out of the box', function() {
    $settings = new justinholtweb\joan\models\Settings();

    return $settings->validate() ?: 'defaults failed validation: ' . json_encode($settings->getErrors());
});

check('no setting is required', function() {
    // A `required` rule invalidates the whole model, which blocks saving any setting at all
    // — including on a fresh install, where none of them are set yet.
    $settings = new justinholtweb\joan\models\Settings();

    foreach ($settings->rules() as $rule) {
        if (($rule[1] ?? null) === 'required') {
            return 'a required rule exists: ' . json_encode($rule[0]);
        }
    }

    return true;
});

check('blank lines are stripped from path settings', function() {
    $settings = new justinholtweb\joan\models\Settings();
    $settings->scanPaths = ['templates', '', '  ', 'modules '];
    $settings->scanExtensions = ['.twig', 'PHP', ''];

    return $settings->normalizedScanPaths() === ['templates', 'modules']
        && $settings->normalizedExtensions() === ['twig', 'php']
        ?: 'normalization is wrong';
});

check('the cache key moves with Craft’s field version', function() use ($plugin) {
    $method = new ReflectionMethod($plugin->inventory, 'cacheKey');
    $method->setAccessible(true);
    $before = $method->invoke($plugin->inventory);

    Craft::$app->getFields()->updateFieldVersion();

    $after = $method->invoke($plugin->inventory);

    return $before !== $after ?: 'the key did not change';
});

check('the cache key moves with the settings that change the answer', function() use ($plugin) {
    $method = new ReflectionMethod($plugin->inventory, 'cacheKey');
    $method->setAccessible(true);
    $settings = $plugin->getSettings();
    $before = $method->invoke($plugin->inventory);
    $original = $settings->includeDrafts;
    $settings->includeDrafts = !$original;
    $after = $method->invoke($plugin->inventory);
    $settings->includeDrafts = $original;

    return $before !== $after ?: 'the key did not change';
});

check('an ignored field is never flagged', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $original = $settings->ignoredFields;
    $candidate = null;

    foreach ($plugin->inventory->fields() as $field) {
        if ($field->needsAttention()) {
            $candidate = $field->handle;
            break;
        }
    }

    if ($candidate === null) {
        return true;
    }

    $settings->ignoredFields = [$candidate];
    $plugin->inventory->invalidate();
    $report = $plugin->inventory->getByHandle($candidate);
    $flagged = $report?->needsAttention() ?? true;

    $settings->ignoredFields = $original;
    $plugin->inventory->invalidate();

    return !$flagged ?: "$candidate was still flagged";
});

section('Summary');

check('the summary adds up', function() use ($plugin) {
    $summary = $plugin->inventory->summary();
    $verdictTotal = $summary['inUse'] + $summary['empty'] + $summary['stranded']
        + $summary['codeOnly'] + $summary['unused'] + $summary['uncountable'];

    return $verdictTotal === $summary['fields']
        ?: sprintf('verdicts total %s, fields total %s', $verdictTotal, $summary['fields']);
});

check('the summary agrees with the layout count', function() use ($plugin, $layouts) {
    return $plugin->inventory->summary()['layouts'] === count($layouts) ?: 'they disagree';
});

printf("\n%s%d passed, %d failed\033[0m\n", $failed === 0 ? "\033[32m" : "\033[31m", $passed, $failed);

if ($failures !== []) {
    printf("\nFailed:\n  - %s\n", implode("\n  - ", $failures));
}

exit($failed === 0 ? 0 : 1);
