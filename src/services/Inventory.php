<?php

namespace justinholtweb\joan\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\base\RelationalFieldInterface;
use craft\base\MissingComponentInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use justinholtweb\joan\events\DefineFieldUsageEvent;
use justinholtweb\joan\models\ContentScan;
use justinholtweb\joan\models\FieldInstance;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\models\Settings;
use justinholtweb\joan\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Builds the field inventory: one report per field, with every usage signal Joan can find.
 *
 * The whole inventory is assembled in one go rather than field by field, because every
 * expensive part of it — the layout walk, the content scan, the code scan — costs the same
 * for one field as for all of them. Building it for a single field would be the slow way
 * to answer a question about eighty.
 */
class Inventory extends Component
{
    /**
     * @event DefineFieldUsageEvent Fired for each field once it's been measured, before its
     *                              verdict is decided. The place for a field type that
     *                              stores its values somewhere Joan can't see to say so.
     */
    public const EVENT_DEFINE_FIELD_USAGE = 'defineFieldUsage';

    private const CACHE_PREFIX = 'joan.inventory';

    /** @var FieldReport[]|null Keyed by field UID. */
    private ?array $reports = null;

    private ?ContentScan $scan = null;

    /** @var array{files: int, bytes: int, truncated: bool, ran: bool, runtime: float, roots: string[]}|null */
    private ?array $codeStats = null;

    /**
     * Every field on the site, reported on.
     *
     * @return FieldReport[] Keyed by field UID.
     */
    public function fields(bool $refresh = false): array
    {
        if ($this->reports !== null && !$refresh) {
            return $this->reports;
        }

        $cache = Craft::$app->getCache();
        $key = $this->cacheKey();

        if (!$refresh) {
            $cached = $cache->get($key);

            if (is_array($cached) && isset($cached['reports'], $cached['scan'])) {
                $this->scan = $cached['scan'];
                $this->codeStats = $cached['codeStats'];

                return $this->reports = $cached['reports'];
            }
        }

        $reports = $this->build();
        $duration = $this->settings()->cacheDuration;

        if ($duration > 0) {
            $cache->set($key, [
                'reports' => $reports,
                'scan' => $this->scan,
                'codeStats' => $this->codeStats,
            ], $duration);
        }

        return $this->reports = $reports;
    }

    public function getByUid(string $uid): ?FieldReport
    {
        return $this->fields()[$uid] ?? null;
    }

    public function getById(int $id): ?FieldReport
    {
        foreach ($this->fields() as $report) {
            if ($report->id === $id) {
                return $report;
            }
        }

        return null;
    }

    public function getByHandle(string $handle): ?FieldReport
    {
        foreach ($this->fields() as $report) {
            if ($report->handle === $handle) {
                return $report;
            }
        }

        return null;
    }

    /**
     * The content scan behind the current inventory. Its `ran` flag is what separates
     * "no elements use this" from "nobody looked".
     */
    public function contentScan(): ContentScan
    {
        $this->fields();

        return $this->scan ?? new ContentScan(['ran' => false]);
    }

    /**
     * @return array{files: int, bytes: int, truncated: bool, ran: bool, runtime: float, roots: string[]}
     */
    public function codeStats(): array
    {
        $this->fields();

        return $this->codeStats ?? [
            'files' => 0,
            'bytes' => 0,
            'truncated' => false,
            'ran' => false,
            'runtime' => 0.0,
            'roots' => [],
        ];
    }

    /**
     * Headline figures for the overview screen.
     *
     * @return array<string, int|bool>
     */
    public function summary(): array
    {
        $reports = $this->fields();
        $byVerdict = array_count_values(array_map(fn(FieldReport $r) => $r->verdict, $reports));
        $layouts = Plugin::getInstance()->layouts->all();
        $entryTypes = Plugin::getInstance()->entryTypes->all();

        return [
            'fields' => count($reports),
            'inUse' => $byVerdict[FieldReport::VERDICT_IN_USE] ?? 0,
            'empty' => $byVerdict[FieldReport::VERDICT_EMPTY] ?? 0,
            'stranded' => $byVerdict[FieldReport::VERDICT_STRANDED] ?? 0,
            'codeOnly' => $byVerdict[FieldReport::VERDICT_CODE_ONLY] ?? 0,
            'unused' => $byVerdict[FieldReport::VERDICT_UNUSED] ?? 0,
            'uncountable' => $byVerdict[FieldReport::VERDICT_UNCOUNTABLE] ?? 0,
            'layouts' => count($layouts),
            'unattributedLayouts' => count(Plugin::getInstance()->layouts->unattributed()),
            'entryTypes' => count($entryTypes),
            'unusedEntryTypes' => count(array_filter($entryTypes, fn($t) => $t->needsAttention())),
            'strandedKeys' => count($this->contentScan()->strandedKeys),
            'contentScanned' => $this->contentScan()->ran,
            'codeScanned' => $this->codeStats()['ran'],
        ];
    }

