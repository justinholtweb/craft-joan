<?php

namespace justinholtweb\joan\variables;

use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\Plugin;

/**
 * `craft.joan` — the inventory, from a template.
 *
 * Mostly for the control panel screens, but it's public API: a build script that wants to
 * fail a deploy when someone adds a field nothing uses can read the same numbers.
 */
class JoanVariable
{
    /**
     * @return FieldReport[]
     */
    public function fields(): array
    {
        return Plugin::getInstance()->inventory->fields();
    }

    public function field(string $handle): ?FieldReport
    {
        return Plugin::getInstance()->inventory->getByHandle($handle);
    }

    /**
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
     * @return EntryTypeReport[]
     */
    public function entryTypes(): array
    {
        return Plugin::getInstance()->entryTypes->all();
    }

    /**
     * @return \justinholtweb\joan\models\NestedFieldReport[]
     */
    public function nestedFields(): array
    {
        return Plugin::getInstance()->entryTypes->nestedFields();
    }

    /**
     * @return \justinholtweb\joan\models\LayoutRef[]
     */
    public function layouts(): array
    {
        return Plugin::getInstance()->layouts->all();
    }

    /**
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

        return array_map(fn($instance) => $instance->layout->label, $field->instances);
    }
}
