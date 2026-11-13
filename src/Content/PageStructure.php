<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Fragment\FragmentCompositor;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\FilesModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\Ai\WritingAid;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Controller\StyleController;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\SourceHash;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Settings;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the editor needs to know about a page: its articles and content
 * elements in order, the values of the inline fields and, per element, what
 * the current user may change. Data on its way to the browser, hence arrays.
 */
final class PageStructure
{
    /** How deep elements in elements are shown (Contao allows any depth; pages rarely go beyond two). */
    private const MAX_NESTING = 5;

    private const SUMMARY_LENGTH = 80;

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly LanguageVersions $versions,
        private readonly ElementLinks $links,
        private readonly SourceHash $hashes,
        private readonly TranslationService $translation,
        private readonly WritingAid $writingAid,
        private readonly Settings $settings,
        private readonly PageEditor $pages,
        private readonly PageStyles $styles,
        private readonly StyleVoter $styleVoter,
        private readonly RecordAccess $records,
        private readonly InsertTagParser $insertTags,
        private readonly TextSources $textSources,
        private readonly ElementPlaces $places,
        #[Autowire(service: 'contao.fragment.compositor')]
        private readonly FragmentCompositor|null $compositor = null,
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $imageExtensions = [],
    ) {
    }

    /** @return array<string, mixed>|null null when the page does not exist or is outside the user's page mounts */
    public function forPage(int $pageId): array|null
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_content');
        System::loadLanguageFile('default');
        // Extensions name their element types in "modules" (e.g. comments).
        System::loadLanguageFile('modules');
        // The names of the fields edited on the page (textFields()).
        System::loadLanguageFile('tl_content');

        $page = PageModel::findById($pageId);

        if (null === $page || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, $pageId)) {
            return null;
        }

        $page->loadDetails();

        // Drafts (tstamp 0) are records whose form has not been saved yet; Contao does not show them either.
        $articles = $this->db->fetchAllAssociative(
            'SELECT * FROM tl_article WHERE pid = ? AND tstamp > 0 ORDER BY sorting',
            [$pageId],
        );

        $elements = [] === $articles ? [] : $this->db->fetchAllAssociative(
            "SELECT * FROM tl_content WHERE ptable = 'tl_article' AND pid IN (?) AND tstamp > 0 ORDER BY pid, sorting",
            [array_column($articles, 'id')],
            [ArrayParameterType::INTEGER],
        );

        $paid = $this->editionInfo($pageId);
        $translations = $this->translations($paid['languages'], $pageId, $elements);
        $byArticle = [];

        foreach ($elements as $element) {
            $byArticle[(int) $element['pid']][] = $this->element($element, (string) $page->language) + ['translation' => $translations[(int) $element['id']] ?? null];
        }

        $columns = $this->columns($pageId);
        $nested = [];

        foreach ($elements as $element) {
            array_push($nested, ...$this->nestedGroups([$element], (string) $page->language, (int) $element['pid'], $paid['languages'], $pageId));
        }

        return [
            'page' => [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'language' => (string) $page->language,
                'type' => (string) $page->type,
                'published' => (bool) $page->published,
                'start' => (int) $page->start ?: null,
                'stop' => (int) $page->stop ?: null,
                ...($paid['pages'] ? $this->pages->rights($pageId) : []),
                ...($paid['style'] ? ['style' => $this->style($page)] : []),
                ...($paid['design'] ? ['layout' => $this->layout($page)] : []),
            ],
            'permissions' => [
                'editContent' => $this->access->has(Permission::EditContent),
                'editStructure' => $this->access->has(Permission::EditStructure),
                'createArticles' => $this->access->has(Permission::CreateArticles),
            ],
            'editions' => $paid,
            // Layout columns that show articles, where the user may create one.
            'columns' => array_values(array_filter($columns, fn (array $column): bool => $this->access->canCreateArticle($pageId, $column['id']))),
            'articles' => array_map(fn (array $article): array => [
                'id' => (int) $article['id'],
                'title' => (string) $article['title'],
                'column' => (string) $article['inColumn'],
                'columnLabel' => $this->columnLabel($columns, (string) $article['inColumn']),
                'published' => (bool) $article['published'],
                'canCreate' => $this->access->canCreateIn((int) $article['id']),
                // Its settings in Contao's form (Contao's voters: back-end module and the page's article rights).
                'canEdit' => $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'article')
                    && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_article', new UpdateAction('tl_article', $article)),
                'elements' => $byArticle[(int) $article['id']] ?? [],
            ], $articles),
            // Elements that contain elements (accordion, slider, element group …), each with its elements, in page order.
            'nested' => $nested,
        ];
    }

    /**
     * Groups of nested elements: for each element whose type may contain
     * elements (Contao's nested fragments), the elements in it, followed by
     * the groups of those elements (depth-first, as on the page).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $languages the language versions of the page (Plus), for the states of translations
     *
     * @return list<array<string, mixed>>
     */
    private function nestedGroups(array $rows, string $language, int $articleId, array $languages, int $pageId, int $depth = 0): array
    {
        $containers = array_values(array_filter($rows, fn (array $row): bool => $this->supportsNesting((string) $row['type'])));

        if ([] === $containers || $depth >= self::MAX_NESTING) {
            return [];
        }

        $children = $this->db->fetchAllAssociative(
            "SELECT * FROM tl_content WHERE ptable = 'tl_content' AND pid IN (?) AND tstamp > 0 ORDER BY pid, sorting",
            [array_map(static fn (array $row): int => (int) $row['id'], $containers)],
            [ArrayParameterType::INTEGER],
        );
        $byParent = [];
        $translations = $this->translations($languages, $pageId, $children);

        foreach ($children as $child) {
            $byParent[(int) $child['pid']][] = $child;
        }

        $groups = [];

        foreach ($containers as $row) {
            $id = (int) $row['id'];
            $headline = StringUtil::deserialize($row['headline'] ?? '', true);
            $kids = $byParent[$id] ?? [];

            $groups[] = [
                'id' => 'n:'.$id,
                'kind' => 'nested',
                'parentId' => $id,
                'article' => $articleId,
                'typeLabel' => (string) ($GLOBALS['TL_LANG']['CTE'][$row['type']][0] ?? $row['type']),
                'title' => StringUtil::decodeEntities((string) ($headline['value'] ?? '')),
                'canCreate' => $this->access->canCreateInParent('tl_content', $id),
                'elements' => array_map(fn (array $child): array => $this->element($child, $language) + ['parent' => $id, 'translation' => $translations[(int) $child['id']] ?? null], $kids),
            ];

            array_push($groups, ...$this->nestedGroups($kids, $language, $articleId, $languages, $pageId, $depth + 1));
        }

        return $groups;
    }

    /** Whether elements of this type contain elements (Contao's nested fragments). */
    private function supportsNesting(string $type): bool
    {
        return null !== $this->compositor && $this->compositor->supportsNesting('contao.content_element.'.$type);
    }

    /** Loads what element() needs (labels of the element types, the DCA) outside of forPage(). */
    public function prepare(): void
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_content');
        System::loadLanguageFile('default');
        // Extensions name their element types in "modules" (e.g. comments).
        System::loadLanguageFile('modules');
        // The names of the fields edited on the page (textFields()).
        System::loadLanguageFile('tl_content');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function element(array $row, string $language): array
    {
        $type = (string) $row['type'];
        $fields = [];
        $canUpdate = $this->access->canUpdate($row);

        if ($canUpdate) {
            $fields = array_values(array_filter(Palette::inlineFields($type), $this->access->canEditField(...)));

            // Alt text and caption need "overwrite metadata", which is switched on when they are saved.
            if (!$row['overwriteMeta'] && !$this->access->canEditField('overwriteMeta')) {
                $fields = array_values(array_diff($fields, ['alt', 'caption']));
            }
        }

        $headline = StringUtil::deserialize($row['headline'] ?? '', true);
        $image = $this->image($row, $language);

        return [
            'id' => (int) $row['id'],
            'article' => (int) $row['pid'],
            'type' => $type,
            'typeLabel' => (string) ($GLOBALS['TL_LANG']['CTE'][$type][0] ?? $type),
            'invisible' => (bool) $row['invisible'],
            'summary' => $this->summary((string) ($headline['value'] ?? ''), (string) ($row['text'] ?? ''), $image),
            'canUpdate' => $canUpdate,
            ...$this->access->structureRights($row),
            'fields' => $fields,
            'sources' => $this->sources($row, $canUpdate),
            'images' => $this->imageFields($row, $canUpdate),
            'links' => $this->linkFields($row, $canUpdate),
            'values' => [
                'headline' => ['value' => (string) ($headline['value'] ?? ''), 'unit' => (string) ($headline['unit'] ?? 'h2')],
                'text' => (string) ($row['text'] ?? ''),
                'image' => $image,
            ],
        ];
    }

    /**
     * What the paid editions offer on this page for this user. Without a
     * licence, "upgrade" says whether a hint on Plus makes sense here: the page
     * has language versions and the user may edit them.
     *
     * @return array<string, mixed>
     */
    private function editionInfo(int $pageId): array
    {
        $edition = $this->editions->edition($pageId);
        $plus = \in_array($edition, [Editions::PLUS, Editions::PRO], true);
        $pro = Editions::PRO === $edition;
        $mayLanguages = $this->access->has(Permission::EditLanguages);
        $versions = $mayLanguages ? $this->versions->versions($pageId) : [];

        return [
            'plus' => $plus,
            'pro' => $pro,
            'languages' => $plus && \count($versions) > 1 ? $versions : [],
            'changelanguageMissing' => $plus && $mayLanguages && !$this->versions->isAvailable() && $this->rootLanguages() > 1,
            'translate' => $plus && $this->access->has(Permission::Translate),
            'translateReady' => $this->translation->isReady(),
            // Named at every proposal: a machine translation by this service.
            'translateService' => $this->translation->service(),
            'writingAid' => $pro && $this->access->has(Permission::EditContent),
            'writingAidReady' => $this->writingAid->isConfigured() && $this->settings->aiDailyLimit() > 0,
            'writingAidLeft' => $pro ? $this->settings->aiRequestsLeft() : 0,
            'writingAidLimit' => $pro ? $this->settings->aiDailyLimit() : 0,
            'upgrade' => !$plus && \count($versions) > 1,
            // Pages, posts and modules (Plus): offered to users with the switch; without a licence as a hint.
            'pages' => $plus && $this->access->has(Permission::EditPages),
            'posts' => $plus && $this->access->has(Permission::EditPosts),
            'modules' => $plus && $this->access->has(Permission::OpenModules),
            'hintPages' => !$plus && $this->access->has(Permission::EditPages),
            'hintPosts' => !$plus && $this->access->has(Permission::EditPosts),
            'hintModules' => !$plus && $this->access->has(Permission::OpenModules),
            // Checks and screen sizes (Pro): for everyone who may use the editor; without Pro as a hint.
            'check' => $pro,
            'hintCheck' => !$pro,
            // Page style (Pro): for users with the switch; with Plus only as a hint.
            'style' => $pro && $this->access->has(Permission::EditStyles),
            'hintStyle' => $plus && !$pro && $this->access->has(Permission::EditStyles),
            // Templates, modules, layout, form fields (Basic): for web designers with the switch.
            'design' => $this->access->has(Permission::EditDesign),
            'canOpenAccess' => AccessPage::canOpen(),
        ];
    }

    /**
     * The style of the page: whether the user may change it, whether the page
     * has its own, and the page above it whose style applies to it, too.
     *
     * @return array{can: bool, own: bool, from: string|null}
     */
    private function style(PageModel $page): array
    {
        $token = $this->security->getToken();
        $styles = $this->styles->forPage($page);
        $inherited = array_values(array_filter($styles, static fn (array $style): bool => !$style['own']));

        return [
            'can' => null !== $token
                && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, StyleController::MODULE)
                && $this->styleVoter->mayStyle($token, (int) $page->id),
            'own' => [] !== array_filter($styles, static fn (array $style): bool => $style['own']),
            'from' => [] === $inherited ? null : $inherited[\count($inherited) - 1]['title'],
        ];
    }

    /**
     * The layout of the page and whether the user may change it in Contao's
     * form (module "Themes" and the permission for the layout).
     *
     * @return array{id: int, name: string, canEdit: bool}|null
     */
    private function layout(PageModel $page): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_layout WHERE id = ?', [(int) $page->layout]);

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            ...$this->records->check('tl_layout', $row),
        ];
    }

    private function rootLanguages(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(DISTINCT language) FROM tl_page WHERE type = 'root'");
    }

    /**
     * State of the translation of one element, as in the structure (null in the
     * main language): for the element a save answers with, so the editor keeps
     * its state and its entry "Open translation window". Any element of an
     * article or a post, also inside an element (ElementPlaces).
     *
     * @param array<string, mixed> $element
     *
     * @return array{status: string, main: int|null}|null
     */
    public function translationOf(array $element): array|null
    {
        $pageId = $this->places->pageOf($element);

        return $this->translations($this->languagesOf($pageId), $pageId, [$element])[(int) $element['id']] ?? null;
    }

    /**
     * The language versions of a page for the states of translations: with
     * Plus, the switch and more than one language; otherwise none.
     *
     * @return list<array<string, mixed>>
     */
    private function languagesOf(int $pageId): array
    {
        if ($pageId < 1 || !$this->access->has(Permission::EditLanguages) || !$this->editions->hasPlus($pageId)) {
            return [];
        }

        $versions = $this->versions->versions($pageId);

        return \count($versions) > 1 ? $versions : [];
    }

    /**
     * State of the translation of each element of a language version: current,
     * outdated (its element in the main language changed since) or unlinked;
     * on the page of the main language: all (a current version in every
     * other language), outdated (a version is out of date) or missing.
     *
     * @param list<array<string, mixed>> $languages the language versions of the page $pageId
     * @param list<array<string, mixed>> $elements  elements of the page (any container)
     *
     * @return array<int, array{status: string, main: int|null}>
     */
    private function translations(array $languages, int $pageId, array $elements): array
    {
        $current = array_values(array_filter($languages, static fn (array $version): bool => $version['main']));

        if ([] === $languages || [] === $elements) {
            return [];
        }

        // On the page of the main language: in how many of the other languages each element has its version.
        if (($current[0]['id'] ?? null) === $pageId) {
            $others = \count($languages) - 1;
            $byMain = $this->links->ofMains(array_map('intval', array_column($elements, 'id')));
            $states = [];

            foreach ($elements as $element) {
                $rows = $byMain[(int) $element['id']] ?? [];
                $linked = \count(array_unique(array_column($rows, 'language')));
                $hash = $this->hashes->of($element);
                $outdated = [] !== array_filter($rows, static fn (array $row): bool => (string) $row['source_hash'] !== $hash);
                $status = $linked < $others ? 'missing' : ($outdated ? 'outdated' : 'all');
                $states[(int) $element['id']] = ['status' => $status, 'main' => null, 'linked' => $linked, 'versions' => $others];
            }

            return $states;
        }

        $links = $this->links->ofElements(array_map('intval', array_column($elements, 'id')));
        $mains = [] === $links ? [] : $this->db->fetchAllAssociative('SELECT * FROM tl_content WHERE id IN (?)', [array_map('intval', array_column($links, 'main'))], [ArrayParameterType::INTEGER]);
        $mainHashes = [];

        foreach ($mains as $main) {
            $mainHashes[(int) $main['id']] = $this->hashes->of($main);
        }

        $states = [];

        foreach ($elements as $element) {
            $link = $links[(int) $element['id']] ?? null;
            $hash = null !== $link ? $mainHashes[(int) $link['main']] ?? null : null;

            $states[(int) $element['id']] = null === $hash
                ? ['status' => 'unlinked', 'main' => null]
                : ['status' => $hash === (string) $link['source_hash'] ? 'current' : 'outdated', 'main' => (int) $link['main']];
        }

        return $states;
    }

    /**
     * The columns of the page's layout that show articles, from the options of
     * the article field "column" (Contao's own options callback), with their
     * names from the language files and the layout's own sections.
     *
     * @return list<array{id: string, label: string}>
     */
    private function columns(int $pageId): array
    {
        Controller::loadDataContainer('tl_article');
        System::loadLanguageFile('tl_article');

        $callback = $GLOBALS['TL_DCA']['tl_article']['fields']['inColumn']['options_callback'] ?? null;
        $dc = new EditorDataContainer('tl_article', ['id' => 0, 'pid' => $pageId], 'inColumn');

        $options = match (true) {
            \is_array($callback) => System::importStatic($callback[0])->{$callback[1]}($dc),
            \is_callable($callback) => $callback($dc),
            default => (array) ($GLOBALS['TL_DCA']['tl_article']['fields']['inColumn']['options'] ?? ['main']),
        };

        // Names of the layout's own sections, as Backend::addCustomLayoutSectionReferences() adds them.
        $labels = (array) ($GLOBALS['TL_LANG']['COLS'] ?? []);

        foreach ($this->db->fetchFirstColumn("SELECT sections FROM tl_layout WHERE sections != ''") as $sections) {
            foreach (StringUtil::deserialize($sections, true) as $section) {
                if (!empty($section['id'])) {
                    $labels[$section['id']] = (string) $section['title'];
                }
            }
        }

        $columns = [];

        foreach ($options as $key => $value) {
            $id = (string) (\is_int($key) ? $value : $key);
            $label = $labels[$id] ?? (\is_int($key) ? $id : $value);
            $columns[] = ['id' => $id, 'label' => (string) (\is_array($label) ? $label[0] : $label)];
        }

        return $columns;
    }

    /** @param list<array{id: string, label: string}> $columns */
    private function columnLabel(array $columns, string $id): string
    {
        foreach ($columns as $column) {
            if ($column['id'] === $id) {
                return $column['label'];
            }
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function image(array $row, string $language): array|null
    {
        if ('' === (string) ($row['singleSRC'] ?? '')) {
            return null;
        }

        $file = FilesModel::findByUuid($row['singleSRC']);
        $meta = $file?->getMetadata($language);

        return [
            'uuid' => StringUtil::binToUuid($row['singleSRC']),
            'path' => (string) $file?->path,
            'overwriteMeta' => (bool) $row['overwriteMeta'],
            // The values the website shows: the element's own with "overwrite metadata", else the file's.
            'alt' => $row['overwriteMeta'] ? (string) $row['alt'] : (string) $meta?->getAlt(),
            'caption' => $row['overwriteMeta'] ? (string) $row['caption'] : (string) $meta?->getCaption(),
        ];
    }

    /**
     * The texts of the element on the page, with their source: the text fields
     * of its palette (one source per row of a list and cell of a table) and
     * those of its records in child tables (SourceMap\TextSources). Each says
     * whether the user may change it there, decided by Contao: the right to
     * change the record (voters) and the field (allowed fields of the user
     * group); otherwise why not.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    private function sources(array $row, bool $canUpdate): array
    {
        $records = [['table' => 'tl_content', 'row' => $row, 'child' => false, 'included' => null]];

        // "Include element": the texts are the original element's, changed there (it may be shown on other pages too).
        $original = 'alias' === $row['type'] && $row['cteAlias'] ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [(int) $row['cteAlias']]) : false;

        if (false !== $original) {
            $records[] = ['table' => 'tl_content', 'row' => $original, 'child' => false, 'included' => (int) $original['id']];
        }

        foreach ($this->textSources->children((int) $row['id']) as $child) {
            $records[] = $child + ['child' => true, 'included' => null];
        }

        foreach (false !== $original ? $this->textSources->children((int) $original['id']) : [] as $child) {
            $records[] = $child + ['child' => true, 'included' => (int) $original['id']];
        }

        $sources = [];
        $metaAllowed = $row['overwriteMeta'] || $this->access->canEditField('overwriteMeta');

        foreach ($records as ['table' => $table, 'row' => $record, 'child' => $child, 'included' => $included]) {
            $recordAllowed = null !== $included ? $this->access->canUpdate($record) : $canUpdate && (!$child || $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $record)));

            foreach ($this->textSources->of($table, $record) as $source) {
                $field = $source['field'];
                $reason = match (true) {
                    !$recordAllowed => 'record',
                    !$this->access->canEditField($field, $table) => 'field',
                    !$child && \in_array($field, ['alt', 'caption'], true) && !$metaAllowed => 'field',
                    default => null,
                };

                // The keys of the source are replaced where they are given here (value).
                $sources[] = array_replace($source, [
                    // Contao's label of the field, in the language of the back-end user: the text toolbar names it.
                    'label' => $this->textSources->label($table, $field, $source['row'], $source['col']),
                    'scope' => $child ? 'child' : (null !== $included ? 'element' : 'own'),
                    'owner' => $included ?? ($child ? (int) $row['id'] : null),
                    'included' => null !== $included,
                    'editable' => null === $reason,
                    'reason' => $reason,
                    // Rich text as stored (HTML with insert tags); plain text as Contao's form shows it.
                    'value' => 'rich' === $source['kind'] ? $source['value'] : StringUtil::decodeEntities($source['value']),
                    'shown' => $this->insertTags->replaceInline($source['value']),
                ]);
            }
        }

        return $sources;
    }

    /**
     * The image pickers of the element (also an image its template shows as a background), with the chosen file
     * and whether the user may change it (Contao's field permission).
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{field: string, label: string, uuid: string, path: string, editable: bool}>
     */
    private function imageFields(array $row, bool $canUpdate): array
    {
        $images = [];

        foreach (Palette::imageFields('tl_content', $row, $this->imageExtensions) as $field) {
            $value = (string) ($row[$field] ?? '');
            $uuid = '' !== $value ? (Validator::isStringUuid($value) ? $value : StringUtil::binToUuid($value)) : '';
            $file = '' !== $uuid ? FilesModel::findByUuid($uuid) : null;
            $label = $GLOBALS['TL_LANG']['tl_content'][$field] ?? $GLOBALS['TL_DCA']['tl_content']['fields'][$field]['label'] ?? $field;

            $images[] = [
                'field' => $field,
                'label' => (string) (\is_array($label) ? ($label[0] ?? $field) : $label),
                'uuid' => $uuid,
                'path' => (string) $file?->path,
                'editable' => $canUpdate && $this->access->canEditField($field),
                // Whether the field may be emptied ("remove image").
                'mandatory' => (bool) ($GLOBALS['TL_DCA']['tl_content']['fields'][$field]['eval']['mandatory'] ?? false),
            ];
        }

        return $images;
    }

    /**
     * The link fields of the element (Contao's link picker or a URL), with the stored value, the address the page
     * shows for it (insert tags replaced) and whether the user may change it.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{field: string, label: string, value: string, href: string, editable: bool}>
     */
    private function linkFields(array $row, bool $canUpdate): array
    {
        $links = [];

        foreach (Palette::linkFields('tl_content', $row) as $field) {
            $value = (string) ($row[$field] ?? '');

            if ('' === trim($value)) {
                continue;
            }

            $label = $GLOBALS['TL_LANG']['tl_content'][$field] ?? $GLOBALS['TL_DCA']['tl_content']['fields'][$field]['label'] ?? $field;

            try {
                $href = $this->insertTags->replaceInline($value);
            } catch (\Throwable) {
                $href = $value;
            }

            $links[] = [
                'field' => $field,
                'label' => (string) (\is_array($label) ? ($label[0] ?? $field) : $label),
                'value' => $value,
                'href' => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'editable' => $canUpdate && $this->access->canEditField($field),
            ];
        }

        return $links;
    }

    /** @param array<string, mixed>|null $image */
    private function summary(string $headline, string $text, array|null $image): string
    {
        $summary = '' !== trim($headline) ? $headline : trim(strip_tags(str_replace('><', '> <', $text)));

        if ('' === $summary && null !== $image) {
            $summary = '' !== $image['alt'] ? $image['alt'] : basename($image['path']);
        }

        $summary = html_entity_decode((string) preg_replace('/\s+/', ' ', $summary), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strlen($summary) > self::SUMMARY_LENGTH ? rtrim(mb_substr($summary, 0, self::SUMMARY_LENGTH - 1)).'…' : $summary;
    }
}
