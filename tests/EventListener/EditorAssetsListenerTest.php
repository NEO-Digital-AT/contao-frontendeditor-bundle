<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Contao\CoreBundle\Csp\CspParser;
use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandlerFactory;
use Nelmio\SecurityBundle\ContentSecurityPolicy\PolicyManager;
use Neo\FrontendEditorBundle\EventListener\EditorAssetsListener;
use Neo\FrontendEditorBundle\Tests\Support\EditModes;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class EditorAssetsListenerTest extends TestCase
{
    use EditModes;

    private const PAGE = "<!DOCTYPE html>\n<html><head></head><body><main>Hello</main></body></html>";

    public function testThePageInTheFrameGetsItsIdAndHidesContaosPreviewBar(): void
    {
        $response = $this->respond($this->framedPage());
        $content = (string) $response->getContent();

        $this->assertStringContainsString('<script type="application/json" id="nfe-page">{"page":42,"nonce":null,"styleNonce":null}</script>', $content);
        $this->assertStringContainsString('<style data-nfe-frame>cto-toolbar,body>.cto-toolbar{display:none!important}</style>', $content);
        $this->assertStringEndsWith("\n</body></html>", $content);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testWithContentSecurityPolicyTheEditorGetsItsNonces(): void
    {
        $response = $this->respond($this->framedPage(), "default-src 'self'; script-src 'self'; style-src 'self'");
        $content = (string) $response->getContent();

        preg_match('/"nonce":"([^"]+)","styleNonce":"([^"]+)"/', $content, $nonces);
        $this->assertCount(3, $nonces);
        $this->assertStringContainsString('<style data-nfe-frame nonce="'.$nonces[2].'">', $content);
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'nonce-".$nonces[1]."'", $policy);
        $this->assertStringContainsString("'nonce-".$nonces[2]."'", $policy);
    }

    public function testVisitorsAndOtherResponsesGetThePageUnchanged(): void
    {
        $visitor = Request::create('https://www.example.com/about.html');
        $visitor->attributes->set('pageModel', (object) ['id' => 42]);
        $this->assertSame(self::PAGE, $this->respond($visitor)->getContent(), 'a visitor');

        $notFramed = $this->editedPage('https://www.example.com/about.html');
        $notFramed->attributes->set('pageModel', (object) ['id' => 42]);
        $this->assertSame(self::PAGE, $this->respond($notFramed)->getContent(), 'the editor view itself');

        $this->assertSame(self::PAGE, $this->respond($this->framedPage(), status: 404)->getContent(), 'an error page');
        $this->assertSame('{"a":1}', $this->respond($this->framedPage(), body: '{"a":1}', type: 'application/json')->getContent(), 'JSON');
        $this->assertSame(self::PAGE, $this->respond($this->framedPage(), user: false)->getContent(), 'without editor');

        $noPage = $this->editedPage();
        $this->assertSame(self::PAGE, $this->respond($noPage)->getContent(), 'without page');
    }

    private function framedPage(): Request
    {
        $request = $this->editedPage();
        $request->attributes->set('pageModel', (object) ['id' => 42]);

        return $request;
    }

    private function respond(Request $request, string|null $csp = null, int $status = 200, string $body = self::PAGE, string $type = 'text/html; charset=UTF-8', bool $user = true): Response
    {
        $response = new Response($body, $status, ['Content-Type' => $type]);

        if (null !== $csp) {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        $listener = new EditorAssetsListener($this->editMode($user ? Users::admin() : null), new CspHandlerFactory(new CspParser(new PolicyManager())));
        $listener(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
