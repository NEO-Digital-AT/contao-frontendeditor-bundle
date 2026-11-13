<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content;

use Composer\InstalledVersions;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\TestCase;

final class FieldWriterTest extends TestCase
{
    use EditorFixture;

    protected function setUp(): void
    {
        $this->setUpEditor();
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testAnElementThatDoesNotExistIsNotFound(): void
    {
        $this->assertSame(WriteStatus::NotFound, $this->writer()->update(99, ['text' => '<p>New</p>'])->status);
    }

    public function testWithoutTheSwitchNothingIsSaved(): void
    {
        $this->switches = ['edit_mode'];

        $result = $this->writer()->update(12, ['text' => '<p>New</p>']);

        $this->assertSame(WriteStatus::Forbidden, $result->status);
        $this->assertSame('<p>Old text</p>', $this->stored('text'));
        $this->assertSame([], $this->askedActions());
    }

    public function testOnlyTheTextFieldsOfTheElementsPaletteMayBeWritten(): void
    {
        $writer = $this->writer();

        foreach ([['type' => 'html'], ['cssID' => serialize(['x', 'y'])], ['text' => '<p>New</p>', 'invisible' => '1'], ['html' => '<script>alert(1)</script>'], ['pid' => '7']] as $input) {
            $this->assertSame(WriteStatus::Forbidden, $writer->update(12, $input)->status, implode(', ', array_keys($input)));
        }

        $this->assertSame('text', $this->stored('type'));
        $this->assertSame('<p>Old text</p>', $this->stored('text'));
        $this->assertSame(0, (int) $this->stored('invisible'));
    }

    public function testEmptyInputIsNotASave(): void
    {
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(12, [])->status);
    }

    public function testAFieldTheUserGroupMayNotEditIsForbidden(): void
    {
        $this->contao = static fn (string $attribute, mixed $subject): bool => !(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && 'tl_content::text' === $subject);

        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(12, ['text' => '<p>New</p>'])->status);
        $this->assertSame('<p>Old text</p>', $this->stored('text'));

