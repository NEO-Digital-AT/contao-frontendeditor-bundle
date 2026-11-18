<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\Backend;
use Contao\Config;
use Contao\PageModel;
use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\Settings;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The editor view: when the browser opens a page of Contao's preview in edit
 * mode (EditMode), it gets a document of its own instead of the page, with the
 * editor's configuration and script. The script shows the sidebar and loads
 * the page into a frame next to it (EditMode::FRAME_PARAMETER), so the page
 * becomes narrower instead of being covered. The page is not rendered for this
 * document; Contao adds its preview bar to it as to every page of the preview.
 * Runs after Contao's checks of the page (PageAccessListener, priority 7): a
 * page that needs a member login is answered by Contao as before. Visitors
 * never get this document: without the preview there is no edit mode.
 */
#[AsEventListener(priority: -16)]
final class EditorViewListener
{
    /** Keys of the translation domain the editor shows (translations/neo_frontend_editor.*.yaml). */
    private const TEXT_KEYS = [
        'page', 'article', 'hidden', 'collapse', 'expand', 'sidebar', 'panel_pin', 'badge_all', 'badge_missing', 'badge_missing_title', 'all_pages', 'levels', 'open_tinymce', 'open_tinymce_title', 'open_source', 'html_editor', 'html_editor_title', 'move_toolbar', 'format_menu', 'structure', 'close_editor', 'back_to_structure', 'close',
        'edit', 'all_fields', 'all_fields_of', 'move', 'move_up', 'move_down', 'duplicate', 'hide', 'delete', 'more',
        'insert_element', 'create_article', 'edit_text', 'saved', 'dirty', 'not_saved', 'shortcuts', 'cancel', 'save',
        'headline_level', 'bold', 'italic', 'link', 'list', 'paragraph', 'choose_image', 'alt', 'save_failed', 'save_again',
        // Language versions (Plus) and writing aid (Pro)
        'languages', 'languages_of', 'main_language', 'translate_page', 'summary', 'article_missing', 'post_missing', 'parent_missing', 'apply_all',
        'status_current', 'status_outdated', 'status_missing', 'status_unlinked', 'outdated_short', 'mark_current',
        'adopt', 'adopt_main', 'adopt_main_denied', 'link_element', 'unlink', 'apply_save', 'discard', 'missing_in', 'no_counterpart', 'proposal_note',
        'writing_aid', 'writing_aid_of', 'ai', 'writing_aid_note', 'for', 'search_term', 'create_proposals', 'proposal_n',
        'apply', 'requests_left', 'plus', 'plus_intro', 'plus_languages', 'plus_translate', 'plus_outdated', 'enter_license',
        'changelanguage_missing', 'error.translate_not_configured', 'error.ai_not_configured', 'error.ai_limit_reached',
        'service_azure', 'service_deepl', 'service_ai',
        // Window "Translate" (Plus)
        'tr_window', 'tr_title', 'tr_into', 'tr_from', 'tr_swap', 'tr_direction', 'tr_propose_all', 'tr_propose', 'tr_element', 'tr_locked_place', 'tr_code_note', 'tr_image_note',
        'tr_nothing', 'tr_not_saved', 'tr_unsaved_text', 'tr_unsaved_proposals', 'tr_main_missing', 'tr_proposed', 'tr_open',
        'tr_proposals', 'tr_review', 'tr_page_done', 'tr_page_nothing', 'tr_page_missing', 'tr_page_only', 'tr_page_none',
        // Records translated on their own: posts, archives, the page's title and description, modules (Plus, Pro)
        'rec_translate', 'rec_subject', 'rec_link_label', 'rec_link', 'rec_suggested', 'rec_create', 'rec_archive_missing', 'rec_link_archive', 'rec_none',
        'rec_page_meta', 'rec_versions_created', 'rec_archive_translate', 'rec_outdated', 'rec_nothing', 'rec_linked', 'rec_pro',
        // Pages, posts and modules (Plus)
        'view_page', 'view_posts', 'view_modules', 'views', 'state_published', 'state_draft', 'state_planned', 'state_expired',
        'publish', 'withdraw', 'new_page', 'new_page_hint', 'page_more', 'page_settings', 'page_duplicate', 'page_delete',
        'page_withdraw_confirm', 'page_withdraw_confirm_children', 'page_delete_confirm', 'page_withdrawn', 'page_published',
        'post_kind', 'archive_news', 'archive_events', 'archive_faq', 'archive_other', 'on_page', 'order_hint', 'search',
        'search_posts', 'new_news', 'new_events', 'new_faq', 'new_other', 'open_on_page', 'properties', 'properties_of', 'form_no_module', 'session_expired', 'reload',
        'post_delete_confirm', 'post_withdraw_confirm', 'no_entries', 'no_post_types', 'loading', 'busy_working', 'busy_page', 'tr_translating', 'tr_page_progress', 'apply_progress', 'busy_saving', 'aid_busy', 'post', 'post_content',
        'modules_intro', 'modules_own', 'open', 'back_to_modules', 'no_modules', 'plus_pages', 'plus_posts', 'plus_modules',
        // Checks and screen sizes (Pro)
        'view_check', 'check', 'check_seo', 'check_a11y', 'check_again', 'checking', 'check_failed', 'pro', 'pro_intro',
        'pro_seo', 'pro_a11y', 'pro_screens', 'pro_writing_aid', 'score_good', 'score_ok', 'score_low', 'score_for',
        'score_no_term', 'keyword', 'keyword_hint', 'serp_caption', 'finding_problem', 'finding_improve',
        'finding_good', 'group_problem', 'group_improve', 'to_element', 'to_element_of', 'jump_hidden', 'marker', 'seo_title_missing',
        'seo_title_missing_text', 'seo_title_short', 'seo_title_long', 'seo_title_ok',
        'seo_description_missing', 'seo_description_missing_text', 'seo_description_short', 'seo_description_long',
        'seo_description_ok', 'seo_description_length_text', 'seo_h1_missing', 'seo_h1_missing_text', 'seo_h1_many',
        'seo_h1_many_text', 'seo_h1_ok', 'seo_h1_ok_text', 'seo_heading_skip', 'seo_heading_skip_text',
        'seo_headings_ok', 'seo_headings_ok_text', 'seo_words_few', 'seo_words_ok', 'seo_alt_missing',
        'seo_alt_ok', 'seo_alt_ok_text', 'seo_links_none', 'seo_links_none_text',
        'seo_links_ok', 'seo_noindex', 'seo_noindex_text', 'seo_index_ok', 'seo_index_ok_text',
        'seo_title_keyword', 'seo_title_keyword_ok', 'seo_description_keyword', 'seo_description_keyword_ok',
        'seo_h1_keyword', 'seo_h1_keyword_ok', 'seo_url_keyword', 'seo_url_keyword_ok', 'seo_paragraph_keyword',
        'seo_paragraph_keyword_ok', 'seo_keyword_in_text', 'seo_place_title', 'seo_place_description', 'seo_place_h1',
        'seo_place_url', 'seo_place_paragraph', 'seo_keyword_elsewhere', 'seo_keyword_elsewhere_text',
        'seo_keyword_unique', 'seo_keyword_unique_text', 'a11y_summary', 'a11y_note', 'a11y_none', 'a11y_in_content',
        'a11y_in_layout', 'a11y_layout_note', 'a11y_contrast', 'a11y_contrast_text', 'a11y_contrast_detail', 'a11y_alt',
        'a11y_alt_text', 'a11y_enter_alt', 'a11y_link_name', 'a11y_link_name_text', 'a11y_button_name',
        'a11y_button_name_text', 'a11y_heading_order', 'a11y_heading_order_text', 'a11y_set_level',
        'a11y_empty_heading', 'a11y_empty_heading_text', 'a11y_label', 'a11y_label_text', 'a11y_lang', 'a11y_lang_text',
        'a11y_frame_title', 'a11y_frame_title_text', 'screen_size', 'screen_computer', 'screen_tablet', 'screen_phone',
        'screen_tablet_name', 'screen_phone_name', 'screen_landscape', 'screen_rotate',
        // Page style (Pro)
        'style_menu', 'style_own', 'style_from', 'style_of', 'style_ready', 'style_preview', 'style_saved', 'style_kept', 'pro_style',
        // Templates, modules, layout, form fields (Basic)
        'info', 'info_of', 'id_label', 'templates', 'template_studio', 'template_studio_open', 'template_customize', 'template_edit_file',
        'source_contao', 'source_extension', 'source_custom', 'source_theme', 'source_unknown', 'style_hint_title', 'style_hint_class',
        'style_hint_own', 'style_hint_add', 'item_module', 'edit_texts', 'module_everywhere', 'included_everywhere',
        'edit_text', 'saves_into', 'needs_pro', 'no_permission', 'menu_text', 'menu_image', 'menu_link', 'article_named', 'article_settings', 'change_image', 'caption_text', 'remove_image', 'image_template', 'edit_link', 'edit_link_text', 'open_link', 'link_template', 'insert_before', 'insert_after', 'module_note', 'unsaved_title', 'unsaved_text', 'unsaved_cancel', 'change_unmapped',
        'reason_field', 'reason_record', 'reason_module', 'reason_unmapped', 'reason_navigation', 'reason_post',
        'reason_other', 'reason_template', 'item_field', 'item_comment', 'item_post', 'edit_module', 'edit_field',
        'edit_comment', 'edit_post', 'module_of', 'field_of', 'comment_of', 'page_templates', 'page_templates_of', 'page_templates_intro',
        'tpl_group_page', 'tpl_page_layout', 'tpl_group_modules', 'tpl_group_elements', 'tpl_group_parts',
        'layout_edit', 'layout_of', 'resize_panel', 'missing_title', 'missing_intro', 'missing_other',
        'field_insert_after', 'field_delete', 'field_new', 'field_delete_confirm', 'nested_content', 'nested_content_untitled',
        'guide', 'guide_title', 'guide_hint', 'guide_open', 'guide_select_title', 'guide_select', 'guide_links_title', 'guide_links',
        'guide_menu_title', 'guide_menu', 'guide_panel_title', 'guide_panel', 'guide_languages_title', 'guide_languages', 'guide_keys_title', 'guide_keys', 'follow_hint', 'key_ctrl', 'key_enter', 'key_delete', 'spelling_hint',
        'lv_title', 'lv_intro', 'lv_main', 'lv_main_badge', 'lv_name', 'lv_alias', 'lv_alias_hint', 'lv_place', 'lv_place_after', 'lv_place_first', 'lv_missing', 'lv_choose', 'lv_create', 'lv_skip', 'lv_proposal', 'lv_untranslated', 'lv_no_right', 'lv_no_link', 'lv_done', 'lv_failed', 'lv_reason_forbidden', 'lv_reason_place', 'lv_reason_failed', 'lv_finish', 'lv_retry', 'lv_auto', 'lv_auto_untranslated', 'lv_others', 'lv_open', 'lv_note', 'lv_name_missing',
    ];

