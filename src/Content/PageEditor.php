<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DC_Table;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Pages in the editor (Plus): publish and withdraw, duplicate, delete
 * (without subpages), and the preparation of Contao's page tree for a new
 * page. Everything runs through Contao's own operations of the page module;
 * the switch "create and publish pages" narrows, Contao's voters decide.
 * The position of pages in the tree (the navigation) is not changed here.
 */
final class PageEditor
{
    /** Where the filter of the page tree is kept while the editor's tree for a new page is open. */
    private const NODE_BEFORE = 'nfe_page_node_before';

    /** New pages whose form the editor opened (session attribute). */
    private const DRAFTS = 'nfe_page_drafts';

    private const PASTE_AFTER = 1;

    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly DcaRunner $runner,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly PageTreeClipboard $clipboard,
    ) {
    }

    /**
     * What the user may do with the page, for the bar above the structure.
     *
     * @return array{canPublish: bool, canCreate: bool, canCopy: bool, canDelete: bool, canSettings: bool, hasSubpages: bool}
     */
    public function rights(int $pageId): array
    {
        $page = $this->page($pageId);
        $none = ['canPublish' => false, 'canCreate' => false, 'canCopy' => false, 'canDelete' => false, 'canSettings' => false, 'hasSubpages' => false];

        if (null === $page || !$this->access->has(Permission::EditPages)) {
            return $none;
        }

        $this->framework->initialize();
        Controller::loadDataContainer('tl_page');

        $config = $GLOBALS['TL_DCA']['tl_page']['config'] ?? [];
        $hasSubpages = false !== $this->db->fetchOne('SELECT id FROM tl_page WHERE pid = ? AND tstamp > 0 LIMIT 1', [$pageId]);
        $copy = $page;
        unset($copy['id']);

        return [
            'canPublish' => $this->access->canEditField('published', 'tl_page')
                && $this->granted(new UpdateAction('tl_page', $page, ['published' => $page['published'] ? '' : '1'])),
            'canCreate' => !($config['closed'] ?? false) && !($config['notCreatable'] ?? false) && $this->granted(new CreateAction('tl_page')),
            'canCopy' => !($config['closed'] ?? false) && !($config['notCopyable'] ?? false) && 'root' !== $page['type']
                && $this->granted(new CreateAction('tl_page', $copy)),
            'canDelete' => !$hasSubpages && !($config['notDeletable'] ?? false) && 'root' !== $page['type']
                && $this->granted(new DeleteAction('tl_page', $page)),
            'canSettings' => !($config['notEditable'] ?? false) && $this->granted(new UpdateAction('tl_page', $page)),
            'hasSubpages' => $hasSubpages,
        ];
    }

    /** Publishes or withdraws the page (field "published", as Contao's toggle). */
    public function togglePublished(int $pageId): StructureResult
    {
        if (!$this->access->has(Permission::EditPages)) {
            return StructureResult::forbidden();
        }

        if (null === $this->page($pageId)) {
            return StructureResult::notFound();
        }

        return $this->runner->run('page', 'tl_page', ['act' => 'toggle', 'id' => $pageId], function (DC_Table $dc) use ($pageId): StructureResult {
            $dc->toggle($pageId, 'published', true);

            return StructureResult::done($pageId, ['published' => (bool) $this->db->fetchOne('SELECT published FROM tl_page WHERE id = ?', [$pageId])]);
        });
    }

    /** Duplicates the page with its articles, after the original; the copy is not published (Contao's doNotCopy). */
    public function copyPage(int $pageId): StructureResult
    {
        if (!$this->access->has(Permission::EditPages)) {
            return StructureResult::forbidden();
        }

        $page = $this->page($pageId);

        if (null === $page) {
            return StructureResult::notFound();
        }

        if ('root' === $page['type']) {
            return StructureResult::forbidden();
        }

        return $this->runner->run('page', 'tl_page', ['act' => 'copy', 'mode' => self::PASTE_AFTER, 'pid' => $pageId, 'id' => $pageId], function (DC_Table $dc): StructureResult {
            $newId = $dc->copy(true);

            if (!\is_numeric($newId)) {
                return StructureResult::invalid();
            }

            // Contao does not copy the alias; the copy gets one from its name right away, so it has an address.
            $row = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [(int) $newId]);

            if (false !== $row) {
                PageAlias::ensure(new EditorDataContainer('tl_page', $row, 'alias'), $this->db);
            }

            return StructureResult::done((int) $newId);
        });
    }

    /** Deletes a page without subpages; Contao keeps it in its undo list. */
    public function deletePage(int $pageId): StructureResult
    {
        if (!$this->access->has(Permission::EditPages)) {
            return StructureResult::forbidden();
        }

        $page = $this->page($pageId);

        if (null === $page) {
            return StructureResult::notFound();
        }

        if ('root' === $page['type'] || false !== $this->db->fetchOne('SELECT id FROM tl_page WHERE pid = ? LIMIT 1', [$pageId])) {
            return StructureResult::forbidden();
        }

        return $this->runner->run('page', 'tl_page', ['act' => 'delete', 'id' => $pageId], static function (DC_Table $dc) use ($pageId, $page): StructureResult {
            $dc->delete(true);

            // The editor shows the parent page afterwards.
            return StructureResult::done($pageId, ['parent' => (int) $page['pid']]);
        });
    }

    /**
     * Prepares Contao's page tree for a new page, as the button "New" of the
     * page list does: the clipboard in create mode, the tree open down to the
     * current page. The tree then shows "paste after/into" where the user may
     * create a page.
     */
    public function prepareTree(int $currentPageId): bool
    {
        if (!$this->access->has(Permission::EditPages) || !$this->rights($currentPageId)['canCreate']) {
            return false;
        }

        $this->clipboard->startCreate('tl_page');

        $bag = $this->requestStack->getSession()->getBag('contao_backend');
        $open = $bag->get('tl_page_tree');
        $open = \is_array($open) ? $open : [];

        foreach ($this->trail($currentPageId) as $id) {
            $open[$id] = 1;
        }

        $bag->set('tl_page_tree', $open);

        // Only the website (root page) of the current page, with Contao's own filter of the page tree (the
        // path above the tree), so the new page cannot land in another website or language. The user's own
        // filter comes back once the tree is left (restoreTreeNode()).
        $rootId = (int) PageModel::findWithDetails($currentPageId)?->rootId;

        if ($rootId > 0) {
            if (!$bag->has(self::NODE_BEFORE)) {
                $bag->set(self::NODE_BEFORE, (int) $bag->get('tl_page_node'));
            }

            $bag->set('tl_page_node', $rootId);
        }

        return true;
    }

    /**
     * Remembers a new page whose form the editor opened. Contao keeps its own
     * list of new records in the back-end session of the popup, which the
     * editor's requests do not reach; this list lives in the session itself.
     */
    public function rememberDraft(int $pageId): void
    {
        $session = $this->requestStack->getSession();
        $drafts = array_map('intval', (array) $session->get(self::DRAFTS, []));

        if (!\in_array($pageId, $drafts, true)) {
            $session->set(self::DRAFTS, [...\array_slice($drafts, -19), $pageId]);
        }
    }

    /**
     * Removes a new page that was left without saving: only a page this
     * session created through the editor, only while it is a draft (tstamp 0).
     */
    public function discardDraft(int $pageId): StructureResult
    {
        $session = $this->requestStack->getSession();
        $drafts = array_map('intval', (array) $session->get(self::DRAFTS, []));

        if (!\in_array($pageId, $drafts, true)) {
            return StructureResult::notFound();
        }

        $this->db->executeStatement('DELETE FROM tl_page WHERE id = ? AND tstamp = 0', [$pageId]);
        $session->set(self::DRAFTS, array_values(array_filter($drafts, static fn (int $id): bool => $id !== $pageId)));

        return StructureResult::done($pageId);
    }

    /** Gives the page tree back the filter it had before prepareTree(). */
    public function restoreTreeNode(): void
    {
        $bag = $this->requestStack->getSession()->getBag('contao_backend');

        if ($bag->has(self::NODE_BEFORE)) {
            $bag->set('tl_page_node', (int) $bag->get(self::NODE_BEFORE));
            $bag->remove(self::NODE_BEFORE);
        }
    }

    /** Leaves the create mode of the page tree (the user closed it without choosing a place). */
    public function leaveTree(): void
    {
        $this->restoreTreeNode();
        $this->clipboard->endCreate('tl_page');
    }

    /**
     * A page and whether it was saved (a new page counts once Contao's form was saved).
     *
     * @return array{id: int, title: string, saved: bool, published: bool}|null
     */
    public function state(int $pageId): array|null
    {
        $row = $this->db->fetchAssociative('SELECT id, title, tstamp, published FROM tl_page WHERE id = ?', [$pageId]);

        if (false === $row || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, $pageId)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'saved' => (int) $row['tstamp'] > 0, 'published' => (bool) $row['published']];
    }

    /** @return list<int> the page and its parents */
    private function trail(int $pageId): array
    {
        $trail = [];

        while ($pageId > 0 && \count($trail) < 50) {
            $trail[] = $pageId;
            $pageId = (int) $this->db->fetchOne('SELECT pid FROM tl_page WHERE id = ?', [$pageId]);
        }

        return $trail;
    }

    /** @return array<string, mixed>|null */
    private function page(int $pageId): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ? AND tstamp > 0', [$pageId]);

        return false === $row ? null : $row;
    }

    private function granted(CreateAction|UpdateAction|DeleteAction $action): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', $action);
    }
}
