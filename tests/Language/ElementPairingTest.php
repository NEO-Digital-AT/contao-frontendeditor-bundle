<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Locales;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPairing;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * A website whose English version was made before the editor: the same
 * elements on both pages, no links yet.
 */
#[CoversClass(ElementPairing::class)]
final class ElementPairingTest extends TestCase
{
    use EditorFixture;

    private ElementLinks $links;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->links = new ElementLinks($this->db);
        $this->db->insert('tl_article', ['id' => 7, 'pid' => 2, 'tstamp' => 1, 'title' => 'Start']);
        $this->db->insert('tl_article', ['id' => 25, 'pid' => 24, 'tstamp' => 1, 'title' => 'Home']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testTheVersionMadeBeforeTheEditorIsPairedByStructureAndCountsAsCurrent(): void
    {
        $de = $this->elements(7, 100, ['lf_stage', 'lf_strip', 'module_grid', 'lf_faq']);
        $en = $this->elements(25, 200, ['lf_stage', 'lf_strip', 'module_grid', 'lf_faq']);

        $this->assertSame(4, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));

        foreach ($en as $i => $id) {
            $link = $this->links->ofElement($id);
            $this->assertSame($de[$i], (int) $link['main']);
            $this->assertSame('en', $link['language']);
            $this->assertSame('', $link['outdated'], 'current for the main element as it is now');
            $this->assertSame($this->sourceHash()->of($this->row($de[$i])), $link['source_hash']);
        }
    }

