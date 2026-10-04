<?php

namespace justinholtweb\joan\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\Plugin;
use yii\console\ExitCode;

/**
 * The field inventory, from the command line.
 *
 *     php craft joan/fields
 *     php craft joan/fields --verdict=unused
 *     php craft joan/fields/show heroImage
 */
class FieldsController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var string|null Only show fields with this verdict: inUse, empty, stranded, codeOnly, unused, uncountable.
     */
    public ?string $verdict = null;

    /**
     * @var bool Rebuild the inventory rather than using a cached one.
     */
    public bool $refresh = false;

    /**
     * @var bool Print where each field is used, not just how many places.
     */
    public bool $verbose = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'index' => array_merge($options, ['verdict', 'refresh', 'verbose']),
            'show' => array_merge($options, ['refresh']),
            default => array_merge($options, ['refresh']),
        };
    }

    /**
     * Lists every field with what's using it.
     */
    public function actionIndex(): int
    {
        $fields = Plugin::getInstance()->inventory->fields($this->refresh);

        if ($this->verdict !== null) {
            $fields = array_filter($fields, fn(FieldReport $f) => $f->verdict === $this->verdict);
        }

        if ($fields === []) {
            $this->stdout("No fields matched.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-32s %-22s %7s %9s %6s  %s\n", 'FIELD', 'TYPE', 'LAYOUTS', 'ELEMENTS', 'CODE', 'VERDICT'), Console::FG_GREY);

        foreach ($fields as $field) {
            $this->stdout(sprintf(
                "%-32s %-22s %7s %9s %6s  ",
                $this->truncate($field->handle, 32),
                $this->truncate($field->typeName, 22),
                $field->getLayoutCount(),
                $field->wasCounted() ? number_format($field->usedElements) : '?',
                $field->codeScanned ? $field->getCodeRefCount() : '?',
            ));
            $this->stdout($this->verdictLabel($field->verdict) . "\n", $this->verdictColor($field->verdict));

            if ($this->verbose) {
                foreach ($field->instances as $instance) {
                    $this->stdout(sprintf(
                        "    ↳ %s%s\n",
                        $instance->layout->label,
                        $instance->handleOverridden ? " (as $instance->handle)" : '',
                    ), Console::FG_GREY);
                }
            }
        }

        $this->stdout("\n");
        $this->printScanNotes();

        return ExitCode::OK;
    }

    /**
     * Everything known about one field.
     *
     * @param string $handle The field handle.
     */
    public function actionShow(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $plugin->inventory->fields($this->refresh);
        $field = $plugin->inventory->getByHandle($handle);

        if ($field === null) {
            $this->stderr("No field with the handle “{$handle}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("$field->name\n", Console::FG_CYAN);
        $this->stdout(sprintf("  handle    %s\n", $field->handle));
        $this->stdout(sprintf("  type      %s\n", $field->typeName));
        $this->stdout(sprintf("  storage   %s\n", implode(', ', $field->strategies)));
        $this->stdout('  verdict   ');
        $this->stdout($this->verdictLabel($field->verdict) . "\n", $this->verdictColor($field->verdict));
        $this->stdout("\n");

        $this->stdout("Used in\n", Console::FG_CYAN);

        if ($field->instances === []) {
            $this->stdout("  no field layout\n", Console::FG_YELLOW);
        }

        foreach ($field->instances as $instance) {
            $this->stdout(sprintf(
                "  %-40s %s%s%s\n",
                $instance->layout->label,
                $instance->tab !== null ? "tab: $instance->tab" : '',
                $instance->required ? '  required' : '',
                $instance->handleOverridden ? "  (as $instance->handle)" : '',
            ));
        }

        $this->stdout("\nContent\n", Console::FG_CYAN);

        if (!$field->wasCounted()) {
            $this->stdout("  not counted — this field's values aren't stored anywhere Joan can measure\n", Console::FG_YELLOW);
        } else {
            $this->stdout(sprintf("  %s element(s) with a value\n", number_format($field->usedElements)));

            foreach ($field->byElementType as $type => $count) {
                $this->stdout(sprintf("    %-30s %s\n", $type, number_format($count)), Console::FG_GREY);
            }

            if ($field->nestedElements > 0) {
                $this->stdout(sprintf("  %s nested element(s)\n", number_format($field->nestedElements)));

                foreach ($field->nestedByType as $type => $count) {
                    $this->stdout(sprintf("    %-30s %s\n", $type, number_format($count)), Console::FG_GREY);
                }
            }
        }

        $this->stdout("\nCode\n", Console::FG_CYAN);

        if (!$field->codeScanned) {
            $this->stdout("  not scanned\n", Console::FG_YELLOW);
        } elseif ($field->codeRefs === []) {
            $this->stdout("  no references found\n", Console::FG_GREY);
        } else {
            foreach ($field->codeRefs as $ref) {
                $this->stdout(sprintf("  %s:%s  %s\n", $ref->path, $ref->line, $this->truncate($ref->snippet, 90)), $ref->isStrong() ? Console::FG_GREEN : Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    private function printScanNotes(): void
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->inventory->contentScan();
        $code = $plugin->inventory->codeStats();

        if (!$scan->ran) {
            $this->stdout("Content wasn't counted, so element counts read as “?”.\n", Console::FG_YELLOW);
        } elseif ($scan->truncated) {
            $this->stdout("The content scan hit its row limit — counts are lower bounds.\n", Console::FG_YELLOW);
        }

        if (!$code['ran']) {
            $this->stdout("The codebase wasn't scanned, so no field is reported as safe to delete.\n", Console::FG_YELLOW);
        }
    }

    private function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            FieldReport::VERDICT_IN_USE => 'in use',
            FieldReport::VERDICT_EMPTY => 'empty',
            FieldReport::VERDICT_STRANDED => 'stranded',
            FieldReport::VERDICT_CODE_ONLY => 'code only',
            FieldReport::VERDICT_UNUSED => 'unused',
            default => 'uncountable',
        };
    }

    private function verdictColor(string $verdict): int
    {
        return match ($verdict) {
            FieldReport::VERDICT_IN_USE => Console::FG_GREEN,
            FieldReport::VERDICT_UNUSED, FieldReport::VERDICT_STRANDED => Console::FG_RED,
            FieldReport::VERDICT_EMPTY, FieldReport::VERDICT_CODE_ONLY => Console::FG_YELLOW,
            default => Console::FG_GREY,
        };
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
