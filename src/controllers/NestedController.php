<?php

namespace justinholtweb\joan\controllers;

use yii\web\Response;

/**
 * Matrix and every other field that nests entries.
 */
class NestedController extends BaseController
{
    public function actionIndex(): Response
    {
        $reports = $this->plugin()->entryTypes->nestedFields();

        return $this->renderTemplate('joan/nested/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Matrix'),
            'reports' => $reports,
        ]));
    }
}
