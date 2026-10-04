<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\models\EntryTypeReport;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Entry types — including the ones that are only ever Matrix blocks.
 */
class EntryTypesController extends BaseController
{
    public function actionIndex(): Response
    {
        $reports = $this->plugin()->entryTypes->all();
        $verdict = $this->request->getQueryParam('verdict');

        if ($verdict !== null && $verdict !== '') {
            $reports = array_filter($reports, fn(EntryTypeReport $report) => $report->verdict === $verdict);
        }

        return $this->renderTemplate('joan/entrytypes/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Entry types'),
            'reports' => $reports,
            'total' => count($this->plugin()->entryTypes->all()),
            'verdict' => $verdict,
        ]));
    }

    public function actionDetail(string $uid): Response
    {
        $report = $this->plugin()->entryTypes->getByUid($uid);

        if ($report === null) {
            throw new NotFoundHttpException('Entry type not found');
        }

        $fields = [];

        foreach ($this->plugin()->inventory->fields() as $field) {
            foreach ($field->instances as $instance) {
                if ($report->fieldLayoutId !== null && $instance->layout->id === $report->fieldLayoutId) {
                    $fields[] = ['field' => $field, 'instance' => $instance];
                }
            }
        }

        usort($fields, fn(array $a, array $b) => [$a['instance']->tab ?? '', $a['field']->name] <=> [$b['instance']->tab ?? '', $b['field']->name]);

        return $this->renderTemplate('joan/entrytypes/detail', $this->withChrome([
            'title' => $report->name,
            'report' => $report,
            'fields' => $fields,
        ]));
    }
}
