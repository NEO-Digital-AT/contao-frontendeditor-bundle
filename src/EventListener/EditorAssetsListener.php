<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandlerFactory;
use Neo\FrontendEditorBundle\EditMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The page in the frame of the editor view (EditorViewListener): the page as
 * Contao renders it in edit mode, with the markers of its parts
 * (ContentMarkerListener, DesignMarkerListener) and, before </body>, the ID of
 * the page and the nonces of Contao's Content Security Policy for what the
 * editor adds to the page. Contao's preview bar, which Contao adds to every
 * page of the preview, is hidden there: it is shown at the top of the view.
 * Every other response leaves this listener unchanged, so visitors get exactly
 * the page they got without the extension. Edited pages are never stored in a
 * cache.
 */
#[AsEventListener]
final class EditorAssetsListener
{
    public function __construct(
        private readonly EditMode $editMode,
        #[Autowire(service: 'contao.routing.response_context.csp_handler_factory')]
        private readonly CspHandlerFactory $cspHandlerFactory,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (
            !$this->editMode->isFrame($request)
            || null === $this->editMode->editor($request)
            || $request->isXmlHttpRequest()
            || 'html' !== $request->getRequestFormat()
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
            || !$response->isSuccessful()
        ) {
            return;
        }

        $content = (string) $response->getContent();
        $position = strripos($content, '</body>');
        $page = $request->attributes->get('pageModel');

        if (false === $position || !\is_object($page) || !isset($page->id)) {
            return;
        }

        $csp = $response->headers->has('Content-Security-Policy');
        $cspHandler = $this->cspHandlerFactory->create((string) $response->headers->get('Content-Security-Policy', ''));
        // The accessibility check (Pro) loads axe-core into the page, the preview of a page style (Pro) is a <style> element.
        $nonce = $csp ? $cspHandler->getNonce('script-src') : null;
        $styleNonce = $csp ? $cspHandler->getNonce('style-src') : null;
        $data = ['page' => (int) $page->id, 'nonce' => $nonce, 'styleNonce' => $styleNonce];
        $styleAttribute = null !== $styleNonce ? ' nonce="'.htmlspecialchars($styleNonce, ENT_QUOTES).'"' : '';

        $html = '<script type="application/json" id="nfe-page">'.json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'</script>'
            .'<style data-nfe-frame'.$styleAttribute.'>cto-toolbar,body>.cto-toolbar{display:none!important}</style>';

        $response->setContent(substr($content, 0, $position).$html."\n".substr($content, $position));

        if ($csp) {
            $cspHandler->applyHeaders($response, $request);
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }
}
