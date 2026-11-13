<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Content;

use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class RelatedRecordsTest extends TestCase
{
    use ContaoContainer;
    use Pages;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create('tl_faq_category', 'tl_faq');
        $this->setUpContao(null, $this->db);
        $this->db->executeStatement("CREATE TABLE tl_offer (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '')");
        $this->db->executeStatement("CREATE TABLE tl_offer_tier (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', published char(1) NOT NULL DEFAULT '')");
        ContaoState::dca('tl_faq_category', ['config' => ['ctable' => ['tl_faq']], 'fields' => ['title' => []]]);
        ContaoState::dca('tl_faq', ['config' => ['ptable' => 'tl_faq_category'], 'fields' => ['question' => [], 'alias' => [], 'answer' => [], 'published' => []]]);
        ContaoState::dca('tl_offer', ['config' => ['ctable' => ['tl_offer_tier']], 'fields' => ['title' => []]]);
        ContaoState::dca('tl_offer_tier', ['config' => ['ptable' => 'tl_offer'], 'fields' => ['title' => [], 'published' => []]]);

        foreach (['tl_faq_category', 'tl_faq', 'tl_offer', 'tl_offer_tier'] as $table) {
            ContaoState::language($table);
        }
        $this->db->insert('tl_faq_category', ['id' => 1, 'title' => 'Questions']);
        $this->db->insert('tl_faq', ['id' => 1, 'pid' => 1, 'sorting' => 128, 'question' => 'Why?', 'alias' => 'why']);
        $this->db->insert('tl_offer', ['id' => 1, 'title' => 'Offer']);
        $this->db->insert('tl_offer_tier', ['id' => 1, 'pid' => 1, 'sorting' => 128, 'title' => 'Small', 'published' => '1']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testThePostsOfAnArchiveAreLeftToTheirOwnTranslationWhenAsked(): void
    {
        $related = new RelatedRecords($this->framework(), $this->db);

        // Shown by an element: the category with its questions.
        $this->assertSame(['Why?'], array_column(array_column($related->entries('tl_faq_category', 1), 'row'), 'question'));
        // Translated on its own: the category without its posts (each has an address of its own) …
        $this->assertSame([], $related->entries('tl_faq_category', 1, false));
        // … but with entries that are no posts (published, without an address).
        $this->assertSame(['Small'], array_column(array_column($related->entries('tl_offer', 1, false), 'row'), 'title'));
    }
}
