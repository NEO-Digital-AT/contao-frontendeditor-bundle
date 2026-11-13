<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\ContentModel;
use Contao\FrontendTemplate;
use Contao\ModuleModel;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Content\SourceMap\SourceMapRenderer;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\EventListener\ContentMarkerListener;
use Neo\FrontendEditorBundle\EventListener\DesignMarkerListener;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\ContaoState;
use Neo\FrontendEditorBundle\Tests\Support\ContentDca;
use Neo\FrontendEditorBundle\Tests\Support\EditModes;
use Neo\FrontendEditorBundle\Tests\Support\Pages;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The markers of the parts of a page in edit mode (content elements, modules,
 * comments, events), and that visitors get the output unchanged. Elements and
 * modules here have no texts of their own, so no second rendering for the
 * source map is needed.
 */
final class MarkerListenersTest extends TestCase
{
    use ContaoContainer;
    use EditModes;
    use Pages;

    private RequestStack $stack;

    protected function setUp(): void
    {
        $db = TestDatabase::create('tl_content', 'tl_module');
        $this->stack = $this->setUpContao(null, $db);
        ContaoState::dca('tl_content', ContentDca::content());
        ContaoState::dca('tl_module', ContentDca::module());
        ContaoState::language('tl_content');
        ContaoState::language('tl_module');
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testInEditModeAnElementIsEnclosedInCommentsWithItsId(): void
    {
        $this->stack->push($this->editedPage());

        $html = $this->contentListener()($this->element(['id' => 12, 'type' => 'html', 'html' => '<div>Code</div>']), '<div>Code</div>', null);

        $this->assertSame('<!--nfe:ce:12--><div>Code</div><!--/nfe:ce:12-->', $html);
    }

    public function testVisitorsGetTheOutputOfElementsAndModulesUnchanged(): void
    {
        $this->stack->push(Request::create('https://www.example.com/about.html'));

        $this->assertSame('<div>Code</div>', $this->contentListener()($this->element(['id' => 12, 'type' => 'html']), '<div>Code</div>', null));
        $this->assertSame('<nav>Menu</nav>', $this->designListener()->module($this->module(['id' => 4, 'type' => 'navigation']), '<nav>Menu</nav>', null));
        $this->assertSame('<div class="comment">Nice</div>', $this->designListener()->template('<div class="comment">Nice</div>', 'com_default', $this->template('com_default', ['id' => 'c7'])));
    }

    public function testEmptyOutputStaysEmpty(): void
    {
        $this->stack->push($this->editedPage());

        $this->assertSame(' ', $this->contentListener()($this->element(['id' => 12, 'type' => 'html']), ' ', null));
        $this->assertSame('', $this->designListener()->module($this->module(['id' => 4, 'type' => 'navigation']), '', null));
    }

    public function testInEditModeModulesCommentsAndEventsAreMarked(): void
    {
        $this->stack->push($this->editedPage());
        $design = $this->designListener();

        $this->assertSame('<!--nfe:fm:4--><nav>Menu</nav><!--/nfe:fm:4-->', $design->module($this->module(['id' => 4, 'type' => 'navigation']), '<nav>Menu</nav>', null));
        $this->assertSame('<!--nfe:cm:7--><p>Nice</p><!--/nfe:cm:7-->', $design->template('<p>Nice</p>', 'com_default', $this->template('com_default', ['id' => 'c7'])));
        $this->assertSame('<!--nfe:lp:tl_calendar_events:9--><p>Party</p><!--/nfe:lp:tl_calendar_events:9-->', $design->template('<p>Party</p>', 'event_list', $this->template('event_list', ['id' => '9'])));
        // The reader of an event marks its event too: its texts are edited on the page.
        $this->assertSame('<!--nfe:lp:tl_calendar_events:9--><p>Party</p><!--/nfe:lp:tl_calendar_events:9-->', $design->template('<p>Party</p>', 'event_full', $this->template('event_full', ['id' => '9'])));
        $this->assertSame('<p>Other</p>', $design->template('<p>Other</p>', 'ce_text', $this->template('ce_text', ['id' => 'c7'])));
    }

    public function testTheColumnOfAnElementComesFromContaosElementObject(): void
    {
        $element = new class {
            protected string $strColumn = 'left';
        };

        $this->assertSame('left', ContentMarkerListener::column($element));
        $this->assertSame('main', ContentMarkerListener::column(null));
        $this->assertSame('main', ContentMarkerListener::column(new \stdClass()));
    }

    private function contentListener(): ContentMarkerListener
    {
        return new ContentMarkerListener($this->editMode(Users::admin(), stack: $this->stack), $this->renderer());
    }

    private function designListener(): DesignMarkerListener
    {
        return new DesignMarkerListener($this->editMode(Users::admin(), stack: $this->stack), $this->renderer());
    }

    private function renderer(): SourceMapRenderer
    {
        return new SourceMapRenderer(new TextSources($this->framework(), $db = TestDatabase::create(), new RelatedRecords($this->framework(), $db)), $this->stack, new NullLogger());
    }

    /** @param array<string, mixed> $row */
    private function element(array $row): ContentModel
    {
        $model = (new \ReflectionClass(ContentModel::class))->newInstanceWithoutConstructor();

        foreach ($row as $key => $value) {
            $model->$key = $value;
        }

        return $model;
    }

    /** @param array<string, mixed> $row */
    private function module(array $row): ModuleModel
    {
        $model = (new \ReflectionClass(ModuleModel::class))->newInstanceWithoutConstructor();

        foreach ($row as $key => $value) {
            $model->$key = $value;
        }

        return $model;
    }

    /** @param array<string, mixed> $data */
    private function template(string $name, array $data): FrontendTemplate
    {
        $template = new FrontendTemplate($name);
        $template->setData($data);

        return $template;
    }
}
