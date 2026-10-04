<?php

namespace justinholtweb\joan\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\models\LayoutRef;
use justinholtweb\joan\Plugin;
use yii\console\ExitCode;

/**
 * Everything nothing appears to be using.
 *
 *     php craft joan/unused
 *     php craft joan/unused --fail-on=unused
 *
 * The `--fail-on` flag is there so this can sit in a build: a pull request that adds a
 * field and forgets to use it fails, and so does one that deletes the last template
 * reference to a field and leaves the field behind.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class UnusedController extends Controller
{
    // Public Properties
    // =========================================================================

    public $defaultAction = 'index';

    /**
     * @var string|null Exit non-zero when anything at this level or worse is found:
     *                  `unused` (nothing references it at all), `stranded` (content with
     *                  nowhere to live), or `any` (including empty and code-only).
     */
    public ?string $failOn = null;

    /**
     * @var bool Rebuild the inventory rather than using a cached one.
     */
    public bool $refresh = true;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['failOn', 'refresh']);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['f' => 'failOn']);
    }

    /**
     * Lists everything unused, stranded or empty, grouped by how worried to be.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $fields = $plugin->inventory->fields($this->refresh);
        $entryTypes = $plugin->entryTypes->all();

        $unusedFields = array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_UNUSED && !$f->ignored);
        $strandedFields = array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_STRANDED && !$f->ignored);
        $emptyFields = array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_EMPTY && !$f->ignored);
        $codeOnlyFields = array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_CODE_ONLY && !$f->ignored);
        $unusedTypes = array_filter($entryTypes, fn(EntryTypeReport $t) => $t->verdict === EntryTypeReport::VERDICT_UNUSED);
        $strandedTypes = array_filter($entryTypes, fn(EntryTypeReport $t) => $t->verdict === EntryTypeReport::VERDICT_STRANDED);

        $unusedBlockTypes = [];

        foreach ($plugin->entryTypes->nestedFields() as $nested) {
            foreach ($nested->getUnusedEntryTypes() as $entryType) {
                $unusedBlockTypes[] = sprintf('%s → %s', $nested->name, $entryType['name']);
            }
        }

        $this->_section('Fields in no layout, with no content and no code references', array_map(
            fn(FieldReport $f) => sprintf('%s (%s)', $f->handle, $f->typeName),
            $unusedFields,
        ), Console::FG_RED);

        $this->_section('Fields in no layout, but content still exists', array_map(
            fn(FieldReport $f) => sprintf('%s — %s element(s) still hold a value', $f->handle, number_format($f->usedElements)),
            $strandedFields,
        ), Console::FG_RED);

        $this->_section('Fields in no layout, but the codebase still names them', array_map(
            fn(FieldReport $f) => sprintf('%s — %s reference(s)', $f->handle, $f->getCodeRefCount()),
            $codeOnlyFields,
        ), Console::FG_YELLOW);

        $this->_section('Fields in a layout that nobody has ever filled in', array_map(
            fn(FieldReport $f) => sprintf('%s — in %s layout(s), 0 elements', $f->handle, $f->getLayoutCount()),
            $emptyFields,
        ), Console::FG_YELLOW);

        $this->_section('Entry types attached to nothing', array_map(
            fn(EntryTypeReport $t) => sprintf('%s (%s)', $t->name, $t->handle),
            $unusedTypes,
        ), Console::FG_RED);

        $this->_section('Entry types attached to nothing, with entries still in them', array_map(
            fn(EntryTypeReport $t) => sprintf('%s — %s entries', $t->name, number_format($t->getTotalEntries())),
            $strandedTypes,
        ), Console::FG_RED);

        $this->_section('Block types a field allows but nothing uses', $unusedBlockTypes, Console::FG_YELLOW);

        $this->_section('Field layouts nothing claims', array_map(
            fn(LayoutRef $layout) => sprintf('#%s (%s), %s field(s)', $layout->id, $layout->typeName ?? 'unknown type', $layout->fieldCount),
            $plugin->layouts->unattributed(),
        ), Console::FG_YELLOW);

        $strandedKeys = $plugin->inventory->contentScan()->strandedKeys;

        $this->_section('Content keys belonging to no field layout', array_map(
            fn(string $key, int $rows) => sprintf('%s — %s row(s)', $key, number_format($rows)),
            array_keys($strandedKeys),
            array_values($strandedKeys),
        ), Console::FG_YELLOW);

        $totals = [
            'unused' => count($unusedFields) + count($unusedTypes),
            'stranded' => count($strandedFields) + count($strandedTypes),
            'other' => count($emptyFields) + count($codeOnlyFields) + count($unusedBlockTypes),
        ];

        if (array_sum($totals) === 0) {
            $this->stdout("Nothing unused. Every field and entry type is attached to something.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "\n%s unused, %s stranded, %s worth a look.\n",
            $totals['unused'],
            $totals['stranded'],
            $totals['other'],
        ));

        if (!$plugin->inventory->codeStats()['ran']) {
            $this->stdout("The codebase wasn't scanned — turn `scanCode` on before trusting “unused”.\n", Console::FG_YELLOW);
        }

        return $this->_exitCode($totals);
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether what was found is bad enough, by the `--fail-on` threshold, to fail the build.
     *
     * @param array<string, int> $totals
     */
    private function _exitCode(array $totals): int
    {
        return match ($this->failOn) {
            'unused' => $totals['unused'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'stranded' => ($totals['unused'] + $totals['stranded']) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            'any' => array_sum($totals) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK,
            default => ExitCode::OK,
        };
    }

    /**
     * Prints one group of findings. An empty group prints nothing, heading included.
     *
     * @param string[] $lines
     */
    private function _section(string $heading, array $lines, int $color): void
    {
        if ($lines === []) {
            return;
        }

        $this->stdout("$heading\n", Console::FG_CYAN);

        foreach ($lines as $line) {
            $this->stdout("  $line\n", $color);
        }

        $this->stdout("\n");
    }
}