        // The headline of the same element is allowed.
        $this->assertSame(WriteStatus::Saved, $this->writer()->update(12, ['headline' => ['value' => 'New', 'unit' => 'h2']])->status);
    }

    public function testRichTextIsFilteredLikeTheBackEndFormAndKeepsInsertTags(): void
    {
        $result = $this->writer()->update(12, ['text' => '<p>Hi <strong>you</strong><script>alert(1)</script> <a href="{{link_url::3}}" onclick="steal()">More</a> {{insert_article::5}}</p><img src="x" onerror="steal()">']);

        $this->assertSame(WriteStatus::Saved, $result->status);
        $text = $this->stored('text');
        $this->assertStringContainsString('<p>Hi <strong>you</strong>', $text);
        $this->assertStringNotContainsString('<script', $text);
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('onerror', $text);
        $this->assertMatchesRegularExpression('/<a href="\{\{link_url::3(\|urlattr)?\}\}">More<\/a>/', $text);
        $this->assertStringContainsString('{{insert_article::5}}', $text);
        $this->assertSame($text, $result->record['text']);
        $this->assertGreaterThan(100, (int) $this->stored('tstamp'));
        $this->assertSame(['contao.db.tl_content.12'], $this->cacheTags->invalidated);
    }

    public function testPlainTextIsStoredLikeTheBackEndFormAndItsInsertTagsKept(): void
    {
        $this->db->insert('tl_content', ['id' => 13, 'pid' => 3, 'type' => 'hyperlink', 'url' => 'https://www.example.com', 'linkTitle' => 'Old']);

        $result = $this->writer()->update(13, ['linkTitle' => 'More <b>info</b> & "prices" {{date::Y}}']);

        // The widget reads the value as in Contao's own form: Contao 5 encodes special
        // characters of plain text there, Contao 6 stores it as typed and escapes on output.
        $expected = version_compare((string) InstalledVersions::getVersion('contao/core-bundle'), '6.0.0', '>=')
            ? 'More <b>info</b> & "prices" {{date::Y}}'
            : 'More &#60;b&#62;info&#60;/b&#62; & &#34;prices&#34; {{date::Y}}';

        $this->assertSame(WriteStatus::Saved, $result->status);
        $this->assertSame($expected, $this->stored('linkTitle', 13));
    }

    public function testTheLinkOfAnElementIsCheckedByItsWidget(): void
    {
        $this->db->insert('tl_content', ['id' => 13, 'pid' => 3, 'type' => 'hyperlink', 'url' => 'https://www.example.com']);

        $this->assertSame(WriteStatus::Saved, $this->writer()->update(13, ['url' => '{{link_url::7}}'])->status);
        $this->assertMatchesRegularExpression('/^\{\{link_url::7(\|urlattr)?\}\}$/', $this->stored('url', 13));
        $saved = $this->stored('url', 13);

        $invalid = $this->writer()->update(13, ['url' => 'two words']);
        $this->assertSame(WriteStatus::Invalid, $invalid->status);
        $this->assertSame(['url' => [$GLOBALS['TL_LANG']['ERR']['url']]], $invalid->errors);
        $this->assertSame($saved, $this->stored('url', 13));
    }

    public function testMarkersOfTheSourceMapAreNeverSaved(): void
    {
        $this->db->insert('tl_content', ['id' => 14, 'pid' => 3, 'type' => 'list', 'listitems' => serialize(['One', 'Two'])]);

        $this->writer()->update(12, [
            'headline' => ['value' => Markers::wrap('New headline', 0), 'unit' => 'h2'],
            'text' => '<p>'.Markers::wrap('New text', 1).'</p>',
        ]);
        $this->writer()->update(14, [], ['listitems' => ['1' => Markers::wrap('Second', 3)]]);

        $this->assertSame(['value' => 'New headline', 'unit' => 'h2'], unserialize($this->stored('headline')));
        $this->assertSame('<p>New text</p>', $this->stored('text'));
        $this->assertSame(['One', 'Second'], unserialize($this->stored('listitems', 14)));

        foreach ($this->db->fetchAllAssociative('SELECT * FROM tl_content') as $row) {
            foreach ($row as $value) {
                $this->assertFalse(Markers::contains((string) $value));
            }
        }
    }

    public function testAValueThatDiffersOnlyByMarkersIsUnchanged(): void
    {
        $result = $this->writer()->update(12, ['text' => Markers::wrap('<p>Old text</p>', 1)]);

        $this->assertSame(WriteStatus::Saved, $result->status);
        $this->assertSame(100, (int) $this->stored('tstamp'));
        $this->assertSame([], $this->cacheTags->invalidated);
        // Contao was not asked about new values: there are none.
        $this->assertSame(['contao_dc.tl_content UpdateAction'], $this->askedActions());
    }

    public function testAHeadlineIsSavedWithItsLevelAndOnlyWithALevelOfTheField(): void
    {
        $this->assertSame(WriteStatus::Saved, $this->writer()->update(12, ['headline' => ['value' => 'Fresh', 'unit' => 'h3']])->status);
        $this->assertSame(['value' => 'Fresh', 'unit' => 'h3'], unserialize($this->stored('headline')));

        $result = $this->writer()->update(12, ['headline' => ['value' => 'Fresh', 'unit' => 'script']]);
        $this->assertSame(WriteStatus::Invalid, $result->status);
        $this->assertSame(['headline' => ['Invalid option value!']], $result->errors);
        $this->assertSame('h3', unserialize($this->stored('headline'))['unit']);
    }

    public function testAHeadlineStoredAsPlainTextStaysPlainText(): void
    {
        $this->db->insert('tl_content', ['id' => 15, 'pid' => 3, 'type' => 'headline', 'headline' => 'Plain headline']);

        $this->assertSame(WriteStatus::Saved, $this->writer()->update(15, ['headline' => 'New plain headline'])->status);
        $this->assertSame('New plain headline', $this->stored('headline', 15));
    }

    public function testChangedRowsOfAListAreMergedIntoTheStoredList(): void
    {
        $this->db->insert('tl_content', ['id' => 14, 'pid' => 3, 'type' => 'list', 'listitems' => serialize(['One', 'Two', 'Three'])]);

        $this->assertSame(WriteStatus::Saved, $this->writer()->update(14, [], ['listitems' => ['1' => 'Two <em>new</em>']])->status);
        $this->assertSame(['One', 'Two <em>new</em>', 'Three'], unserialize($this->stored('listitems', 14)));

        // A row that does not exist is not added.
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(14, [], ['listitems' => ['3' => 'Four']])->status);
        $this->assertCount(3, unserialize($this->stored('listitems', 14)));
    }

    public function testCellsOfATableAreChangedByPosition(): void
    {
        $this->db->insert('tl_content', ['id' => 16, 'pid' => 3, 'type' => 'table', 'tableitems' => serialize([['Name', 'Price'], ['Tea', '2']])]);

        $this->assertSame(WriteStatus::Saved, $this->writer()->update(16, [], ['tableitems' => ['1' => ['1' => '3']]])->status);
        $this->assertSame([['Name', 'Price'], ['Tea', '3']], unserialize($this->stored('tableitems', 16)));

        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(16, [], ['tableitems' => ['1' => ['2' => 'x']]])->status);
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(16, [], ['tableitems' => ['2' => ['0' => 'x']]])->status);
    }

    public function testContaoDecidesOnTheNewValues(): void
    {
        $this->contao = static fn (string $attribute, mixed $subject): bool => !($subject instanceof UpdateAction && null !== $subject->getNew());

        $result = $this->writer()->update(12, ['text' => '<p>New</p>']);

        $this->assertSame(WriteStatus::Forbidden, $result->status);
        $this->assertSame('<p>Old text</p>', $this->stored('text'));
        $this->assertSame([], $this->cacheTags->invalidated);
    }

    public function testErrorsOfTheWidgetAreReturnedByField(): void
    {
        $result = $this->writer()->update(12, ['text' => '']);

        $this->assertSame(WriteStatus::Invalid, $result->status);
        $this->assertSame(['text'], array_keys($result->errors));
        $this->assertSame([\sprintf($GLOBALS['TL_LANG']['ERR']['mandatory'], 'text')], $result->errors['text']);
        $this->assertSame('<p>Old text</p>', $this->stored('text'));
    }

    public function testSaveCallbacksOfTheFieldRunAndTheirErrorsAreShown(): void
    {
        $this->db->insert('tl_content', ['id' => 13, 'pid' => 3, 'type' => 'hyperlink', 'url' => 'https://www.example.com', 'linkTitle' => 'Old']);
        $GLOBALS['TL_DCA']['tl_content']['fields']['linkTitle']['save_callback'] = [static fn (mixed $value): string => strtoupper((string) $value)];

        $this->writer()->update(13, ['linkTitle' => 'shout']);
        $this->assertSame('SHOUT', $this->stored('linkTitle', 13));

        $GLOBALS['TL_DCA']['tl_content']['fields']['linkTitle']['save_callback'] = [static fn (): never => throw new \RuntimeException('Not allowed here.')];
        $result = $this->writer()->update(13, ['linkTitle' => 'other']);

        $this->assertSame(WriteStatus::Invalid, $result->status);
        $this->assertSame(['linkTitle' => ['Not allowed here.']], $result->errors);
        $this->assertSame('SHOUT', $this->stored('linkTitle', 13));
    }

    public function testAltTextAndCaptionSwitchOnOverwriteMetadata(): void
    {
        $this->db->insert('tl_content', ['id' => 17, 'pid' => 3, 'type' => 'image', 'overwriteMeta' => 0]);

        $this->assertSame(WriteStatus::Saved, $this->writer()->update(17, ['alt' => 'A cat on a chair'])->status);
        $this->assertSame('A cat on a chair', $this->stored('alt', 17));
        $this->assertSame('1', (string) $this->stored('overwriteMeta', 17));
    }

    public function testAltTextNeedsThePermissionForOverwriteMetadata(): void
    {
        $this->db->insert('tl_content', ['id' => 17, 'pid' => 3, 'type' => 'image', 'overwriteMeta' => 0]);
        $this->contao = static fn (string $attribute, mixed $subject): bool => !(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && 'tl_content::overwriteMeta' === $subject);

        $this->assertSame(WriteStatus::Forbidden, $this->writer()->update(17, ['alt' => 'A cat'])->status);
        $this->assertSame('', $this->stored('alt', 17));
    }

    public function testTheTableNameOfARecordIsChecked(): void
    {
        $writer = $this->writer();

        foreach (['tl_content', 'tl_user; DROP TABLE tl_content', 'TL_CARD', ''] as $table) {
            $this->assertSame(WriteStatus::Forbidden, $writer->updateRecord($table, 1, 12, ['title' => 'x'])->status, $table);
        }

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM tl_content WHERE id = 12'));
    }

    public function testARecordOfAChildTableBelongsToTheElement(): void
    {
        $this->db->insert('tl_card', ['id' => 1, 'pid' => 12, 'title' => 'Card']);
        $this->db->insert('tl_card', ['id' => 2, 'pid' => 40, 'title' => 'Card of another element']);

        $result = $this->writer()->updateRecord('tl_card', 1, 12, ['title' => 'New card']);

        $this->assertSame(WriteStatus::Saved, $result->status);
        $this->assertSame('New card', $this->db->fetchOne('SELECT title FROM tl_card WHERE id = 1'));
        $this->assertSame(['contao.db.tl_card.1', 'contao.db.tl_content.12'], $this->cacheTags->invalidated);

        // The record of another element, through an element the user may change.
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_card', 2, 12, ['title' => 'Taken over'])->status);
        $this->assertSame('Card of another element', $this->db->fetchOne('SELECT title FROM tl_card WHERE id = 2'));
        $this->assertSame(WriteStatus::NotFound, $this->writer()->updateRecord('tl_card', 9, 12, ['title' => 'x'])->status);
    }

    public function testARecordTheElementShowsFromAnotherTableIsChangedThroughIt(): void
    {
        $this->addFaq();

        $result = $this->writer()->updateRecord('tl_faq', 2, 70, ['question' => 'How exactly?']);

        $this->assertSame(WriteStatus::Saved, $result->status);
        $this->assertSame('How exactly?', $this->db->fetchOne('SELECT question FROM tl_faq WHERE id = 2'));
        $this->assertSame(['contao.db.tl_faq.2', 'contao.db.tl_content.70'], $this->cacheTags->invalidated);
        $this->assertSame(WriteStatus::Saved, $this->writer()->updateRecord('tl_faq_category', 1, 70, ['title' => 'Questions'])->status, 'the category itself');

        // A question of another category, through an element the user may change.
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_faq', 3, 70, ['question' => 'Taken over'])->status);
        $this->assertSame('Wie?', $this->db->fetchOne('SELECT question FROM tl_faq WHERE id = 3'));

        // The voters of the other table decide.
        $this->contao = static fn (string $attribute, mixed $subject): bool => 'contao_dc.tl_faq' !== $attribute;
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_faq', 1, 70, ['question' => 'Denied'])->status);
        $this->assertSame('Why?', $this->db->fetchOne('SELECT question FROM tl_faq WHERE id = 1'));
    }

    public function testATableThatIsNoChildTableOfContentElementsIsForbidden(): void
    {
        $this->db->executeStatement("CREATE TABLE tl_news_card (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '')");
        $this->db->insert('tl_news_card', ['id' => 1, 'pid' => 12, 'title' => 'Card']);

        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_news_card', 1, 12, ['title' => 'New'])->status);
        $this->assertSame('Card', $this->db->fetchOne('SELECT title FROM tl_news_card WHERE id = 1'));
    }

    public function testAChildRecordNeedsTheRightToChangeTheElement(): void
    {
        $this->db->insert('tl_card', ['id' => 1, 'pid' => 12, 'title' => 'Card']);
        $this->contao = static fn (string $attribute, mixed $subject): bool => 'contao_dc.tl_content' !== $attribute;

        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_card', 1, 12, ['title' => 'New'])->status);
    }

    public function testAFrontEndModuleNeedsTheSwitchAndContaosPermissions(): void
    {
        $this->db->insert('tl_module', ['id' => 5, 'type' => 'search', 'name' => 'Search', 'headline' => serialize(['value' => 'Search', 'unit' => 'h2'])]);

        $this->assertSame(WriteStatus::Saved, $this->writer()->updateRecord('tl_module', 5, 0, ['headline' => ['value' => 'Find', 'unit' => 'h2']])->status);
        $this->assertSame('Find', unserialize($this->db->fetchOne('SELECT headline FROM tl_module WHERE id = 5'))['value']);
        $this->assertSame(['contao.db.tl_module.5'], $this->cacheTags->invalidated);

        // Without access to the back-end module of themes.
        $this->contao = static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE !== $attribute;
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_module', 5, 0, ['headline' => ['value' => 'Gone', 'unit' => 'h2']])->status);

        // Without the switch.
        $this->contao = static fn (string $attribute, mixed $subject): bool => true;
        $this->switches = ['edit_mode'];
        $this->assertSame(WriteStatus::Forbidden, $this->writer()->updateRecord('tl_module', 5, 0, ['headline' => ['value' => 'Gone', 'unit' => 'h2']])->status);
        $this->assertSame('Find', unserialize($this->db->fetchOne('SELECT headline FROM tl_module WHERE id = 5'))['value']);
    }
}
