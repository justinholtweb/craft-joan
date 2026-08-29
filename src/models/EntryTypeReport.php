<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * Everything Joan knows about one entry type.
 *
 * Craft 5 folded Matrix block types into entry types, so this one model covers both the
 * "Article" attached to a section and the "Image + Caption" block nested inside a Matrix
 * field — which is exactly the point. An entry type used by nothing is invisible in the
 * control panel until you go looking, and Craft 5 sites accumulate them fast, because
 * every Matrix block type a Craft 4 site ever had came across in the upgrade.
 */
class EntryTypeReport extends Model
{
    /** Attached to a section, a nesting field, or both — and entries exist. */
    public const VERDICT_IN_USE = 'inUse';

    /** Attached to something, but no entry has ever been created with it. */
    public const VERDICT_EMPTY = 'empty';

    /** Attached to nothing, but entries of this type still exist. */
    public const VERDICT_STRANDED = 'stranded';

    /** Attached to nothing, and no entries. Nothing would miss it. */
    public const VERDICT_UNUSED = 'unused';

    public int $id = 0;
    public string $uid = '';
    public string $name = '';
    public string $handle = '';
    public ?string $icon = null;
    public ?string $color = null;
    public ?string $cpEditUrl = null;

    public bool $hasTitleField = true;
    public int $fieldCount = 0;
    public ?int $fieldLayoutId = null;

    /** @var array<int, array{id: int, name: string, handle: string, url: string|null}> Sections using it. */
    public array $sections = [];

    /** @var array<int, array{id: int, name: string, handle: string, type: string}> Fields nesting it. */
    public array $nestingFields = [];

    /** @var int Entries of this type that belong to a section. */
    public int $topLevelEntries = 0;

    /** @var int Entries of this type nested inside a field. */
    public int $nestedEntries = 0;

    /** @var array<string, int> Nesting field name => entry count. */
    public array $entriesByField = [];

    public string $verdict = self::VERDICT_UNUSED;

    public function getTotalEntries(): int
    {
        return $this->topLevelEntries + $this->nestedEntries;
    }

    public function getUsageCount(): int
    {
        return count($this->sections) + count($this->nestingFields);
    }

    public function isNestedOnly(): bool
    {
        return $this->sections === [] && $this->nestingFields !== [];
    }

    public function needsAttention(): bool
    {
        return in_array($this->verdict, [self::VERDICT_EMPTY, self::VERDICT_STRANDED, self::VERDICT_UNUSED], true);
    }
}
