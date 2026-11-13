<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Routing\ResponseContext\Csp\CspHandlerFactory;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Language\RecordVersions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\SiteOrigins;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Contao's back-end form saves without loading the page again (Turbo), so the
 * frame that shows it in the editor's sidebar reports no "load". When the
 * editor opens the form, it passes its own address (nfe_origin); every page
 * the frame then shows tells the editor that it is there, by postMessage to
 * exactly that address and only when it is an address of the website. Back-end
 * pages opened in any other way stay unchanged.
 */
final class FormBridgeListener
{
    public const ORIGIN_PARAMETER = 'nfe_origin';

    /** Value of nfe_view for the form of a page style (Pro). */
    public const STYLE = 'style';

    /** Value of nfe_view for Contao's Template Studio in the sidebar (Basic). */
    public const TEMPLATE = 'template';

    /**
     * Template Studio in the sidebar: the studio fills the frame (like its own
     * full screen), and the template named in nfe_template is opened from the
     * tree. Placeholder %s: the style nonce attribute.
     */
    private const STUDIO_STYLE = '<style%s>#template-studio{position:fixed;inset:0;z-index:1000;margin:0;max-width:none;background:var(--content-bg,#fff)}</style>';

    private const STUDIO_SCRIPT = '(function(){var n=new URLSearchParams(location.search).get("nfe_template"),i=0;if(!n)return;'
        .'(function f(){var b=document.querySelector("#template-studio--tree [data-name=\""+n.replace(/["\\\\]/g,"\\\\$&")+"\"] button");'
        .'if(b){b.click();return;}if(++i<40)setTimeout(f,100);})();})();';

    /**
     * Form of a page style: every change in Contao's code editor (ACE, or the
     * plain field without it) goes to the editor for the preview, half a second
     * after typing stops; the editor sends back the line of an error, which
     * ACE marks (until its own syntax check runs again on the next change).
     * Placeholder %s: the address of the editor (JSON).
     */
    private const STYLE_BRIDGE = '(function(){var o=%s,n=0,t=null;'
        .'function send(v){clearTimeout(t);t=setTimeout(function(){parent.postMessage({type:"nfe:style",scss:v},o);},500);}'
        .'function hook(last){var a=document.querySelector("#ctrl_scss"),el=a&&a.parentNode.querySelector(".ace_editor"),ed=el&&el.env&&el.env.editor;'
        .'if(ed){ed.session.on("change",function(){send(ed.getValue());});'
        .'window.addEventListener("message",function(e){if(e.origin!==o||!e.data||e.data.type!=="nfe:style-error"||!e.data.line)return;'
        .'ed.session.setAnnotations([{row:e.data.line-1,column:0,type:"error",text:String(e.data.text||"")}]);});return true;}'
        .'if(a&&last){a.addEventListener("input",function(){send(a.value);});return true;}return false;}'
        .'(function wait(){if(!hook(n>=50)&&++n<=50)setTimeout(wait,100);})();})();';

    /** Marks the current page in the tree (parameter nfe_current) by its "paste after" form and scrolls it into view. */
    private const MARK_CURRENT = '(function(){var c=new URLSearchParams(location.search).get("nfe_current");if(!c)return;'
        .'document.querySelectorAll(".tl_listing form[action*=\\"act=create\\"]").forEach(function(f){var q=new URL(f.action,location.href).searchParams;'
        .'if(q.get("mode")==="1"&&q.get("pid")===c){var r=f.closest("li.tl_file,li.tl_folder");if(r){r.classList.add("nfe-current");r.scrollIntoView({block:"center"});}}});})();';

