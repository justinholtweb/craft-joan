<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\models\FieldReport;
use yii\web\Response;

/**
 * The overview: how big the content model is, and how much of it nothing is using.
 */
class OverviewController extends BaseController
{
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $fields = $plugin->inventory->fields();

        // The busiest fields are worth showing next to the abandoned ones: a report that
        // only ever lists problems gives no sense of scale to judge them against.
        $busiest = $fields;
        uasort($busiest, fn(FieldReport $a, FieldReport $b) => $b->usedElements <=> $a->usedElements);

        return $this->renderTemplate('joan/overview/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Inventory'),
            'fields' => $fields,
            'busiest' => array_slice($busiest, 0, 8, true),
            'attention' => array_slice(array_filter($fields, fn(FieldReport $f) => $f->needsAttention()), 0, 8, true),
            'entryTypes' => $plugin->entryTypes->all(),
            'nested' => $plugin->entryTypes->nestedFields(),
        ]));
    }
}
