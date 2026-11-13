<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\HttpCache;

use Contao\CoreBundle\EventListener\HttpCache\StripCookiesSubscriber;
use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\CacheInvalidation;
use FOS\HttpCache\SymfonyCache\Events;
use Neo\FrontendEditorBundle\ContaoManager\Plugin;
use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\HttpCache\EditModeCookieSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * The switch of the editor against Contao's HTTP cache with a cookie allow list
 * (COOKIE_ALLOW_LIST): as on a website that names only its session and CSRF cookies.
 */
#[CoversClass(EditModeCookieSubscriber::class)]
final class EditModeCookieSubscriberTest extends TestCase
{
    public function testTheSwitchReachesThePreviewAlthoughTheAllowListDoesNotNameIt(): void
    {
        $request = $this->request(true);
        $this->dispatch($request, new EditModeCookieSubscriber());

        $this->assertSame('1', $request->cookies->get(EditMode::COOKIE));
        $this->assertSame('abc', $request->cookies->get('PHPSESSID'));
        $this->assertFalse($request->cookies->has('_ga'), 'other cookies stay removed');
        $this->assertFalse($request->attributes->has('_neo_frontend_editor_cookie'), 'no trace left in the request');
    }

    public function testWithoutTheSubscriberTheCacheRemovesTheSwitch(): void
    {
        $request = $this->request(true);
        $this->dispatch($request);

        $this->assertFalse($request->cookies->has(EditMode::COOKIE), 'the failure the subscriber is for');
    }

    public function testOutsideThePreviewTheCacheDecides(): void
    {
        $request = $this->request(false);
        $this->dispatch($request, new EditModeCookieSubscriber());

        $this->assertFalse($request->cookies->has(EditMode::COOKIE));
    }

    public function testTheManagerPluginRegistersIt(): void
    {
        $subscribers = (new Plugin())->getHttpCacheSubscribers();

        $this->assertCount(1, $subscribers);
        $this->assertInstanceOf(EditModeCookieSubscriber::class, $subscribers[0]);
    }

    private function request(bool $preview): Request
    {
        $request = Request::create('https://www.example.com/preview.php/en/', 'GET', [], ['PHPSESSID' => 'abc', EditMode::COOKIE => '1', '_ga' => 'GA1.1.1']);

        if ($preview) {
            $request->attributes->set('_preview', true);
        }

        return $request;
    }

    private function dispatch(Request $request, EditModeCookieSubscriber|null $subscriber = null): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StripCookiesSubscriber(['PHPSESSID', 'csrf_.*']));

        if (null !== $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }

        $dispatcher->dispatch(new CacheEvent($this->createStub(CacheInvalidation::class), $request), Events::PRE_HANDLE);
    }
}
