<?php

namespace justinholtweb\joan\services;

use craft\helpers\Json;
use justinholtweb\joan\models\FieldInstance;
use justinholtweb\joan\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Turns a report into something you can take away.
 *
 * A content-model audit is rarely finished in the control panel. It ends up in a spreadsheet
 * a client signs off, or in a ticket, or in a diff between this month and last — so every
 * screen exports, and the CSV is flat and boring on purpose.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class Exports extends Component
{
    // Const Properties
    // =========================================================================

    public const REPORT_FIELDS = 'fields';

    public const REPORT_INSTANCES = 'instances';

    public const REPORT_ENTRY_TYPES = 'entry-types';

    public const REPORT_NESTED = 'nested';

    public const REPORT_LAYOUTS = 'layouts';

    public const REPORT_CLEANUP = 'cleanup';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_JSON = 'json';

    // Public Methods
    // =========================================================================

    /**
     * Every report Joan can export.
     *
     * @return string[]
     */
    public static function reports(): array
    {
        return [
            self::REPORT_FIELDS,
            self::REPORT_INSTANCES,
            self::REPORT_ENTRY_TYPES,
            self::REPORT_NESTED,
            self::REPORT_LAYOUTS,
            self::REPORT_CLEANUP,
        ];
    }

    /**
     * The rows behind a report, ready for either format.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $report): array
    {
        return match ($report) {
            self::REPORT_FIELDS => $this->_fieldRows(),
            self::REPORT_INSTANCES => $this->_instanceRows(),
            self::REPORT_ENTRY_TYPES => $this->_entryTypeRows(),
            self::REPORT_NESTED => $this->_nestedRows(),
            self::REPORT_LAYOUTS => $this->_layoutRows(),
            self::REPORT_CLEANUP => $this->_cleanupRows(),
            default => throw new InvalidArgumentException("Unknown report: $report"),
        };
    }

    /**
     * One report as CSV or pretty-printed JSON.
     */
    public function render(string $report, string $format): string
    {
        $rows = $this->rows($report);

        return $format === self::FORMAT_JSON
            ? Json::encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $this->_toCsv($rows);
    }

    /**
     * A dated filename, so successive exports don't overwrite one another.
     */
    public function filename(string $report, string $format): string
    {
        return sprintf('joan-%s-%s.%s', $report, date('Y-m-d'), $format);
    }

    /**
     * The content type to send a format with.
     */
    public function mimeType(string $format): string
    {
        return $format === self::FORMAT_JSON ? 'application/json' : 'text/csv';
    }

    // Private Methods
    // =========================================================================

    /**
     * One row per field.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _fieldRows(): array
    {
        $rows = [];

        foreach (Plugin::getInstance()->inventory->fields() as $report) {
            $rows[] = [
                'name' => $report->name,
                'handle' => $report->handle,
                'type' => $report->typeName,
                'typeClass' => $report->type,
                'context' => $report->context,
                'verdict' => $report->verdict,
                'layouts' => $report->getLayoutCount(),
                'layoutsCraftShows' => $report->getElementLayoutCount(),
                'elementsWithContent' => $report->wasCounted() ? $report->usedElements : '',
                'nestedElements' => $report->nestedElements,
                'codeReferences' => $report->getCodeRefCount(),
                'usedIn' => implode(' | ', array_map(
                    fn(FieldInstance $i) => $i->layout->label,
                    $report->instances,
                )),
                'handles' => implode(' | ', $report->getAllHandles()),
                'searchable' => $report->searchable ? 'yes' : 'no',
                'translation' => $report->translationMethod,
                'ignored' => $report->ignored ? 'yes' : 'no',
            ];
        }

        return $rows;
    }

    /**
     * One row per field *per layout* — the shape you want when the question is
     * "what's on the Article entry type?" rather than "what is this field?".
     *
     * @return array<int, array<string, mixed>>
     */
    private function _instanceRows(): array
    {
        $rows = [];

        foreach (Plugin::getInstance()->inventory->fields() as $report) {
            foreach ($report->instances as $instance) {
                $rows[] = [
                    'layout' => $instance->layout->label,
                    'layoutType' => $instance->layout->typeName,
                    'layoutKind' => $instance->layout->kind,
                    'tab' => $instance->tab,
                    'field' => $report->name,
                    'handle' => $instance->handle,
                    'originalHandle' => $report->handle,
                    'renamed' => $instance->handleOverridden ? 'yes' : 'no',
                    'label' => $instance->label,
                    'type' => $report->typeName,
                    'required' => $instance->required ? 'yes' : 'no',
                    'conditional' => $instance->conditional ? 'yes' : 'no',
                    'contentKey' => $instance->elementUid,
                ];
            }
        }

        usort($rows, fn(array $a, array $b) => [$a['layout'], $a['tab'] ?? '', $a['field']] <=> [$b['layout'], $b['tab'] ?? '', $b['field']]);

        return $rows;
    }

    /**
     * One row per entry type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _entryTypeRows(): array
    {
        $rows = [];

        foreach (Plugin::getInstance()->entryTypes->all() as $report) {
            $rows[] = [
                'name' => $report->name,
                'handle' => $report->handle,
                'verdict' => $report->verdict,
                'sections' => implode(' | ', array_column($report->sections, 'name')),
                'nestedIn' => implode(' | ', array_column($report->nestingFields, 'name')),
                'fields' => $report->fieldCount,
                'topLevelEntries' => $report->topLevelEntries,
                'nestedEntries' => $report->nestedEntries,
                'totalEntries' => $report->getTotalEntries(),
            ];
        }

        return $rows;
    }

    /**
     * One row per entry type allowed in each nesting field.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _nestedRows(): array
    {
        $rows = [];

        foreach (Plugin::getInstance()->entryTypes->nestedFields() as $report) {
            foreach ($report->entryTypes as $entryType) {
                $rows[] = [
                    'field' => $report->name,
                    'fieldHandle' => $report->handle,
                    'fieldType' => $report->typeName,
                    'entryType' => $entryType['name'],
                    'entryTypeHandle' => $entryType['handle'],
                    'blocks' => $entryType['entries'],
                    'fieldTotalBlocks' => $report->totalBlocks,
                    'fieldOwners' => $report->owners,
                ];
            }

            if ($report->entryTypes === []) {
                $rows[] = [
                    'field' => $report->name,
                    'fieldHandle' => $report->handle,
                    'fieldType' => $report->typeName,
                    'entryType' => '',
                    'entryTypeHandle' => '',
                    'blocks' => 0,
                    'fieldTotalBlocks' => $report->totalBlocks,
                    'fieldOwners' => $report->owners,
                ];
            }
        }

        return $rows;
    }

    /**
     * One row per field layout.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _layoutRows(): array
    {
        $rows = [];
        $counts = Plugin::getInstance()->usage->layoutElementCounts();

        foreach (Plugin::getInstance()->layouts->all() as $layout) {
            $rows[] = [
                'layout' => $layout->label,
                'elementType' => $layout->typeName,
                'kind' => $layout->kind,
                'id' => $layout->id,
                'uid' => $layout->uid,
                'tabs' => $layout->tabCount,
                'fields' => $layout->fieldCount,
                'elements' => $layout->id !== null ? ($counts[$layout->id] ?? 0) : '',
            ];
        }

        return $rows;
    }

    /**
     * Everything Joan thinks is worth a look, in one list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _cleanupRows(): array
    {
        $plugin = Plugin::getInstance();
        $rows = [];

        foreach ($plugin->inventory->fields() as $report) {
            if (!$report->needsAttention()) {
                continue;
            }

            $rows[] = [
                'kind' => 'field',
                'name' => $report->name,
                'handle' => $report->handle,
                'verdict' => $report->verdict,
                'detail' => sprintf(
                    '%s layout(s), %s element(s), %s code reference(s)',
                    $report->getLayoutCount(),
                    $report->wasCounted() ? $report->usedElements : '?',
                    $report->getCodeRefCount(),
                ),
            ];
        }

        foreach ($plugin->entryTypes->all() as $report) {
            if (!$report->needsAttention()) {
                continue;
            }

            $rows[] = [
                'kind' => 'entryType',
                'name' => $report->name,
                'handle' => $report->handle,
                'verdict' => $report->verdict,
                'detail' => sprintf(
                    '%s usage(s), %s entries',
                    $report->getUsageCount(),
                    $report->getTotalEntries(),
                ),
            ];
        }

        foreach ($plugin->entryTypes->nestedFields() as $report) {
            foreach ($report->getUnusedEntryTypes() as $entryType) {
                $rows[] = [
                    'kind' => 'blockType',
                    'name' => sprintf('%s → %s', $report->name, $entryType['name']),
                    'handle' => $entryType['handle'],
                    'verdict' => 'unused',
                    'detail' => 'Allowed by the field, never used in content',
                ];
            }
        }

        foreach ($plugin->layouts->unattributed() as $layout) {
            $rows[] = [
                'kind' => 'layout',
                'name' => $layout->label,
                'handle' => $layout->uid,
                'verdict' => 'unattributed',
                'detail' => sprintf('%s field(s), type %s', $layout->fieldCount, $layout->typeName ?? 'unknown'),
            ];
        }

        foreach ($plugin->inventory->contentScan()->strandedKeys as $key => $count) {
            $rows[] = [
                'kind' => 'contentKey',
                'name' => $key,
                'handle' => '',
                'verdict' => 'stranded',
                'detail' => sprintf('%s row(s) still carry a value under this key', $count),
            ];
        }

        return $rows;
    }

    /**
     * Rows as CSV, with the first row's keys as the header.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function _toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_keys($rows[0]), escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn(mixed $value) => is_bool($value) ? ($value ? 'yes' : 'no') : $this->_csvCell((string)($value ?? '')),
                $row,
            ), escape: '');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralises a cell a spreadsheet would run as a formula. Field and layout names are
     * free text, and this file is opened in Excel by whoever does the cleanup.
     */
    private function _csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
