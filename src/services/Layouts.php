<?php

namespace justinholtweb\joan\services;

use Craft;
use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\ElementInterface;
use craft\base\Iconic;
use craft\elements\GlobalSet;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Component as ComponentHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use justinholtweb\joan\models\FieldInstance;
use justinholtweb\joan\models\LayoutRef;
use justinholtweb\joan\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Finds every field layout on the site and works out what owns it.
 *
 * This is the part Craft doesn't hand you. `Fields::getAllLayouts()` returns layouts with
 * an ID, a type and a config — no owner. `Fields::findFieldUsages()` adds a filter that
 * drops any layout whose type isn't an element class at all, so a field used only by a
 * plugin that hangs a layout off a plain class is reported as having no usages. And the
 * control panel's "Used by" panel can only *name* a layout whose element type hands over a
 * provider; everything else it renders as a bare tally — "2 asset field layouts", "1
 * unknown field layout" — which tells you a field is used without telling you where.
 *
 * Joan attributes layouts in three passes, most trustworthy first:
 *
 * 1. Ask every installed element type for its layouts. Element types return them *with*
 *    providers attached — the entry type, the category group, the volume — so this pass
 *    yields real names and real edit URLs, for third-party element types as much as core.
 * 2. Anything left whose `type` is a class Craft can load gets named from the class.
 * 3. Anything still left is reported as unattributed, which is a finding in itself.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class Layouts extends Component
{
    // Private Properties
    // =========================================================================

    /** @var LayoutRef[]|null Keyed by layout UID. */
    private ?array $_refs = null;

    /** @var array<string, FieldLayout>|null Keyed by layout UID. */
    private ?array $_layouts = null;

    // Public Methods
    // =========================================================================

    /**
     * Every layout on the site, attributed, keyed by layout UID.
     *
     * @return LayoutRef[]
     */
    public function all(): array
    {
        if ($this->_refs !== null) {
            return $this->_refs;
        }

        $layouts = $this->_rawLayouts();
        $providers = $this->_providersByLayoutUid();
        $refs = [];

        foreach ($layouts as $uid => $layout) {
            $refs[$uid] = $this->_buildRef($layout, $providers[$uid] ?? null);
        }

        uasort($refs, function(LayoutRef $a, LayoutRef $b) {
            return [$a->kind, $a->typeName ?? '', $a->label] <=> [$b->kind, $b->typeName ?? '', $b->label];
        });

        return $this->_refs = $refs;
    }

    /**
     * One layout's reference, or null if there's no such layout.
     */
    public function getByUid(string $uid): ?LayoutRef
    {
        return $this->all()[$uid] ?? null;
    }

    /**
     * The layouts nothing would claim.
     *
     * @return LayoutRef[]
     */
    public function unattributed(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn(LayoutRef $ref) => $ref->kind === LayoutRef::KIND_UNATTRIBUTED,
        ));
    }

    /**
     * Every instance of every field, indexed by field UID.
     *
     * One pass over every layout builds the whole map, which is the only sane way to do
     * it: the alternative is Craft's `findFieldUsages()` per field, and that walks all
     * layouts once per field.
     *
     * @return array<string, FieldInstance[]>
     */
    public function instancesByFieldUid(): array
    {
        $instances = [];
        $refs = $this->all();

        foreach ($this->_rawLayouts() as $uid => $layout) {
            $ref = $refs[$uid] ?? null;

            if ($ref === null) {
                continue;
            }

            foreach ($this->_customFieldElements($layout) as [$element, $tabName]) {
                $fieldUid = $this->_fieldUid($element);

                if ($fieldUid === null) {
                    continue;
                }

                $instances[$fieldUid][] = new FieldInstance([
                    'layout' => $ref,
                    'elementUid' => (string)$element->uid,
                    'handle' => $this->_instanceHandle($element),
                    'handleOverridden' => $element->handle !== null && $element->handle !== $this->_originalHandle($element),
                    'label' => $this->_instanceLabel($element),
                    'required' => (bool)$element->required,
                    'tab' => $tabName,
                    'conditional' => $this->_isConditional($element),
                ]);
            }
        }

        return $instances;
    }

    /**
     * The content keys a field's values are stored under, across every layout.
     *
     * @param array<string, FieldInstance[]> $instancesByFieldUid
     * @return string[]
     */
    public function contentKeys(string $fieldUid, array $instancesByFieldUid): array
    {
        $keys = [];

        foreach ($instancesByFieldUid[$fieldUid] ?? [] as $instance) {
            if ($instance->layout->storesElementContent() && $instance->elementUid !== '') {
                $keys[] = $instance->elementUid;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Forgets the memoized layouts. Called after anything that could change them.
     */
    public function reset(): void
    {
        $this->_refs = null;
        $this->_layouts = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Every field layout Craft has, memoized.
     *
     * @return array<string, FieldLayout> Keyed by UID.
     */
    private function _rawLayouts(): array
    {
        if ($this->_layouts !== null) {
            return $this->_layouts;
        }

        $layouts = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if (isset($layout->uid)) {
                $layouts[$layout->uid] = $layout;
            }
        }

        return $this->_layouts = $layouts;
    }

    /**
     * Asks every element type for its layouts, so we get owners rather than IDs.
     *
     * @return array<string, array{provider: Chippable|null, type: class-string<ElementInterface>}>
     */
    private function _providersByLayoutUid(): array
    {
        $providers = [];

        foreach ($this->_elementTypes() as $type) {
            try {
                $layouts = $type::fieldLayouts(null);
            } catch (Throwable $e) {
                // A third-party element type that can't enumerate its own layouts shouldn't
                // take the whole inventory down with it.
                Craft::warning(sprintf(
                    'Could not read field layouts for %s: %s',
                    $type,
                    $e->getMessage(),
                ), Plugin::LOG_CATEGORY);
                continue;
            }

            foreach ($layouts as $layout) {
                if (!isset($layout->uid) || isset($providers[$layout->uid])) {
                    continue;
                }

                $providers[$layout->uid] = [
                    'provider' => $layout->provider instanceof Chippable ? $layout->provider : null,
                    'type' => $type,
                ];
            }
        }

        return $providers;
    }

    /**
     * Every registered element type that really is one.
     *
     * @return class-string<ElementInterface>[]
     */
    private function _elementTypes(): array
    {
        $types = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            if (ComponentHelper::validateComponentClass($type, ElementInterface::class)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Describes one layout, using its owner when one was found.
     *
     * @param array{provider: Chippable|null, type: class-string<ElementInterface>}|null $attribution
     */
    private function _buildRef(FieldLayout $layout, ?array $attribution): LayoutRef
    {
        $tabs = $this->_tabs($layout);

        $ref = new LayoutRef([
            'id' => $layout->id ?? null,
            'uid' => (string)$layout->uid,
            'type' => $layout->type,
            'fieldCount' => count($layout->getCustomFields()),
            'tabCount' => count($tabs),
        ]);

        if ($attribution !== null) {
            $elementType = $attribution['type'];
            $provider = $attribution['provider'];

            $ref->kind = LayoutRef::KIND_ELEMENT;
            $ref->isElementLayout = true;
            $ref->typeName = $this->_displayName($elementType);

            if ($provider !== null) {
                $ref->label = $provider->getUiLabel();
                $ref->icon = $provider instanceof Iconic ? $provider->getIcon() : null;
                $ref->cpEditUrl = $this->_providerUrl($provider);
            } else {
                // An element type with exactly one layout and nothing to name it — Users,
                // Addresses. The element type's own name is the honest label.
                $ref->label = $ref->typeName;
            }

            return $ref;
        }

        // No element type claimed it. If the layout's type is a class we can load, it's a
        // layout on something that isn't an element — Hyper's link types are the usual
        // case, and fields in them are absolutely in use. A registered element type doesn't
        // count: its unclaimed layout is an orphan (a deleted category group, say), not this.
        if (
            $layout->type !== null &&
            class_exists($layout->type) &&
            !in_array($layout->type, $this->_elementTypes(), true)
        ) {
            $ref->kind = LayoutRef::KIND_OTHER;
            $ref->isElementLayout = false;
            $ref->typeName = $this->_displayName($layout->type);
            $ref->label = $this->_componentLabel($layout->type);

            return $ref;
        }

        $ref->kind = LayoutRef::KIND_UNATTRIBUTED;
        $ref->isElementLayout = false;
        $ref->typeName = $layout->type;
        $ref->label = $layout->type !== null
            ? Craft::t('joan', 'Unclaimed layout ({type})', ['type' => $this->_shortClass($layout->type)])
            : Craft::t('joan', 'Unclaimed layout #{id}', ['id' => $layout->id ?? '?']);

        return $ref;
    }

    /**
     * Where to edit the layout's owner, if anywhere.
     */
    private function _providerUrl(Chippable $provider): ?string
    {
        // Global sets are edited from the settings screen, not the element's edit page —
        // the same special case Craft's own "Used by" panel makes.
        if ($provider instanceof GlobalSet && isset($provider->id)) {
            return \craft\helpers\UrlHelper::cpUrl("settings/globals/$provider->id");
        }

        if ($provider instanceof CpEditable) {
            try {
                return $provider->getCpEditUrl();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The layout's tabs, or none if they can't be read.
     *
     * @return FieldLayoutTab[]
     */
    private function _tabs(FieldLayout $layout): array
    {
        try {
            return $layout->getTabs();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Every custom-field element in a layout, paired with the tab it sits on.
     *
     * @return array<int, array{0: CustomField, 1: string|null}>
     */
    private function _customFieldElements(FieldLayout $layout): array
    {
        $found = [];

        foreach ($this->_tabs($layout) as $tab) {
            foreach ($tab->getElements() as $element) {
                if ($element instanceof CustomField) {
                    $found[] = [$element, $tab->name ?? null];
                }
            }
        }

        return $found;
    }

    /**
     * A layout element's field UID, without instantiating the field.
     *
     * `getFieldUid()` throws when the underlying field has been deleted out from under the
     * layout, which is precisely the state Joan exists to report on.
     */
    private function _fieldUid(CustomField $element): ?string
    {
        try {
            return $element->getFieldUid();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The field's own handle, ignoring any override on this layout.
     */
    private function _originalHandle(CustomField $element): ?string
    {
        try {
            return $element->getOriginalHandle();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The handle templates use for the field on this layout.
     */
    private function _instanceHandle(CustomField $element): string
    {
        if ($element->handle !== null && $element->handle !== '') {
            return $element->handle;
        }

        return (string)($this->_originalHandle($element) ?? '');
    }

    /**
     * The label editors see on this layout, if it can be read.
     */
    private function _instanceLabel(CustomField $element): ?string
    {
        try {
            return $element->label();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the field is only shown on this layout under some condition.
     */
    private function _isConditional(CustomField $element): bool
    {
        try {
            return $element->hasConditions();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A class's display name, or its short name if it has none.
     */
    private function _displayName(string $class): string
    {
        if (method_exists($class, 'displayName')) {
            try {
                return (string)$class::displayName();
            } catch (Throwable) {
                // fall through
            }
        }

        return $this->_shortClass($class);
    }

    /**
     * A readable label for a non-element layout owner: "Hyper — Entry".
     */
    private function _componentLabel(string $class): string
    {
        $name = $this->_displayName($class);
        $parts = explode('\\', $class);
        $vendor = $parts[0];

        if ($vendor !== '' && $vendor !== 'craft') {
            return sprintf('%s — %s', ucfirst($vendor), $name);
        }

        return $name;
    }

    /**
     * A class name without its namespace.
     */
    private function _shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return (string)end($parts);
    }
}
