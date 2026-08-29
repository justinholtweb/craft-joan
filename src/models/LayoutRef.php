<?php

namespace justinholtweb\joan\models;

use craft\base\Model;

/**
 * One field layout, with the thing that owns it worked out.
 *
 * A `fieldlayouts` row on its own tells you almost nothing — it has an ID, a type, and a
 * blob of config. What an editor actually needs to hear is "Blog → Article" or "Users" or
 * "Product Type: T-shirts". Working that out is the job of {@see \justinholtweb\joan\services\Layouts},
 * and this is what it hands back.
 */
class LayoutRef extends Model
{
    /** Owned by an element type's provider — an entry type, a category group, a volume. */
    public const KIND_ELEMENT = 'element';

    /**
     * Owned by something no registered element type claims.
     *
     * Hyper's link types are the common case: they subclass `craft\base\Element` and carry
     * real field layouts, but they're never registered as element types and never saved as
     * elements, so their values live wherever the owning plugin puts them.
     */
    public const KIND_OTHER = 'other';

    /** In the `fieldlayouts` table, but nothing claims it. */
    public const KIND_UNATTRIBUTED = 'unattributed';

    public ?int $id = null;
    public string $uid = '';

    /** @var string|null The layout's `type` column — usually an element class, but not always. */
    public ?string $type = null;

    /** @var string What to call this layout in a report. */
    public string $label = '';

    /** @var string|null The element type's display name, when the layout belongs to one. */
    public ?string $typeName = null;

    /** @var string|null Where an admin goes to edit it. */
    public ?string $cpEditUrl = null;

    /** @var string|null An icon name the provider supplied. */
    public ?string $icon = null;

    public string $kind = self::KIND_ELEMENT;

    /** @var bool Whether this layout hangs off an element type Craft can enumerate. */
    public bool $isElementLayout = true;

    public int $fieldCount = 0;
    public int $tabCount = 0;

    /**
     * @var string|null Set when the layout belongs to a *nested* element — a Matrix entry type,
     *                  for instance — naming the field that nests it.
     */
    public ?string $nestedIn = null;

    /**
     * Whether content saved against this layout lives in `elements_sites.content`.
     *
     * Layouts no element type claims still contain custom fields, but their values are
     * stored by the owning plugin however it likes. A field used only there is genuinely
     * used — it just can't be counted the usual way, and saying "0 elements" about it
     * would be a lie that got the field deleted.
     */
    public function storesElementContent(): bool
    {
        return $this->isElementLayout;
    }
}