    /**
     * Throws away everything memoized and cached, so the next read measures the site again.
     */
    public function invalidate(): void
    {
        $this->reports = null;
        $this->scan = null;
        $this->codeStats = null;

        Craft::$app->getCache()->delete($this->cacheKey());

        $plugin = Plugin::getInstance();
        $plugin->layouts->reset();
        $plugin->usage->reset();
        $plugin->entryTypes->reset();
    }

    /**
     * @return FieldReport[]
     */
    private function build(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $this->settings();
        $fields = $this->allFields();
        $instances = $plugin->layouts->instancesByFieldUid();

        // Content is keyed by layout element UID; the scan needs to know which field each
        // key belongs to, and which keys belong to no field at all.
        $keyToField = [];

        foreach ($instances as $fieldUid => $fieldInstances) {
            foreach ($fieldInstances as $instance) {
                if ($instance->elementUid !== '') {
                    $keyToField[$instance->elementUid] = $fieldUid;
                }
            }
        }

        $this->scan = $plugin->usage->scanContent($keyToField);
        $relationCounts = $plugin->usage->relationCounts();
        $nestedCounts = $plugin->usage->nestedCounts();
        $entryTypeNames = $plugin->entryTypes->names();

        $codeRefs = $plugin->codeScan->scan($this->handleMap($fields, $instances));
        $codeTotals = $plugin->codeScan->totals();
        $this->codeStats = $plugin->codeScan->stats();

        $reports = [];

        foreach ($fields as $field) {
            $uid = (string)$field->uid;
            $report = new FieldReport([
                'id' => (int)$field->id,
                'uid' => $uid,
                'name' => (string)$field->name,
                'handle' => (string)$field->handle,
                'type' => $field::class,
                'typeName' => $this->typeName($field),
                'typeExists' => !$field instanceof MissingComponentInterface,
                'context' => (string)$field->context,
                'searchable' => (bool)$field->searchable,
                'translationMethod' => (string)$field->translationMethod,
                'instances' => $instances[$uid] ?? [],
                'strategies' => $this->strategies($field),
                'codeRefs' => $codeRefs[$uid] ?? [],
                'codeScanned' => $this->codeStats['ran'],
                'ignored' => in_array($field->handle, $settings->ignoredFields, true),
            ]);

            $this->applyCounts($report, $field, $relationCounts, $nestedCounts, $entryTypeNames);

            if (isset($codeTotals[$uid])) {
                $report->codeRefTotal = $codeTotals[$uid];
            }

            if ($this->hasEventHandlers(self::EVENT_DEFINE_FIELD_USAGE)) {
                $this->trigger(self::EVENT_DEFINE_FIELD_USAGE, new DefineFieldUsageEvent([
                    'field' => $field,
                    'report' => $report,
                ]));
            }

            $report->verdict = $this->verdict($report);
            $reports[$uid] = $report;
        }

        uasort($reports, fn(FieldReport $a, FieldReport $b) => strcasecmp($a->name, $b->name));

        return $reports;
    }

    /**
     * @param array<int, array{elements: int, targets: int}> $relationCounts
     * @param array<int, array{blocks: int, owners: int, byType: array<int, int>}> $nestedCounts
     * @param array<int, string> $entryTypeNames
     */
    private function applyCounts(
        FieldReport $report,
        FieldInterface $field,
        array $relationCounts,
        array $nestedCounts,
        array $entryTypeNames,
    ): void {
        $scan = $this->scan;

        if ($scan !== null && $scan->ran && in_array(FieldReport::STRATEGY_CONTENT, $report->strategies, true)) {
            $report->usedElements = $scan->countFor($report->uid);
            $report->byElementType = $this->labelTypes($scan->typesFor($report->uid));
            $report->bySite = $this->labelSites($scan->sitesFor($report->uid));
        }

        if (in_array(FieldReport::STRATEGY_RELATIONS, $report->strategies, true)) {
            $relations = $relationCounts[$report->id] ?? null;

            if ($relations !== null) {
                $report->relatedElements = $relations['elements'];
                $report->relatedTargets = $relations['targets'];
                // A relational field stores its target IDs in the content column too, so
                // the content scan usually agrees. When it doesn't — a site upgraded from
                // Craft 4 whose content was never resaved — the relations table is the one
                // telling the truth about what editors selected.
                $report->usedElements = max($report->usedElements, $relations['elements']);
            }
        }

        if (in_array(FieldReport::STRATEGY_NESTED, $report->strategies, true)) {
            $nested = $nestedCounts[$report->id] ?? null;

            if ($nested !== null) {
                $report->nestedElements = $nested['blocks'];
                $report->usedElements = max($report->usedElements, $nested['owners']);

                foreach ($nested['byType'] as $typeId => $count) {
                    $report->nestedByType[$entryTypeNames[$typeId] ?? "Entry type #$typeId"] = $count;
                }

                arsort($report->nestedByType);
            } elseif ($report->usedElements < 0) {
                // A container field with no rows is a definite zero, not an unknown.
                $report->usedElements = 0;
            }
        }
    }