    /**
     * The list of a back-end module in the sidebar: a button "Translate" at
     * every record of the listed table, which asks the editor to open the
     * window "Translate" for it (RecordVersions). It is one more operation
     * before "Edit" in Contao's own markup (an item of the operations list in
     * Contao 5.7, a link before the next one otherwise), with the extension's
     * icon in both colour schemes. Placeholders: the address of the editor,
     * the table, the label and the icons (JSON).
     */
    private const TRANSLATE_LIST = '(function(){var o=%s,t=%s,l=%s,i=%s;'
        .'document.querySelectorAll("a[href*=\\"act=edit\\"]").forEach(function(a){var u=new URL(a.href,location.href),id=u.searchParams.get("id");'
        .'if(!id||(u.searchParams.get("table")||t)!==t)return;var s=a.closest(".operations"),r=(s||a).parentNode.closest("tr,li,.tl_content,.tl_header,.tl_listing_container > div")||a.parentNode;'
        .'if(r.querySelector(".nfe-translate"))return;var e=a.querySelector("img"),b=document.createElement("a");b.href="#";b.className="nfe-translate has-icon";'
        .'[["light",i.light],["dark",i.dark]].forEach(function(v){var m=document.createElement("img");m.src=v[1];m.width=16;m.height=16;m.alt=l;m.className="color-scheme--"+v[0];'
        .'if(e&&e.hasAttribute("data-contao--tooltips-target"))m.setAttribute("data-contao--tooltips-target","tooltip");b.appendChild(m);});'
        .'if(!e||!e.hasAttribute("data-contao--tooltips-target"))b.title=l;'
        .'b.addEventListener("click",function(v){v.preventDefault();v.stopPropagation();parent.postMessage({type:"nfe:translate",table:t,id:Number(id)},o);});'
        .'if("LI"===a.parentNode.nodeName){var c=document.createElement("li");c.appendChild(b);a.parentNode.parentNode.insertBefore(c,a.parentNode);}'
        .'else{a.parentNode.insertBefore(b,a);a.parentNode.insertBefore(document.createTextNode(" "),a);}});})();';

    /** The call of Contao's popup script (be_main) on the document of the modal window's parent. */
    /**
     * Opens Contao's form at one field (nfe_field): its section unfolded, the
     * field scrolled to the top and focused, a TinyMCE text once Contao has
     * started its editor.
     */
    private const FOCUS_FIELD = '(function(){var f=new URLSearchParams(location.search).get("nfe_field");if(!f||!/^[a-z0-9_]+$/i.test(f))return;'
        .'var go=function(n){var e=document.getElementById("ctrl_"+f);if(!e){if(n<50)setTimeout(function(){go(n+1)},100);return;}'
        .'var s=e.closest("fieldset");if(s&&s.classList.contains("collapsed"))s.classList.remove("collapsed");'
        .'(e.closest(".widget")||e).scrollIntoView({block:"start"});'
        .'var m=window.tinymce&&tinymce.get("ctrl_"+f);if(m&&m.initialized){m.focus();return;}'
        .'if(e.tagName==="TEXTAREA"&&n<50){setTimeout(function(){go(n+1)},100);return;}e.focus();};go(0);})();';

    private const MODAL_FOOTER = "parent.document.getElements('.simple-modal-footer a.primary')";

    /** An empty MooTools collection: addClass() and removeClass() do nothing. */
    private const NO_MODAL_FOOTER = '$$([])';

    public function __construct(
        private readonly SiteOrigins $origins,
        private readonly RecordVersions $records,
        private readonly ElementAccess $access,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly Packages $packages,
        #[Autowire(service: 'contao.routing.response_context.csp_handler_factory')]
        private readonly CspHandlerFactory $cspHandlerFactory,
        #[Autowire('%contao.backend.route_prefix%')]
        private readonly string $backendPrefix,
    ) {
    }

    #[AsEventListener(KernelEvents::RESPONSE, priority: -1000)]
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $origin = strtolower(rtrim((string) $request->query->get(self::ORIGIN_PARAMETER, ''), '/'));
        $prefix = '/'.trim($this->backendPrefix, '/');

        if (
            !$event->isMainRequest()
            || '' === $origin
            || !$request->query->has('popup')
            || ($request->getPathInfo() !== $prefix && !str_starts_with($request->getPathInfo(), $prefix.'/'))
            || !str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')
            || !$this->origins->allows($origin)
        ) {
            return;
        }

