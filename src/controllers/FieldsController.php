<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\services\CodeScan;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The field inventory, and the drill-down behind each row.
 */
class FieldsController extends BaseController
{
    public function actionIndex(): Response
    {
        $fields = $this->plugin()->inventory->fields();
        $verdict = $this->request->getQueryParam('verdict');
        $type = $this->request->getQueryParam('type');
        $search = trim((string)$this->request->getQueryParam('search', ''));

        $filtered = array_filter($fields, function(FieldReport $field) use ($verdict, $type, $search) {
            if ($verdict !== null && $verdict !== '' && $field->verdict !== $verdict) {
                return false;
            }

            if ($type !== null && $type !== '' && $field->type !== $type) {
                return false;
            }

            if ($search !== '') {
                $haystack = strtolower($field->name . ' ' . $field->handle . ' ' . $field->typeName);

                if (!str_contains($haystack, strtolower($search))) {
                    return false;
                }
            }

            return true;
        });

        $types = [];

        foreach ($fields as $field) {
            $types[$field->type] = $field->typeName;
        }

        asort($types);

        return $this->renderTemplate('joan/fields/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Fields'),
            'fields' => $filtered,
            'total' => count($fields),
            'types' => $types,
            'verdict' => $verdict,
            'type' => $type,
            'search' => $search,
        ]));
    }

    public function actionDetail(string $uid): Response
    {
        $field = $this->plugin()->inventory->getByUid($uid);

        if ($field === null) {
            throw new NotFoundHttpException('Field not found');
        }

        return $this->renderTemplate('joan/fields/detail', $this->withChrome([
            'title' => $field->name,
            'field' => $field,
            'ambiguous' => CodeScan::isAmbiguousHandle($field->handle),
            'craftEditUrl' => $field->id ? \craft\helpers\UrlHelper::cpUrl("settings/fields/edit/$field->id") : null,
        ]));
    }
}
