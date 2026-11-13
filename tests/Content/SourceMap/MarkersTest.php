<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content\SourceMap;

use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use PHPUnit\Framework\TestCase;

final class MarkersTest extends TestCase
{
    public function testAWrappedValueIsInvisibleAndComesBackWhenStripped(): void
    {
        $wrapped = Markers::wrap('Welcome', 7);

        $this->assertNotSame('Welcome', $wrapped);
        $this->assertTrue(Markers::contains($wrapped));
        $this->assertSame('Welcome', Markers::strip($wrapped));
        // Only characters of Unicode's private use plane 16 are added.
        $this->assertSame('Welcome', preg_replace('/[\x{100000}-\x{10FFFF}]/u', '', $wrapped));
    }

    public function testTextWithoutMarkersIsLeftAlone(): void
    {
        $text = 'Grüße <b>aus</b> Wien 😀 {{link_url::3}}';

        $this->assertFalse(Markers::contains($text));
        $this->assertSame($text, Markers::strip($text));
        // Another character of the same plane that is no marker.
        $this->assertFalse(Markers::contains("a\u{10FF40}b"));
        $this->assertSame("a\u{10FF40}b", Markers::strip("a\u{10FF40}b"));
    }

    public function testMarkersInTextBecomeCommentsWithTheSource(): void
    {
        $html = '<h2>'.Markers::wrap('Welcome', 0).'</h2><p>'.Markers::wrap('Hello <b>you</b>', 12).'</p>';

        $result = Markers::convert($html, [0 => 'tl_content.5.headline', 12 => 'tl_content.5.text']);

        $this->assertSame('<h2><!--nfe:f:tl_content.5.headline-->Welcome<!--/nfe:f:tl_content.5.headline--></h2><p><!--nfe:f:tl_content.5.text-->Hello <b>you</b><!--/nfe:f:tl_content.5.text--></p>', $result['html']);
        $this->assertSame(['tl_content.5.headline', 'tl_content.5.text'], $result['found']);
    }

    public function testMarkersInTagsAttributesScriptsStylesAndCommentsAreRemoved(): void
    {
        $value = Markers::wrap('Welcome', 1);
        $html = '<a href="/'.$value.'" title=\''.$value.'\' data-x='.$value.'>x</a>'
            .'<script>var t = "'.$value.'";</script>'
            .'<style>.a::after{content:"'.$value.'"}</style>'
            .'<!-- '.$value.' -->'
            .'<title>'.$value.'</title>'
            .'<textarea>'.$value.'</textarea>'
            .'<img alt="'.$value.'">';

        $result = Markers::convert($html, [1 => 'tl_content.5.headline']);

        $this->assertFalse(Markers::contains($result['html']));
        $this->assertSame(Markers::strip($html), $result['html']);
        $this->assertStringNotContainsString('nfe:f:', $result['html']);
        $this->assertSame([], $result['found']);
    }

    public function testAValueWithoutItsClosingMarkerIsNotFound(): void
    {
        $open = Markers::wrap('', 3);
        $html = '<p>'.substr($open, 0, (int) (\strlen($open) / 2)).'Cut</p>';

        $result = Markers::convert($html, [3 => 'tl_content.1.text']);

        $this->assertFalse(Markers::contains($result['html']));
        $this->assertSame([], $result['found']);
    }

    public function testAMarkerWithAnUnknownNumberIsRemoved(): void
    {
        $result = Markers::convert('<p>'.Markers::wrap('Other', 4).'</p>', [3 => 'tl_content.1.text']);

        $this->assertSame('<p>Other</p>', $result['html']);
        $this->assertSame([], $result['found']);
    }

    public function testAMarkerSplitByATagIsFoundOnBothSides(): void
    {
        // A template that puts the value into an attribute and into the text.
        $value = Markers::wrap('Mehr erfahren', 25);
        $html = '<a title="'.$value.'">'.$value.'</a>';

        $result = Markers::convert($html, [25 => 'tl_content.9.linkTitle']);

        $this->assertSame('<a title="Mehr erfahren"><!--nfe:f:tl_content.9.linkTitle-->Mehr erfahren<!--/nfe:f:tl_content.9.linkTitle--></a>', $result['html']);
        $this->assertSame(['tl_content.9.linkTitle'], $result['found']);
    }

    public function testNumbersWithSeveralDigitsAreKeptApart(): void
    {
        $html = Markers::wrap('A', 1).Markers::wrap('B', 11);

        $result = Markers::convert($html, [1 => 'k1', 11 => 'k11']);

        $this->assertSame('<!--nfe:f:k1-->A<!--/nfe:f:k1--><!--nfe:f:k11-->B<!--/nfe:f:k11-->', $result['html']);
    }
}