    public function testAnElementOnlyOneSideHasDoesNotShiftTheRest(): void
    {
        $de = $this->elements(7, 100, ['lf_stage', 'text', 'lf_faq', 'lf_closer']);
        $en = $this->elements(25, 200, ['lf_stage', 'lf_faq', 'image', 'lf_closer']);

        $this->assertSame(3, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertSame($de[0], (int) $this->links->ofElement($en[0])['main']);
        $this->assertSame($de[2], (int) $this->links->ofElement($en[1])['main'], 'the FAQ with the FAQ');
        $this->assertNull($this->links->ofElement($en[2]), 'the image has no counterpart');
        $this->assertSame($de[3], (int) $this->links->ofElement($en[3])['main']);
    }

    public function testLinksThatExistStayAndOnlyTheRestIsPaired(): void
    {
        $de = $this->elements(7, 100, ['text', 'text']);
        $en = $this->elements(25, 200, ['text', 'text']);
        $this->links->link($de[0], $en[1], 'en', 'own');

        $this->assertSame(1, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertSame('own', $this->links->ofElement($en[1])['source_hash'], 'a link someone made stays');
        $this->assertSame($de[1], (int) $this->links->ofElement($en[0])['main']);
    }

    public function testOncePerArticlePairSoARemovedLinkIsNotMadeAgain(): void
    {
        $this->elements(7, 100, ['text']);
        $en = $this->elements(25, 200, ['text']);
        $this->pairing()->pair(2, 24, 'en', static fn (): bool => true);
        $this->links->unlink((int) $this->links->ofElement($en[0])['id']);

        $this->assertSame(0, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertNull($this->links->ofElement($en[0]));
    }

    /**
     * A new element whose languages the editor asks about (ElementSync) is
     * left out: it is not paired by position with an element of the other
     * language that happens to have the same type.
     */
    public function testANewElementIsLeftOutAndNotPairedByPosition(): void
    {
        $de = $this->elements(7, 100, ['headline', 'text']);
        $en = $this->elements(25, 200, ['headline', 'text']);

        $this->assertSame(1, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true, [$de[1]]));
        $this->assertSame($de[0], (int) $this->links->ofElement($en[0])['main']);
        $this->assertNull($this->links->ofElement($en[1]), 'the English text belongs to nothing');
        $this->assertSame([], $this->links->ofMains([$de[1]]), 'the new German text belongs to nothing');
    }

    public function testAUserWhoMayNotChangeTheVersionPairsNothingAndLeavesItForOneWhoMay(): void
    {
        $this->elements(7, 100, ['text']);
        $this->elements(25, 200, ['text']);

        $this->assertSame(0, $this->pairing()->pair(2, 24, 'en', static fn (): bool => false));
        $this->assertSame(1, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
    }

    public function testAnEmptyVersionIsAskedAgainLater(): void
    {
        $this->elements(7, 100, ['text']);

        $this->assertSame(0, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $en = $this->elements(25, 200, ['text']);
        $this->assertSame(1, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true), 'made in the back end afterwards');
        $this->assertNotNull($this->links->ofElement($en[0]));
    }

    /**
     * The accordions of both pages are paired by position; the elements in
     * them by the link of their accordions and their position (an element only
     * one side has does not shift the rest), and so on one level deeper.
     */
    public function testElementsInElementsArePairedThroughTheLinkOfTheirParentAndTheirPosition(): void
    {
        [$deAccordion] = $this->elements(7, 100, ['accordion']);
        [$enAccordion] = $this->elements(25, 200, ['accordion']);
        $de = $this->elements($deAccordion, 110, ['headline', 'text', 'element_group'], 'tl_content');
        $en = $this->elements($enAccordion, 210, ['headline', 'element_group'], 'tl_content');
        $deDeep = $this->elements($de[2], 120, ['text'], 'tl_content');
        $enDeep = $this->elements($en[1], 220, ['text'], 'tl_content');

        $this->assertSame(4, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertSame($deAccordion, (int) $this->links->ofElement($enAccordion)['main']);
        $this->assertSame($de[0], (int) $this->links->ofElement($en[0])['main']);
        $this->assertSame($de[2], (int) $this->links->ofElement($en[1])['main'], 'the text exists only in German');
        $this->assertSame($deDeep[0], (int) $this->links->ofElement($enDeep[0])['main'], 'one level deeper');
        $this->assertSame('', $this->links->ofElement($enDeep[0])['outdated'], 'current for the main element as it is now');
        $this->assertSame($this->sourceHash()->of($this->row($deDeep[0])), $this->links->ofElement($enDeep[0])['source_hash']);

        // Once per pair of containers: a link removed later is not made again.
        $this->links->unlink((int) $this->links->ofElement($enDeep[0])['id']);
        $this->assertSame(0, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertNull($this->links->ofElement($enDeep[0]));
    }

    /** Pages paired before elements in elements were: their accordions are linked, the elements in them get paired now. */
    public function testTheElementsInElementsOfArticlesPairedBeforeArePairedToo(): void
    {
        [$deAccordion] = $this->elements(7, 100, ['accordion']);
        [$enAccordion] = $this->elements(25, 200, ['accordion']);
        $de = $this->elements($deAccordion, 110, ['text', 'text'], 'tl_content');
        $en = $this->elements($enAccordion, 210, ['text', 'text'], 'tl_content');
        $this->links->link($deAccordion, $enAccordion, 'en', 'linked');
        $this->db->insert(ElementPairing::TABLE, ['tstamp' => 1, 'ptable' => 'tl_article', 'main_article' => 7, 'target_article' => 25]);

        $this->assertSame(2, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertSame($de[1], (int) $this->links->ofElement($en[1])['main']);
        $this->assertSame('linked', $this->links->ofElement($enAccordion)['source_hash'], 'the link of the accordion stays');
    }

    /** Containers that are not linked: their elements are not paired. */
    public function testElementsInElementsThatAreNotLinkedStayAsTheyAre(): void
    {
        [$deAccordion] = $this->elements(7, 100, ['accordion']);
        [$enGroup] = $this->elements(25, 200, ['element_group']);
        $this->elements($deAccordion, 110, ['text'], 'tl_content');
        $en = $this->elements($enGroup, 210, ['text'], 'tl_content');

        $this->assertSame(0, $this->pairing()->pair(2, 24, 'en', static fn (): bool => true));
        $this->assertNull($this->links->ofElement($en[0]));
    }

    /**
     * The elements of a post and of its post in the version (linked by
     * languageMain) are paired the same way, also the elements in them; a
     * later change of a main element makes its translation outdated.
     */
    public function testTheElementsOfAPostArePairedWithThoseOfItsPostInTheMainLanguage(): void
    {
        $de = $this->elements(5, 100, ['text', 'accordion'], 'tl_news');
        $en = $this->elements(6, 200, ['text', 'accordion'], 'tl_news');
        $deDeep = $this->elements($de[1], 110, ['text'], 'tl_content');
        $enDeep = $this->elements($en[1], 210, ['text'], 'tl_content');

        $this->assertSame(3, $this->pairing()->pairContainer('tl_news', 5, 6, 'en', static fn (): bool => true));
        $this->assertSame($de[0], (int) $this->links->ofElement($en[0])['main']);
        $this->assertSame($deDeep[0], (int) $this->links->ofElement($enDeep[0])['main']);
        $this->assertSame(0, $this->pairing()->pairContainer('tl_news', 5, 6, 'en', static fn (): bool => true), 'once');

        $this->db->update('tl_content', ['headline' => 'Changed'], ['id' => $deDeep[0]]);
        $this->links->refreshOutdated($deDeep[0], $this->sourceHash()->of($this->row($deDeep[0])));
        $this->assertSame('1', $this->links->ofElement($enDeep[0])['outdated'], 'outdated after the change');
        $this->assertSame('', $this->links->ofElement($en[0])['outdated']);
    }

    private function pairing(): ElementPairing
    {
        $versions = new LanguageVersions($this->createStub(ContaoFramework::class), $this->createStub(Security::class), $this->db, $this->createStub(Locales::class));

        return new ElementPairing($this->db, $versions, $this->links, $this->sourceHash());
    }

    /**
     * @param list<string> $types
     *
     * @return list<int>
     */
    private function elements(int $parent, int $firstId, array $types, string $ptable = 'tl_article'): array
    {
        $ids = [];

        foreach ($types as $i => $type) {
            $this->db->insert('tl_content', ['id' => $firstId + $i, 'pid' => $parent, 'ptable' => $ptable, 'sorting' => ($i + 1) * 128, 'tstamp' => 1, 'type' => $type, 'headline' => $type.' '.$parent]);
            $ids[] = $firstId + $i;
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }
}
