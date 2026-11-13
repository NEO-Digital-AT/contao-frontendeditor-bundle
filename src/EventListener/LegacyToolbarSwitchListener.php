<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandlerFactory;
use Neo\FrontendEditorBundle\EditMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The switch "Edit page" in Contao's preview toolbar before Contao 5.7.
 * From Contao 5.7 on the toolbar template has blocks, and the switch comes
 * with the extension's template (frontend_preview/toolbar.html.twig). Before
 * that the toolbar has none (Contao 5.3 and 5.4: preview_toolbar_base.html.twig,
 * 5.5 and 5.6: frontend_preview/toolbar.html.twig without blocks): this
 * listener adds a small script to the preview page of a user who may use the
 * editor, which puts the same switch at the start of the toolbar once Contao
 * has loaded it. The toolbar is either a custom element with a shadow root
 * (cto-toolbar, Contao 5.3.40+, 5.6.10+) that fires "cto-toolbar-loaded" on
 * itself, or a plain element in the page that fires it on the window. Runs
 * after Contao's own listener, which adds the toolbar.
 */
#[AsEventListener(priority: -16)]
final class LegacyToolbarSwitchListener
{
    /** Template of the toolbar of Contao 5.3 and 5.4 (removed in 5.5). */
    private const LEGACY_TOOLBAR = '@ContaoCore/Frontend/preview_toolbar_base_js.html.twig';

    /** Contao's own template of the toolbar from Contao 5.5 on; it has the block "widgets" from 5.7 on. */
    private const CORE_TOOLBAR = '@Contao_ContaoCoreBundle/frontend_preview/toolbar.html.twig';

    public function __construct(
        private readonly EditMode $editMode,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
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

        if ($request->isXmlHttpRequest() || 'html' !== $request->getRequestFormat() || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }

        $content = (string) $response->getContent();
        $position = strripos($content, '</body>');

        if (false === $position || !str_contains($content, 'cto-toolbar')) {
            return;
        }

        $user = $this->editMode->previewUser($request);

        // In the frame of the editor view the toolbar is hidden (EditorAssetsListener); the view has its own.
        if (null === $user || $this->editMode->isFrame($request) || $this->toolbarHasBlocks()) {
            return;
        }

        $label = $this->translator->trans('toolbar.switch', [], 'neo_frontend_editor', (string) $user->language);
        $on = '1' === $request->cookies->get(EditMode::COOKIE);

        $cspHandler = $this->cspHandlerFactory->create((string) $response->headers->get('Content-Security-Policy', ''));
        $csp = $response->headers->has('Content-Security-Policy');
        $scriptNonce = $csp ? $cspHandler->getNonce('script-src') : null;
        $styleNonce = $csp ? $cspHandler->getNonce('style-src') : null;

        $script = \sprintf(
            '<script%s>(function(){var label=%s,on=%s,cookie=%s,styleNonce=%s;'
            // The shadow root of cto-toolbar, or the page where the toolbar is a plain element.
            .'function root(){var host=document.querySelector("cto-toolbar");return host&&host.shadowRoot?host.shadowRoot:document;}'
            .'function add(){var area=root(),body=area.querySelector(".cto-toolbar .formbody");'
            .'if(!body||body.querySelector("[data-nfe-switch]")){return;}'
            .'var style=document.createElement("style");if(styleNonce){style.setAttribute("nonce",styleNonce);}'
            .'style.textContent=".cto-toolbar .nfe-switch .tl_submit[aria-pressed=\\"true\\"]{background:#222;border-color:#222;color:#fff}";'
            .'(area===document?document.head:area).appendChild(style);'
            .'var wrap=document.createElement("div");wrap.className="nfe-switch";var button=document.createElement("button");'
            .'button.type="button";button.className="tl_submit";button.setAttribute("data-nfe-switch","");button.setAttribute("aria-pressed",on?"true":"false");button.textContent=label;'
            .'wrap.appendChild(button);body.insertBefore(wrap,body.firstChild);'
            .'button.addEventListener("click",function(){var next="true"!==button.getAttribute("aria-pressed");var secure="https:"===location.protocol?"; Secure":"";'
            .'document.cookie=cookie+"="+(next?"1":"")+"; Path=/; SameSite=Lax"+secure+(next?"":"; Max-Age=0");location.reload();});}'
            // "cto-toolbar-loaded" does not bubble: in the shadow root in the capture phase, otherwise on the window.
            .'var area=root();if(area!==document){area.addEventListener("cto-toolbar-loaded",add,true);}'
            .'window.addEventListener("cto-toolbar-loaded",add);add();})();</script>',
            null !== $scriptNonce ? ' nonce="'.htmlspecialchars($scriptNonce, ENT_QUOTES).'"' : '',
            json_encode($label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $on ? 'true' : 'false',
            json_encode(EditMode::COOKIE, JSON_THROW_ON_ERROR),
            json_encode($styleNonce, JSON_THROW_ON_ERROR),
        );

        $response->setContent(substr($content, 0, $position).$script."\n".substr($content, $position));

        if (null !== $scriptNonce || null !== $styleNonce) {
            $cspHandler->applyHeaders($response, $request);
        }
    }

    /** Whether Contao's toolbar template has the block the extension's template adds the switch to (Contao 5.7+). */
    private function toolbarHasBlocks(): bool
    {
        $loader = $this->twig->getLoader();

        if ($loader->exists(self::LEGACY_TOOLBAR) || !$loader->exists(self::CORE_TOOLBAR)) {
            return false;
        }

        return 1 === preg_match('/\{%-?\s*block\s+widgets\b/', $loader->getSourceContext(self::CORE_TOOLBAR)->getCode());
    }
}
