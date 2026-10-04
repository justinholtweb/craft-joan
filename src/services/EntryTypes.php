<?php

namespace justinholtweb\joan\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\base\FieldLayoutProviderInterface;
use craft\models\EntryType;
use craft\models\Section;
use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\NestedFieldReport;
use justinholtweb\joan\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The entry type half of the inventory — which covers Matrix blocks too.
 *
 * Craft 5 turned Matrix block types into entry types, which is tidy and has one awkward
 * consequence: a Craft 4 site that upgrades arrives with an entry type for every block
 * type it ever had, all sitting in one flat list, and no screen anywhere says which of
 * them a section or a Matrix field still points at. An entry type attached to nothing is
 * invisible until you go looking for it.
 *
 * The nesting side is deliberately asked of the *field*, through
 * `ElementContainerFieldInterface::getFieldLayoutProviders()`, rather than read out of
 * Matrix's settings. Any field type that nests entries answers that — so a third-party
 * container field is inventoried on the same terms as Matrix, without Joan knowing it
 * exists.
 */
class EntryTypes extends Component
{
    /** @var EntryTypeReport[]|null Keyed by entry type UID. */
    private ?array $reports = null;

    /** @var NestedFieldReport[]|null Keyed by field UID. */
    private ?array $nested = null;

    /** @var array<int, string>|null */
    private ?array $names = null;

    /**
     * @return EntryTypeReport[] Keyed by entry type UID.
     */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $entryTypes = $this->entryTypes();
        $counts = Plugin::getInstance()->usage->entryTypeCounts();
        $sectionsByType = $this->sectionsByEntryType();
        $fieldsByType = $this->nestingFieldsByEntryType();
        $fieldNames = $this->fieldNames();
        $reports = [];

        foreach ($entryTypes as $entryType) {
            $uid = (string)$entryType->uid;
            $count = $counts[$entryType->id] ?? ['topLevel' => 0, 'nested' => 0, 'byField' => []];

            $report = new EntryTypeReport([
                'id' => (int)$entryType->id,
                'uid' => $uid,
                'name' => (string)$entryType->name,
                'handle' => (string)$entryType->handle,
                'icon' => $entryType->icon,
                'color' => $entryType->color?->value,
                'cpEditUrl' => $this->editUrl($entryType),
                'hasTitleField' => (bool)$entryType->hasTitleField,
                'fieldLayoutId' => $entryType->fieldLayoutId,
                'fieldCount' => $this->fieldCount($entryType),
                'sections' => $sectionsByType[$entryType->id] ?? [],
                'nestingFields' => $fieldsByType[$entryType->id] ?? [],
                'topLevelEntries' => $count['topLevel'],
                'nestedEntries' => $count['nested'],
            ]);

            foreach ($count['byField'] as $fieldId => $entries) {
                $report->entriesByField[$fieldNames[$fieldId] ?? "Field #$fieldId"] = $entries;
            }

            arsort($report->entriesByField);
            $report->verdict = $this->verdict($report);
            $reports[$uid] = $report;
        }

        uasort($reports, fn(EntryTypeReport $a, EntryTypeReport $b) => strcasecmp($a->name, $b->name));

