<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * The result of one pass over `elements_sites.content`.
 *
 * Joan reads the content table exactly once per inventory, not once per field. That's a
 * deliberate trade: decoding JSON in PHP is slower per row than a `JSON_EXTRACT` in SQL,
 * but a per-field SQL count means one full scan *per field*, and a site with eighty fields
 * then scans the biggest table on the site eighty times. One pass also answers a question
 * the per-field version can't: which content keys belong to no field layout at all.
 *
 * Counts are per *field*, not per content key, and deduplicated per element — a field
 * placed twice in one layout has two keys and is still one field, and an element that
 * filled in both is still one element.
 */
class ContentScan extends Model
{
    /** @var array<string, int> Field UID => elements holding a non-empty value. */
    public array $countsByField = [];

    /** @var array<string, array<string, int>> Field UID => element class => element count. */
    public array $byFieldAndType = [];

    /** @var array<string, array<int, int>> Field UID => site ID => element count. */
    public array $byFieldAndSite = [];

    /**
     * @var array<string, int> Content keys belonging to no current layout => rows carrying one.
     *
     * A key gets stranded when a field is removed from a layout: Craft leaves the value in
     * `elements_sites.content` under the departed layout element's UID, where nothing will
     * read it again. The layout element that knew which field it was is gone, so this is
     * as far as the trail goes — but the weight is worth reporting.
     */
    public array $strandedKeys = [];

    public int $rowsScanned = 0;
    public int $elementsScanned = 0;

    /** @var bool Whether the scan stopped before the end of the table. */
    public bool $truncated = false;

    /** @var bool Whether the scan ran at all. When false, every count is unknown, not zero. */
    public bool $ran = true;

    public float $runtime = 0.0;

    public function countFor(string $fieldUid): int
    {
        return $this->countsByField[$fieldUid] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function typesFor(string $fieldUid): array
    {
        return $this->byFieldAndType[$fieldUid] ?? [];
    }

    /**
     * @return array<int, int>
     */
    public function sitesFor(string $fieldUid): array
    {
        return $this->byFieldAndSite[$fieldUid] ?? [];
    }

    public function getStrandedRowCount(): int
    {
        return array_sum($this->strandedKeys);
    }
}
