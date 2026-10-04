<?php

namespace justinholtweb\joan\controllers;

use craft\web\Controller;
use justinholtweb\joan\Plugin;
use yii\web\Response;

/**
 * Shared plumbing for Joan's control panel screens.
 *
 * Every screen is read-only, so the permission story is short: you can look, and — because
 * looking is expensive — you may or may not be allowed to make Joan look again.
 */
abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * Adds the variables every Joan screen's layout expects.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    protected function withChrome(array $variables): array
    {
        $inventory = $this->plugin()->inventory;

        return $variables + [
            'summary' => $inventory->summary(),
            'contentScan' => $inventory->contentScan(),
            'codeStats' => $inventory->codeStats(),
            'canRefresh' => \Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REFRESH),
        ];
    }

    /**
     * Rebuilds the inventory and returns to wherever the request came from.
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REFRESH);

        $this->plugin()->inventory->invalidate();
        $this->plugin()->inventory->fields(true);

        $this->setSuccessFlash(\Craft::t('joan', 'Inventory rebuilt.'));

        return $this->redirectToPostedUrl();
    }
}
