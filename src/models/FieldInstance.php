<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * One appearance of a field inside one field layout.
 *
 * Craft 5 lets the same field appear in many layouts, and lets each layout override its
 * label, instructions, requiredness and — importantly for anyone reading templates — its
 * handle. So "where is this field used" is a list of these, not a list of layouts.
 *
 * The `elementUid` is the interesting one: since Craft 5, `elements_sites.content` is keyed
 * by the *layout element's* UID rather than by the field. That's the key Joan counts.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class FieldInstance extends Model
{
    // Public Properties
    // =========================================================================

    public LayoutRef $layout;

    /** @var string The field layout element UID — the key this instance's content is stored under. */
    public string $elementUid = '';

    /** @var string The handle this instance answers to in templates. */
    public string $handle = '';

    /** @var bool Whether this layout renamed the field's handle for itself. */
    public bool $handleOverridden = false;

    /** @var string|null The label shown to editors here. */
    public ?string $label = null;

    public bool $required = false;

    /** @var string|null The tab it sits on. */
    public ?string $tab = null;

    /** @var bool Whether the instance is conditionally shown. */
    public bool $conditional = false;

    /** @var int Elements with a non-empty value under this instance. -1 when not counted. */
    public int $usedElements = -1;
}
