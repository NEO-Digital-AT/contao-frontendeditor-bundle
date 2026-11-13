<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Language;

use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Language\RecordLinks;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class RecordLinksTest extends TestCase
{
    private Connection $db;

    private RecordLinks $links;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create(RecordLinks::TABLE);
        $this->links = new RecordLinks($this->db);
    }

    public function testAVersionIsFoundFromBothSides(): void
    {
        $this->links->link('tl_offer', 1, 7, 'en');
        $this->links->link('tl_offer', 1, 8, 'fr');

        $this->assertSame(7, $this->links->targetOf('tl_offer', 1, 'en'));
        $this->assertSame(8, $this->links->targetOf('tl_offer', 1, 'fr'));
        $this->assertNull($this->links->targetOf('tl_offer', 1, 'it'));
        $this->assertNull($this->links->targetOf('tl_other', 1, 'en'));
        $this->assertSame(['main' => 1, 'language' => 'en', 'source_hash' => ''], $this->links->ofTarget('tl_offer', 7));
        $this->assertSame([7, 8], $this->links->targets('tl_offer'));
    }

    public function testANewLinkReplacesTheOldOneOfTheTargetAndOfTheLanguage(): void
    {
        $this->links->link('tl_offer', 1, 7, 'en');
        // Another record as the English version of 1, and 7 as the version of another record.
        $this->links->link('tl_offer', 1, 9, 'en');
        $this->links->link('tl_offer', 2, 7, 'en');

        $this->assertSame(9, $this->links->targetOf('tl_offer', 1, 'en'));
        $this->assertSame(['main' => 2, 'language' => 'en', 'source_hash' => ''], $this->links->ofTarget('tl_offer', 7));
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.RecordLinks::TABLE));
    }

    public function testTheStateOfATranslationIsKeptAtItsLink(): void
    {
        $this->links->link('tl_offer', 1, 7, 'en');
        $this->links->translated('tl_offer', 1, 7, 'en', 'hash-1');
        // Without a link yet, the translation makes one.
        $this->links->translated('tl_offer', 3, 4, 'en', 'hash-2');

        $this->assertSame('hash-1', $this->links->ofTarget('tl_offer', 7)['source_hash'] ?? null);
        $this->assertSame(['main' => 3, 'language' => 'en', 'source_hash' => 'hash-2'], $this->links->ofTarget('tl_offer', 4));

        $this->links->unlink('tl_offer', 7);

        $this->assertNull($this->links->ofTarget('tl_offer', 7));
    }
}
