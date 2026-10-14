<?php

namespace Tests\Unit;

use Database\Seeders\Support\MarkerParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkerParserTest extends TestCase
{
    /**
     * Every marker variant found in the paper job sheets.
     */
    public static function markers(): array
    {
        return [
            'stars' => ['***', 'photo_or_video', false],
            'four stars' => ['NA Button  ****', 'photo_or_video', true],
            'na with stars' => ['NA Button   ***', 'photo_or_video', true],
            'direct' => ['Direct', 'none', false],
            'direct with trailing space' => ['Direct ', 'none', false],
            'blank' => ['', 'none', false],
            'direct and na' => ['Direct & NA Button', 'none', true],
            'na then direct, odd spacing' => ['NA Button     Direct', 'none', true],
        ];
    }

    #[DataProvider('markers')]
    public function test_parses_sheet_markers(string $marker, string $media, bool $na): void
    {
        $parsed = MarkerParser::parse($marker);

        $this->assertSame($media, $parsed['media_requirement']);
        $this->assertSame($na, $parsed['na_allowed']);
        $this->assertFalse($parsed['unknown']);
    }

    public function test_star_default_is_configurable(): void
    {
        $this->assertSame('photo', MarkerParser::parse('***', 'photo')['media_requirement']);
    }

    public function test_flags_unknown_markers(): void
    {
        $this->assertTrue(MarkerParser::parse('Photo please')['unknown']);
    }
}
