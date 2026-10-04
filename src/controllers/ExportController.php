<?php

namespace justinholtweb\joan\controllers;

use justinholtweb\joan\services\Exports;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Sends a report back as a file.
 *
 * @author Justin Holt <justin@justinholt.com>
 * @since 5.0.0
 */
class ExportController extends BaseController
{
    // Public Methods
    // =========================================================================

    /**
     * Sends a report as a file download.
     *
     * @throws BadRequestHttpException if the report or format isn't one Joan knows
     */
    public function actionDownload(): Response
    {
        $report = (string)$this->request->getRequiredParam('report');
        $format = (string)$this->request->getParam('format', Exports::FORMAT_CSV);

        if (!in_array($report, Exports::reports(), true)) {
            throw new BadRequestHttpException("Unknown report: $report");
        }

        if (!in_array($format, [Exports::FORMAT_CSV, Exports::FORMAT_JSON], true)) {
            throw new BadRequestHttpException("Unknown format: $format");
        }

        $exports = $this->plugin()->exports;

        return $this->response->sendContentAsFile(
            $exports->render($report, $format),
            $exports->filename($report, $format),
            ['mimeType' => $exports->mimeType($format)],
        );
    }
}
