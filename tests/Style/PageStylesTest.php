<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Style;

use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class PageStylesTest extends TestCase
{
    use ContaoContainer;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create('tl_page', PageStyles::TABLE);
        $this->setUpContao(null, $this->db);

        foreach ([1 => 'Root', 2 => 'Products', 3 => 'Chairs', 4 => 'About'] as $id => $title) {
            $this->db->insert('tl_page', ['id' => $id, 'title' => $title]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testOpeningCreatesADraftOnceAndDiscardingRemovesOnlyDrafts(): void
    {
        $styles = new PageStyles($this->db);

        [$draft, $created] = $styles->open(3);
        $this->assertTrue($created);
        $this->assertSame(0, (int) $draft['tstamp']);
        $this->assertSame(PageStyles::SCOPE_PAGE, $draft['scope']);

        [$again, $createdAgain] = $styles->open(3);
        $this->assertFalse($createdAgain);
        $this->assertSame($draft['id'], $again['id']);

        $this->db->insert(PageStyles::TABLE, ['pid' => 4, 'tstamp' => 1700000000, 'scope' => 'page', 'scss' => '.a{}', 'css' => '.a{}']);
        $saved = (int) $this->db->lastInsertId();

        $this->assertFalse($styles->discard($saved));
        $this->assertNotNull($styles->byId($saved));
        $this->assertTrue($styles->discard((int) $draft['id']));
        $this->assertNull($styles->record(3));
    }

    public function testThePageGetsTheStylesOfItsTrailInOrderAndItsOwnLast(): void
    {
        $this->style(1, PageStyles::SCOPE_SUBPAGES, 'body{margin:0}');
        $this->style(2, PageStyles::SCOPE_PAGE, '.products-only{}');
        $this->style(3, PageStyles::SCOPE_PAGE, '.chairs{}');
        $this->style(4, PageStyles::SCOPE_SUBPAGES, '.about{}');

        $styles = (new PageStyles($this->db))->forPage($this->pageModel(['id' => 3, 'trail' => [1, 2, 3]]));

        $this->assertSame([1, 3], array_column($styles, 'pid'));
        $this->assertSame(['body{margin:0}', '.chairs{}'], array_column($styles, 'css'));
        $this->assertSame([false, true], array_column($styles, 'own'));
        $this->assertSame(['Root', 'Chairs'], array_column($styles, 'title'));
    }

    public function testDraftsAndEmptyStylesDoNotApply(): void
    {
        $this->style(1, PageStyles::SCOPE_SUBPAGES, 'body{margin:0}', 0);
        $this->style(3, PageStyles::SCOPE_PAGE, '');

        $this->assertSame([], (new PageStyles($this->db))->forPage($this->pageModel(['id' => 3, 'trail' => [1, 2, 3]])));
    }

    public function testAPageWithoutTrailHasItsOwnStyle(): void
    {
        $this->style(4, PageStyles::SCOPE_PAGE, '.about{}');

        $this->assertSame(['.about{}'], array_column((new PageStyles($this->db))->forPage($this->pageModel(['id' => 4])), 'css'));
    }

    public function testBeforeTheDatabaseUpdateThereAreNoStyles(): void
    {
        $styles = new PageStyles(TestDatabase::create('tl_page'));

        $this->assertNull($styles->record(3));
        $this->assertSame([], $styles->forPage($this->pageModel(['id' => 3, 'trail' => [1, 3]])));
    }

    private function style(int $page, string $scope, string $css, int $tstamp = 1700000000): void
    {
        $this->db->insert(PageStyles::TABLE, ['pid' => $page, 'tstamp' => $tstamp, 'scope' => $scope, 'scss' => $css, 'css' => $css]);
    }
}