    /** Contao's own texts the editor shows, where the back end says the same: key => [domain, ID]. */
    private const CONTAO_TEXT_KEYS = [
        'deleteConfirm' => ['contao_default', 'MSC.deleteConfirm'],
        'headline_field' => ['contao_tl_content', 'tl_content.headline.0'],
        'text_field' => ['contao_tl_content', 'tl_content.text.0'],
    ];

    /**
     * Icons of Contao's back-end theme the editor uses, by the name it uses
     * them under; "@name" for the extension's own where the theme has no such
     * symbol (translation, swapping both languages, the direction of a translation,
     * closing and opening the sidebar, pinning it beside the page).
     */
    private const ICONS = [
        'edit' => 'edit', 'editor' => 'editor', 'fields' => 'header', 'children' => 'children', 'close' => 'close', 'back' => 'back',
        'ok' => 'ok', 'error' => 'error', 'save' => 'save', 'image' => 'image', 'pickpage' => 'pickpage', 'collapse' => 'navcol',
        'drag' => 'drag', 'copy' => 'copy', 'hide' => 'invisible', 'delete' => 'delete', 'new' => 'new', 'more' => 'more',
        'article' => 'article', 'pagemounts' => 'pagemounts', 'languages' => 'root', 'translate' => '@translate', 'swap' => '@swap', 'arrow' => '@arrow', 'panel_close' => '@panel-close', 'panel_open' => '@panel-open', 'pin' => '@pin', 'sync' => 'sync', 'link' => 'pickpage',
        'page' => 'regular', 'posts' => 'articles', 'modules' => 'modules', 'visible' => 'visible', 'preview' => 'preview',
        'settings' => 'settings', 'help' => 'help', 'search' => 'search', 'monitor' => 'monitor', 'code' => 'code', 'info' => 'info', 'layout' => 'layout',
    ];

