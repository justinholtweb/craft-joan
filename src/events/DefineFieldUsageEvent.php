<?php

namespace justinholtweb\joan\events;

use craft\base\FieldInterface;
use justinholtweb\joan\models\FieldReport;
use yii\base\Event;

/**
 * Fired for each field once Joan has finished measuring it, before the verdict is decided.
 *
 * This exists because Joan can be wrong about exactly one kind of field, and it can't fix
 * that on its own: a field type that stores its values somewhere other than the content
 * table, the relations table, or nested entries. Joan reports those as "not counted" rather
 * than guessing — but the plugin that owns the field type knows the real answer, and this
 * is where it says so.
 *
 * ```php
 * use justinholtweb\joan\events\DefineFieldUsageEvent;
 * use justinholtweb\joan\services\Inventory;
 * use yii\base\Event;
 *
 * Event::on(Inventory::class, Inventory::EVENT_DEFINE_FIELD_USAGE, function(DefineFieldUsageEvent $event) {
 *     if ($event->field instanceof MyField) {
 *         $event->report->usedElements = MyPlugin::getInstance()->storage->countFor($event->field);
 *     }
 * });
 * ```
 */
class DefineFieldUsageEvent extends Event
{
    /** @var FieldInterface The field being reported on. */
    public FieldInterface $field;

    /**
     * @var FieldReport The report so far. Counts are already filled in; the verdict isn't.
     *                  Setting `usedElements` to anything but -1 makes the field countable.
     */
    public FieldReport $report;
}
