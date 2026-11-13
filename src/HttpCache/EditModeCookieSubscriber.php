<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\HttpCache;

use FOS\HttpCache\SymfonyCache\CacheEvent;
use FOS\HttpCache\SymfonyCache\Events;
use Neo\FrontendEditorBundle\EditMode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the switch of the editor (cookie EditMode::COOKIE) in Contao's preview.
 *
 * Contao's HTTP cache also handles preview.php (ContaoKernel::fromRequest, unless
 * in debug mode) and removes every cookie that COOKIE_ALLOW_LIST does not name
 * (StripCookiesSubscriber) before the application sees the request. A website
 * that sets this list without the editor's cookie then never got the editor:
 * the switch set the cookie, the page reloaded, and nothing happened. This
 * subscriber notes the cookie before the cache removes it and puts this one
 * cookie back afterwards, only for requests of the preview. Pages outside the
 * preview and all other cookies stay as the cache leaves them.
 */
final class EditModeCookieSubscriber implements EventSubscriberInterface
{
    private const NOTED = '_neo_frontend_editor_cookie';

    public static function getSubscribedEvents(): array
    {
        // Contao's StripCookiesSubscriber runs at priority 0.
        return [
            Events::PRE_HANDLE => [['note', 64], ['restore', -64]],
        ];
    }

    public function note(CacheEvent $event): void
    {
        $request = $event->getRequest();

        if (true === $request->attributes->get('_preview') && $request->cookies->has(EditMode::COOKIE)) {
            $request->attributes->set(self::NOTED, (string) $request->cookies->get(EditMode::COOKIE));
        }
    }

    public function restore(CacheEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->has(self::NOTED) && !$request->cookies->has(EditMode::COOKIE)) {
            $request->cookies->set(EditMode::COOKIE, (string) $request->attributes->get(self::NOTED));
        }

        $request->attributes->remove(self::NOTED);
    }
}
