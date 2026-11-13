<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Neo\FrontendEditorBundle\EventListener\CrossOriginListener;
use Neo\FrontendEditorBundle\SiteOrigins;
use Neo\FrontendEditorBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The back end runs under manage.example.com, the website under
 * www.example.com (a root page).
 */
final class CrossOriginListenerTest extends TestCase
{
    private const API = 'https://manage.example.com/contao/frontend-editor/element/12';

    public function testThePreflightOfTheWebsiteIsAnswered(): void
    {
        $request = $this->request(self::API, 'OPTIONS', 'https://www.example.com');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener($request)->onRequest($event);
        $response = $event->getResponse();

        $this->assertNotNull($response);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://www.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertSame('GET, POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, X-Request-Token', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertContains('Origin', $response->getVary());
    }

    public function testOtherAddressesOtherPathsAndOtherMethodsGetNoPreflightAnswer(): void
    {
        foreach ([
            [self::API, 'OPTIONS', 'https://attacker.example'],
            [self::API, 'OPTIONS', 'http://www.example.com'],
            [self::API, 'OPTIONS', 'https://manage.example.com'],
            [self::API, 'OPTIONS', null],
            ['https://manage.example.com/contao/login', 'OPTIONS', 'https://www.example.com'],
            ['https://manage.example.com/contao/frontend-editorial/x', 'OPTIONS', 'https://www.example.com'],
            [self::API, 'GET', 'https://www.example.com'],
        ] as [$uri, $method, $origin]) {
            $request = $this->request($uri, $method, $origin);
            $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

            $this->listener($request)->onRequest($event);

            $this->assertNull($event->getResponse(), $method.' '.$uri.' from '.$origin);
        }
    }

    public function testAnswersOfTheEndpointsCarryCorsHeadersForTheWebsiteOnly(): void
    {
        $allowed = $this->respond($this->request(self::API, 'POST', 'https://www.example.com'));
        $this->assertSame('https://www.example.com', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));

        $foreign = $this->respond($this->request(self::API, 'POST', 'https://attacker.example'));
        $this->assertFalse($foreign->headers->has('Access-Control-Allow-Origin'));

        $otherPath = $this->respond($this->request('https://manage.example.com/contao', 'GET', 'https://www.example.com'));
        $this->assertFalse($otherPath->headers->has('Access-Control-Allow-Origin'));
    }

    public function testFramedBackEndPagesMayBeEmbeddedByTheWebsite(): void
    {
        $request = $this->request('https://manage.example.com/contao?do=article&act=edit&popup=1', 'GET', null);
        $response = new Response('<form></form>');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'self'");

        $this->respond($request, $response);

        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $this->assertSame("default-src 'self'; frame-ancestors 'self' https://www.example.com", $response->headers->get('Content-Security-Policy'));
    }

    public function testAPolicyWithoutFrameAncestorsGetsOne(): void
    {
        $request = $this->request('https://manage.example.com/contao/picker?popup=1', 'GET', null);
        $response = new Response('');
        $response->headers->set('Content-Security-Policy', "default-src 'self'");

        $this->respond($request, $response);

        $this->assertSame(["default-src 'self'", "frame-ancestors 'self' https://www.example.com"], $response->headers->all('content-security-policy'));
    }

    public function testPagesOfTheWebsiteAndUnframedBackEndPagesKeepTheirHeaders(): void
    {
        foreach (['https://www.example.com/about.html?popup=1', 'https://manage.example.com/contao?do=article'] as $uri) {
            $response = new Response('');
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

            $this->respond($this->request($uri, 'GET', null), $response);

            $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), $uri);
            $this->assertFalse($response->headers->has('Content-Security-Policy'), $uri);
        }
    }

    public function testWithBackEndAndWebsiteUnderOneAddressNothingChanges(): void
    {
        $request = $this->request('https://www.example.com/contao?popup=1', 'GET', null);
        $response = new Response('');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        $listener = new CrossOriginListener(new SiteOrigins(TestDatabase::create(), $this->stack($request)), '/contao');
        $listener->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    private function respond(Request $request, Response|null $response = null): Response
    {
        $response ??= new Response('{"success":true}');
        $this->listener($request)->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function listener(Request $request): CrossOriginListener
    {
        $db = TestDatabase::create('tl_page');
        $db->insert('tl_page', ['type' => 'root', 'dns' => 'www.example.com', 'useSSL' => '1']);

        return new CrossOriginListener(new SiteOrigins($db, $this->stack($request)), 'contao');
    }

    private function request(string $uri, string $method, string|null $origin): Request
    {
        $request = Request::create($uri, $method);

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    private function stack(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
