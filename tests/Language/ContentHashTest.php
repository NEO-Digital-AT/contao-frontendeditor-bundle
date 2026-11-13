<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Neo\FrontendEditorBundle\Language\ContentHash;
use PHPUnit\Framework\TestCase;

final class ContentHashTest extends TestCase
{
    private const ROW = ['headline' => 'a:2:{s:5:"value";s:7:"Welcome";s:4:"unit";s:2:"h2";}', 'text' => '<p>Hello</p>', 'alt' => 'A cat', 'caption' => 'Our cat', 'singleSRC' => 'uuid-1', 'cssID' => ''];

    public function testOnlyTheTranslatedTextsCount(): void
    {
        $hash = ContentHash::legacy(self::ROW);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        // The level of the headline, the image and the layout are not translated.
        $this->assertSame($hash, ContentHash::legacy(['headline' => serialize(['value' => 'Welcome', 'unit' => 'h3']), 'singleSRC' => 'uuid-2', 'cssID' => 'x'] + self::ROW));
        // Spaces around the texts neither.
        $this->assertSame($hash, ContentHash::legacy(['text' => " <p>Hello</p>\n", 'alt' => ' A cat '] + self::ROW));
    }

    public function testEveryTranslatedTextChangesTheHash(): void
    {
        $hash = ContentHash::legacy(self::ROW);

        foreach (['headline' => serialize(['value' => 'Hi', 'unit' => 'h2']), 'text' => '<p>Hi</p>', 'alt' => 'A dog', 'caption' => 'Our dog'] as $field => $value) {
            $this->assertNotSame($hash, ContentHash::legacy([$field => $value] + self::ROW), $field);
        }
    }

    public function testValuesDoNotRunIntoEachOther(): void
    {
        $this->assertNotSame(ContentHash::legacy(['alt' => 'ab', 'caption' => '']), ContentHash::legacy(['alt' => 'a', 'caption' => 'b']));
        $this->assertSame(ContentHash::legacy([]), ContentHash::legacy(['headline' => '', 'text' => null]));
    }
}
