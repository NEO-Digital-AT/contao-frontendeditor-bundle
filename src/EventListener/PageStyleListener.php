<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandler;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\PageModel;
use Neo\BasicBundle\Cache\PageCacheTags;
use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Delivers the styles of a page (Pro): those of the pages above it that apply
 * to their subpages, then its own, each as <style> at the very end of the
 * head, after every style sheet of the layout (also those added as extra head
 * tags), so a page style wins at equal weight. With Contao's Content Security
 * Policy the element carries its nonce. Pages without a style stay unchanged.
 * The styles are delivered whether or not the website still has Pro; the
 * licence decides who may change them, not how the website looks.
 */
final class PageStyleListener
{
    public function __construct(
        private readonly PageStyles $styles,
        private readonly EditMode $editMode,
        private readonly ResponseContextAccessor $responseContextAccessor,
        private readonly PageCacheTags $cacheTags,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsHook('modifyFrontendPage')]
    public function __invoke(string $buffer, string $template): string
    {
        $request = $this->requestStack->getMainRequest();
        $page = $request?->attributes->get('pageModel');
        $position = stripos($buffer, '</head>');

        if (!str_starts_with($template, 'fe_page') || !$page instanceof PageModel || false === $position) {
            return $buffer;
        }

        $styles = $this->styles->forPage($page);

        if ([] === $styles) {
            return $buffer;
        }

        $context = $this->responseContextAccessor->getResponseContext();
        $nonce = $context?->has(CspHandler::class) ? $context->get(CspHandler::class)->getNonce('style-src') : null;
        // In the preview of a user who may use the editor (also the page in the frame of the editor view) the editor finds the element.
        $editing = $this->editMode->mayEdit($request);
        $html = '';

        foreach ($styles as $style) {
            // The preview of a style replaces its element; visitors get the plain element.
            $html .= '<style'
                .(null !== $nonce ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"' : '')
                .($editing ? ' data-nfe-page-style="'.$style['pid'].'"' : '')
                .'>'.$style['css'].'</style>';
        }

        $this->cacheTags->tag(array_map(static fn (array $style): string => 'contao.db.'.PageStyles::TABLE.'.'.$style['id'], $styles));

        return substr($buffer, 0, $position).$html.substr($buffer, $position);
    }
}
