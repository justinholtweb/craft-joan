<?php

namespace justinholtweb\joan\events;

use yii\base\Event;

/**
 * Fired when the code scanner works out which directories to read.
 *
 * For the module that lives outside the configured paths, or the plugin that ships
 * templates of its own that reference site fields.
 *
 * ```php
 * Event::on(CodeScan::class, CodeScan::EVENT_REGISTER_SCAN_PATHS, function(RegisterScanPathsEvent $event) {
 *     $event->paths[] = Craft::getAlias('@mymodule/templates');
 * });
 * ```
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class RegisterScanPathsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /** @var string[] Absolute directory paths. Anything that isn't a directory is dropped. */
    public array $paths = [];
}
