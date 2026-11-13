<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Locales;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\News;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * A website in German (page 2, the main language), English (page 24) and
 * French (page 30), one article each (7, 25, 31). The news of the languages
 * are shown on the pages 11 (German) and 12 (English); the English news 6
 * points to the German news 5 (languageMain).
 */
#[CoversClass(ElementPlaces::class)]
final class ElementPlacesTest extends TestCase
{
    use EditorFixture;

    private ElementLinks $links;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->links = new ElementLinks($this->db);

        foreach ([7 => 2, 25 => 24, 31 => 30] as $article => $page) {
            $this->db->insert('tl_article', ['id' => $article, 'pid' => $page, 'tstamp' => 1, 'title' => 'Article']);
        }

        News::create($this->db);
        $this->db->insert('tl_news_archive', ['id' => 1, 'jumpTo' => 11]);
        $this->db->insert('tl_news_archive', ['id' => 2, 'jumpTo' => 12]);
        $this->db->insert('tl_news', ['id' => 5, 'pid' => 1, 'tstamp' => 1, 'headline' => 'Neu', 'languageMain' => 0]);
        $this->db->insert('tl_news', ['id' => 6, 'pid' => 2, 'tstamp' => 1, 'headline' => 'New', 'languageMain' => 5]);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testThePageOfAnElementIsThatOfItsArticleOrItsPostAlsoInsideElements(): void
    {
        $this->element(100, 'tl_article', 7);
        $this->element(101, 'tl_content', 100);
        $this->element(103, 'tl_content', 101);
        $this->element(110, 'tl_news', 5);
        $this->element(111, 'tl_content', 110);
        $this->element(120, 'tl_form', 3);

        $places = $this->places();
        $this->assertSame(2, $places->pageOf($this->row(100)));
        $this->assertSame(2, $places->pageOf($this->row(103)), 'two levels deep');
        $this->assertSame(11, $places->pageOf($this->row(110)), 'the page that shows the news of the archive');
        $this->assertSame(11, $places->pageOf($this->row(111)));
        $this->assertSame(0, $places->pageOf($this->row(120)), 'no article and no post');
        $this->assertNull($places->element(120));
        $this->assertSame(['ptable' => 'tl_article', 'pid' => 7], $places->top($this->row(103)));
        $this->assertSame(['ptable' => 'tl_news', 'pid' => 5], $places->top($this->row(111)));
        $this->assertTrue($places->isPostTable('tl_news'));
        $this->assertFalse($places->isPostTable('tl_article'));

        // A draft (not saved yet) has no page.
        $this->db->update('tl_content', ['tstamp' => 0], ['id' => 101]);
        $this->assertNull($this->places()->element(103));
    }

    public function testTheMatchingArticleInAnotherLanguageAlsoBetweenTwoVersions(): void
    {
        $places = $this->places();

        $this->assertSame(25, $places->containerOn('tl_article', 7, 2, 2, 24));
        $this->assertSame(7, $places->containerOn('tl_article', 25, 24, 2, 2));
        $this->assertSame(31, $places->containerOn('tl_article', 25, 24, 2, 30), 'from one version into another');
        $this->assertNull($places->containerOn('tl_article', 7, 2, 2, 40), 'a page without articles');
    }

    public function testTheMatchingElementOfAnElementIsItsLinkedElement(): void
    {
        $this->element(100, 'tl_article', 7);
        $this->element(200, 'tl_article', 25);
        $this->element(300, 'tl_article', 31);
        $this->links->link(100, 200, 'en', 'h');
        $places = $this->places();

        $this->assertSame(200, $places->containerOn('tl_content', 100, 2, 2, 24));
        $this->assertSame(100, $places->containerOn('tl_content', 200, 24, 2, 2));
        $this->assertNull($places->containerOn('tl_content', 200, 24, 2, 30), 'not linked in French yet');

        $this->links->link(100, 300, 'fr', 'h');
        $this->assertSame(300, $this->places()->containerOn('tl_content', 200, 24, 2, 30));
        $this->assertNull($this->places()->containerOn('tl_content', 300, 30, 24, 2), 'the main element is not on the main page');
    }

    public function testTheMatchingPostIsLinkedByLanguageMain(): void
    {
        $places = $this->places();

        $this->assertSame(6, $places->containerOn('tl_news', 5, 11, 11, 12));
        $this->assertSame(5, $places->containerOn('tl_news', 6, 12, 11, 11));
        $this->assertSame(5, $places->mainPostOf('tl_news', 6));
        $this->assertNull($places->mainPostOf('tl_news', 5));
        $this->assertSame(6, $places->postOn('tl_news', 5, 12));
        $this->assertNull($places->postOn('tl_news', 5, 13), 'no post of that language');

        // A table of posts without the field of changelanguage: no posts of other languages.
        ContaoState::dca('tl_news', ['config' => ['ptable' => 'tl_news_archive'], 'fields' => []]);
        $this->assertNull($this->places()->postOn('tl_news', 5, 12));
    }

    public function testTheElementOfAMainElementOnAPage(): void
    {
        $this->element(100, 'tl_article', 7);
        $this->element(101, 'tl_content', 100);
        $this->element(201, 'tl_content', 200);
        $this->element(200, 'tl_article', 25);
        $this->element(301, 'tl_article', 31);
        $this->element(110, 'tl_news', 5);
        $this->element(210, 'tl_news', 6);
        $this->links->link(101, 201, 'en', 'h');
        $this->links->link(101, 301, 'fr', 'h');
        $this->links->link(110, 210, 'en', 'h');
        $places = $this->places();

        $this->assertSame(201, $places->counterpartOn(101, 24), 'inside an element');
        $this->assertSame(301, $places->counterpartOn(101, 30));
        $this->assertNull($places->counterpartOn(101, 40), 'no element on that page');
        $this->assertSame(210, $places->counterpartOn(110, 12), 'of a post');
        $this->assertNull($places->counterpartOn(100, 24), 'not linked');

        // A draft (not saved yet) is no counterpart.
        $this->db->update('tl_content', ['tstamp' => 0], ['id' => 301]);
        $this->assertNull($this->places()->counterpartOn(101, 30));
    }

    public function testTheElementsOfAContainerAndWhichOfThemContainElements(): void
    {
        $this->element(100, 'tl_article', 7, 256);
        $this->element(104, 'tl_article', 7, 128);
        $this->element(101, 'tl_content', 100);

        $places = $this->places();
        $this->assertSame([104, 100], array_map('intval', array_column($places->elements('tl_article', 7), 'id')));
        $this->assertSame([100 => true], $places->withChildren([100, 104]));
        $this->assertSame([], $places->withChildren([]));
    }

    private function places(): ElementPlaces
    {
        $versions = new LanguageVersions($this->createStub(ContaoFramework::class), $this->createStub(Security::class), $this->db, $this->createStub(Locales::class));

        return new ElementPlaces($this->db, $this->framework(), $versions, $this->links);
    }

    private function element(int $id, string $ptable, int $pid, int $sorting = 128): void
    {
        $this->db->insert('tl_content', ['id' => $id, 'pid' => $pid, 'ptable' => $ptable, 'sorting' => $sorting, 'tstamp' => 1, 'type' => 'text']);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }
}
