<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Contao\CoreBundle\Intl\Locales;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\RecordLinks;
use Neo\FrontendEditorBundle\Language\RecordVersions;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\Decisions;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class RecordVersionsTest extends TestCase
{
    use ContaoContainer;
    use Decisions;
    use Pages;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create(RecordLinks::TABLE);
        $this->setUpContao(null, $this->db);
        $this->db->executeStatement("CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', jumpTo int NOT NULL DEFAULT 0, master int NOT NULL DEFAULT 0)");
        $this->db->executeStatement("CREATE TABLE tl_news (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, headline varchar(255) NOT NULL DEFAULT '', alias varchar(255) NOT NULL DEFAULT '', published char(1) NOT NULL DEFAULT '', languageMain int NOT NULL DEFAULT 0)");
        $this->db->executeStatement("CREATE TABLE tl_offer (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', language varchar(5) NOT NULL DEFAULT '')");
        $this->db->executeStatement("CREATE TABLE tl_partner (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, name varchar(255) NOT NULL DEFAULT '')");
        ContaoState::dca('tl_news_archive', ['config' => ['ctable' => ['tl_news', 'tl_content']], 'fields' => ['title' => [], 'jumpTo' => [], 'master' => []]]);
        ContaoState::dca('tl_news', ['config' => ['ptable' => 'tl_news_archive'], 'list' => ['label' => ['fields' => ['headline']]], 'fields' => ['headline' => [], 'alias' => [], 'published' => [], 'languageMain' => []]]);
        ContaoState::dca('tl_offer', ['config' => [], 'fields' => ['title' => [], 'language' => []]]);
        ContaoState::dca('tl_partner', ['config' => [], 'fields' => ['name' => []]]);
        ContaoState::dca('tl_page', ['config' => [], 'fields' => ['title' => []]]);
        $GLOBALS['BE_MOD'] = ['content' => ['news' => ['tables' => ['tl_news_archive', 'tl_news', 'tl_content']]], 'website' => ['offers' => ['tables' => ['tl_offer']], 'partners' => ['tables' => ['tl_partner']]]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_MOD'], $GLOBALS['FE_MOD']);
        $this->tearDownContao();
    }

    public function testHowTheRecordsOfATableAreLinked(): void
    {
        $versions = $this->versions();

        $this->assertSame('page', $versions->kind('tl_page'));
        $this->assertSame('post', $versions->kind('tl_news'));
        $this->assertSame('archive', $versions->kind('tl_news_archive'));
        $this->assertSame('record', $versions->kind('tl_offer'));
        $this->assertSame('tl_news_archive', $versions->archiveTable('tl_news'));
        $this->assertNull($versions->archiveTable('tl_offer'));
    }

    public function testTheLanguageOfARecord(): void
    {
        $this->db->insert('tl_news_archive', ['id' => 1, 'tstamp' => 1, 'jumpTo' => 10]);
        $this->db->insert('tl_partner', ['id' => 5, 'tstamp' => 1, 'name' => 'Partner']);
        $links = new RecordLinks($this->db);
        $links->link('tl_partner', 4, 5, 'en');
        $versions = $this->versions(links: $links);

        // A post: of its archive's page; a record: its own field, else its link.
        $this->assertSame('de', $versions->languageOf('tl_news', ['id' => 1, 'pid' => 1]));
        $this->assertSame('fr', $versions->languageOf('tl_offer', ['id' => 2, 'language' => 'fr']));
        $this->assertSame('en', $versions->languageOf('tl_partner', ['id' => 5]));
        $this->assertSame('', $versions->languageOf('tl_partner', ['id' => 6]));
    }

    public function testTheRecordAtTheSamePlaceIsProposed(): void
    {
        foreach ([[1, 'de'], [2, 'de'], [3, 'de'], [4, 'en'], [5, 'en'], [6, 'en']] as $i => [$id, $language]) {
            $this->db->insert('tl_offer', ['id' => $id, 'tstamp' => 1, 'sorting' => ($i % 3 + 1) * 128, 'title' => 'Offer '.$id, 'language' => $language]);
        }

        $links = new RecordLinks($this->db);
        $versions = $this->versions(links: $links);
        $main = $versions->row('tl_offer', 2);

        $candidates = $versions->candidates('tl_offer', $main, 'en');

        $this->assertSame([4, 5, 6], array_column($candidates, 'id'));
        $this->assertSame([false, true, false], array_column($candidates, 'proposed'));
        $this->assertSame('Offer 5', $candidates[1]['title']);

        // A record already linked as a version is no longer offered; the proposal keeps its place.
        $links->link('tl_offer', 1, 4, 'en');
        $candidates = $versions->candidates('tl_offer', $main, 'en');

        $this->assertSame([5, 6], array_column($candidates, 'id'));
        $this->assertSame([true, false], array_column($candidates, 'proposed'));
    }

    public function testARecordOfAVersionIsOfferedOnlyRecordsOfTheMainLanguageWithoutAVersion(): void
    {
        foreach ([[1, 'de', 128], [2, 'de', 256], [3, 'en', 128], [4, 'en', 256]] as [$id, $language, $sorting]) {
            $this->db->insert('tl_offer', ['id' => $id, 'tstamp' => 1, 'sorting' => $sorting, 'title' => 'Offer '.$id, 'language' => $language]);
        }

        $links = new RecordLinks($this->db);
        $links->link('tl_offer', 1, 3, 'en');
        $versions = $this->versions(links: $links);

        // 4 is written in English first; 1 already has its English version (3), 2 stands at the same place as 4.
        $candidates = $versions->candidates('tl_offer', $versions->row('tl_offer', 4), 'de');

        $this->assertSame([2], array_column($candidates, 'id'));
        $this->assertTrue($candidates[0]['proposed']);
    }

    public function testARecordIsLinkedOnlyWithContaosPermission(): void
    {
        $this->db->insert('tl_offer', ['id' => 1, 'tstamp' => 1, 'language' => 'de']);
        $this->db->insert('tl_offer', ['id' => 2, 'tstamp' => 1, 'language' => 'en']);
        $links = new RecordLinks($this->db);

        $denied = $this->versions($this->security(static fn (): bool => false), $links);
        $this->assertFalse($denied->link('tl_offer', $denied->row('tl_offer', 1), $denied->row('tl_offer', 2), 'en'));
        $this->assertNull($links->targetOf('tl_offer', 1, 'en'));

        $this->asked = [];
        $granted = $this->versions(links: $links);
        $this->assertTrue($granted->link('tl_offer', $granted->row('tl_offer', 1), $granted->row('tl_offer', 2), 'en'));
        $this->assertSame(2, $links->targetOf('tl_offer', 1, 'en'));
        $this->assertSame([ContaoCorePermissions::DC_PREFIX.'tl_offer UpdateAction'], $this->askedActions());
        $this->assertFalse($granted->link('tl_offer', $granted->row('tl_offer', 1), $granted->row('tl_offer', 1), 'en'));
    }

    public function testLinkedArchivesLinkTheirPostsByTheirOrder(): void
    {
        $this->db->insert('tl_news_archive', ['id' => 1, 'tstamp' => 1, 'jumpTo' => 10]);
        $this->db->insert('tl_news_archive', ['id' => 2, 'tstamp' => 1, 'jumpTo' => 20]);

        foreach ([[1, 1], [2, 1], [3, 2], [4, 2]] as [$id, $pid]) {
            $this->db->insert('tl_news', ['id' => $id, 'pid' => $pid, 'tstamp' => 1, 'headline' => 'Post '.$id]);
        }

        $versions = $this->versions();

        $this->assertTrue($versions->link('tl_news_archive', $versions->row('tl_news_archive', 1), $versions->row('tl_news_archive', 2), 'en'));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT master FROM tl_news_archive WHERE id = 2'));
        $this->assertSame([0, 0, 1, 2], array_map('intval', $this->db->fetchFirstColumn('SELECT languageMain FROM tl_news ORDER BY id')));

        // The version of a post in the other language, found by changelanguage's field.
        $this->assertSame(4, (int) ($versions->versionIn('tl_news', $versions->row('tl_news', 2), 'en')['id'] ?? 0));
        $this->assertSame(2, (int) $versions->mainOf('tl_news', $versions->row('tl_news', 4))['id']);
    }

    public function testRecordsOfContaoAndOfExtensions(): void
    {
        $GLOBALS['FE_MOD'] = ['navigation' => ['navigation' => 'Contao\ModuleNavigation'], 'extension' => ['offers' => 'Vendor\Module\OfferList']];
        $versions = $this->versions();

        $this->assertTrue($versions->isContao('tl_news', []));
        $this->assertTrue($versions->isContao('tl_module', ['type' => 'navigation']));
        $this->assertFalse($versions->isContao('tl_module', ['type' => 'offers']));
        $this->assertFalse($versions->isContao('tl_offer', []));
    }

    public function testOnlyRecordsOfModulesTheUserMayOpenAreTranslated(): void
    {
        $versions = $this->versions($this->security(static fn (string $attribute, mixed $module): bool => 'partners' !== $module));

        $this->assertTrue($versions->translatable('tl_offer'));
        $this->assertFalse($versions->translatable('tl_partner'));
        $this->assertFalse($versions->translatable('tl_content'));
        $this->assertFalse($versions->translatable('tl_neo_settings'));
        $this->assertFalse($versions->translatable('tl_offer; DROP'));
    }

    public function testACopyGetsItsAddressFromItsTranslatedTitle(): void
    {
        $this->db->insert('tl_news_archive', ['id' => 2, 'tstamp' => 1, 'jumpTo' => 20]);
        $this->db->insert('tl_news', ['id' => 1, 'pid' => 2, 'tstamp' => 1, 'headline' => 'First post', 'alias' => 'first-post']);
        $this->db->insert('tl_news', ['id' => 2, 'pid' => 2, 'tstamp' => 1, 'headline' => 'First post', 'alias' => '']);
        $this->db->insert('tl_news', ['id' => 3, 'pid' => 2, 'tstamp' => 1, 'headline' => 'Own', 'alias' => 'own-address']);
        $slug = $this->createMock(Slug::class);
        $slug->expects($this->once())->method('generate')->willReturnCallback(
            // The page of the archive gives the rules; an address in use gets a number.
            static fn (string $text, mixed $options, \Closure $exists): string => 20 === $options && 'First post' === $text && $exists('first-post') ? 'first-post-2' : 'wrong',
        );
        $versions = $this->versions(slug: $slug);

        $versions->fillAlias('tl_news', 2);
        $versions->fillAlias('tl_news', 3);
        $versions->fillAlias('tl_offer', 1);

        $this->assertSame(['first-post', 'first-post-2', 'own-address'], $this->db->fetchFirstColumn('SELECT alias FROM tl_news ORDER BY id'));
    }

    private function versions(Security|null $security = null, RecordLinks|null $links = null, Slug|null $slug = null): RecordVersions
    {
        $framework = $this->framework([10 => ['language' => 'de'], 20 => ['language' => 'en']]);
        $security ??= $this->security(static fn (): bool => true);

        return new RecordVersions(
            $this->db,
            $framework,
            $security,
            new LanguageVersions($framework, $security, $this->db, $this->createStub(Locales::class)),
            $links ?? new RecordLinks($this->db),
            new DcaRunner($framework),
            $slug ?? $this->createStub(Slug::class),
        );
    }
}
