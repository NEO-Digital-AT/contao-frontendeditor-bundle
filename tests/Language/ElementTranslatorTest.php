<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Neo\BasicBundle\Translation\TranslationException;
use Neo\FrontendEditorBundle\Language\ElementTranslator;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\TranslationServices;
use PHPUnit\Framework\TestCase;

/**
 * Element 12 is the main element (English), element 30 its counterpart in the
 * German version.
 */
final class ElementTranslatorTest extends TestCase
{
    use EditorFixture;

    private TranslationServices $services;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->db->update('tl_content', ['text' => '<p>Read <a href="{{link_url::5}}">more</a> about {{page::title}}.</p>'], ['id' => 12]);
        $this->db->insert('tl_content', ['id' => 30, 'pid' => 4, 'ptable' => 'tl_article', 'type' => 'text', 'headline' => serialize(['value' => 'Alte Überschrift', 'unit' => 'h3']), 'text' => '<p>Alt</p>']);
        $this->services = new TranslationServices(['azure_translator_key' => 'azure-key']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testTheProposalTranslatesEveryTextOfTheMainElement(): void
    {
        $proposal = $this->translator()->proposal($this->row(12), $this->row(30), 'en', 'de');

        $this->assertSame([
            'e|headline' => '[de] Old headline',
            'e|text' => '[de] <p>Read <a href="{{link_url::5}}">more</a> about {{page::title}}.</p>',
        ], $proposal);

        // Plain texts and rich text in two requests, from the language of the main page into that of the version.
        $this->assertCount(2, $this->services->requests);
        $this->assertStringContainsString('from=en&to=de', $this->services->requests[0]['url']);
        $this->assertStringContainsString('textType=html', $this->services->requests[1]['url']);
        // Insert tags are not sent to the service.
        $this->assertStringNotContainsString('{{', (string) json_encode($this->services->requests));
        // Nothing is saved.
        $this->assertSame('<p>Alt</p>', $this->stored('text', 30));
    }

    /** An element written in the version first: its texts are the source, its element in the main language the target. */
    public function testTheOtherWayRoundTheVersionIsTranslatedIntoTheMainLanguage(): void
    {
        $proposal = $this->translator()->proposal($this->row(30), $this->row(12), 'de', 'en');

        $this->assertSame(['e|headline' => '[en] Alte Überschrift', 'e|text' => '[en] <p>Alt</p>'], $proposal);
        $this->assertStringContainsString('from=de&to=en', $this->services->requests[0]['url']);
        $this->assertSame('<p>Read <a href="{{link_url::5}}">more</a> about {{page::title}}.</p>', $this->stored('text', 12), 'nothing is saved');
    }

    public function testAVersionWithoutTheElementGetsAProposalToo(): void
    {
        $this->assertSame(['e|headline', 'e|text'], array_keys($this->translator()->proposal($this->row(12), null, 'en', 'de')));
    }

    public function testOnlyTheRequestedTextsAreTranslated(): void
    {
        $proposal = $this->translator()->proposal($this->row(12), $this->row(30), 'en', 'de', ['e|text', 'e|unknown']);

        $this->assertSame(['e|text'], array_keys($proposal));
        $this->assertCount(1, $this->services->requests);
        $this->assertSame([], $this->translator()->proposal($this->row(12), $this->row(30), 'en', 'de', []));
    }

    public function testHtmlCodeIsTranslatedOtherCodeIsNot(): void
    {
        $this->db->insert('tl_content', ['id' => 40, 'pid' => 3, 'type' => 'html', 'html' => '<p class="note">Opening hours</p>']);
        $this->db->insert('tl_content', ['id' => 41, 'pid' => 3, 'type' => 'code', 'code' => 'body { color: red; }']);

        $this->assertSame(['e|html' => '[de] <p class="note">Opening hours</p>'], $this->translator()->proposal($this->row(40), null, 'en', 'de'));
        $this->assertSame([], $this->translator()->proposal($this->row(41), null, 'en', 'de'));
    }

    public function testLongListsGoInSeveralRequests(): void
    {
        $this->db->insert('tl_content', ['id' => 42, 'pid' => 3, 'type' => 'list', 'listitems' => serialize(array_map(static fn (int $i): string => "Item $i", range(1, 30)))]);

        $proposal = $this->translator()->proposal($this->row(42), null, 'en', 'de');

        $this->assertCount(30, $proposal);
        $this->assertSame('[de] Item 30', $proposal['e|listitems.29']);
        $this->assertCount(2, $this->services->requests);
    }

    public function testWithoutTranslationServiceTheProposalFails(): void
    {
        $this->services = new TranslationServices();

        $this->expectException(TranslationException::class);
        $this->translator()->proposal($this->row(12), $this->row(30), 'en', 'de');
    }

    private function translator(): ElementTranslator
    {
        return new ElementTranslator($this->translationUnits(), new TranslationService(new Settings(TestDatabase::create()), $this->services->translators));
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }
}
