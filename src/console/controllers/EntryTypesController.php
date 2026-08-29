<?php

namespace justinholtweb\joan\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\Plugin;
use yii\console\ExitCode;

/**
 * Entry types and the fields that nest them.
 *
 *     php craft joan/entry-types
 *     php craft joan/entry-types/nested
 */
class EntryTypesController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var bool Rebuild the inventory rather than using a cached one.
     */
    public bool $refresh = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['refresh']);
    }

    /**
     * Lists every entry type with what points at it.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->refresh) {
            $plugin->inventory->invalidate();
        }

        $reports = $plugin->entryTypes->all();

        if ($reports === []) {
            $this->stdout("No entry types.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-30s %6s %8s %9s  %s\n", 'ENTRY TYPE', 'FIELDS', 'ENTRIES', 'USED BY', 'VERDICT'), Console::FG_GREY);

        foreach ($reports as $report) {
            $this->stdout(sprintf(
                "%-30s %6s %8s %9s  ",
                $this->truncate($report->handle, 30),
                $report->fieldCount,
                number_format($report->getTotalEntries()),
                $report->getUsageCount(),
            ));

            $this->stdout($this->label($report->verdict) . "\n", $this->color($report->verdict));

            foreach ($report->sections as $section) {
                $this->stdout("    ↳ section: {$section['name']}\n", Console::FG_GREY);
            }

            foreach ($report->nestingFields as $field) {
                $this->stdout("    ↳ nested in: {$field['name']} ({$field['type']})\n", Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Lists the Matrix-style fields and how much of what is actually inside them.
     */
    public function actionNested(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->refresh) {
            $plugin->inventory->invalidate();
        }

        $reports = $plugin->entryTypes->nestedFields();

        if ($reports === []) {
            $this->stdout("No fields on this site nest entries.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($reports as $report) {
            $this->stdout(sprintf("%s (%s)\n", $report->name, $report->handle), Console::FG_CYAN);
            $this->stdout(sprintf(
                "  %s block(s) across %s owner(s), %s per owner on average\n",
                number_format($report->totalBlocks),
                number_format($report->owners),
                $report->getAverageBlocksPerOwner(),
            ));

            foreach ($report->entryTypes as $entryType) {
                $this->stdout(sprintf(
                    "    %-30s %s\n",
                    $this->truncate($entryType['handle'], 30),
                    number_format($entryType['entries']),
                ), $entryType['entries'] > 0 ? Console::FG_GREEN : Console::FG_YELLOW);
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    private function label(string $verdict): string
    {
        return match ($verdict) {
            EntryTypeReport::VERDICT_IN_USE => 'in use',
            EntryTypeReport::VERDICT_EMPTY => 'empty',
            EntryTypeReport::VERDICT_STRANDED => 'stranded',
            default => 'unused',
        };
    }

    private function color(string $verdict): int
    {
        return match ($verdict) {
            EntryTypeReport::VERDICT_IN_USE => Console::FG_GREEN,
            EntryTypeReport::VERDICT_EMPTY => Console::FG_YELLOW,
            default => Console::FG_RED,
        };
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
