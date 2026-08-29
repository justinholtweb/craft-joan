<?php

namespace justinholtweb\joan\tests\unit;

use justinholtweb\joan\models\CodeReference;
use justinholtweb\joan\models\FieldInstance;
use justinholtweb\joan\models\FieldReport;
use justinholtweb\joan\models\LayoutRef;
use PHPUnit\Framework\TestCase;

class FieldReportTest extends TestCase
{
    public function testLayoutCountsSplitElementLayoutsFromTheRest(): void
    {
        $report = new FieldReport([
            'instances' => [
                $this->instance(true),
                $this->instance(true),
                $this->instance(false),
            ],
        ]);

        self::assertSame(3, $report->getLayoutCount());
        self::assertSame(2, $report->getElementLayoutCount());
        self::assertCount(1, $report->getHiddenInstances());
    }

    public function testEveryHandleTheFieldAnswersToIsReported(): void
    {
        $report = new FieldReport([
            'handle' => 'heroImage',
            'instances' => [
                $this->instance(true, 'heroImage'),
                $this->instance(true, 'image'),
                $this->instance(true, 'image'),
            ],
        ]);

        self::assertSame(['heroImage', 'image'], $report->getAllHandles());
        self::assertTrue($report->hasHandleOverrides());
    }

    public function testAFieldWithNoRenamesReportsOnlyItsOwnHandle(): void
    {
        $report = new FieldReport([
            'handle' => 'body',
            'instances' => [$this->instance(true, 'body')],
        ]);

        self::assertSame(['body'], $report->getAllHandles());
        self::assertFalse($report->hasHandleOverrides());
    }

    public function testOnlyStrongReferencesArgueAgainstDeletion(): void
    {
        $report = new FieldReport([
            'codeRefs' => [
                new CodeReference(['context' => CodeReference::CONTEXT_MENTION]),
                new CodeReference(['context' => CodeReference::CONTEXT_MENTION]),
            ],
        ]);

        self::assertFalse($report->hasStrongCodeRefs());
        self::assertCount(0, $report->getStrongCodeRefs());

        $report->codeRefs[] = new CodeReference(['context' => CodeReference::CONTEXT_PROPERTY]);

        self::assertTrue($report->hasStrongCodeRefs());
        self::assertCount(1, $report->getStrongCodeRefs());
    }

    public function testTheReferenceCountSurvivesTheCap(): void
    {
        $report = new FieldReport([
            'codeRefs' => [new CodeReference(), new CodeReference()],
            'codeRefTotal' => 90,
        ]);

        self::assertSame(90, $report->getCodeRefCount());
    }

    public function testAnUncountedFieldIsNotACountOfZero(): void
    {
        $report = new FieldReport();

        self::assertFalse($report->wasCounted());

        $report->usedElements = 0;

        self::assertTrue($report->wasCounted());
    }

    public function testOnlyAnUnusedFieldIsDeletable(): void
    {
        foreach ([
            FieldReport::VERDICT_IN_USE,
            FieldReport::VERDICT_EMPTY,
            FieldReport::VERDICT_STRANDED,
            FieldReport::VERDICT_CODE_ONLY,
            FieldReport::VERDICT_UNCOUNTABLE,
        ] as $verdict) {
            self::assertFalse((new FieldReport(['verdict' => $verdict]))->isDeletable(), $verdict);
        }

        self::assertTrue((new FieldReport(['verdict' => FieldReport::VERDICT_UNUSED]))->isDeletable());
    }

    public function testAnIgnoredFieldIsNeverFlaggedOrDeletable(): void
    {
        $report = new FieldReport([
            'verdict' => FieldReport::VERDICT_UNUSED,
            'ignored' => true,
        ]);

        self::assertFalse($report->needsAttention());
        self::assertFalse($report->isDeletable());
    }

    public function testInUseNeverNeedsAttention(): void
    {
        self::assertFalse((new FieldReport(['verdict' => FieldReport::VERDICT_IN_USE]))->needsAttention());
        self::assertTrue((new FieldReport(['verdict' => FieldReport::VERDICT_EMPTY]))->needsAttention());
    }

    private function instance(bool $isElementLayout, string $handle = 'field'): FieldInstance
    {
        return new FieldInstance([
            'layout' => new LayoutRef(['isElementLayout' => $isElementLayout]),
            'handle' => $handle,
            'handleOverridden' => $handle !== 'field' && $handle !== 'body' && $handle !== 'heroImage',
        ]);
    }
}