    /**
     * Icons of buttons without text that the theme of Contao 5.3 to 5.6 does not
     * have yet: the same symbol of the theme under another name, or "@name" for
     * the extension's own (public/icons/name.svg and name--dark.svg). Contao 6
     * has no "header" (edit the settings) any more; "wrench" is its symbol for
     * settings.
     */
    private const FALLBACKS = ['info' => 'show', 'help' => 'info', 'close' => '@close', 'more' => '@more', 'header' => 'wrench'];

    /**
     * Texts with a number whose wording depends on it (Symfony's plural forms,
     * "one|many" in the translation). The editor gets each form for a few
     * sample numbers and picks the one whose plural category (Intl.PluralRules)
     * is that of the number it shows.
     */
    private const PLURAL_KEYS = ['entries', 'group_good', 'seo_title_length_text', 'seo_words_text', 'seo_alt_missing_text', 'seo_links_ok_text', 'tpl_elements_count'];

    /** Numbers that cover the plural categories of the eleven languages (one, two, few, many, other). */
    private const PLURAL_SAMPLES = [0, 1, 2, 3, 4, 5, 11, 12, 21, 22, 25, 101, 102, 103, 105, 111];

    public function __construct(
        private readonly EditMode $editMode,
        private readonly Settings $settings,
        private readonly Packages $packages,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        #[Autowire('%contao.backend.route_prefix%')]
        private readonly string $backendPrefix,
        #[Autowire('%contao.web_dir%')]
        private readonly string $webDir,
        /** @var array<string, array{path?: string}> */
        #[Autowire('%contao.backend.icons%')]
        private readonly array $backendIcons = [],
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        $page = $request->attributes->get('pageModel');

        if (!$page instanceof PageModel || !$this->editMode->isDocumentRequest($request)) {
            return;
        }

        $editor = $this->editMode->editor($request);

        if (null === $editor) {
            return;
        }

        $locale = (string) $editor->language;
        $config = json_encode($this->config((int) $page->id, $locale), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = new Response($this->twig->render('@Contao/neo_frontend_editor/view.html.twig', [
            'language' => $locale,
            // Until the page in the frame is there; the editor then shows the page's own title.
            'title' => (string) ($page->pageTitle ?: $page->title),
            'config' => $config,
            'script' => $this->packages->getUrl('bundles/neofrontendeditor/editor.js'),
        ]));

        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        // What other listeners add to the view is taken out again, apart from Contao's preview bar (EditorViewCleanupListener).
        $request->attributes->set(EditorViewCleanupListener::ATTRIBUTE, $response->getContent());
        $event->setResponse($response);
    }

    /** @return array<string, mixed> */
    private function config(int $pageId, string $locale): array
    {
        $backend = $this->settings->backendUrl().'/'.trim($this->backendPrefix, '/');
        $texts = [];
        $plurals = [];

        foreach (self::TEXT_KEYS as $key) {
            $texts[$key] = $this->translator->trans('editor.'.$key, [], 'neo_frontend_editor', $locale);
        }

        foreach (self::CONTAO_TEXT_KEYS as $key => [$domain, $id]) {
            $texts[$key] = $this->translator->trans($id, [], $domain, $locale);
        }

        // The translator chooses the form for each sample; "{n}" in the text stays for the editor.
        foreach (self::PLURAL_KEYS as $key) {
            foreach (self::PLURAL_SAMPLES as $sample) {
                $plurals[$key][(string) $sample] = $this->translator->trans('editor.'.$key, ['%count%' => $sample], 'neo_frontend_editor', $locale);
            }
        }

        return [
            // The page the view was opened with; the editor follows the page in its frame.
            'page' => $pageId,
            'backend' => $backend,
            'api' => $backend.'/frontend-editor',
            'styles' => [$backend.'/frontend-editor/backend-styles', $this->packages->getUrl('bundles/neofrontendeditor/editor.css')],
            'cookie' => EditMode::COOKIE,
            'lang' => $locale,
            'texts' => $texts,
            'plurals' => $plurals,
            'icons' => $this->icons(),
            // Accessibility check (Pro): axe-core, loaded into the page only when the check runs.
            'axe' => $this->packages->getUrl('bundles/neofrontendeditor/axe/axe.min.js'),
            'axeLocale' => $this->axeLocale($locale),
            // Contao's rich text editor for the texts of the page (inline), where it is switched on (System › Settings).
            'tinymce' => Config::get('useRTE') ? $this->packages->getUrl('js/tinymce.min.js', 'contao-components/tinymce4') : null,
            'frame' => EditMode::FRAME_PARAMETER,
        ];
    }

    /**
     * Light and, where the theme has one, dark variant of each icon.
     *
     * @return array<string, array{light: string, dark: string|null}>
     */
    private function icons(): array
    {
        // Contao 5: the icons of the back-end theme. Contao 6 has no themes and
        // lists its icons with their public path in contao.backend.icons.
        $directory = method_exists(Backend::class, 'getTheme') ? '/system/themes/'.Backend::getTheme().'/icons/' : null;
        $icons = [];

        foreach (self::ICONS as $key => $name) {
            if (str_starts_with($name, '@')) {
                $icons[$key] = $this->fallbackIcon($directory, $name);

                continue;
            }

            $light = $this->iconPath($directory, $name.'.svg');

            if (null === $light && isset(self::FALLBACKS[$name])) {
                $icons[$key] = $this->fallbackIcon($directory, self::FALLBACKS[$name]);

                continue;
            }

            if (null === $light) {
                continue;
            }

            $icons[$key] = ['light' => $light, 'dark' => $this->iconPath($directory, $name.'--dark.svg')];
        }

        return array_filter($icons, static fn (array|null $icon): bool => null !== $icon);
    }

    /** @return array{light: string, dark: string|null}|null */
    private function fallbackIcon(string|null $directory, string $name): array|null
    {
        if (str_starts_with($name, '@')) {
            $file = 'bundles/neofrontendeditor/icons/'.substr($name, 1);

            return ['light' => $this->packages->getUrl($file.'.svg'), 'dark' => $this->packages->getUrl($file.'--dark.svg')];
        }

        $light = $this->iconPath($directory, $name.'.svg');

        return null === $light ? null : ['light' => $light, 'dark' => $this->iconPath($directory, $name.'--dark.svg')];
    }

    private function iconPath(string|null $directory, string $file): string|null
    {
        if (null === $directory) {
            $path = $this->backendIcons[$file]['path'] ?? null;

            return \is_string($path) ? '/'.ltrim($path, '/') : null;
        }

        return is_file($this->webDir.$directory.$file) ? $directory.$file : null;
    }

    /** axe-core's own translation of its messages, where it has one for the language. */
    private function axeLocale(string $locale): string|null
    {
        $language = strtolower(substr($locale, 0, 2));

        return is_file(\dirname(__DIR__, 2).'/public/axe/locales/'.$language.'.json') ? $this->packages->getUrl('bundles/neofrontendeditor/axe/locales/'.$language.'.json') : null;
    }
}
