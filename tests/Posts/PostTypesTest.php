<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Posts;

use Contao\ContentModel;
use Contao\DataContainer;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\EditorFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A module of news (archive and posts) and a module of an extension built
 * like posts whose table has no Contao model.
 */
#[CoversClass(PostTypes::class)]
final class PostTypesTest extends TestCase
{
    use EditorFixture;

    protected function setUp(): void
    {
        $this->setUpEditor();
        $GLOBALS['BE_MOD'] = ['content' => [
            'news' => ['tables' => ['tl_news_archive', 'tl_news', 'tl_content']],
            'sources' => ['tables' => ['tl_source_group', 'tl_source']],
        ]];
        $posts = static fn (string $archive): array => [
            'config' => ['ptable' => $archive, 'ctable' => ['tl_content']],
            'list' => ['sorting' => ['mode' => DataContainer::MODE_PARENT, 'fields' => ['date DESC']]],
            'fields' => ['headline' => [], 'date' => [], 'published' => []],
        ];
        ContaoState::dca('tl_news', $posts('tl_news_archive'));
        ContaoState::dca('tl_news_archive', ['fields' => ['title' => []]]);
        ContaoState::dca('tl_source', $posts('tl_source_group'));
        ContaoState::dca('tl_source_group', ['fields' => ['title' => []]]);
        ContaoState::language('modules');
        // Any model class will do; only its existence counts.
        $GLOBALS['TL_MODELS'] = ['tl_news' => ContentModel::class];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_MODELS']);
        $this->tearDownContao();
    }

    /** From Contao 5.7 on, asking for the model of a table without one throws: such a table is no post type. */
    public function testATableWithoutAModelIsNoPostType(): void
    {
        $types = new PostTypes($this->framework(), $this->editorSecurity(), $this->db);

        $this->assertSame(['tl_news'], array_column($types->all(), 'table'));
        $this->assertTrue($types->all()[0]['hasContent']);
        $this->assertNull($types->get('tl_source'));
    }
}
