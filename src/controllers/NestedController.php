<?php

namespace justinholtweb\joan\controllers;

use yii\web\Response;

/**
 * Matrix and every other field that nests entries.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class NestedController extends BaseController
{
    // Public Methods
    // =========================================================================

    /**
     * The fields that nest entries, and what's inside them.
     */
    public function actionIndex(): Response
    {
        $reports = $this->plugin()->entryTypes->nestedFields();

        return $this->renderTemplate('joan/nested/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Matrix'),
            'reports' => $reports,
        ]));
    }
}
