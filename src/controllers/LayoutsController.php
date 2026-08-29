<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\models\LayoutRef;
use yii\web\Response;

/**
 * Every field layout on the site, with the thing that owns it.
 */
class LayoutsController extends BaseController
{
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $layouts = $plugin->layouts->all();
        $counts = $plugin->usage->layoutElementCounts();

        // Which fields sit on each layout — the same instance data the field screens use,
        // turned the other way round.
        $fieldsByLayout = [];

        foreach ($plugin->inventory->fields() as $field) {
            foreach ($field->instances as $instance) {
                $fieldsByLayout[$instance->layout->uid][] = ['field' => $field, 'instance' => $instance];
            }
        }

        $kind = $this->request->getQueryParam('kind');

        if ($kind !== null && $kind !== '') {
            $layouts = array_filter($layouts, fn(LayoutRef $layout) => $layout->kind === $kind);
        }

        return $this->renderTemplate('joan/layouts/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Field layouts'),
            'layouts' => $layouts,
            'total' => count($plugin->layouts->all()),
            'counts' => $counts,
            'fieldsByLayout' => $fieldsByLayout,
            'kind' => $kind,
        ]));
    }
}
