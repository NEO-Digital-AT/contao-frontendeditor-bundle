<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Posts;

use Contao\Controller;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DC_Table;
use Contao\Model;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Posts in the editor (Plus): news, events, FAQ and posts of extensions
 * (PostTypes). Lists with their state, publish/withdraw, duplicate, delete,
 * reorder (types sorted by hand), create; everything through Contao's own
 * operations of the post's back-end module, Contao's voters decide.
 */
final class PostEditor
{
    private const PASTE_AFTER = 1;

    private const PASTE_INTO = 2;

    private const LIMIT = 200;

    /** Fields of front-end modules that name the archives of the known types. */
    private const MODULE_FIELDS = ['tl_news' => 'news_archives', 'tl_calendar_events' => 'cal_calendar', 'tl_faq' => 'faq_categories'];

    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly PostTypes $types,
        private readonly ElementAccess $access,
        private readonly DcaRunner $runner,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'contao.routing.content_url_generator')]
        private readonly ContentUrlGenerator $urlGenerator,
    ) {
    }

    /**
     * The post types with the archives the user may open; archives shown on
     * the page come first and are marked.
     *
     * @return list<array<string, mixed>>
     */
    public function typesForPage(int $pageId): array
    {
        if (!$this->access->has(Permission::EditPosts)) {
            return [];
        }

        $modules = $this->modulesOnPage($pageId);
        $site = $this->siteOf($pageId);
        $result = [];

        foreach ($this->types->all() as $type) {
            $onPage = $this->archivesOnPage($type, $modules);
            $archives = [];

            foreach ($this->db->fetchAllAssociative('SELECT * FROM '.$this->db->quoteIdentifier($type['archiveTable']).' ORDER BY '.$this->db->quoteIdentifier($type['archiveTitle'])) as $archive) {
                if (!$this->granted($type['archiveTable'], new ReadAction($type['archiveTable'], $archive))) {
                    continue;
                }

                // Archives of another website of the installation (their reader page has another domain) are left out,
                // so a post cannot land in the wrong website. Archives without a reader page cannot be told apart.
                $reader = (int) ($archive['jumpTo'] ?? 0);

                if ($reader > 0 && !\in_array((int) $archive['id'], $onPage['archives'], true) && null !== $site && $this->siteOf($reader) !== $site) {
                    continue;
                }

                $archives[] = [
                    'id' => (int) $archive['id'],
                    'title' => (string) ($archive[$type['archiveTitle']] ?? $archive['id']),
                    'onPage' => \in_array((int) $archive['id'], $onPage['archives'], true),
                    'canCreate' => $this->granted($type['table'], new CreateAction($type['table'], ['pid' => (int) $archive['id']])),
                ];
            }

            if ([] === $archives) {
                continue;
            }

            usort($archives, static fn (array $a, array $b): int => $b['onPage'] <=> $a['onPage']);

            $result[] = [
                'table' => $type['table'],
                'archiveTable' => $type['archiveTable'],
                'module' => (string) $type['module'],
                'label' => $type['label'],
                'kind' => $type['kind'],
                'sortable' => $type['sortable'],
                'hasDate' => null !== $type['dateField'],
                'onPage' => $onPage['modules'],
                'archives' => $archives,
            ];
        }

        // The types shown on the page first.
        usort($result, static fn (array $a, array $b): int => ([] !== $b['onPage']) <=> ([] !== $a['onPage']));

        return $result;
    }

    /**
     * Posts of an archive, newest first (or in their order), with state and rights.
     *
     * @return list<array<string, mixed>>|null null when type or archive are not available
     */
    public function items(string $table, int $archiveId, string $search = ''): array|null
    {
        $type = $this->types->get($table);

        if (null === $type || !$this->access->has(Permission::EditPosts) || null === $this->archive($type, $archiveId)) {
            return null;
        }

        $order = $type['sortable'] ? 'sorting' : (null !== $type['dateField'] ? $this->db->quoteIdentifier($type['dateField']).' DESC' : 'id DESC');
        $title = $this->db->quoteIdentifier($type['titleField']);
        $sql = 'SELECT * FROM '.$this->db->quoteIdentifier($table).' WHERE pid = ? AND tstamp > 0';
        $params = [$archiveId];

        if ('' !== trim($search)) {
            $sql .= " AND $title LIKE ?";
            $params[] = '%'.addcslashes(trim($search), '%_\\').'%';
        }

        $rows = $this->db->fetchAllAssociative("$sql ORDER BY $order LIMIT ".self::LIMIT, $params);

        return array_values(array_filter(array_map(fn (array $row): array|null => $this->granted($table, new ReadAction($table, $row)) ? $this->item($type, $row) : null, $rows)));
    }

    /**
     * The posts whose content elements are on the current page (the reader of
     * a news, an event …), each with all of its elements.
     *
     * The post of a reader page is also found by its address (the item alias
     * after the page alias, as Contao's readers find it), so a post without
     * elements shows up, too.
     *
     * @param list<int>                                    $elementIds IDs of the elements found on the page
     * @param \Closure(array<string, mixed>): array<string, mixed> $element  data of one element (PageStructure::element)
     *
     * @return list<array<string, mixed>>
     */
    public function postsOfElements(array $elementIds, \Closure $element, int $pageId = 0, string $item = ''): array
    {
        if (!$this->access->has(Permission::EditPosts)) {
            return [];
        }

        $parents = [] === $elementIds ? [] : $this->db->fetchAllAssociative(
            "SELECT DISTINCT ptable, pid FROM tl_content WHERE id IN (?) AND ptable != 'tl_article' AND tstamp > 0",
            [$elementIds],
            [ArrayParameterType::INTEGER],
        );

        foreach ($this->postsByAddress($pageId, $item) as $parent) {
            if (!\in_array($parent, $parents, false)) {
                $parents[] = $parent;
            }
        }

        $posts = [];

        foreach ($parents as $parent) {
            $type = $this->types->get((string) $parent['ptable']);
            $row = null !== $type ? $this->db->fetchAssociative('SELECT * FROM '.$this->db->quoteIdentifier($type['table']).' WHERE id = ?', [(int) $parent['pid']]) : false;

            if (null === $type || false === $row || !$this->granted($type['table'], new ReadAction($type['table'], $row))) {
                continue;
            }

            // Posts without content elements (FAQ) still get their head with "Properties" on their page.
            $elements = $type['hasContent'] ? $this->db->fetchAllAssociative('SELECT * FROM tl_content WHERE ptable = ? AND pid = ? AND tstamp > 0 ORDER BY sorting', [$type['table'], (int) $row['id']]) : [];
            $archive = $this->archive($type, (int) $row['pid']);

            $posts[] = $this->item($type, $row) + [
                'table' => $type['table'],
                'typeLabel' => $type['label'],
                'archive' => null !== $archive ? (string) ($archive[$type['archiveTitle']] ?? '') : '',
                'canCreate' => $type['hasContent'] && $this->access->canCreateInParent($type['table'], (int) $row['id']),
                'elements' => array_map($element, $elements),
            ];
        }

        return $posts;
    }

    /**
     * Posts of archives whose reader is this page (field jumpTo), by alias or ID.
     *
     * @return list<array{ptable: string, pid: int}>
     */
    private function postsByAddress(int $pageId, string $item): array
    {
        if ($pageId < 1 || '' === $item) {
            return [];
        }

        $found = [];

        foreach ($this->types->all() as $type) {
            $archiveDca = $GLOBALS['TL_DCA'][$type['archiveTable']]['fields'] ?? [];

            if (!isset($archiveDca['jumpTo'])) {
                continue;
            }

            $hasAlias = isset($GLOBALS['TL_DCA'][$type['table']]['fields']['alias']);
            $id = $this->db->fetchOne(
                'SELECT p.id FROM '.$this->db->quoteIdentifier($type['table']).' p JOIN '.$this->db->quoteIdentifier($type['archiveTable']).' a ON a.id = p.pid'
                .' WHERE a.jumpTo = ? AND p.tstamp > 0 AND ('.($hasAlias ? 'p.alias = ? OR ' : '').'p.id = ?) LIMIT 1',
                $hasAlias ? [$pageId, $item, ctype_digit($item) ? (int) $item : 0] : [$pageId, ctype_digit($item) ? (int) $item : 0],
            );

            if (false !== $id) {
                $found[] = ['ptable' => $type['table'], 'pid' => (int) $id];
            }
        }

        return $found;
    }

    /**
     * A post shown in a list on a page (Stage 7: "Edit post" at the entry):
     * its state and rights, with the back-end module of its type.
     *
     * @return array<string, mixed>|null
     */
    public function listItem(string $table, int $id): array|null
    {
        $type = $this->types->get($table);
        $row = null !== $type && $this->access->has(Permission::EditPosts) ? $this->row($table, $id) : null;

        if (null === $type || null === $row || !$this->granted($table, new ReadAction($table, $row))) {
            return null;
        }

        return $this->item($type, $row) + ['table' => $table, 'module' => $type['module'], 'typeLabel' => $type['label']];
    }

    public function toggle(string $table, int $id): StructureResult
    {
        return $this->operate($table, $id, ['act' => 'toggle', 'id' => $id], function (DC_Table $dc) use ($table, $id): StructureResult {
            $dc->toggle($id, 'published', true);

            return StructureResult::done($id, ['published' => (bool) $this->db->fetchOne('SELECT published FROM '.$this->db->quoteIdentifier($table).' WHERE id = ?', [$id])]);
        });
    }

    /** Duplicates the post (with its elements) after the original; the copy is not published. */
    public function copy(string $table, int $id): StructureResult
    {
        return $this->operate($table, $id, ['act' => 'copy', 'mode' => self::PASTE_AFTER, 'pid' => $id, 'id' => $id], static function (DC_Table $dc): StructureResult {
            $newId = $dc->copy(true);

            return \is_numeric($newId) ? StructureResult::done((int) $newId) : StructureResult::invalid();
        });
    }

    public function delete(string $table, int $id): StructureResult
    {
        return $this->operate($table, $id, ['act' => 'delete', 'id' => $id], static function (DC_Table $dc) use ($id): StructureResult {
            $dc->delete(true);

            return StructureResult::done($id);
        });
    }

    /** Reorders a post of a type sorted by hand (FAQ): after another post, or to the top of its archive. */
    public function move(string $table, int $id, int|null $afterId): StructureResult
    {
        $type = $this->types->get($table);
        $row = null !== $type ? $this->row($table, $id) : null;

        if (null === $type || null === $row || !$type['sortable']) {
            return null === $row ? StructureResult::notFound() : StructureResult::invalid();
        }

        if (null !== $afterId && (int) ($this->row($table, $afterId)['pid'] ?? 0) !== (int) $row['pid']) {
            return StructureResult::notFound();
        }

        $query = null !== $afterId
            ? ['act' => 'cut', 'mode' => self::PASTE_AFTER, 'pid' => $afterId, 'id' => $id]
            : ['act' => 'cut', 'mode' => self::PASTE_INTO, 'pid' => (int) $row['pid'], 'id' => $id];

        return $this->operate($table, $id, $query, static function (DC_Table $dc) use ($id): StructureResult {
            $dc->cut(true);

            return StructureResult::done($id);
        });
    }

    /** New post in the archive; a draft (tstamp 0) until Contao's form saves it. */
    public function create(string $table, int $archiveId): StructureResult
    {
        $type = $this->types->get($table);

        if (null === $type || !$this->access->has(Permission::EditPosts)) {
            return StructureResult::forbidden();
        }

        if (null === $this->archive($type, $archiveId)) {
            return StructureResult::notFound();
        }

        return $this->runner->run($type['module'], $table, ['act' => 'create', 'mode' => self::PASTE_INTO, 'pid' => $archiveId, 'id' => $archiveId], function (DC_Table $dc) use ($table): StructureResult {
            $before = $this->newRecords($table);

            try {
                $dc->create();
            } catch (RedirectResponseException) {
                // Contao switches to its form after creating; the editor opens that form itself.
            }

            $created = array_values(array_diff($this->newRecords($table), $before));

            return [] === $created ? StructureResult::forbidden() : StructureResult::done((int) end($created));
        });
    }

    /**
     * What to pass to Contao's preview (/contao/preview?…) to show the post on
     * its page: Contao's own parameters for news and events, otherwise the path
     * from Contao's content URL resolvers. Null when the post has no page.
     */
    public function previewQuery(string $table, int $id): string|null
    {
        $known = ['tl_news' => 'news', 'tl_calendar_events' => 'calendar'];

        if (isset($known[$table])) {
            return $known[$table].'='.$id;
        }

        $this->framework->initialize();
        $class = Model::getClassFromTable($table);
        $model = class_exists($class) ? $class::findById($id) : null;

        if (null === $model) {
            return null;
        }

        try {
            $path = $this->urlGenerator->generate($model, [], UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (\Throwable) {
            return null;
        }

        $base = (string) $this->requestStack->getMainRequest()?->getBasePath();
        $path = '' !== $base && str_starts_with($path, $base) ? substr($path, \strlen($base)) : $path;

        return 'url='.rawurlencode(ltrim(preg_replace('#^/index\.php#', '', $path) ?? $path, '/'));
    }

    /** Whether Contao's form of the post was saved (a new post is a draft with tstamp 0 until then). */
    public function isSaved(string $table, int $id): bool
    {
        return null !== $this->types->get($table) && null !== $this->row($table, $id);
    }

    /** @return array<string, mixed>|null the post type of a table, for the forms of its module */
    public function type(string $table): array|null
    {
        return $this->types->get($table);
    }

    /**
     * @param array<string, int|string>        $query
     * @param \Closure(DC_Table): StructureResult $operation
     */
    private function operate(string $table, int $id, array $query, \Closure $operation): StructureResult
    {
        $type = $this->types->get($table);

        if (null === $type || !$this->access->has(Permission::EditPosts)) {
            return StructureResult::forbidden();
        }

        if (null === $this->row($table, $id)) {
            return StructureResult::notFound();
        }

        return $this->runner->run($type['module'], $table, $query, $operation);
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function item(array $type, array $row): array
    {
        $table = $type['table'];
        $copy = $row;
        unset($copy['id']);
        $now = time();
        $start = (int) ($row['start'] ?? 0);
        $stop = (int) ($row['stop'] ?? 0);
        $date = null !== $type['dateField'] ? (int) ($row[$type['dateField']] ?? 0) : 0;

        $status = match (true) {
            !$row['published'] => 'draft',
            $start > $now => 'planned',
            $stop > 0 && $stop <= $now => 'expired',
            default => 'published',
        };

        $config = $GLOBALS['TL_DCA'][$table]['config'] ?? [];

        return [
            'id' => (int) $row['id'],
            // Back-end module of the post: Contao's form of the post opens under it (do=…).
            'module' => (string) $type['module'],
            'archiveId' => (int) $row['pid'],
            'title' => StringUtil::decodeEntities((string) ($row[$type['titleField']] ?? '')),
            'date' => $date ?: null,
            'status' => $status,
            'start' => $start ?: null,
            'stop' => $stop ?: null,
            'published' => (bool) $row['published'],
            'canEdit' => !($config['notEditable'] ?? false) && $this->granted($table, new UpdateAction($table, $row)),
            'canToggle' => $this->access->canEditField('published', $table) && $this->granted($table, new UpdateAction($table, $row, ['published' => $row['published'] ? '' : '1'])),
            'canCopy' => !($config['notCopyable'] ?? false) && $this->granted($table, new CreateAction($table, $copy)),
            'canDelete' => !($config['notDeletable'] ?? false) && $this->granted($table, new DeleteAction($table, $row)),
            'canMove' => $type['sortable'] && !($config['notSortable'] ?? false) && $this->granted($table, new UpdateAction($table, $row, ['pid' => $row['pid'], 'sorting' => $row['sorting'] ?? 0])),
            'preview' => $this->previewQuery($table, (int) $row['id']),
        ];
    }

    /**
     * Front-end modules on the page: in its articles (element "module") and in its layout.
     *
     * @return list<array<string, mixed>>
     */
    private function modulesOnPage(int $pageId): array
    {
        $ids = $this->db->fetchFirstColumn(
            "SELECT c.module FROM tl_content c JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article' WHERE a.pid = ? AND c.type = 'module' AND c.invisible = ''",
            [$pageId],
        );

        $page = PageModel::findWithDetails($pageId);
        $layout = null !== $page ? $this->db->fetchOne('SELECT modules FROM tl_layout WHERE id = ?', [(int) $page->layout]) : false;

        foreach (StringUtil::deserialize($layout, true) as $entry) {
            if (!empty($entry['mod']) && !empty($entry['enable'])) {
                $ids[] = $entry['mod'];
            }
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        return [] === $ids ? [] : $this->db->fetchAllAssociative('SELECT * FROM tl_module WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
    }

    /** @var array<int, string|null> */
    private array $sites = [];

    /**
     * The website of a page: the domain of its root page (language versions of
     * one domain are one website); null when the page does not exist.
     */
    private function siteOf(int $pageId): string|null
    {
        if (!\array_key_exists($pageId, $this->sites)) {
            $page = PageModel::findWithDetails($pageId);
            $this->sites[$pageId] = null === $page ? null : strtolower(trim((string) $page->domain));
        }

        return $this->sites[$pageId];
    }

    /**
     * @param array<string, mixed>       $type
     * @param list<array<string, mixed>> $modules
     *
     * @return array{archives: list<int>, modules: list<string>}
     */
    private function archivesOnPage(array $type, array $modules): array
    {
        Controller::loadDataContainer('tl_module');
        $fields = isset(self::MODULE_FIELDS[$type['table']]) ? [self::MODULE_FIELDS[$type['table']]] : [];

        // Modules of extensions: fields that point to the archive table.
        foreach ($GLOBALS['TL_DCA']['tl_module']['fields'] ?? [] as $name => $field) {
            if (str_starts_with((string) ($field['foreignKey'] ?? ''), $type['archiveTable'].'.')) {
                $fields[] = $name;
            }
        }

        $archives = [];
        $names = [];

        foreach ($modules as $module) {
            foreach ($fields as $field) {
                $found = array_map('intval', StringUtil::deserialize($module[$field] ?? null, true));

                if ([] !== $found) {
                    $archives = [...$archives, ...$found];
                    $names[] = (string) $module['name'];
                }
            }
        }

        return ['archives' => array_values(array_unique($archives)), 'modules' => array_values(array_unique($names))];
    }

    /**
     * @param array<string, mixed> $type
     *
     * @return array<string, mixed>|null an archive the user may open
     */
    private function archive(array $type, int $archiveId): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.$this->db->quoteIdentifier($type['archiveTable']).' WHERE id = ?', [$archiveId]);

        return false !== $row && $this->granted($type['archiveTable'], new ReadAction($type['archiveTable'], $row)) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $id): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.$this->db->quoteIdentifier($table).' WHERE id = ? AND tstamp > 0', [$id]);

        return false === $row ? null : $row;
    }

    private function granted(string $table, CreateAction|ReadAction|UpdateAction|DeleteAction $action): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, $action);
    }

    /** @return list<int> */
    private function newRecords(string $table): array
    {
        $records = $this->requestStack->getSession()->getBag('contao_backend')->get('new_records') ?? [];

        return array_map('intval', $records[$table] ?? []);
    }
}
