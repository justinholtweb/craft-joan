<?php

namespace justinholtweb\joan\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use justinholtweb\joan\Plugin;
use justinholtweb\joan\services\Exports;
use Throwable;
use yii\console\ExitCode;

/**
 * Writes a report to a file, or to stdout.
 *
 *     php craft joan/export fields
 *     php craft joan/export instances --format=json --path=storage/joan-instances.json
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class ExportController extends Controller
{
    // Public Properties
    // =========================================================================

    public $defaultAction = 'run';

    /**
     * @var string Output format: csv or json.
     */
    public string $format = Exports::FORMAT_CSV;

    /**
     * @var string|null Where to write. Prints to stdout when omitted.
     */
    public ?string $path = null;

    /**
     * @var bool Rebuild the inventory rather than using a cached one.
     */
    public bool $refresh = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['format', 'path', 'refresh']);
    }

    /**
     * Renders one report and writes it out.
     *
     * @param string $report One of: fields, instances, entry-types, nested, layouts, cleanup.
     */
    public function actionRun(string $report = Exports::REPORT_FIELDS): int
    {
        if (!in_array($report, Exports::reports(), true)) {
            $this->stderr(sprintf("Unknown report “%s”. Try one of: %s\n", $report, implode(', ', Exports::reports())), Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!in_array($this->format, [Exports::FORMAT_CSV, Exports::FORMAT_JSON], true)) {
            $this->stderr("Format must be csv or json.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();

        if ($this->refresh) {
            $plugin->inventory->invalidate();
        }

        $output = $plugin->exports->render($report, $this->format);

        if ($this->path === null) {
            $this->stdout($output);

            return ExitCode::OK;
        }

        try {
            FileHelper::writeToFile($this->path, $output);
        } catch (Throwable $e) {
            $this->stderr("Couldn't write to $this->path: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::IOERR;
        }

        $this->stdout(sprintf("Wrote %s to %s\n", $report, $this->path), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
