<?php

namespace justinholtweb\joan\variables;

use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\FieldInstance;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\Plugin;

/**
 * `craft.joan` — the inventory, from a template.
 *
 * Mostly for the control panel screens, but it's public API: a build script that wants to
 * fail a deploy when someone adds a field nothing uses can read the same numbers.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class JoanVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Every field in the inventory.
     *
     * @return FieldReport[]
     */
    public function fields(): array
    {
        return Plugin::getInstance()->inventory->fields();
    }

    /**
     * One field's report, by handle.
     */
    public function field(string $handle): ?FieldReport
    {
        return Plugin::getInstance()->inventory->getByHandle($handle);
    }

    /**
     * Fields nothing uses, minus the ignored ones.
     *
     * @return FieldReport[]
     */
    public function unusedFields(): array
    {
        return array_values(array_filter(
            $this->fields(),
            fn(FieldReport $field) => $field->verdict === FieldReport::VERDICT_UNUSED && !$field->ignored,
        ));
    }

    /**
     * Every entry type.
     *
     * @return EntryTypeReport[]
     */
    public function entryTypes(): array
    {
        return Plugin::getInstance()->entryTypes->all();
    }

    /**
     * Every field that nests entries.
     *
     * @return \justinholtweb\joan\models\NestedFieldReport[]
     */
    public function nestedFields(): array
    {
        return Plugin::getInstance()->entryTypes->nestedFields();
    }

    /**
     * Every field layout.
     *
     * @return \justinholtweb\joan\models\LayoutRef[]
     */
    public function layouts(): array
    {
        return Plugin::getInstance()->layouts->all();
    }

    /**
     * The headline counts shown on every screen.
     *
     * @return array<string, int|bool>
     */
    public function summary(): array
    {
        return Plugin::getInstance()->inventory->summary();
    }

    /**
     * Where a field is used, as a plain list of layout labels.
     *
     * @return string[]
     */
    public function usedIn(string $handle): array
    {
        $field = $this->field($handle);

        if ($field === null) {
            return [];
        }

        return array_map(fn(FieldInstance $instance) => $instance->layout->label, $field->instances);
    }
}
