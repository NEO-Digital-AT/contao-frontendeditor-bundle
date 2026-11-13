<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content\SourceMap;

use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\ContentDca;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class TextSourcesTest extends TestCase
{
    use ContaoContainer;
    use Pages;

    protected function setUp(): void
    {
        $this->setUpContao();
        ContaoState::dca('tl_content', ContentDca::content());
        ContaoState::dca('tl_card', [
            'config' => ['ptable' => 'tl_content', 'dynamicPtable' => true],
            'palettes' => ['default' => '{title_legend},title'],
            'fields' => ['title' => ['label' => ['Card title', ''], 'inputType' => 'text']],
        ]);
        ContaoState::language('tl_content', ['tl_content' => ['headline' => ['Headline', 'Help'], 'listitems' => ['List items', '']]]);
        ContaoState::language('tl_card');
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testEveryTextOfARecordIsASourceWithItsKey(): void
    {
        $row = [
            'id' => 12,
            'type' => 'text',
            'headline' => serialize(['value' => 'Welcome', 'unit' => 'h3']),
            'text' => '<p>Hello</p>',
        ];

        $this->assertSame([
            ['table' => 'tl_content', 'id' => 12, 'field' => 'headline', 'key' => 'tl_content.12.headline', 'row' => null, 'col' => null, 'kind' => 'headline', 'value' => 'Welcome', 'unit' => 'h3'],
            ['table' => 'tl_content', 'id' => 12, 'field' => 'text', 'key' => 'tl_content.12.text', 'row' => null, 'col' => null, 'kind' => 'rich', 'value' => '<p>Hello</p>', 'unit' => null],
        ], $this->sources()->of('tl_content', $row));
    }

    public function testRowsOfAListAndCellsOfATableAreSourcesOfTheirOwn(): void
    {
        $list = $this->sources()->of('tl_content', ['id' => 3, 'type' => 'list', 'listitems' => serialize(['First', ' ', 'Third'])]);
        $table = $this->sources()->of('tl_content', ['id' => 4, 'type' => 'table', 'tableitems' => serialize([['Name', 'Price'], ['Tea', '']])]);

        $this->assertSame(['tl_content.3.listitems.0', 'tl_content.3.listitems.2'], array_column($list, 'key'));
        $this->assertSame([0, 2], array_column($list, 'row'));
        $this->assertSame(['tl_content.4.tableitems.0.0', 'tl_content.4.tableitems.0.1', 'tl_content.4.tableitems.1.0'], array_column($table, 'key'));
        $this->assertSame(['Name', 'Price', 'Tea'], array_column($table, 'value'));
        $this->assertSame([0, 1, 0], array_column($table, 'col'));
    }

    public function testAHeadlineStoredAsPlainTextIsALine(): void
    {
        $sources = $this->sources()->of('tl_content', ['id' => 5, 'type' => 'headline', 'headline' => 'Plain headline']);

        $this->assertSame('line', $sources[0]['kind']);
        $this->assertSame('Plain headline', $sources[0]['value']);
        $this->assertNull($sources[0]['unit']);
    }

    public function testEmptyValuesTheImageAndAnIncludedElementsIdAreHandled(): void
    {
        $sources = $this->sources()->of('tl_content', [
            'id' => 20,
            'origId' => 7,
            'type' => 'image',
            'headline' => serialize(['value' => '  ', 'unit' => 'h2']),
            'singleSRC' => 'binary-uuid',
            'overwriteMeta' => '1',
            'alt' => 'A cat',
            'imageTitle' => '',
            'caption' => null,
        ]);

        // An included element shows the texts of the original (origId).
        $this->assertSame(['tl_content.7.alt'], array_column($sources, 'key'));
    }

    public function testVeryLongValuesAreNotSources(): void
    {
        $sources = $this->sources()->of('tl_content', ['id' => 1, 'type' => 'text', 'text' => str_repeat('a', 65536)]);

        $this->assertSame([], $sources);
    }

    public function testTheMarkedCopyWrapsEveryValueAndKeepsUnitsAndOtherCells(): void
    {
        $text = $this->sources();
        $row = ['id' => 4, 'type' => 'table', 'headline' => serialize(['value' => 'Prices', 'unit' => 'h2']), 'tableitems' => serialize([['Name', 'Price'], ['Tea', '2']])];
        $sources = $text->of('tl_content', $row);

        $marked = $text->mark($row, $sources);

        $this->assertSame([0 => 'tl_content.4.headline', 1 => 'tl_content.4.tableitems.0.0', 2 => 'tl_content.4.tableitems.0.1', 3 => 'tl_content.4.tableitems.1.0', 4 => 'tl_content.4.tableitems.1.1'], $marked['keys']);
        $headline = unserialize($marked['changes']['headline']);
        $this->assertSame('h2', $headline['unit']);
        $this->assertSame(Markers::wrap('Prices', 0), $headline['value']);
        $cells = unserialize($marked['changes']['tableitems']);
        $this->assertSame(Markers::wrap('Price', 2), $cells[0][1]);
        $this->assertSame([['Name', 'Price'], ['Tea', '2']], array_map(static fn (array $cells): array => array_map(Markers::strip(...), $cells), $cells));
        // The record itself is not changed.
        $this->assertSame(serialize([['Name', 'Price'], ['Tea', '2']]), $row['tableitems']);
    }

    public function testNamedCellsAreMarkedByPosition(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['fields']['tableitems']['inputType'] = 'keyValueWizard';
        $text = $this->sources();
        $row = ['id' => 4, 'type' => 'table', 'tableitems' => serialize([['key' => 'Colour', 'value' => 'Blue']])];

        $marked = $text->mark($row, $text->of('tl_content', $row));

        $this->assertSame(['key' => Markers::wrap('Colour', 0), 'value' => Markers::wrap('Blue', 1)], unserialize($marked['changes']['tableitems'])[0]);
    }

    public function testChildRecordsOfAnElementInTheirOrder(): void
    {
        $db = TestDatabase::create('tl_card', 'tl_content');
        $db->insert('tl_card', ['id' => 1, 'pid' => 12, 'ptable' => 'tl_content', 'sorting' => 256, 'title' => 'Second']);
        $db->insert('tl_card', ['id' => 2, 'pid' => 12, 'ptable' => 'tl_content', 'sorting' => 128, 'title' => 'First']);
        $db->insert('tl_card', ['id' => 3, 'pid' => 12, 'ptable' => 'tl_news', 'sorting' => 64, 'title' => 'Of a news item']);
        $db->insert('tl_card', ['id' => 4, 'pid' => 13, 'ptable' => 'tl_content', 'sorting' => 64, 'title' => 'Of another element']);

        $children = $this->sources($db)->children(12);

        $this->assertSame(['tl_card', 'tl_card'], array_column($children, 'table'));
        $this->assertSame(['First', 'Second'], array_column(array_column($children, 'row'), 'title'));
    }

    public function testLabelsInTheLanguageOfTheBackEnd(): void
    {
        $text = $this->sources();

        $this->assertSame('Headline', $text->label('tl_content', 'headline'));
        $this->assertSame('List items (3)', $text->label('tl_content', 'listitems', 2));
        $this->assertSame('tableitems (2/1)', $text->label('tl_content', 'tableitems', 1, 0));
        $this->assertSame('Card title', $text->label('tl_card', 'title'));
    }

    private function sources(Connection|null $db = null): TextSources
    {
        $db ??= TestDatabase::create();

        return new TextSources($this->framework(), $db, new RelatedRecords($this->framework(), $db));
    }
}
