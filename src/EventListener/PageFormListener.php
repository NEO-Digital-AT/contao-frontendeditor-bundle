<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\PageAlias;
use Neo\FrontendEditorBundle\Content\PageEditor;
use Neo\FrontendEditorBundle\License\Editions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Contao's form of a page, opened from the editor's sidebar, shows only what
 * the editor offers: for a new standard page its name (and the page type when
 * the user may choose between types), in the page settings name, alias, page
 * title, description and the publication dates (with Pro also indexing and
 * Contao's preview of the search result). Navigation, layout, cache and
 * access protection stay in the back end. The user's field permissions apply
 * as always; the form is only narrowed, never widened.
 */
#[AsHook('loadDataContainer')]
final class PageFormListener
{
    public const VIEW_PARAMETER = 'nfe_view';

    public const TREE = 'page_tree';

    public const SETTINGS = 'page_settings';

    private const NEW_PAGE_FIELDS = ['title', 'alias', 'type'];

    private const SETTINGS_FIELDS = ['title', 'alias', 'pageTitle', 'description', 'start', 'stop'];

    /** With Pro: indexing (robots) and Contao's own preview of the search result. */
    private const PRO_SETTINGS_FIELDS = ['robots', 'serpPreview'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly Connection $db,
        private readonly Editions $editions,
        private readonly PageEditor $pages,
    ) {
    }

    public function __invoke(string $table): void
    {
        $request = $this->requestStack->getMainRequest();

        if ('tl_page' !== $table || null === $request || !$request->query->has(FormBridgeListener::ORIGIN_PARAMETER) || !$request->query->has('popup')) {
            return;
        }

        $view = (string) $request->query->get(self::VIEW_PARAMETER, '');

        if (!\in_array($view, [self::TREE, self::SETTINGS], true)) {
            return;
        }

        $GLOBALS['TL_DCA']['tl_page']['config']['onload_callback'][] = function (DataContainer|null $dc) use ($view, $request): void {
            // The request, not Contao's Input (deprecated in Contao 6).
            if (null === $dc || 'edit' !== $request->query->get('act')) {
                return;
            }

            // The place of the new page is chosen: the page tree gets its own filter back, and the new page is
            // remembered, so the editor can remove it when its form is left without saving.
            if (self::TREE === $view) {
                $this->pages->restoreTreeNode();

                if (0 === (int) ($dc->getCurrentRecord()['tstamp'] ?? 1)) {
                    $this->pages->rememberDraft((int) $dc->id);
                }
            }

            $record = $dc->getCurrentRecord() ?? [];
            $type = (string) ($request->request->get('type') ?: ($record['type'] ?? 'regular'));

            // A new page of another type keeps Contao's form: its own fields (e.g. the target of a redirect) are required.
            if (self::TREE === $view && 'regular' !== $type) {
                return;
            }

            $fields = self::TREE === $view ? $this->newPageFields() : [...self::SETTINGS_FIELDS, ...($this->editions->hasPro((int) $dc->id) ? self::PRO_SETTINGS_FIELDS : [])];
            $palette = $GLOBALS['TL_DCA']['tl_page']['palettes'][$type] ?? null;

            if (\is_string($palette)) {
                $GLOBALS['TL_DCA']['tl_page']['palettes'][$type] = self::narrow($palette, $fields);
            }
        };

        // Without the alias field in the form, Contao's own alias callbacks still give the new page its address.
        $GLOBALS['TL_DCA']['tl_page']['config']['onsubmit_callback'][] = function (DataContainer $dc): void {
            PageAlias::ensure($dc, $this->db);
        };
    }

    /**
     * Keeps the given fields of a palette, in its order, with their legends
     * (opened); legends without fields are left out.
     *
     * @param list<string> $fields
     */
    public static function narrow(string $palette, array $fields): string
    {
        $boxes = [];

        foreach (explode(';', $palette) as $box) {
            $parts = array_map('trim', explode(',', $box));
            $legend = str_starts_with($parts[0] ?? '', '{') ? array_shift($parts) : null;
            $kept = array_values(array_filter($parts, static fn (string $field): bool => \in_array($field, $fields, true)));

            if ([] === $kept) {
                continue;
            }

            $boxes[] = implode(',', [...(null !== $legend ? [str_replace(':hide', '', $legend)] : []), ...$kept]);
        }

        return implode(';', $boxes);
    }

    /** @return list<string> the page type only for users who may choose between types */
    private function newPageFields(): array
    {
        $user = $this->security->getUser();
        $choice = $user instanceof BackendUser && ($user->isAdmin || \count(array_filter((array) $user->alpty)) > 1);

        // The alias (the address) right away, e.g. "index" for a page called "Home"; empty: Contao makes it from the name.
        return $choice ? self::NEW_PAGE_FIELDS : ['title', 'alias'];
    }
}