        // Contao's popup script enables the button of the modal window in its parent
        // (MooTools). In the sidebar the parent is the website, which has neither:
        // the same call on an empty MooTools collection of the form's own page.
        $content = str_replace(self::MODAL_FOOTER, self::NO_MODAL_FOOTER, (string) $response->getContent());
        $position = strripos($content, '</body>');

        if (false === $position) {
            return;
        }

        $nonce = null;
        $styleNonce = null;
        $policy = (string) $response->headers->get('Content-Security-Policy', '');

        if (str_contains($policy, 'script-src') || str_contains($policy, 'style-src')) {
            $cspHandler = $this->cspHandlerFactory->create($policy);
            $nonce = str_contains($policy, 'script-src') ? $cspHandler->getNonce('script-src') : null;
            $styleNonce = str_contains($policy, 'style-src') ? $cspHandler->getNonce('style-src') : null;
            $cspHandler->applyHeaders($response, $request);
        }

        $tree = '';

        // Contao's page tree for a new page: only the "paste after/into" buttons, nothing that edits, publishes or moves pages.
        if (PageFormListener::TREE === $request->query->get(PageFormListener::VIEW_PARAMETER) && !$request->query->has('act')) {
            $tree = '<style'.(null !== $styleNonce ? ' nonce="'.htmlspecialchars($styleNonce, ENT_QUOTES).'"' : '').'>'
                .'#tl_buttons,.tl_listing .operations li:not(:has(form[action*="act=create"])),.tl_listing .operations-menu-container{display:none!important}'
                .'.tl_listing .tl_left a:not(.foldable){pointer-events:none;color:inherit}'
                .'.tl_listing .nfe-current{background:var(--active-bg);box-shadow:inset 3px 0 0 var(--contao)}'
                .'</style>';
        }

        $encodedOrigin = json_encode($origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $view = (string) $request->query->get(PageFormListener::VIEW_PARAMETER, '');
        $bridge = match ($view) {
            self::STYLE => \sprintf(self::STYLE_BRIDGE, $encodedOrigin),
            self::TEMPLATE => self::STUDIO_SCRIPT,
            default => '',
        };

        if (self::TEMPLATE === $view) {
            $tree .= \sprintf(self::STUDIO_STYLE, null !== $styleNonce ? ' nonce="'.htmlspecialchars($styleNonce, ENT_QUOTES).'"' : '');
        }

        // The list of a module whose records are translated on their own (Plus or Pro; the window says which).
        $listed = '' === $view && !$request->query->has('act') ? $this->listedTable($request) : null;

        if (null !== $listed) {
            $user = $this->security->getUser();
            $label = $this->translator->trans('editor.rec_translate', [], 'neo_frontend_editor', $user instanceof BackendUser ? (string) $user->language : null);
            $icons = ['light' => $this->packages->getUrl('bundles/neofrontendeditor/icons/translate.svg'), 'dark' => $this->packages->getUrl('bundles/neofrontendeditor/icons/translate--dark.svg')];
            $bridge .= \sprintf(self::TRANSLATE_LIST, $encodedOrigin, json_encode($listed, JSON_HEX_TAG | JSON_THROW_ON_ERROR), json_encode($label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR), json_encode($icons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        // Turbo runs scripts of every page it shows, so the message goes out after each save, too.
        $script = '<script'.(null !== $nonce ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"' : '').'>'
            .($tree ? self::MARK_CURRENT : '')
            .($request->query->has('nfe_field') ? self::FOCUS_FIELD : '')
            .$bridge
            .'parent.postMessage({type:"nfe:form",search:location.search},'.$encodedOrigin.');'
            .'</script>';

        $response->setContent(substr($content, 0, $position).$tree.$script.substr($content, $position));
    }

    /** The table a module's list shows (its "table" or the module's first one), when its records are translated on their own. */
    private function listedTable(Request $request): string|null
    {
        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditLanguages)) {
            return null;
        }

        $module = (string) $request->query->get('do', '');
        $table = (string) ($request->query->get('table') ?: (DcaRunner::moduleConfig($module)['tables'][0] ?? ''));

        return '' !== $table && $this->records->translatable($table) ? $table : null;
    }
}
