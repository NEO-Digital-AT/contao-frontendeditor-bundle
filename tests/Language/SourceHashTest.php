<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Locales;
use Contao\StringUtil;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\SourceHash;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use Neo\FrontendEditorBundle\Tests\Support\News;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Element 12 (a text element in article 3 of the English page 1) with an
 * entry (tl_card) and an image (file 7, metadata in English and German).
 */
final class SourceHashTest extends TestCase
{
    use EditorFixture;

    private const UUID = "\x9e\x47\xa0\xf6\x1f\x5e\x11\xef\x8d\x2c\x02\x42\xac\x12\x00\x02";

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->db->executeStatement('ALTER TABLE tl_content ADD COLUMN addImage tinyint(1) NOT NULL DEFAULT 0');
        $this->db->insert('tl_article', ['id' => 3, 'pid' => 1]);
        $this->db->insert('tl_files', ['id' => 7, 'uuid' => self::UUID, 'path' => 'files/hotel.jpg', 'extension' => 'jpg', 'meta' => serialize(['en' => ['alt' => 'Hotel'], 'de' => ['alt' => 'Hotel']])]);
        $this->db->update('tl_content', ['addImage' => 1, 'singleSRC' => self::UUID], ['id' => 12]);
        $this->db->insert('tl_card', ['pid' => 12, 'ptable' => 'tl_content', 'title' => 'Family run']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testTheHashFollowsEveryTextOfTheElement(): void
    {
        $hash = $this->hash();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        $this->db->update('tl_content', ['tstamp' => 999, 'headline' => serialize(['value' => 'Old headline', 'unit' => 'h4'])], ['id' => 12]);
        $this->assertSame($hash, $this->hash(), 'neither the time nor the level of the headline is a text');

        $this->db->update('tl_card', ['title' => 'Family owned'], ['pid' => 12]);
        $this->assertNotSame($hash, $entry = $this->hash(), 'the text of an entry');

        $this->db->update('tl_files', ['meta' => serialize(['en' => ['alt' => 'Hotel'], 'de' => ['alt' => 'Haus']])], ['id' => 7]);
        $this->assertSame($entry, $this->hash(), 'the metadata of another language');

        $this->db->update('tl_files', ['meta' => serialize(['en' => ['alt' => 'Our hotel'], 'de' => ['alt' => 'Haus']])], ['id' => 7]);
        $this->assertNotSame($entry, $this->hash(), 'the metadata in the language of the page');
    }

    public function testTheLanguageIsThatOfTheArticlesPage(): void
    {
        $this->assertSame('en', $this->sourceHash([1 => ['language' => 'en']])->language(3));
        $this->assertSame('', $this->sourceHash([1 => ['language' => 'en']])->language(99));
    }

    /** An element in an element: the language of the page of the outer element; an element of a post: of the post's page. */
    public function testTheLanguageOfAnElementInAnElementOrOfAPostIsThatOfItsPage(): void
    {
        News::create($this->db);
        $this->db->insert('tl_news_archive', ['id' => 1, 'jumpTo' => 11]);
        $this->db->insert('tl_news', ['id' => 5, 'pid' => 1, 'tstamp' => 1]);
        $this->db->insert('tl_content', ['id' => 40, 'pid' => 12, 'ptable' => 'tl_content', 'tstamp' => 1, 'type' => 'text']);
        $this->db->insert('tl_content', ['id' => 41, 'pid' => 5, 'ptable' => 'tl_news', 'tstamp' => 1, 'type' => 'text']);
        $pages = [1 => ['language' => 'en'], 11 => ['language' => 'de']];
        $links = new ElementLinks($this->db);
        $versions = new LanguageVersions($this->createStub(ContaoFramework::class), $this->createStub(Security::class), $this->db, $this->createStub(Locales::class));
        $hashes = new SourceHash($this->db, $this->framework($pages), $this->translationUnits(), new ElementPlaces($this->db, $this->framework($pages), $versions, $links));

        $this->assertSame('en', $hashes->languageOf((array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 40')));
        $this->assertSame('de', $hashes->languageOf((array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 41')));
        $this->assertSame('en', $hashes->languageOf((array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 12')));
    }

    private function hash(): string
    {
        $row = (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 12');
        $this->assertSame(self::UUID, StringUtil::deserialize($row['singleSRC'], true)[0]);

        return $this->sourceHash([1 => ['language' => 'en']])->of($row);
    }
}
