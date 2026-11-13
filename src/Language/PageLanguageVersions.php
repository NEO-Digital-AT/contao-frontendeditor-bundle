<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\CoreBundle\EventListener\DataContainer\ContentCompositionListener;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Terminal42\ChangeLanguage\PageFinder;

/**
 * The language versions of a new page (Plus): the same page in the other
 * languages of its website, at the same place in each language's page tree,
 * linked as changelanguage links them (field languageMain: every version
 * points to the page of the main language, the language of the fallback root
 * page). When the new page is not in the main language, its version in the
 * main language is required, otherwise the versions could not be linked.
 *
 * The place in another language: after the counterpart of the page before
 * the new one, else as the first subpage of the counterpart of its parent
 * (the language's root page for top-level pages). Without a counterpart the
 * user chooses the parent page.
 *
 * Pages are created with Contao's own operation (DC_Table create in the module
 * "page"), so Contao's voters decide (edit page and page hierarchy of the
 * parent, pagemounts, page types, table permissions); they get Contao's alias
 * callbacks and Contao's article for new pages, and are not published.
 */
final class PageLanguageVersions
{
    private const PASTE_AFTER = 1;
    private const PASTE_INTO = 2;
    private const MAX_PARENTS = 400;

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly LanguageVersions $versions,
        private readonly TranslationService $translation,
        private readonly DcaRunner $runner,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'contao.slug')]
        private readonly Slug $slug,
        #[Autowire(service: 'contao.listener.data_container.content_composition')]
        private readonly ContentCompositionListener $articles,
    ) {
    }

    /**
     * What can be created for the page in the other languages, one entry per
     * language root page; empty when the website has one language.
     *
     * @return array{page: array<string, mixed>, languages: list<array<string, mixed>>, canLink: bool, canAlias?: bool}|null
     */
    public function plan(int $pageId): array|null
    {
        $this->framework->initialize();
        $page = PageModel::findWithDetails($pageId);

        if (null === $page || 'root' === $page->type || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, $pageId)) {
            return null;
        }

        $result = ['page' => ['id' => (int) $page->id, 'title' => StringUtil::decodeEntities((string) $page->title), 'language' => $this->versions->name((string) $page->language)], 'languages' => [], 'canLink' => false];
        [$roots, $master] = $this->roots($page);

        if ('regular' !== $page->type || null === $master || \count($roots) < 2) {
            return $result;
        }

        $result['canLink'] = $this->access->canEditField('languageMain', 'tl_page');
        // Without the field right for the alias Contao makes it from the title, as in its form.
        $result['canAlias'] = $this->access->canEditField('alias', 'tl_page');
        $canTitle = $this->access->canEditField('title', 'tl_page');
        $position = $this->position($page);
        $translate = $this->access->has(Permission::Translate);

        foreach ($roots as $root) {
            // Only the languages in which the page does not exist yet (also after a failed attempt).
            if ((int) $root->id === (int) $page->rootId || null !== $this->counterpart($pageId, (int) $root->id)) {
                continue;
            }

            $target = $this->target($position, (int) $root->id);
            [$title, $translated] = $this->proposeTitle((string) $page->title, (string) $page->language, (string) $root->language, $translate);
            $isMain = (int) $root->id === (int) $master->id;

            $result['languages'][] = [
                'root' => (int) $root->id,
                'language' => (string) $root->language,
                'name' => $this->versions->name((string) $root->language),
                'main' => $isMain,
                // The main language is needed to link the versions when the new page is in another language.
                'required' => $isMain && (int) $page->rootId !== (int) $master->id,
                'title' => $title,
                'translated' => $translated,
                'aliasProposal' => $this->slug->generate($title, (int) $root->id),
                'target' => $target,
                'missing' => null === $target ? $this->titleOf($position['after'] ?? $position['parent']) : null,
                'canCreate' => $canTitle && null !== $target && $this->canCreateIn($target['parent']),
                'parents' => null === $target ? $this->parents((int) $root->id) : [],
            ];
        }

        return $result;
    }

    /**
     * Creates the versions. Each item: root (ID of the language root page),
     * title, alias (empty: Contao makes it from the title), parent (only when
     * the plan found no place: the chosen parent page in that language).
     *
     * @param list<array<string, mixed>> $items
     */
    public function create(int $pageId, array $items): StructureResult
    {
        $plan = $this->plan($pageId);

        if (null === $plan || [] === $plan['languages']) {
            return StructureResult::notFound();
        }

        if (!$plan['canLink']) {
            return StructureResult::forbidden();
        }

        $byRoot = [];

        foreach ($plan['languages'] as $language) {
            $byRoot[$language['root']] = $language;
        }

        $wanted = [];

        foreach ($items as $item) {
            $root = (int) ($item['root'] ?? 0);

            if (!isset($byRoot[$root]) || !\is_string($item['title'] ?? null) || '' === trim($item['title']) || !\is_string($item['alias'] ?? '')) {
                return StructureResult::invalid();
            }

            $wanted[$root] = $item;
        }

        foreach ($byRoot as $root => $language) {
            if ($language['required'] && !isset($wanted[$root])) {
                return StructureResult::invalid();
            }
        }

        // The main language first: the other versions point to its page.
        uasort($wanted, static fn (array $a, array $b): int => (int) $byRoot[$b['root']]['main'] <=> (int) $byRoot[$a['root']]['main']);

        $page = PageModel::findWithDetails($pageId);
        $mainRootId = (int) array_key_first(array_filter($byRoot, static fn (array $language): bool => $language['main']));
        // Without a row for the main language the page is in it, or its page there exists already.
        $mainPageId = 0 === $mainRootId ? ($this->isInMainLanguage($page) ? $pageId : (int) $page->languageMain) : 0;
        $created = [];
        $errors = [];

        foreach ($wanted as $root => $item) {
            $language = $byRoot[$root];
            $target = $language['target'];

            if (null === $target) {
                $parent = (int) ($item['parent'] ?? 0);
                $target = $parent > 0 && $this->rootOf($parent) === $root ? ['mode' => 'into', 'pid' => $parent, 'parent' => $parent] : null;
            }

            if (null === $target || !$this->access->canEditField('title', 'tl_page') || !$this->canCreateIn($target['parent'])) {
                $errors[] = ['root' => $root, 'language' => $language['name'], 'reason' => null === $target ? 'place' : 'forbidden'];

                if ($language['main']) {
                    break;
                }

                continue;
            }

            $alias = ($plan['canAlias'] ?? false) ? trim((string) ($item['alias'] ?? '')) : '';
            $id = $this->createPage($target, trim((string) $item['title']), $alias, $language['main'] ? 0 : $mainPageId, (string) $page->type, $errors, $language);

            if (null === $id) {
                if ($language['main']) {
                    break;
                }

                continue;
            }

            if ($language['main']) {
                $mainPageId = $id;
            }

            $created[] = ['root' => $root, 'language' => $language['name'], 'id' => $id, 'title' => trim((string) $item['title']), 'alias' => (string) $this->db->fetchOne('SELECT alias FROM tl_page WHERE id = ?', [$id])];
        }

        // The new page itself points to the page of the main language when it is not in the main language.
        if ($mainPageId > 0 && $mainPageId !== $pageId && $mainPageId !== (int) $page->languageMain && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new UpdateAction('tl_page', $page->row(), ['languageMain' => $mainPageId]))) {
            $this->db->update('tl_page', ['languageMain' => $mainPageId, 'tstamp' => time()], ['id' => $pageId]);
        }

        return StructureResult::done($pageId, ['created' => $created, 'errors' => $errors]);
    }

    /**
     * @param array{mode: string, pid: int, parent: int} $target
     * @param list<array<string, mixed>>                 $errors
     * @param array<string, mixed>                       $language
     */
    private function createPage(array $target, string $title, string $alias, int $languageMain, string $type, array &$errors, array $language): int|null
    {
        $query = 'after' === $target['mode']
            ? ['act' => 'create', 'mode' => self::PASTE_AFTER, 'pid' => $target['pid']]
            : ['act' => 'create', 'mode' => self::PASTE_INTO, 'pid' => $target['pid']];
        $newId = null;

        $result = $this->runner->run('page', 'tl_page', $query, function (DC_Table $dc) use ($title, $alias, $languageMain, $type, &$newId, &$errors, $language): StructureResult {
            $before = $this->newRecords();

            try {
                $dc->create(['type' => $type, 'title' => $title]);
            } catch (RedirectResponseException) {
                // Contao switches to its form after creating; the page is completed here instead.
            }

            $created = array_values(array_diff($this->newRecords(), $before));

            if ([] === $created) {
                return StructureResult::forbidden();
            }

            $id = (int) end($created);
            // The ID as Contao keeps it in its list of new records (Contao compares it strictly, e.g. for the article).
            $dc->id = $this->rawNewRecord($id) ?? $id;

            // Contao's own alias callbacks: from the title when empty, unique, with the website's settings.
            try {
                $value = $alias;

                foreach ($GLOBALS['TL_DCA']['tl_page']['fields']['alias']['save_callback'] ?? [] as $callback) {
                    $value = (string) (\is_array($callback) ? System::importStatic($callback[0])->{$callback[1]}($value, $dc) : $callback($value, $dc));
                }
            } catch (\Throwable $exception) {
                $this->db->executeStatement('DELETE FROM tl_page WHERE id = ? AND tstamp = 0', [$id]);
                $errors[] = ['root' => $language['root'], 'language' => $language['name'], 'reason' => 'alias', 'message' => $exception->getMessage()];

                return StructureResult::invalid();
            }

            $this->db->update('tl_page', ['alias' => $value, 'title' => $title, 'published' => 0, 'languageMain' => $languageMain, 'tstamp' => time()], ['id' => $id]);

            // Contao's article for a new page, as on saving its form (column of the layout, permissions).
            $this->articles->generateArticleForPage($dc);
            $newId = $id;

            return StructureResult::done($id);
        });

        if (null === $newId && !array_filter($errors, static fn (array $error): bool => $error['root'] === $language['root'])) {
            $errors[] = ['root' => $language['root'], 'language' => $language['name'], 'reason' => match ($result->status->name) { 'Forbidden' => 'forbidden', default => 'failed' }];
        }

        return $newId;
    }

    /**
     * The language root pages of the website of the page and its main one.
     *
     * @return array{0: list<PageModel>, 1: PageModel|null}
     */
    private function roots(PageModel $page): array
    {
        if (!$this->versions->isAvailable()) {
            return [[], null];
        }

        $finder = new PageFinder();

        return [array_values($finder->findRootPagesForPage($page, false, false)), $finder->findMasterRootForPage($page)];
    }

    /** @return array{parent: int, before: list<int>, after: int|null} the parent and the pages before the new page, nearest first */
    private function position(PageModel $page): array
    {
        $before = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_page WHERE pid = ? AND sorting < ? AND tstamp > 0 ORDER BY sorting DESC', [(int) $page->pid, (int) $page->sorting]));

        return ['parent' => (int) $page->pid, 'before' => $before, 'after' => $before[0] ?? null];
    }

    /** @return array{mode: string, pid: int, parent: int, parentTitle: string, afterTitle: string|null}|null */
    private function target(array $position, int $rootId): array|null
    {
        $parent = $this->counterpart($position['parent'], $rootId);

        // After the nearest page before the new one that exists in this language, below the same parent.
        foreach ($position['before'] as $before) {
            $after = $this->counterpart($before, $rootId);

            if (null === $after) {
                continue;
            }

            $afterParent = (int) $this->db->fetchOne('SELECT pid FROM tl_page WHERE id = ?', [$after]);

            if (null === $parent || $afterParent === $parent) {
                return ['mode' => 'after', 'pid' => $after, 'parent' => $afterParent, 'parentTitle' => $this->titleOf($afterParent), 'afterTitle' => $this->titleOf($after)];
            }
        }

        if (null !== $parent) {
            return ['mode' => 'into', 'pid' => $parent, 'parent' => $parent, 'parentTitle' => $this->titleOf($parent), 'afterTitle' => null];
        }

        return null;
    }

    /** The page in the language of the root page that is linked to the page (changelanguage), or that root page itself for a root page. */
    private function counterpart(int $pageId, int $rootId): int|null
    {
        $row = $this->db->fetchAssociative('SELECT id, type, languageMain FROM tl_page WHERE id = ?', [$pageId]);

        if (false === $row) {
            return null;
        }

        if ('root' === $row['type']) {
            return $rootId;
        }

        $master = (new PageFinder())->findMasterRootForPage(PageModel::findWithDetails($pageId));
        $main = $this->rootOf($pageId) === (int) $master?->id ? $pageId : (int) $row['languageMain'];

        if ($main < 1) {
            return null;
        }

        if ($this->rootOf($main) === $rootId) {
            return $main;
        }

        foreach ($this->db->fetchFirstColumn('SELECT id FROM tl_page WHERE languageMain = ? AND tstamp > 0', [$main]) as $candidate) {
            if ($this->rootOf((int) $candidate) === $rootId) {
                return (int) $candidate;
            }
        }

        return null;
    }

    private function isInMainLanguage(PageModel $page): bool
    {
        return (int) $page->rootId === (int) (new PageFinder())->findMasterRootForPage($page)?->id;
    }

    private function rootOf(int $pageId): int
    {
        return (int) PageModel::findWithDetails($pageId)?->rootId;
    }

    private function titleOf(int $pageId): string
    {
        return StringUtil::decodeEntities((string) $this->db->fetchOne('SELECT title FROM tl_page WHERE id = ?', [$pageId]));
    }

    /** Whether Contao lets the user create a page in this parent (edit page and page hierarchy, pagemounts, types). */
    private function canCreateIn(int $parentId): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new CreateAction('tl_page', ['pid' => $parentId, 'sorting' => 0, 'type' => 'regular']));
    }

    /**
     * Pages of a language the user may create a page in, in tree order with their depth, for choosing a parent.
     *
     * @return list<array{id: int, title: string, depth: int}>
     */
    private function parents(int $rootId): array
    {
        $result = [];
        $walk = function (int $pid, int $depth) use (&$walk, &$result): void {
            foreach ($this->db->fetchAllAssociative("SELECT id, title FROM tl_page WHERE pid = ? AND tstamp > 0 AND type IN ('root', 'regular') ORDER BY sorting", [$pid]) as $row) {
                if (\count($result) >= self::MAX_PARENTS) {
                    return;
                }

                if ($this->canCreateIn((int) $row['id'])) {
                    $result[] = ['id' => (int) $row['id'], 'title' => StringUtil::decodeEntities((string) $row['title']), 'depth' => $depth];
                }

                $walk((int) $row['id'], $depth + 1);
            }
        };

        if ($this->canCreateIn($rootId)) {
            $result[] = ['id' => $rootId, 'title' => $this->titleOf($rootId), 'depth' => 0];
        }

        $walk($rootId, 1);

        return $result;
    }

    /** @return array{0: string, 1: bool} the title for the language and whether it is a translation proposal */
    private function proposeTitle(string $title, string $from, string $to, bool $translate): array
    {
        $title = StringUtil::decodeEntities($title);

        if (!$translate || $from === $to) {
            return [$title, false];
        }

        try {
            $proposal = trim((string) ($this->translation->translator()->translate([$title], $from, $to, false)[0] ?? ''));

            return '' === $proposal ? [$title, false] : [html_entity_decode($proposal, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true];
        } catch (\Throwable) {
            return [$title, false];
        }
    }

    private function rawNewRecord(int $id): int|string|null
    {
        $records = $this->requestStack->getSession()->getBag('contao_backend')->get('new_records') ?? [];

        foreach ($records['tl_page'] ?? [] as $record) {
            if ((int) $record === $id) {
                return $record;
            }
        }

        return null;
    }

    /** @return list<int> IDs Contao keeps as "new" in the back-end session (see DC_Table::create) */
    private function newRecords(): array
    {
        $records = $this->requestStack->getSession()->getBag('contao_backend')->get('new_records') ?? [];

        return array_map('intval', $records['tl_page'] ?? []);
    }
}
