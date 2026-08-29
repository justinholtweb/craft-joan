<?php

namespace justinholtweb\joan\tests\unit;

use justinholtweb\joan\models\CodeReference;
use justinholtweb\joan\services\CodeScan;
use PHPUnit\Framework\TestCase;

class CodeReferenceTest extends TestCase
{
    public function testPropertyAndQuotedHitsAreStrongAndBareMentionsAreNot(): void
    {
        self::assertTrue((new CodeReference(['context' => CodeReference::CONTEXT_PROPERTY]))->isStrong());
        self::assertTrue((new CodeReference(['context' => CodeReference::CONTEXT_QUOTED]))->isStrong());
        self::assertFalse((new CodeReference(['context' => CodeReference::CONTEXT_MENTION]))->isStrong());
    }

    public function testAMentionIsTheDefault(): void
    {
        // The safe default: a reference Joan can't classify shouldn't quietly count as
        // evidence that a field is in use.
        self::assertFalse((new CodeReference())->isStrong());
    }

    public function testCommonWordsAreFlaggedWhateverTheirCase(): void
    {
        self::assertTrue(CodeScan::isAmbiguousHandle('title'));
        self::assertTrue(CodeScan::isAmbiguousHandle('Body'));
        self::assertTrue(CodeScan::isAmbiguousHandle('URL'));
    }

    public function testASpecificHandleIsNotFlagged(): void
    {
        self::assertFalse(CodeScan::isAmbiguousHandle('heroCalloutText'));
        self::assertFalse(CodeScan::isAmbiguousHandle('newsSummary'));
    }
}
