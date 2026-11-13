<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\EventListener\EditorSwitchListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class EditorSwitchListenerTest extends TestCase
{
    public function testThePreviewOpenedFromTheHeaderTurnsTheEditorOn(): void
    {
        $response = $this->answer('https://www.example.com/preview.php/contao/preview?page=4&nfe_editor=1', 'contao_backend_preview', new RedirectResponse('/de/'));

        $cookie = $this->cookie($response);

        $this->assertNotNull($cookie);
        $this->assertSame('1', $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame(0, $cookie->getExpiresTime());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertTrue($cookie->isSecure());
        $this->assertFalse($cookie->isHttpOnly());
    }

    public function testContaosOwnPreviewLeavesTheSwitchAlone(): void
    {
        $response = $this->answer('https://www.example.com/preview.php/contao/preview?page=4', 'contao_backend_preview', new RedirectResponse('/de/'));

        $this->assertNull($this->cookie($response));
    }

    public function testWithoutThePermissionTheSwitchStaysOff(): void
    {
        $response = $this->answer('https://www.example.com/preview.php/contao/preview?nfe_editor=1', 'contao_backend_preview', new RedirectResponse('/de/'), false);

        $this->assertNull($this->cookie($response));
    }

    public function testOnlyTheRedirectOfThePreviewRouteSetsIt(): void
    {
        $this->assertNull($this->cookie($this->answer('https://www.example.com/de/?nfe_editor=1', 'tl_page.4', new RedirectResponse('/en/'))));
        $this->assertNull($this->cookie($this->answer('https://www.example.com/contao/preview?nfe_editor=1', 'contao_backend_preview', new Response('Access denied', 403))));
    }

    private function answer(string $uri, string $route, Response $response, bool $granted = true): Response
    {
        $request = Request::create($uri);
        $request->attributes->set('_route', $route);

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        (new EditorSwitchListener($checker))(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function cookie(Response $response): Cookie|null
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (EditMode::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
