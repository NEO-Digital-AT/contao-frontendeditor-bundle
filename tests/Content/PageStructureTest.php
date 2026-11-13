<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Ai\WritingAid;
use Neo\FrontendEditorBundle\Content\PageEditor;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Tests\Support\ContentDca;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\Licences;
use Neo\FrontendEditorBundle\Tests\Support\Unused;
use PHPUnit\Framework\TestCase;

/**
 * What the editor learns about one element (PageStructure::element()): the
 * fields it changes on the page, the sources of its texts with whether and why
 * not they can be changed, its pickers and links. The rest of the page
 * structure needs Contao's page models and is not part of these tests.
 */
final class PageStructureTest extends TestCase
{
    use EditorFixture;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->switches = ['edit_mode', 'edit_content'];
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testAnElementTheUserMayChange(): void
    {
        $element = $this->structure()->element($this->row(12), 'en');

        $this->assertSame(12, $element['id']);
        $this->assertSame(3, $element['article']);
        $this->assertSame('text', $element['type']);
        $this->assertTrue($element['canUpdate']);
        $this->assertSame(['headline', 'text'], $element['fields']);
        $this->assertSame('Old headline', $element['summary']);
        $this->assertSame(['canMove' => false, 'canCopy' => false, 'canToggle' => false, 'canDelete' => false], array_intersect_key($element, array_flip(['canMove', 'canCopy', 'canToggle', 'canDelete'])));
        $this->assertSame(['headline' => ['value' => 'Old headline', 'unit' => 'h2'], 'text' => '<p>Old text</p>', 'image' => null], $element['values']);
        $this->assertSame(['tl_content.12.headline', 'tl_content.12.text'], array_column($element['sources'], 'key'));
        $this->assertSame([true, true], array_column($element['sources'], 'editable'));
        $this->assertSame(['own', 'own'], array_column($element['sources'], 'scope'));
    }

    public function testFieldsAndSourcesTheUserMayNotChangeSayWhy(): void
    {
        $this->contao = static fn (string $attribute, mixed $subject): bool => !(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && 'tl_content::text' === $subject);
        $element = $this->structure()->element($this->row(12), 'en');

        $this->assertSame(['headline'], $element['fields']);
        $this->assertSame([null, 'field'], array_column($element['sources'], 'reason'));

        $this->contao = static fn (string $attribute, mixed $subject): bool => 'contao_dc.tl_content' !== $attribute;
        $element = $this->structure()->element($this->row(12), 'en');

        $this->assertFalse($element['canUpdate']);
        $this->assertSame([], $element['fields']);
        $this->assertSame(['record', 'record'], array_column($element['sources'], 'reason'));
    }

    public function testPlainTextsComeAsContaosFormShowsThemAndRichTextAsStored(): void
    {
        $this->db->update('tl_content', ['headline' => serialize(['value' => 'Q&amp;A &#34;now&#34;', 'unit' => 'h2']), 'text' => '<p>Tea &amp; cake</p>'], ['id' => 12]);

        $sources = $this->structure()->element($this->row(12), 'en')['sources'];

        // The editor compares the value with the text on the page and edits it there.
        $this->assertSame('Q&A "now"', $sources[0]['value']);
        $this->assertSame('<p>Tea &amp; cake</p>', $sources[1]['value']);
    }

    public function testTheTextsOfChildRecordsAreSourcesOfTheirOwn(): void
    {
        $this->db->insert('tl_card', ['id' => 1, 'pid' => 12, 'title' => 'First card']);

        $sources = $this->structure()->element($this->row(12), 'en')['sources'];

        $this->assertSame('tl_card.1.title', $sources[2]['key']);
        $this->assertSame('child', $sources[2]['scope']);
        // The element the record belongs to, which the save names.
        $this->assertSame(12, $sources[2]['owner']);
        $this->assertTrue($sources[2]['editable']);
    }

    public function testLinksWithTheAddressThePageShows(): void
    {
        $this->db->insert('tl_content', ['id' => 13, 'pid' => 3, 'type' => 'hyperlink', 'url' => '{{link_url::7}}', 'linkTitle' => 'More']);

        $links = $this->structure()->element($this->row(13), 'en')['links'];

        $this->assertSame([['field' => 'url', 'label' => 'url', 'value' => '{{link_url::7}}', 'href' => '/page-7.html', 'editable' => true]], $links);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }

    private function structure(): PageStructure
    {
        $security = $this->editorSecurity();
        $framework = $this->framework();
        $insertTags = $this->createStub(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnCallback(static fn (string $text): string => (string) preg_replace('/\{\{link_url::(\d+)\}\}/', '/page-$1.html', $text));

        return new PageStructure(
            $this->db,
            $framework,
            $security,
            new ElementAccess($security, $framework),
            Licences::editions($framework),
            Unused::instance(LanguageVersions::class),
            new ElementLinks($this->db),
            $this->sourceHash(),
            Unused::instance(TranslationService::class),
            Unused::instance(WritingAid::class),
            new Settings($this->db),
            Unused::instance(PageEditor::class),
            new PageStyles($this->db),
            Unused::instance(StyleVoter::class),
            new RecordAccess($security, $this->db, $framework),
            $insertTags,
            new TextSources($framework, $this->db, new RelatedRecords($framework, $this->db)),
            new ElementPlaces($this->db, $framework, Unused::instance(LanguageVersions::class), new ElementLinks($this->db)),
            null,
            ContentDca::IMAGE_EXTENSIONS,
        );
    }
}
