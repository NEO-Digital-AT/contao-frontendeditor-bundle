<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class ElementLinksTest extends TestCase
{
    private Connection $db;

    private ElementLinks $links;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create(ElementLinks::TABLE, 'tl_content');
        $this->links = new ElementLinks($this->db);
    }

    public function testAnElementBelongsToOneElementOfTheMainLanguage(): void
    {
        $first = $this->links->link(10, 20, 'de', 'hash-a');
        $second = $this->links->link(11, 20, 'de', 'hash-b');

        $this->assertNull($this->links->find($first));
        $link = $this->links->ofElement(20);
        $this->assertSame($second, (int) $link['id']);
        $this->assertSame(11, (int) $link['main']);
        $this->assertSame('de', $link['language']);
        $this->assertSame('', $link['outdated']);
        $this->assertGreaterThan(0, (int) $link['synced_at']);
    }

    public function testALinkWithoutStateStillHasToBeTranslated(): void
    {
        $this->links->link(10, 21, 'fr', '');

        $link = $this->links->ofElement(21);
        $this->assertSame('1', $link['outdated']);
        $this->assertSame(0, (int) $link['synced_at']);
    }

    public function testChangingTheMainElementMakesOtherStatesOutdated(): void
    {
        $this->links->link(10, 20, 'de', 'hash-a');
        $this->links->link(10, 21, 'fr', 'hash-b');
        $this->links->link(11, 22, 'de', 'hash-a');

        $this->links->refreshOutdated(10, 'hash-b');

        $this->assertSame('1', $this->links->ofElement(20)['outdated']);
        $this->assertSame('', $this->links->ofElement(21)['outdated']);
        $this->assertSame('', $this->links->ofElement(22)['outdated'], 'another main element');

        $this->links->markSynced(20, 'hash-b');
        $this->assertSame('', $this->links->ofElement(20)['outdated']);
        $this->assertSame('hash-b', $this->links->ofElement(20)['source_hash']);
    }

    public function testLinksOfSeveralElementsAndMains(): void
    {
        $this->links->link(10, 20, 'de', 'h');
        $this->links->link(10, 21, 'fr', 'h');
        $this->links->link(11, 22, 'de', 'h');

        $this->assertSame([20, 22], array_keys($this->links->ofElements([20, 22, 99])));
        $this->assertSame([], $this->links->ofElements([]));
        $mains = $this->links->ofMains([10, 11]);
        $this->assertSame([20, 21], array_map('intval', array_column($mains[10], 'element')));
        $this->assertCount(1, $mains[11]);
        $this->assertSame([], $this->links->ofMains([]));
    }

    public function testADeletedElementTakesItsLinksAlong(): void
    {
        $this->links->link(10, 20, 'de', 'h');
        $this->links->link(10, 21, 'fr', 'h');
        $this->links->link(11, 10, 'de', 'h');
        $unrelated = $this->links->link(12, 30, 'de', 'h');

        $this->links->removeElement(10);

        $this->assertSame([$unrelated], array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM '.ElementLinks::TABLE)));

        $this->links->unlink($unrelated);
        $this->assertNull($this->links->ofElement(30));
    }

    /**
     * Article 3 is in the main language, article 4 the same article in the
     * version: an element taken over into the version goes after the
     * counterpart of the nearest element before it that has one there.
     */
    public function testAnElementTakenOverIntoTheVersionGoesAfterTheCounterpartBeforeIt(): void
    {
        $this->elements(3, [10 => 128, 11 => 256, 12 => 384]);
        $this->elements(4, [20 => 128, 21 => 256]);
        $this->elements(5, [30 => 128]);
        $this->links->link(10, 20, 'de', 'h');
        // The counterpart of element 11 stands in another article: it is not a place in article 4.
        $this->links->link(11, 30, 'de', 'h');

        $this->assertSame(20, $this->links->counterpartBefore($this->row(12), 4), 'element 11 has no counterpart in article 4');
        $this->assertSame(20, $this->links->counterpartBefore($this->row(11), 4));
        $this->assertNull($this->links->counterpartBefore($this->row(10), 4), 'the first element goes to the top');
    }

    /** The other way round: an element written in the version first goes into the main language the same way. */
    public function testAnElementTakenOverIntoTheMainLanguageGoesAfterTheCounterpartBeforeIt(): void
    {
        $this->elements(3, [10 => 128, 11 => 256]);
        $this->elements(4, [20 => 128, 21 => 256, 22 => 384, 23 => 512]);
        $this->links->link(11, 21, 'de', 'h');

        $this->assertSame(11, $this->links->counterpartBefore($this->row(23), 3), 'element 22 has no counterpart');
        $this->assertNull($this->links->counterpartBefore($this->row(21), 3), 'element 20 has no counterpart: top of the article');

        // A draft (not saved yet) is no place.
        $this->db->update('tl_content', ['tstamp' => 0], ['id' => 11]);
        $this->assertNull($this->links->counterpartBefore($this->row(23), 3));
    }

    /** From the main language into a version: the version is current once everything was saved. */
    public function testATranslationFromTheMainLanguageMakesTheVersionCurrent(): void
    {
        $this->links->link(10, 20, 'en', 'old');
        $this->links->link(10, 30, 'fr', 'old');
        $this->links->refreshOutdated(10, 'new');

        $this->links->afterTranslation(10, 'new', 10, 20, true, false);
        $this->assertSame('1', $this->links->ofElement(20)['outdated'], 'not everything was saved');

        $this->links->afterTranslation(10, 'new', 10, 20, true, true);
        $this->assertSame('', $this->links->ofElement(20)['outdated']);
        $this->assertSame('new', $this->links->ofElement(20)['source_hash']);
        $this->assertSame('1', $this->links->ofElement(30)['outdated'], 'the other version stays outdated');
    }

    /**
     * From a version into the main language: the other versions are outdated
     * once something was saved; the version it came from is current once
     * everything was saved.
     */
    public function testATranslationIntoTheMainLanguageMakesTheOtherVersionsOutdated(): void
    {
        $this->links->link(10, 20, 'en', 'old');
        $this->links->link(10, 30, 'fr', 'old');

        $this->links->afterTranslation(10, 'changed', 20, 10, true, false);
        $this->assertSame('1', $this->links->ofElement(20)['outdated'], 'not everything was saved');
        $this->assertSame('1', $this->links->ofElement(30)['outdated']);

        $this->links->afterTranslation(10, 'changed', 20, 10, true, true);
        $this->assertSame('', $this->links->ofElement(20)['outdated']);
        $this->assertSame('changed', $this->links->ofElement(20)['source_hash']);
        $this->assertSame('1', $this->links->ofElement(30)['outdated']);

        // Nothing saved (the texts were the same already): the states stay.
        $this->links->link(11, 21, 'en', 'same');
        $this->links->link(11, 31, 'fr', 'same');
        $this->links->afterTranslation(11, 'same', 21, 11, false, true);
        $this->assertSame('', $this->links->ofElement(31)['outdated']);
        $this->assertSame('', $this->links->ofElement(21)['outdated']);
    }

    /**
     * From one version into another: the target corresponds to the main
     * element as the source does, the reference stays the main element's
     * link: current when the source is current, outdated when it is outdated.
     */
    public function testATranslationBetweenVersionsTakesTheStateOfTheSource(): void
    {
        $this->links->link(10, 20, 'en', 'now');
        $this->links->link(10, 30, 'fr', '');

        $this->links->afterTranslation(10, 'now', 20, 30, true, true);
        $this->assertSame('', $this->links->ofElement(30)['outdated']);
        $this->assertSame('now', $this->links->ofElement(30)['source_hash']);
        $this->assertSame('', $this->links->ofElement(20)['outdated'], 'the source stays as it was');

        // The source is outdated itself: so is its translation.
        $this->links->link(11, 21, 'en', 'before');
        $this->links->link(11, 31, 'fr', '');
        $this->links->afterTranslation(11, 'now', 21, 31, true, true);
        $this->assertSame('1', $this->links->ofElement(31)['outdated']);
        $this->assertSame('before', $this->links->ofElement(31)['source_hash']);
        $this->assertSame('1', $this->links->ofElement(21)['outdated']);

        // Not everything saved: the target keeps its state.
        $this->links->link(12, 22, 'en', 'now');
        $this->links->link(12, 32, 'fr', '');
        $this->links->afterTranslation(12, 'now', 22, 32, true, false);
        $this->assertSame('1', $this->links->ofElement(32)['outdated']);
        $this->assertSame('', $this->links->ofElement(32)['source_hash']);
    }

    /**
     * Elements in an element (accordion 40 of the main language, accordion 50
     * of the version) and elements of posts: the place is found among the
     * elements of the same kind of container only.
     */
    public function testAnElementInAnElementOrAPostGoesAfterTheCounterpartInTheMatchingContainer(): void
    {
        $this->elements(40, [41 => 128, 42 => 256, 43 => 384], 'tl_content');
        $this->elements(50, [51 => 128], 'tl_content');
        // An article with the same ID as the accordion of the version: not a place for elements in an element.
        $this->elements(50, [60 => 128], 'tl_article');
        $this->links->link(41, 51, 'en', 'h');
        $this->links->link(42, 60, 'en', 'h');

        $this->assertSame(51, $this->links->counterpartBefore($this->row(43), 50), 'element 42 has its counterpart in an article');
        $this->assertNull($this->links->counterpartBefore($this->row(41), 50));

        $this->elements(7, [70 => 128, 71 => 256], 'tl_news');
        $this->elements(8, [80 => 128], 'tl_news');
        $this->links->link(70, 80, 'en', 'h');
        $this->assertSame(80, $this->links->counterpartBefore($this->row(71), 8));
    }

    /** @param array<int, int> $sorting element ID => sorting */
    private function elements(int $parent, array $sorting, string $ptable = 'tl_article'): void
    {
        foreach ($sorting as $id => $value) {
            $this->db->insert('tl_content', ['id' => $id, 'pid' => $parent, 'ptable' => $ptable, 'sorting' => $value, 'tstamp' => 1]);
        }
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);
    }
}
