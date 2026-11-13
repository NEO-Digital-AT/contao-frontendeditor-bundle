<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The editor view is served under the address of the page, so listeners that
 * add something to every page of the front end (a cookie banner, a chat, an
 * accessibility panel, a credit line before </body>) add it to the view as
 * well, where it would cover the sidebar and the page and catch the clicks.
 * The view belongs to the editor alone: after all other listeners, its HTML
 * is the editor's own again, with Contao's preview bar added by Contao's own
 * listener (and the switch "Edit page" of Contao 5.3 to 5.6, whose bar has no
 * blocks for it). What the extensions add stays where it belongs, in the page
 * in the frame. Their headers (cookies, Content Security Policy) are kept.
 */
#[AsEventListener(priority: -4000)]
final class EditorViewCleanupListener
{
    public const ATTRIBUTE = '_nfe_view';

    public function __construct(
        private readonly LegacyToolbarSwitchListener $legacySwitch,
        // Contao's own listener (the same service in Contao 5.3 to 6.0).
        #[Autowire(service: 'contao.listener.preview_toolbar')]
        private readonly object $previewToolbar,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $view = $request->attributes->get(self::ATTRIBUTE);

        if (!$event->isMainRequest() || !\is_string($view)) {
            return;
        }

        $response = $event->getResponse();

        if ($response->getContent() === $view || !\is_callable($this->previewToolbar)) {
            return;
        }

        $response->setContent($view);
        $clean = new ResponseEvent($event->getKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        ($this->previewToolbar)($clean);
        ($this->legacySwitch)($clean);
    }
}
