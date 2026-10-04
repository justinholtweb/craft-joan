<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\FieldReport;
use yii\web\Response;

/**
 * The actionable screen: everything nothing appears to be using.
 *
 * Joan deletes nothing, and this screen doesn't offer to. Deleting a field deletes its
 * content for every element, irreversibly, and the right place for that decision is the
 * screen Craft already has for it — with this list open in the next tab.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class CleanupController extends BaseController
{
    // Public Methods
    // =========================================================================

    /**
     * Everything that looks safe to remove, or worth a second look.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $fields = $plugin->inventory->fields();
        $entryTypes = $plugin->entryTypes->all();

        $unusedBlockTypes = [];

        foreach ($plugin->entryTypes->nestedFields() as $nested) {
            foreach ($nested->getUnusedEntryTypes() as $entryType) {
                $unusedBlockTypes[] = ['field' => $nested, 'entryType' => $entryType];
            }
        }

        return $this->renderTemplate('joan/cleanup/index', $this->withChrome([
            'title' => \Craft::t('joan', 'Cleanup'),
            'unusedFields' => array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_UNUSED && !$f->ignored),
            'codeOnlyFields' => array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_CODE_ONLY && !$f->ignored),
            'emptyFields' => array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_EMPTY && !$f->ignored),
            'strandedFields' => array_filter($fields, fn(FieldReport $f) => $f->verdict === FieldReport::VERDICT_STRANDED && !$f->ignored),
            'unusedEntryTypes' => array_filter($entryTypes, fn(EntryTypeReport $t) => $t->verdict === EntryTypeReport::VERDICT_UNUSED),
            'strandedEntryTypes' => array_filter($entryTypes, fn(EntryTypeReport $t) => $t->verdict === EntryTypeReport::VERDICT_STRANDED),
            'emptyEntryTypes' => array_filter($entryTypes, fn(EntryTypeReport $t) => $t->verdict === EntryTypeReport::VERDICT_EMPTY),
            'unusedBlockTypes' => $unusedBlockTypes,
            'unattributedLayouts' => $plugin->layouts->unattributed(),
            'strandedKeys' => $plugin->inventory->contentScan()->strandedKeys,
        ]));
    }
}
