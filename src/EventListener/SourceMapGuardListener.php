<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use Neo\FrontendEditorBundle\EditMode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Last step of a response in edit mode: no marker of the source map leaves the
 * server, whatever a template or another listener did with a value, and the
 * response is not kept by any cache. Responses without a back-end user in
 * edit mode are not touched.
 */
#[AsEventListener(priority: -4096)]
final class SourceMapGuardListener
{
    public function __construct(private readonly EditMode $editMode)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->editMode->editor($event->getRequest())) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();

        if (\is_string($content) && Markers::contains($content)) {
            $response->setContent(Markers::strip($content));
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }
}