        return $this->reports = $reports;
    }

    public function getByUid(string $uid): ?EntryTypeReport
    {
        return $this->all()[$uid] ?? null;
    }

    /**
     * Every field that nests entries, with how much of what is actually in it.
     *
     * @return NestedFieldReport[] Keyed by field UID.
     */
    public function nestedFields(): array
    {
        if ($this->nested !== null) {
            return $this->nested;
        }

        $counts = Plugin::getInstance()->usage->nestedCounts();
        $reports = [];

        foreach ($this->containerFields() as $field) {
            $count = $counts[$field->id] ?? ['blocks' => 0, 'owners' => 0, 'byType' => []];

            $report = new NestedFieldReport([
                'id' => (int)$field->id,
                'uid' => (string)$field->uid,
                'name' => (string)$field->name,
                'handle' => (string)$field->handle,
                'type' => $field::class,
                'typeName' => $this->displayName($field),
                'cpEditUrl' => \craft\helpers\UrlHelper::cpUrl("settings/fields/edit/$field->id"),
                'totalBlocks' => $count['blocks'],
                'owners' => $count['owners'],
            ]);

            foreach ($this->providersFor($field) as $provider) {
                if (!$provider instanceof EntryType) {
                    continue;
                }

                $report->entryTypes[] = [
                    'id' => (int)$provider->id,
                    'name' => (string)$provider->name,
                    'handle' => (string)$provider->handle,
                    'entries' => $count['byType'][$provider->id] ?? 0,
                    'url' => $this->editUrl($provider),
                ];
            }

            usort($report->entryTypes, fn(array $a, array $b) => $b['entries'] <=> $a['entries']);
            $reports[(string)$field->uid] = $report;
        }

        uasort($reports, fn(NestedFieldReport $a, NestedFieldReport $b) => strcasecmp($a->name, $b->name));

        return $this->nested = $reports;
    }

    /**
     * @return array<int, string> Entry type ID => name.
     */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = [];

        foreach ($this->entryTypes() as $entryType) {
            $names[(int)$entryType->id] = (string)$entryType->name;
        }

        return $this->names = $names;
    }

    public function reset(): void
    {
        $this->reports = null;
        $this->nested = null;
        $this->names = null;
    }

    /**
     * @return EntryType[]
     */
    private function entryTypes(): array
    {
        try {
            return Craft::$app->getEntries()->getAllEntryTypes();
        } catch (Throwable $e) {
            Craft::error('Could not load entry types: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * @return array<int, array<int, array{id: int, name: string, handle: string, url: string|null}>>
     */
    private function sectionsByEntryType(): array
    {
        $map = [];

        try {
            $sections = Craft::$app->getEntries()->getAllSections();
        } catch (Throwable $e) {
            Craft::error('Could not load sections: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $map;
        }

        foreach ($sections as $section) {
            foreach ($this->sectionEntryTypes($section) as $entryType) {
                $map[(int)$entryType->id][] = [
                    'id' => (int)$section->id,
                    'name' => (string)$section->name,
                    'handle' => (string)$section->handle,
                    'url' => \craft\helpers\UrlHelper::cpUrl("settings/sections/$section->id"),
                ];
            }
        }

        return $map;
    }

    /**
     * @return EntryType[]
     */
    private function sectionEntryTypes(Section $section): array
    {
        try {
            return $section->getEntryTypes();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<int, array{id: int, name: string, handle: string, type: string}>>
     */
    private function nestingFieldsByEntryType(): array
    {
        $map = [];

        foreach ($this->containerFields() as $field) {
            foreach ($this->providersFor($field) as $provider) {
                if ($provider instanceof EntryType) {
                    $map[(int)$provider->id][] = [
                        'id' => (int)$field->id,
                        'name' => (string)$field->name,
                        'handle' => (string)$field->handle,
                        'type' => $this->displayName($field),
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * @return FieldInterface[]
     */
    private function containerFields(): array
    {
        $fields = [];

        try {
            $all = Craft::$app->getFields()->getAllFields(false);
        } catch (Throwable $e) {
            Craft::error('Could not load fields: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }

        foreach ($all as $field) {
            if ($field instanceof ElementContainerFieldInterface) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return FieldLayoutProviderInterface[]
     */
    private function providersFor(FieldInterface $field): array
    {
        if (!$field instanceof ElementContainerFieldInterface) {
            return [];
        }

        try {
            return $field->getFieldLayoutProviders();
        } catch (Throwable $e) {
            Craft::warning(sprintf(
                'Could not read nested layout providers for field “%s”: %s',
                $field->handle,
                $e->getMessage(),
            ), Plugin::LOG_CATEGORY);

            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function fieldNames(): array
    {
        $names = [];

        foreach ($this->containerFields() as $field) {
            $names[(int)$field->id] = (string)$field->name;
        }

        return $names;
    }

    private function fieldCount(EntryType $entryType): int
    {
        try {
            return count($entryType->getFieldLayout()->getCustomFields());
        } catch (Throwable) {
            return 0;
        }
    }

    private function editUrl(EntryType $entryType): ?string
    {
        try {
            return $entryType->getCpEditUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function displayName(FieldInterface $field): string
    {
        try {
            return (string)$field::displayName();
        } catch (Throwable) {
            return \craft\helpers\StringHelper::afterLast($field::class, '\\');
        }
    }

    private function verdict(EntryTypeReport $report): string
    {
        $attached = $report->getUsageCount() > 0;
        $hasEntries = $report->getTotalEntries() > 0;

        if (!$attached) {
            return $hasEntries ? EntryTypeReport::VERDICT_STRANDED : EntryTypeReport::VERDICT_UNUSED;
        }

        return $hasEntries ? EntryTypeReport::VERDICT_IN_USE : EntryTypeReport::VERDICT_EMPTY;
    }
}
