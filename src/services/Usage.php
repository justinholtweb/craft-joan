<?php

namespace justinholtweb\joan\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Json;
use justinholtweb\joan\models\ContentScan;
use justinholtweb\joan\models\Settings;
use justinholtweb\joan\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Counts what's actually filled in.
 *
 * "Is this field used?" has three different answers in Craft 5 depending on where the field
 * type puts its values, and getting this wrong is the difference between a useful report
 * and a dangerous one:
 *
 * - **Most fields** store a value in `elements_sites.content`, keyed by the field layout
 *   element's UID.
 * - **Relational fields** — Entries, Assets, Categories, Tags, Users — store nothing there
 *   at all. Their values are rows in `relations`. Count them from the content table and
 *   every relational field on the site looks abandoned.
 * - **Nested-element fields** — Matrix, and CKEditor when it nests entries — own entries of
 *   their own, joined by `entries.fieldId`. A Matrix field with ten thousand blocks in it
 *   also stores nothing in the content column.
 *
 * All three are counted against *canonical* elements by default. Drafts and revisions are
 * excluded, because a site keeping fifty revisions per entry will otherwise report a field
 * that was abandoned two years ago as thoroughly in use.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class Usage extends Component
{
    // Const Properties
    // =========================================================================

    /** How many rows the content scan reads at a time. */
    private const BATCH_SIZE = 500;

    /** Hard ceiling on rows read in one content scan, whatever the settings say. */
    private const MAX_ROWS = 2_000_000;

    // Private Properties
    // =========================================================================

    private ?ContentScan $_scan = null;

    /** @var array<int, array{elements: int, targets: int}>|null Field ID => relation counts. */
    private ?array $_relationCounts = null;

    /** @var array<int, array{blocks: int, owners: int, byType: array<int, int>}>|null */
    private ?array $_nestedCounts = null;

    // Public Methods
    // =========================================================================

    /**
     * Walks `elements_sites` once, tallying non-empty values per field.
     *
     * @param array<string, string> $keyToField Content key => field UID.
     */
    public function scanContent(array $keyToField): ContentScan
    {
        if ($this->_scan !== null) {
            return $this->_scan;
        }

        $scan = new ContentScan();

        if (!$this->_settings()->countContent) {
            $scan->ran = false;
            return $this->_scan = $scan;
        }

        $started = microtime(true);

        try {
            $this->_runContentScan($scan, $keyToField);
        } catch (Throwable $e) {
            // A content scan is the expensive, fragile half of the inventory. If it fails,
            // the layout and code halves are still worth showing — but every count must
            // then read as "unknown", never as zero.
            Craft::error('Content scan failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $scan->ran = false;
        }

        $scan->runtime = round(microtime(true) - $started, 3);

        return $this->_scan = $scan;
    }

    /**
     * Relation counts for every relational field, in one query.
     *
     * @return array<int, array{elements: int, targets: int}> Field ID => counts.
     */
    public function relationCounts(): array
    {
        if ($this->_relationCounts !== null) {
            return $this->_relationCounts;
        }

        $counts = [];

        try {
            $query = (new Query())
                ->select([
                    'fieldId' => 'relations.fieldId',
                    'sources' => 'COUNT(DISTINCT [[relations.sourceId]])',
                    'targets' => 'COUNT(DISTINCT [[relations.targetId]])',
                ])
                ->from(['relations' => Table::RELATIONS])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[relations.sourceId]]')
                ->groupBy(['relations.fieldId']);

            $this->_applyElementFilters($query);

            foreach ($query->all() as $row) {
                $counts[(int)$row['fieldId']] = [
                    'elements' => (int)$row['sources'],
                    'targets' => (int)$row['targets'],
                ];
            }
        } catch (Throwable $e) {
            Craft::error('Relation count failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        return $this->_relationCounts = $counts;
    }

    /**
     * Nested-element counts for every field that owns nested entries, in one query.
     *
     * @return array<int, array{blocks: int, owners: int, byType: array<int, int>}> Field ID => counts.
     */
    public function nestedCounts(): array
    {
        if ($this->_nestedCounts !== null) {
            return $this->_nestedCounts;
        }

        $counts = [];

        try {
            $query = (new Query())
                ->select([
                    'fieldId' => 'entries.fieldId',
                    'typeId' => 'entries.typeId',
                    'blocks' => 'COUNT(*)',
                    'owners' => 'COUNT(DISTINCT [[entries.primaryOwnerId]])',
                ])
                ->from(['entries' => Table::ENTRIES])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
                ->where(['not', ['entries.fieldId' => null]])
                ->groupBy(['entries.fieldId', 'entries.typeId']);

            $this->_applyElementFilters($query);

            foreach ($query->all() as $row) {
                $fieldId = (int)$row['fieldId'];
                $typeId = (int)$row['typeId'];

                $counts[$fieldId] ??= ['blocks' => 0, 'owners' => 0, 'byType' => []];
                $counts[$fieldId]['blocks'] += (int)$row['blocks'];
                $counts[$fieldId]['byType'][$typeId] = (int)$row['blocks'];
                // Owners are per (field, type) here; the field-wide figure needs its own pass.
                $counts[$fieldId]['owners'] = max($counts[$fieldId]['owners'], (int)$row['owners']);
            }

            foreach ($this->_nestedOwnerCounts() as $fieldId => $owners) {
                if (isset($counts[$fieldId])) {
                    $counts[$fieldId]['owners'] = $owners;
                }
            }
        } catch (Throwable $e) {
            Craft::error('Nested element count failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        return $this->_nestedCounts = $counts;
    }

    /**
     * Entry counts per entry type, split into section-owned and field-nested.
     *
     * @return array<int, array{topLevel: int, nested: int, byField: array<int, int>}>
     */
    public function entryTypeCounts(): array
    {
        $counts = [];

        try {
            $query = (new Query())
                ->select([
                    'typeId' => 'entries.typeId',
                    'fieldId' => 'entries.fieldId',
                    'total' => 'COUNT(*)',
                ])
                ->from(['entries' => Table::ENTRIES])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
                ->groupBy(['entries.typeId', 'entries.fieldId']);

            $this->_applyElementFilters($query);

            foreach ($query->all() as $row) {
                $typeId = (int)$row['typeId'];
                $fieldId = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;
                $total = (int)$row['total'];

                $counts[$typeId] ??= ['topLevel' => 0, 'nested' => 0, 'byField' => []];

                if ($fieldId === null) {
                    $counts[$typeId]['topLevel'] += $total;
                } else {
                    $counts[$typeId]['nested'] += $total;
                    $counts[$typeId]['byField'][$fieldId] = ($counts[$typeId]['byField'][$fieldId] ?? 0) + $total;
                }
            }
        } catch (Throwable $e) {
            Craft::error('Entry type count failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        return $counts;
    }

    /**
     * Elements assigned to each field layout, so a layout's real reach is visible.
     *
     * @return array<int, int> Layout ID => element count.
     */
    public function layoutElementCounts(): array
    {
        $counts = [];

        try {
            $query = (new Query())
                ->select([
                    'fieldLayoutId' => 'elements.fieldLayoutId',
                    'total' => 'COUNT(*)',
                ])
                ->from(['elements' => Table::ELEMENTS])
                ->where(['not', ['elements.fieldLayoutId' => null]])
                ->groupBy(['elements.fieldLayoutId']);

            $this->_applyElementFilters($query);

            foreach ($query->all() as $row) {
                $counts[(int)$row['fieldLayoutId']] = (int)$row['total'];
            }
        } catch (Throwable $e) {
            Craft::error('Layout element count failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }

        return $counts;
    }

    /**
     * Resets everything measured, so a rescan really rescans.
     */
    public function reset(): void
    {
        $this->_scan = null;
        $this->_relationCounts = null;
        $this->_nestedCounts = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads `elements_sites` in batches and tallies values into the scan.
     *
     * @param array<string, string> $keyToField
     */
    private function _runContentScan(ContentScan $scan, array $keyToField): void
    {
        $maxRows = self::MAX_ROWS;

        $query = (new Query())
            ->select([
                'elementId' => 'elements_sites.elementId',
                'siteId' => 'elements_sites.siteId',
                'content' => 'elements_sites.content',
                'elementType' => 'elements.type',
            ])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where(['not', ['elements_sites.content' => null]])
            ->andWhere(['not', ['elements_sites.content' => ['', '[]', '{}']]])
            // Ordering by element is what makes the per-element deduplication below possible
            // without holding every element ID seen in memory.
            ->orderBy(['elements_sites.elementId' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC]);

        $this->_applyElementFilters($query);

        $currentElementId = null;
        $currentType = null;
        /** @var array<string, true> $currentFields */
        $currentFields = [];
        $lastElementId = 0;
        $lastSiteId = 0;

        while (true) {
            // Keyset pagination rather than `each()`. Both walk the table in element order,
            // but `each()` pages with OFFSET, and OFFSET 400000 makes the database count
            // 400,000 rows it has already given us before it hands over the next 500.
            $batch = (clone $query)
                ->andWhere([
                    'or',
                    ['>', 'elements_sites.elementId', $lastElementId],
                    [
                        'and',
                        ['elements_sites.elementId' => $lastElementId],
                        ['>', 'elements_sites.siteId', $lastSiteId],
                    ],
                ])
                ->limit(self::BATCH_SIZE)
                ->all();

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $elementId = (int)$row['elementId'];
                $siteId = (int)$row['siteId'];
                $lastElementId = $elementId;
                $lastSiteId = $siteId;
                $scan->rowsScanned++;

                if ($elementId !== $currentElementId) {
                    // An element's site rows are adjacent in this ordering, so the running
                    // set can be closed out the moment the ID changes — which is what keeps
                    // this a constant-memory scan rather than one that holds every element
                    // ID it has seen.
                    $this->_flushElement($scan, $currentType, $currentFields);
                    $currentElementId = $elementId;
                    $currentType = (string)$row['elementType'];
                    $currentFields = [];
                    $scan->elementsScanned++;
                }

                $decoded = $this->_decode($row['content']);

                if ($decoded === null) {
                    continue;
                }

                $rowFields = [];

                foreach ($decoded as $key => $value) {
                    if ($this->isEmpty($value)) {
                        continue;
                    }

                    $fieldUid = $keyToField[$key] ?? null;

                    if ($fieldUid === null) {
                        $scan->strandedKeys[$key] = ($scan->strandedKeys[$key] ?? 0) + 1;
                        continue;
                    }

                    $rowFields[$fieldUid] = true;
                    $currentFields[$fieldUid] = true;
                }

                foreach (array_keys($rowFields) as $fieldUid) {
                    $scan->byFieldAndSite[$fieldUid][$siteId] = ($scan->byFieldAndSite[$fieldUid][$siteId] ?? 0) + 1;
                }
            }

            if ($scan->rowsScanned >= $maxRows) {
                $scan->truncated = true;
                break;
            }
        }

        $this->_flushElement($scan, $currentType, $currentFields);

        arsort($scan->strandedKeys);
    }

    /**
     * Counts one element once per field it has a value for, however many sites it's on.
     *
     * @param array<string, true> $fields
     */
    private function _flushElement(ContentScan $scan, ?string $elementType, array $fields): void
    {
        foreach (array_keys($fields) as $fieldUid) {
            $scan->countsByField[$fieldUid] = ($scan->countsByField[$fieldUid] ?? 0) + 1;

            if ($elementType !== null) {
                $scan->byFieldAndType[$fieldUid][$elementType] = ($scan->byFieldAndType[$fieldUid][$elementType] ?? 0) + 1;
            }
        }
    }

    /**
     * How many distinct elements own nested entries, per field.
     *
     * @return array<int, int> Field ID => distinct owners.
     */
    private function _nestedOwnerCounts(): array
    {
        $query = (new Query())
            ->select([
                'fieldId' => 'entries.fieldId',
                'owners' => 'COUNT(DISTINCT [[entries.primaryOwnerId]])',
            ])
            ->from(['entries' => Table::ENTRIES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
            ->where(['not', ['entries.fieldId' => null]])
            ->groupBy(['entries.fieldId']);

        $this->_applyElementFilters($query);

        $counts = [];

        foreach ($query->all() as $row) {
            $counts[(int)$row['fieldId']] = (int)$row['owners'];
        }

        return $counts;
    }

    /**
     * Narrows a query to the elements that count as real content.
     *
     * Every query in this service goes through here, so the drafts setting means the same
     * thing everywhere. It has to: a report where relation counts include revisions and
     * content counts don't is worse than no report.
     */
    private function _applyElementFilters(Query $query): void
    {
        $query->andWhere(['elements.dateDeleted' => null]);

        if (!$this->_settings()->includeDrafts) {
            $query
                ->andWhere(['elements.draftId' => null])
                ->andWhere(['elements.revisionId' => null]);
        }
    }

    /**
     * A content column as an array, whether the driver returned JSON or already decoded it.
     *
     * @return array<string, mixed>|null
     */
    private function _decode(mixed $content): ?array
    {
        if (is_array($content)) {
            return $content;
        }

        if (!is_string($content) || $content === '') {
            return null;
        }

        try {
            $decoded = Json::decodeIfJson($content);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a stored value counts as "nothing was entered here".
     *
     * `0` and `false` deliberately count as values: a lightswitch that an editor turned
     * off is a field they used.
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === [] || $value === '') {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' || $trimmed === '[]' || $trimmed === '{}' || $trimmed === 'null';
        }

        if (is_array($value)) {
            // Composite values — Money's amount/currency, a Table field's rows — are empty
            // only when every part of them is.
            foreach ($value as $part) {
                if (!$this->isEmpty($part)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Joan's settings, typed.
     */
    private function _settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
