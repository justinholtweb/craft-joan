<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * Everything Joan knows about one custom field.
 *
 * The verdict at the bottom is the point of the whole plugin: "is it safe to delete this?"
 * Craft's own field settings screen answers a narrower question — which *element* field
 * layouts include it — and answers "No usages" for a field that a Hyper link type, a
 * template, or ten thousand rows of orphaned content are all still relying on.
 */
class FieldReport extends Model
{
    /** In a layout, and elements have values for it. */
    public const VERDICT_IN_USE = 'inUse';

    /** In a layout, but not one element has ever been given a value. */
    public const VERDICT_EMPTY = 'empty';

    /** In no layout — but content rows still carry values that nothing can now render. */
    public const VERDICT_STRANDED = 'stranded';

    /** In no layout and holding no content, but the codebase still names it. */
    public const VERDICT_CODE_ONLY = 'codeOnly';

    /** In no layout, no content, no references. Nothing would miss it. */
    public const VERDICT_UNUSED = 'unused';

    /** Used somewhere Joan can see, but somewhere it can't count values. */
    public const VERDICT_UNCOUNTABLE = 'uncountable';

    /** Values live in `elements_sites.content`, keyed by layout element UID. */
    public const STRATEGY_CONTENT = 'content';

    /** Values live in the `relations` table (Entries, Assets, Categories, Tags, Users…). */
    public const STRATEGY_RELATIONS = 'relations';

    /** Values are nested elements owned by the field (Matrix, CKEditor with entries). */
    public const STRATEGY_NESTED = 'nested';

    /** The field type stores nothing Joan can count. */
    public const STRATEGY_NONE = 'none';

    public int $id = 0;
    public string $uid = '';
    public string $name = '';
    public string $handle = '';

    /** @var string The field class. */
    public string $type = '';

    /** @var string The field type's display name, or the raw class when the plugin is gone. */
    public string $typeName = '';

    /** @var bool Whether the field type is still installed. A missing type is its own finding. */
    public bool $typeExists = true;

    /** @var string The field's context. Anything but `global` belongs to a plugin. */
    public string $context = 'global';

    public bool $searchable = false;
    public string $translationMethod = 'none';

    /** @var FieldInstance[] Every layout this field appears in. */
    public array $instances = [];

    /** @var string[] How usage was counted. More than one for fields that store two ways. */
    public array $strategies = [];

    /** @var int Elements holding a non-empty value. -1 means "not counted". */
    public int $usedElements = -1;

    /** @var array<string, int> Element display name => count. */
    public array $byElementType = [];

    /** @var array<string, int> Site name => count. */
    public array $bySite = [];

    /** @var int Nested elements owned by this field, for Matrix-like fields. */
    public int $nestedElements = 0;

    /** @var array<string, int> Nested entry type name => block count. */
    public array $nestedByType = [];

    /** @var CodeReference[] Places in the codebase that name this handle. */
    public array $codeRefs = [];

    /** @var bool Whether a code scan actually ran. Without one, "unused" is not a safe word. */
    public bool $codeScanned = false;

    /** @var int Total references found, including any past the per-field cap. */
    public int $codeRefTotal = 0;

    /** @var int Elements with at least one relation through this field. */
    public int $relatedElements = 0;

    /** @var int Distinct elements selected through this field. */
    public int $relatedTargets = 0;

    /**
     * @var bool Whether an admin has told Joan to leave this field alone.
     *
     * For the field only ever populated by an import script, which every signal Joan has
     * would otherwise call abandoned.
     */
    public bool $ignored = false;

    public string $verdict = self::VERDICT_UNUSED;

    public function getLayoutCount(): int
    {
        return count($this->instances);
    }

    public function getCodeRefCount(): int
    {
        return max($this->codeRefTotal, count($this->codeRefs));
    }

    /**
     * Whether the codebase names this handle somewhere that looks like a real use.
     *
     * A bare mention isn't enough to stop a deletion on its own — the word `subtitle` in a
     * CSS class is not a use of the `subtitle` field — but `entry.subtitle` is.
     */
    public function hasStrongCodeRefs(): bool
    {
        foreach ($this->codeRefs as $ref) {
            if ($ref->isStrong()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return CodeReference[]
     */
    public function getStrongCodeRefs(): array
    {
        return array_values(array_filter($this->codeRefs, fn(CodeReference $ref) => $ref->isStrong()));
    }

    /**
     * Whether counting was possible at all. A `false` here means every zero on this report
     * is an "unknown", and must never be read as "nothing uses it".
     */
    public function wasCounted(): bool
    {
        return $this->usedElements >= 0;
    }

    /**
     * Whether this field appears in at least one layout Craft's own "Used by" panel would list.
     *
     * The gap between this and {@see getLayoutCount()} is the set of usages Craft doesn't show.
     */
    public function getElementLayoutCount(): int
    {
        return count(array_filter($this->instances, fn(FieldInstance $i) => $i->layout->isElementLayout));
    }

    /**
     * @return FieldInstance[]
     */
    public function getHiddenInstances(): array
    {
        return array_values(array_filter($this->instances, fn(FieldInstance $i) => !$i->layout->isElementLayout));
    }

    /**
     * Whether any layout renamed this field's handle for itself — the reason a template
     * search for the field's own handle can come back empty on a field that's used daily.
     */
    public function hasHandleOverrides(): bool
    {
        foreach ($this->instances as $instance) {
            if ($instance->handleOverridden) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every handle this field answers to, across all its layouts.
     *
     * @return string[]
     */
    public function getAllHandles(): array
    {
        $handles = [$this->handle];

        foreach ($this->instances as $instance) {
            $handles[] = $instance->handle;
        }

        return array_values(array_unique(array_filter($handles)));
    }

    public function isDeletable(): bool
    {
        return !$this->ignored && $this->verdict === self::VERDICT_UNUSED;
    }

    public function needsAttention(): bool
    {
        if ($this->ignored) {
            return false;
        }

        return in_array($this->verdict, [
            self::VERDICT_EMPTY,
            self::VERDICT_STRANDED,
            self::VERDICT_CODE_ONLY,
            self::VERDICT_UNUSED,
        ], true);
    }
}
