<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\CoreBundle\Csp\CspParser;
use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Doctrine\DBAL\Connection;
use Nelmio\SecurityBundle\ContentSecurityPolicy\PolicyManager;
use Neo\FrontendEditorBundle\EventListener\PageStyleListener;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Tests\Support\ContaoContainer;
use Neo\FrontendEditorBundle\Tests\Support\EditModes;
use Neo\FrontendEditorBundle\Tests\Support\RecordingCacheTags;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class PageStyleListenerTest extends TestCase
{
    use ContaoContainer;
    use EditModes;

    private const PAGE = '<!DOCTYPE html><html><head><title>Chairs</title><link rel="stylesheet" href="/assets/css/layout.css"></head><body></body></html>';

    private Connection $db;

    private RecordingCacheTags $cacheTags;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create('tl_page', PageStyles::TABLE);
        $this->setUpContao(null, $this->db);
        $this->cacheTags = new RecordingCacheTags();
        $this->db->insert('tl_page', ['id' => 1, 'title' => 'Root']);
        $this->db->insert('tl_page', ['id' => 3, 'pid' => 1, 'title' => 'Chairs']);
        $this->db->insert(PageStyles::TABLE, ['id' => 7, 'pid' => 1, 'tstamp' => 1700000000, 'scope' => 'subpages', 'scss' => 'body{margin:0}', 'css' => 'body{margin:0}']);
        $this->db->insert(PageStyles::TABLE, ['id' => 8, 'pid' => 3, 'tstamp' => 1700000000, 'scope' => 'page', 'scss' => '.chair{}', 'css' => '.chair\3c {}']);
    }

    protected function tearDown(): void
    {
        $this->tearDownContao();
    }

    public function testVisitorsGetTheStylesAtTheEndOfTheHead(): void
    {
        $html = $this->render(Request::create('https://www.example.com/chairs.html'));

        $this->assertSame('<!DOCTYPE html><html><head><title>Chairs</title><link rel="stylesheet" href="/assets/css/layout.css"><style>body{margin:0}</style><style>.chair\3c {}</style></head><body></body></html>', $html);
        $this->assertSame(['contao.db.tl_neo_fe_style.7', 'contao.db.tl_neo_fe_style.8'], $this->cacheTags->tagged);
    }

    public function testInThePreviewOfAnEditorTheEditorFindsTheStyles(): void
    {
        $html = $this->render($this->editedPage('https://www.example.com/chairs.html', cookie: false));

        $this->assertStringContainsString('<style data-nfe-page-style="1">body{margin:0}</style><style data-nfe-page-style="3">', $html);
    }

    public function testWithContentSecurityPolicyTheStylesCarryTheNonce(): void
    {
        $context = new ResponseContext();
        $context->add(new CspHandler((new CspParser(new PolicyManager()))->parseHeader("style-src 'self'")));
        $nonce = $context->get(CspHandler::class)->getNonce('style-src');

        $html = $this->render(Request::create('https://www.example.com/chairs.html'), $context);

        $this->assertNotNull($nonce);
        $this->assertSame(2, substr_count($html, '<style nonce="'.$nonce.'">'));
    }

    public function testPagesWithoutStyleAndOtherTemplatesStayUnchanged(): void
    {
        $this->assertSame(self::PAGE, $this->render(Request::create('https://www.example.com/'), page: 4));
        $this->assertSame(self::PAGE, $this->render(Request::create('https://www.example.com/chairs.html'), template: 'mod_navigation'));
        $this->assertSame('<body></body>', $this->render(Request::create('https://www.example.com/chairs.html'), buffer: '<body></body>'));
        $this->assertSame([], $this->cacheTags->tagged);
    }

    private function render(Request $request, ResponseContext|null $context = null, int $page = 3, string $template = 'fe_page', string $buffer = self::PAGE): string
    {
        $request->attributes->set('pageModel', $this->pageModel(['id' => $page, 'trail' => 4 === $page ? [4] : [1, $page]]));
        $stack = new RequestStack();
        $stack->push($request);
        $contexts = new ResponseContextAccessor($stack);

        if (null !== $context) {
            $contexts->setResponseContext($context);
        }

        $listener = new PageStyleListener(new PageStyles($this->db), $this->editMode(Users::admin()), $contexts, $this->cacheTags->service, $stack);

        return $listener($buffer, $template);
    }
}
