<?php

namespace justinholtweb\joan\tests\unit;

use justinholtweb\joan\models\EntryTypeReport;
use justinholtweb\joan\models\NestedFieldReport;
use PHPUnit\Framework\TestCase;

class EntryTypeReportTest extends TestCase
{
    public function testEntryTotalsAddTopLevelToNested(): void
    {
        $report = new EntryTypeReport(['topLevelEntries' => 31, 'nestedEntries' => 4]);

        self::assertSame(35, $report->getTotalEntries());
    }

    public function testUsageCountsSectionsAndNestingFieldsTogether(): void
    {
        $report = new EntryTypeReport([
            'sections' => [['id' => 1, 'name' => 'News', 'handle' => 'news', 'url' => null]],
            'nestingFields' => [['id' => 2, 'name' => 'Body', 'handle' => 'body', 'type' => 'Matrix']],
        ]);

        self::assertSame(2, $report->getUsageCount());
        self::assertFalse($report->isNestedOnly());
    }

    public function testABlockTypeIsNestedOnly(): void
    {
        $report = new EntryTypeReport([
            'nestingFields' => [['id' => 2, 'name' => 'Body', 'handle' => 'body', 'type' => 'Matrix']],
        ]);

        self::assertTrue($report->isNestedOnly());
    }

    public function testOnlyProblemVerdictsNeedAttention(): void
    {
        self::assertFalse((new EntryTypeReport(['verdict' => EntryTypeReport::VERDICT_IN_USE]))->needsAttention());

        foreach ([
            EntryTypeReport::VERDICT_EMPTY,
            EntryTypeReport::VERDICT_STRANDED,
            EntryTypeReport::VERDICT_UNUSED,
        ] as $verdict) {
            self::assertTrue((new EntryTypeReport(['verdict' => $verdict]))->needsAttention(), $verdict);
        }
    }

    public function testUnusedBlockTypesAreTheOnesWithNoBlocks(): void
    {
        $report = new NestedFieldReport([
            'entryTypes' => [
                ['id' => 1, 'name' => 'Text', 'handle' => 'text', 'entries' => 40, 'url' => null],
                ['id' => 2, 'name' => 'Quote', 'handle' => 'quote', 'entries' => 0, 'url' => null],
            ],
            'totalBlocks' => 40,
            'owners' => 8,
        ]);

        self::assertCount(1, $report->getUnusedEntryTypes());
        self::assertSame('quote', $report->getUnusedEntryTypes()[0]['handle']);
        self::assertSame(5.0, $report->getAverageBlocksPerOwner());
    }

    public function testAFieldWithNoOwnersHasNoAverage(): void
    {
        self::assertSame(0.0, (new NestedFieldReport())->getAverageBlocksPerOwner());
    }
}
