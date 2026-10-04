<?php

namespace justinholtweb\joan\controllers;

use Craft;
use craft\helpers\StringHelper;
use justinholtweb\joan\models\Settings;
use justinholtweb\joan\Plugin;
use yii\web\Response;

/**
 * Joan's settings screen.
 */
class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // false: with allowAdminChanges off, admins still get the read-only screen.
        // actionSave() keeps the strict check.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('joan/settings/index', $this->withChrome([
            'title' => Craft::t('joan', 'Settings'),
            'settings' => Plugin::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'roots' => Plugin::getInstance()->codeScan->roots(),
        ]));
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('settings', []);

        $settings->setAttributes([
            'countContent' => (bool)($posted['countContent'] ?? false),
            'includeDrafts' => (bool)($posted['includeDrafts'] ?? false),
            'scanCode' => (bool)($posted['scanCode'] ?? false),
            'includePluginContexts' => (bool)($posted['includePluginContexts'] ?? false),
            'scanPaths' => $this->lines($posted['scanPaths'] ?? ''),
            'scanExtensions' => $this->lines($posted['scanExtensions'] ?? ''),
            'scanExclude' => $this->lines($posted['scanExclude'] ?? ''),
            'ignoredFields' => $this->lines($posted['ignoredFields'] ?? ''),
            'maxFileSize' => (int)($posted['maxFileSize'] ?? $settings->maxFileSize),
            'maxFiles' => (int)($posted['maxFiles'] ?? $settings->maxFiles),
            'maxRefsPerField' => (int)($posted['maxRefsPerField'] ?? $settings->maxRefsPerField),
            'cacheDuration' => (int)($posted['cacheDuration'] ?? $settings->cacheDuration),
            'logLevel' => (string)($posted['logLevel'] ?? $settings->logLevel),
        ], false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('joan', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('joan', 'Couldn’t save settings.'));

            return null;
        }

        // What counts as usage just changed, so anything already measured is wrong.
        $plugin->inventory->invalidate();

        $this->setSuccessFlash(Craft::t('joan', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Turns a textarea into a list, forgiving the blank lines everyone leaves behind.
     *
     * @return string[]
     */
    private function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn(string $line) => $line !== ''));
        }

        return array_values(array_filter(
            array_map('trim', StringHelper::split((string)$value, "\n")),
            fn(string $line) => $line !== '',
        ));
    }
}
