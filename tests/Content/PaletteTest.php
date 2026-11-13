<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content;

use Neo\FrontendEditorBundle\Content\Palette;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\ContentDca;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteTest extends TestCase
{
    protected function setUp(): void
    {
        ContaoState::dca('tl_content', ContentDca::content());
        ContaoState::dca('tl_module', ContentDca::module());
    }

    protected function tearDown(): void
    {
        ContaoState::clear();
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function inlineFields(): iterable
    {
        yield 'headline element' => ['headline', ['headline']];
        yield 'text element: headline and rich text' => ['text', ['headline', 'text']];
        yield 'image element: the image, alt text and caption of "overwrite metadata"' => ['image', ['headline', 'singleSRC', 'alt', 'caption']];
        yield 'HTML element: code is no rich text' => ['html', []];
        yield 'code element' => ['code', ['headline']];
        yield 'an unknown type' => ['unknown', []];
    }

    #[DataProvider('inlineFields')]
    public function testInlineFieldsOfAType(string $type, array $expected): void
    {
        $this->assertSame($expected, Palette::inlineFields($type));
    }

    public function testRichTextOnlyWithTinyMce(): void
    {
        // An extension turns the text field of the markdown element into a plain text area.
        $GLOBALS['TL_DCA']['tl_content']['fields']['text']['eval']['rte'] = null;

        $this->assertSame(['headline'], Palette::inlineFields('markdown'));
    }

    public function testAFieldNameIsMatchedAsAWholeName(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['palettes']['teaser'] = '{type_legend},type,headlineSize;{text_legend},texts,subtext';

        $this->assertSame([], Palette::inlineFields('teaser'));
    }

    public function testSourcesAreTheTextFieldsOfThePaletteWithTheirKind(): void
    {
        $this->assertSame([
            ['field' => 'headline', 'kind' => 'unit'],
            ['field' => 'text', 'kind' => 'rich'],
        ], Palette::sources('tl_content', ['type' => 'text']));

        $this->assertSame([
            ['field' => 'headline', 'kind' => 'unit'],
            ['field' => 'listitems', 'kind' => 'list'],
        ], Palette::sources('tl_content', ['type' => 'list']));

        $this->assertSame([
            ['field' => 'headline', 'kind' => 'unit'],
            ['field' => 'tableitems', 'kind' => 'table'],
        ], Palette::sources('tl_content', ['type' => 'table']));

        // The URL has a picker and is no text; a read-only field is not changed on the page.
        $this->assertSame([
            ['field' => 'headline', 'kind' => 'unit'],
            ['field' => 'linkTitle', 'kind' => 'line'],
        ], Palette::sources('tl_content', ['type' => 'hyperlink']));

        // Code (ACE) and dates are no texts of the page.
        $this->assertSame([['field' => 'headline', 'kind' => 'unit']], Palette::sources('tl_content', ['type' => 'code']));
        $this->assertSame([['field' => 'headline', 'kind' => 'unit']], Palette::sources('tl_content', ['type' => 'headline']));
    }

    public function testARowWizardWithTextColumnsIsATableOthersStayInTheForm(): void
    {
        // Contao 5.7's description list: term and description per row.
        $GLOBALS['TL_DCA']['tl_content']['palettes']['description_list'] = '{type_legend},type,headline;{text_legend},data,options';
        $GLOBALS['TL_DCA']['tl_content']['fields']['data'] = ['inputType' => 'rowWizard', 'fields' => ['key' => ['inputType' => 'text'], 'value' => ['inputType' => 'text']]];
        $GLOBALS['TL_DCA']['tl_content']['fields']['options'] = ['inputType' => 'rowWizard', 'fields' => ['label' => ['inputType' => 'text'], 'kind' => ['inputType' => 'select', 'options' => ['a', 'b']]]];

        $this->assertSame([
            ['field' => 'headline', 'kind' => 'unit'],
            ['field' => 'data', 'kind' => 'table'],
        ], Palette::sources('tl_content', ['type' => 'description_list']));
    }

    public function testSourcesIncludeTheSubpalettesThatAreOn(): void
    {
        $off = Palette::sources('tl_content', ['type' => 'text', 'addImage' => '']);
        $on = Palette::sources('tl_content', ['type' => 'text', 'addImage' => '1', 'overwriteMeta' => '1']);
        $metaOff = Palette::sources('tl_content', ['type' => 'text', 'addImage' => '1', 'overwriteMeta' => '0']);

        $this->assertSame(['headline', 'text'], array_column($off, 'field'));
        $this->assertSame(['headline', 'text', 'alt', 'imageTitle', 'caption'], array_column($on, 'field'));
        $this->assertSame(['headline', 'text'], array_column($metaOff, 'field'));
    }

    public function testHiddenInputIsNeverASource(): void
    {
        $this->assertSame(['name', 'headline'], array_column(Palette::sources('tl_module', ['type' => 'secret']), 'field'));
    }

    public function testTheTextFieldsOfATypeLeaveOutTheInlineFields(): void
    {
        $this->assertSame([], Palette::textFields('text', ['type' => 'text']));
        $this->assertSame([['field' => 'linkTitle', 'kind' => 'text']], Palette::textFields('hyperlink', ['type' => 'hyperlink']));
        $this->assertSame(
            [['field' => 'imageTitle', 'kind' => 'text']],
            Palette::textFields('image', ['type' => 'image', 'overwriteMeta' => '1']),
        );
    }

    public function testImageFieldsAreSingleFilePickersForImages(): void
    {
        $this->assertSame(['singleSRC'], Palette::imageFields('tl_content', ['type' => 'image'], ContentDca::IMAGE_EXTENSIONS));
        // Several files, and a single file that cannot be an image.
        $this->assertSame([], Palette::imageFields('tl_content', ['type' => 'video'], ContentDca::IMAGE_EXTENSIONS));
        $this->assertSame([], Palette::imageFields('tl_content', ['type' => 'text', 'addImage' => ''], ContentDca::IMAGE_EXTENSIONS));
        $this->assertSame(['singleSRC'], Palette::imageFields('tl_content', ['type' => 'text', 'addImage' => '1'], ContentDca::IMAGE_EXTENSIONS));

        // A picker without restriction of the file types may take an image.
        unset($GLOBALS['TL_DCA']['tl_content']['fields']['posterSRC']['eval']['extensions']);
        $this->assertSame(['posterSRC'], Palette::imageFields('tl_content', ['type' => 'video'], ContentDca::IMAGE_EXTENSIONS));
    }

    public function testLinkFieldsHaveThePickerOrTakeAUrl(): void
    {
        $this->assertSame(['url'], Palette::linkFields('tl_content', ['type' => 'hyperlink']));
        $this->assertSame(['imageUrl'], Palette::linkFields('tl_content', ['type' => 'image', 'overwriteMeta' => '1']));
        $this->assertSame([], Palette::linkFields('tl_content', ['type' => 'image']));

        $GLOBALS['TL_DCA']['tl_content']['fields']['url']['eval']['disabled'] = true;
        $this->assertSame([], Palette::linkFields('tl_content', ['type' => 'hyperlink']));
    }

    public function testThePaletteOfARecordFollowsItsTypeOrDefault(): void
    {
        $dca = ContentDca::content();

        $this->assertSame($dca['palettes']['text'], Palette::paletteOf('tl_content', ['type' => 'text']));
        $this->assertSame($dca['palettes']['default'], Palette::paletteOf('tl_content', ['type' => 'unknown']));
        $this->assertSame($dca['palettes']['default'], Palette::paletteOf('tl_content', ['type' => '__selector__']));
        $this->assertSame('', Palette::paletteOf('tl_missing', ['type' => 'text']));
    }
}
