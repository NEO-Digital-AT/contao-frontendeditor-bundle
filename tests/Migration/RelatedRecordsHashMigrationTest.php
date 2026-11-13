<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Migration;

use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Migration\RelatedRecordsHashMigration;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\TestCase;

/**
 * Element 70 shows the questions of a FAQ category. Its version 80 was current
 * with the hash before related records were covered, its version 90 outdated.
 */
final class RelatedRecordsHashMigrationTest extends TestCase
{
    use EditorFixture;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $this->addFaq();
        $main = (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 70');
        $links = new ElementLinks($this->db);
        $links->link(70, 80, 'de', $this->sourceHash()->of($main, false));
        $links->link(70, 90, 'fr', 'an older state');
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testCurrentLinksTakeTheTextsOfRelatedRecordsIntoTheirState(): void
    {
        $migration = new RelatedRecordsHashMigration($this->db, $this->sourceHash(), new RelatedRecords($this->framework(), $this->db));

        $this->assertTrue($migration->shouldRun());
        $this->assertTrue($migration->run()->isSuccessful());

        $main = (array) $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = 70');
        $this->assertSame($this->sourceHash()->of($main), $this->hashOf(80));
        $this->assertNotSame($this->sourceHash()->of($main, false), $this->hashOf(80), 'the questions are part of the state now');
        $this->assertSame('an older state', $this->hashOf(90));
        $this->assertFalse($migration->shouldRun());
    }

    private function hashOf(int $element): string
    {
        return (string) $this->db->fetchOne('SELECT source_hash FROM '.ElementLinks::TABLE.' WHERE element = ?', [$element]);
    }
}
