<?php

namespace justinholtweb\joan\tests\unit;

use justinholtweb\joan\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testTheDefaultsValidate(): void
    {
        self::assertTrue((new Settings())->validate());
    }

    public function testNothingIsRequired(): void
    {
        // A `required` rule fails validation for the whole settings model, which blocks
        // saving any setting at all — including on a fresh install where none are set.
        foreach ((new Settings())->rules() as $rule) {
            self::assertNotSame('required', $rule[1] ?? null);
        }
    }

    public function testScanPathsLoseTheBlankLinesATextareaLeaves(): void
    {
        $settings = new Settings();
        $settings->scanPaths = ['templates', '', '   ', ' modules '];

        self::assertSame(['templates', 'modules'], $settings->normalizedScanPaths());
    }

    public function testExtensionsAreLowercasedAndUndotted(): void
    {
        $settings = new Settings();
        $settings->scanExtensions = ['.Twig', 'PHP', '', '.js'];

        self::assertSame(['twig', 'php', 'js'], $settings->normalizedExtensions());
    }

    public function testCountsMustBePositiveWhereZeroWouldBeMeaningless(): void
    {
        $settings = new Settings();
        $settings->maxFileSize = 0;

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('maxFileSize', $settings->getErrors());
    }

    public function testZeroMeansNoLimitWhereThatMakesSense(): void
    {
        $settings = new Settings();
        $settings->maxFiles = 0;
        $settings->cacheDuration = 0;

        self::assertTrue($settings->validate());
    }

    public function testAnUnknownLogLevelIsRefused(): void
    {
        $settings = new Settings();
        $settings->logLevel = 'chatty';

        self::assertFalse($settings->validate());
    }
}
