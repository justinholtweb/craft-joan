<?php

namespace justinholtweb\joan;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\joan\models\Settings;
use justinholtweb\joan\services\CodeScan;
use justinholtweb\joan\services\EntryTypes;
use justinholtweb\joan\services\Exports;
use justinholtweb\joan\services\Inventory;
use justinholtweb\joan\services\Layouts;
use justinholtweb\joan\services\Usage;
use justinholtweb\joan\variables\JoanVariable;
use yii\base\Event;

/**
 * Joan — a complete inventory of your content model.
 *
 * Joan installs no tables and writes nothing. Every screen is a question asked of what's
 * already there: which fields exist, where each one is used, how much content is actually
 * in it, and what would notice if it were gone.
 *
 * @property-read Inventory $inventory
 * @property-read Layouts $layouts
 * @property-read Usage $usage
 * @property-read CodeScan $codeScan
 * @property-read EntryTypes $entryTypes
 * @property-read Exports $exports
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Read the inventory. */
    public const PERMISSION_VIEW = 'joan:view';

    /** Rebuild it, which means rescanning the content table and the codebase. */
    public const PERMISSION_REFRESH = 'joan:refresh';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'joan';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'inventory' => Inventory::class,
                'layouts' => Layouts::class,
                'usage' => Usage::class,
                'codeScan' => CodeScan::class,
                'entryTypes' => EntryTypes::class,
                'exports' => Exports::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
    }

    /**
     * Exposes `craft.joan` to templates.
     */
    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('joan', JoanVariable::class);
            }
        );
    }

    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        // Every screen requires joan:view, so don't offer a nav item that only leads to a 403.
        if (!$user->checkPermission(self::PERMISSION_VIEW)) {
            return null;
        }

        $item = parent::getCpNavItem();

        $subnav = [
            'overview' => ['label' => Craft::t('joan', 'Overview'), 'url' => 'joan'],
            'fields' => ['label' => Craft::t('joan', 'Fields'), 'url' => 'joan/fields'],
            'entry-types' => ['label' => Craft::t('joan', 'Entry types'), 'url' => 'joan/entry-types'],
            'nested' => ['label' => Craft::t('joan', 'Matrix'), 'url' => 'joan/nested'],
            'layouts' => ['label' => Craft::t('joan', 'Layouts'), 'url' => 'joan/layouts'],
            'cleanup' => ['label' => Craft::t('joan', 'Cleanup'), 'url' => 'joan/cleanup'],
        ];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('joan', 'Settings'), 'url' => 'joan/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('joan/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('joan/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['joan'] = 'joan/overview/index';
                $event->rules['joan/fields'] = 'joan/fields/index';
                $event->rules['joan/fields/<uid:[\w\-]+>'] = 'joan/fields/detail';
                $event->rules['joan/entry-types'] = 'joan/entry-types/index';
                $event->rules['joan/entry-types/<uid:[\w\-]+>'] = 'joan/entry-types/detail';
                $event->rules['joan/nested'] = 'joan/nested/index';
                $event->rules['joan/layouts'] = 'joan/layouts/index';
                $event->rules['joan/cleanup'] = 'joan/cleanup/index';
                $event->rules['joan/settings'] = 'joan/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('joan', 'Joan'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('joan', 'View the content model inventory'),
                            'nested' => [
                                self::PERMISSION_REFRESH => [
                                    'label' => Craft::t('joan', 'Rebuild the inventory'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
