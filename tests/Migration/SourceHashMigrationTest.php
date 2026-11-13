<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Migration;

use Neo\FrontendEditorBundle\Language\ContentHash;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Migration\SourceHashMigration;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\TestCase;

/**
 * Element 12 (English) has two versions: 30 was current with the old hash,
 * 31 was outdated.
 */
final class SourceHashMigrationTest extends TestCase
{
    use EditorFixture;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->db->insert('tl_article', ['id' => 3, 'pid' => 1]);
        $this->db->insert('tl_content', ['id' => 30, 'pid' => 4, 'type' => 'text']);
        $this->db->insert('tl_content', ['id' => 31, 'pid' => 5, 'type' => 'text']);
        $main = (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 12');
        $links = new ElementLinks($this->db);
        $links->link(12, 30, 'de', ContentHash::legacy($main));
        $links->link(12, 31, 'fr', ContentHash::legacy(['text' => '<p>Older text</p>'] + $main));
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testCurrentLinksTakeTheHashOfAllTexts(): void
    {
        $migration = new SourceHashMigration($this->db, $this->sourceHash([1 => ['language' => 'en']]));
        $outdated = $this->hashOf(31);

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());

        $main = (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 12');
        $this->assertSame($this->sourceHash([1 => ['language' => 'en']])->of($main), $this->hashOf(30));
        $this->assertSame($outdated, $this->hashOf(31));
        $this->assertFalse($migration->shouldRun());
    }

    public function testWithoutLinksNothingRuns(): void
    {
        $this->db->executeStatement('DELETE FROM '.ElementLinks::TABLE);

        $this->assertFalse((new SourceHashMigration($this->db, $this->sourceHash()))->shouldRun());
    }

    private function hashOf(int $element): string
    {
        return (string) $this->db->fetchOne('SELECT source_hash FROM '.ElementLinks::TABLE.' WHERE element = ?', [$element]);
    }
}
