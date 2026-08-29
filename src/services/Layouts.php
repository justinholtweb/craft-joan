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
 */
class Layouts extends Component
{
    /** @var LayoutRef[]|null Keyed by layout UID. */
    private ?array $refs = null;

    /** @var array<string, FieldLayout>|null Keyed by layout UID. */
    private ?array $layouts = null;

    /**
     * Every layout on the site, attributed, keyed by layout UID.
     *
     * @return LayoutRef[]
     */
    public function all(): array
    {
        if ($this->refs !== null) {
            return $this->refs;
        }

        $layouts = $this->rawLayouts();
        $providers = $this->providersByLayoutUid();
        $refs = [];

        foreach ($layouts as $uid => $layout) {
            $refs[$uid] = $this->buildRef($layout, $providers[$uid] ?? null);
        }

        uasort($refs, function(LayoutRef $a, LayoutRef $b) {
            return [$a->kind, $a->typeName ?? '', $a->label] <=> [$b->kind, $b->typeName ?? '', $b->label];
        });

        return $this->refs = $refs;
    }

    public function getByUid(string $uid): ?LayoutRef
    {
        return $this->all()[$uid] ?? null;
    }

    /**
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

        foreach ($this->rawLayouts() as $uid => $layout) {
            $ref = $refs[$uid] ?? null;

            if ($ref === null) {
                continue;
            }

            foreach ($this->customFieldElements($layout) as [$element, $tabName]) {
                $fieldUid = $this->fieldUid($element);

                if ($fieldUid === null) {
                    continue;
                }

                $instances[$fieldUid][] = new FieldInstance([
                    'layout' => $ref,
                    'elementUid' => (string)$element->uid,
                    'handle' => $this->instanceHandle($element),
                    'handleOverridden' => $element->handle !== null && $element->handle !== $this->originalHandle($element),
                    'label' => $this->instanceLabel($element),
                    'required' => (bool)$element->required,
                    'tab' => $tabName,
                    'conditional' => $this->isConditional($element),
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
        $this->refs = null;
        $this->layouts = null;
    }

    /**
     * @return array<string, FieldLayout> Keyed by UID.
     */
    private function rawLayouts(): array
    {
        if ($this->layouts !== null) {
            return $this->layouts;
        }

        $layouts = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if (isset($layout->uid)) {
                $layouts[$layout->uid] = $layout;
            }
        }

        return $this->layouts = $layouts;
    }

    /**
     * Asks every element type for its layouts, so we get owners rather than IDs.
     *
     * @return array<string, array{provider: Chippable|null, type: class-string<ElementInterface>}>
     */
    private function providersByLayoutUid(): array
    {
        $providers = [];

        foreach ($this->elementTypes() as $type) {
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
     * @return class-string<ElementInterface>[]
     */
    private function elementTypes(): array
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
     * @param array{provider: Chippable|null, type: class-string<ElementInterface>}|null $attribution
     */
    private function buildRef(FieldLayout $layout, ?array $attribution): LayoutRef
    {
        $tabs = $this->tabs($layout);

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
            $ref->typeName = $this->displayName($elementType);

            if ($provider !== null) {
                $ref->label = $provider->getUiLabel();
                $ref->icon = $provider instanceof Iconic ? $provider->getIcon() : null;
                $ref->cpEditUrl = $this->providerUrl($provider);
            } else {
                // An element type with exactly one layout and nothing to name it — Users,
                // Addresses. The element type's own name is the honest label.
                $ref->label = $ref->typeName;
            }

            return $ref;
        }

        // No element type claimed it. If the layout's type is a class we can load, it's a
        // layout on something that isn't an element — Hyper's link types are the usual
        // case, and fields in them are absolutely in use.
        if ($layout->type !== null && class_exists($layout->type)) {
            $ref->kind = LayoutRef::KIND_OTHER;
            $ref->isElementLayout = false;
            $ref->typeName = $this->displayName($layout->type);
            $ref->label = $this->componentLabel($layout->type);

            return $ref;
        }

        $ref->kind = LayoutRef::KIND_UNATTRIBUTED;
        $ref->isElementLayout = false;
        $ref->typeName = $layout->type;
        $ref->label = $layout->type !== null
            ? Craft::t('joan', 'Unclaimed layout ({type})', ['type' => $this->shortClass($layout->type)])
            : Craft::t('joan', 'Unclaimed layout #{id}', ['id' => $layout->id ?? '?']);

        return $ref;
    }

    private function providerUrl(Chippable $provider): ?string
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
     * @return FieldLayoutTab[]
     */
    private function tabs(FieldLayout $layout): array
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
    private function customFieldElements(FieldLayout $layout): array
    {
        $found = [];

        foreach ($this->tabs($layout) as $tab) {
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
    private function fieldUid(CustomField $element): ?string
    {
        try {
            return $element->getFieldUid();
        } catch (Throwable) {
            return null;
        }
    }

    private function originalHandle(CustomField $element): ?string
    {
        try {
            return $element->getOriginalHandle();
        } catch (Throwable) {
            return null;
        }
    }

    private function instanceHandle(CustomField $element): string
    {
        if ($element->handle !== null && $element->handle !== '') {
            return $element->handle;
        }

        return (string)($this->originalHandle($element) ?? '');
    }

    private function instanceLabel(CustomField $element): ?string
    {
        try {
            return $element->label();
        } catch (Throwable) {
            return null;
        }
    }

    private function isConditional(CustomField $element): bool
    {
        try {
            return $element->hasConditions();
        } catch (Throwable) {
            return false;
        }
    }

    private function displayName(string $class): string
    {
        if (method_exists($class, 'displayName')) {
            try {
                return (string)$class::displayName();
            } catch (Throwable) {
                // fall through
            }
        }

        return $this->shortClass($class);
    }

    /**
     * A readable label for a non-element layout owner: "Hyper — Entry".
     */
    private function componentLabel(string $class): string
    {
        $name = $this->displayName($class);
        $parts = explode('\\', $class);
        $vendor = $parts[0] ?? '';

        if ($vendor !== '' && $vendor !== 'craft') {
            return sprintf('%s — %s', ucfirst($vendor), $name);
        }

        return $name;
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return (string)end($parts);
    }
}
