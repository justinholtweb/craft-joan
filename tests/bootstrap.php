<?php

/**
 * Bootstrap for the unit suite.
 *
 * These run against plain PHP — no Craft application, no database. What they cover is the
 * part of Joan that is deliberately pure: how a verdict reads, what counts as a strong
 * code reference, and how a textarea full of paths becomes a list. Everything else about
 * Joan is a question asked of a live site, and is covered by tests/integration/checks.php
 * instead — see tests/README.md.
 *
 * The one concession: Yii's validators reach for `Yii::$app` when they build an error
 * message, so the settings tests need *an* application to exist. A bare console app with
 * no components is enough, and keeps this suite free of Craft and of the database.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

if (!isset(Yii::$app)) {
    new yii\console\Application([
        'id' => 'joan-tests',
        'basePath' => dirname(__DIR__),
        'components' => [],
    ]);
}