    /**
     * Which storage strategies apply to a field type.
     *
     * @return string[]
     */
    private function strategies(FieldInterface $field): array
    {
        $strategies = [];

        try {
            if ($field::dbType() !== null) {
                $strategies[] = FieldReport::STRATEGY_CONTENT;
            }
        } catch (Throwable) {
            // A field type that can't say where it stores things is one Joan can't count.
        }

        if ($field instanceof RelationalFieldInterface) {
            $strategies[] = FieldReport::STRATEGY_RELATIONS;
        }

        if ($field instanceof ElementContainerFieldInterface) {
            $strategies[] = FieldReport::STRATEGY_NESTED;
        }

        return $strategies !== [] ? $strategies : [FieldReport::STRATEGY_NONE];
    }

    /**
     * The verdict. This is the sentence the whole plugin exists to write.
     */
    private function verdict(FieldReport $report): string
    {
        $hasLayouts = $report->instances !== [];
        $hasContent = $report->usedElements > 0 || $report->nestedElements > 0;

        if (!$hasLayouts) {
            if ($hasContent) {
                return FieldReport::VERDICT_STRANDED;
            }

            if ($report->hasStrongCodeRefs()) {
                return FieldReport::VERDICT_CODE_ONLY;
            }

            // With no code scan there's no basis for calling anything safe to delete, so
            // the honest answer is that we couldn't tell.
            if (!$report->codeScanned) {
                return FieldReport::VERDICT_UNCOUNTABLE;
            }

            return FieldReport::VERDICT_UNUSED;
        }

        if ($hasContent) {
            return FieldReport::VERDICT_IN_USE;
        }

        // In a layout, nothing filled in — but only if we were actually able to look. A
        // field that lives only in a Hyper link layout, or whose type stores its values
        // somewhere Joan doesn't know about, must not be reported as empty.
        if ($report->usedElements < 0) {
            return FieldReport::VERDICT_UNCOUNTABLE;
        }

        return FieldReport::VERDICT_EMPTY;
    }

    /**
     * @return FieldInterface[]
     */
    private function allFields(): array
    {
        $settings = $this->settings();
        $context = $settings->includePluginContexts ? false : 'global';

        try {
            return Craft::$app->getFields()->getAllFields($context);
        } catch (Throwable $e) {
            Craft::error('Could not load fields: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * Every handle every field answers to, mapped back to the fields answering to it.
     *
     * A layout can rename a field's handle for itself, so `heroImage` in one section and
     * `image` in another can be the same field — and a template search for only the
     * field's own handle would miss half its uses.
     *
     * @param FieldInterface[] $fields
     * @param array<string, FieldInstance[]> $instances
     * @return array<string, string[]>
     */
    private function handleMap(array $fields, array $instances): array
    {
        $map = [];

        foreach ($fields as $field) {
            $uid = (string)$field->uid;
            $handles = [(string)$field->handle];

            foreach ($instances[$uid] ?? [] as $instance) {
                $handles[] = $instance->handle;
            }

            foreach (array_unique(array_filter($handles)) as $handle) {
                $map[$handle][] = $uid;
            }
        }

        foreach ($map as &$uids) {
            $uids = array_values(array_unique($uids));
        }

        return $map;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function labelTypes(array $counts): array
    {
        $labelled = [];

        foreach ($counts as $class => $count) {
            $label = class_exists($class) && method_exists($class, 'displayName')
                ? (string)$class::displayName()
                : StringHelper::afterLast($class, '\\');
            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        arsort($labelled);

        return $labelled;
    }

    /**
     * @param array<int, int> $counts
     * @return array<string, int>
     */
    private function labelSites(array $counts): array
    {
        $labelled = [];
        $sites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(true), 'id');

        foreach ($counts as $siteId => $count) {
            $label = isset($sites[$siteId]) ? $sites[$siteId]->name : "Site #$siteId";
            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        arsort($labelled);

        return $labelled;
    }

    private function typeName(FieldInterface $field): string
    {
        try {
            return (string)$field::displayName();
        } catch (Throwable) {
            return StringHelper::afterLast($field::class, '\\');
        }
    }

    /**
     * The cache key carries Craft's own field version, so any change to a field or a field
     * layout invalidates the inventory the moment it's saved — no listening required. The
     * settings hash does the same for a change that alters what counts as usage.
     */
    private function cacheKey(): string
    {
        $settings = $this->settings();
        $signature = md5(serialize([
            $settings->countContent,
            $settings->includeDrafts,
            $settings->scanCode,
            $settings->scanPaths,
            $settings->scanExtensions,
            $settings->scanExclude,
            $settings->includePluginContexts,
            $settings->ignoredFields,
            $settings->maxRefsPerField,
        ]));

        return sprintf(
            '%s.%s.%s',
            self::CACHE_PREFIX,
            Craft::$app->getFields()->getFieldVersion() ?? 'none',
            $signature,
        );
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
