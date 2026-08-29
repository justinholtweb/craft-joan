<?php

namespace justinholtweb\joan\tests\unit;

use justinholtweb\joan\models\ContentScan;
use PHPUnit\Framework\TestCase;

class ContentScanTest extends TestCase
{
    public function testAFieldNobodyMentionedCountsZero(): void
    {
        self::assertSame(0, (new ContentScan())->countFor('nothing'));
        self::assertSame([], (new ContentScan())->typesFor('nothing'));
    }

    public function testCountsAreReadBackPerField(): void
    {
        $scan = new ContentScan([
            'countsByField' => ['a' => 12, 'b' => 0],
            'byFieldAndType' => ['a' => ['craft\elements\Entry' => 10, 'craft\elements\Asset' => 2]],
            'byFieldAndSite' => ['a' => [1 => 12, 2 => 4]],
        ]);

        self::assertSame(12, $scan->countFor('a'));
        self::assertSame(0, $scan->countFor('b'));
        self::assertSame(10, $scan->typesFor('a')['craft\elements\Entry']);
        self::assertSame(4, $scan->sitesFor('a')[2]);
    }

    public function testStrandedRowsAreTotalled(): void
    {
        $scan = new ContentScan(['strandedKeys' => ['one' => 4, 'two' => 11]]);

        self::assertSame(15, $scan->getStrandedRowCount());
    }

    public function testAScanThatDidNotRunSaysSo(): void
    {
        $scan = new ContentScan(['ran' => false]);

        self::assertFalse($scan->ran);
        // …and still answers, so callers don't have to guard every read. It's the `ran`
        // flag that tells them a zero here means "nobody looked".
        self::assertSame(0, $scan->countFor('anything'));
    }
}
