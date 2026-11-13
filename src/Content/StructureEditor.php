<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Changes the structure of a page with Contao's own operations of the
 * back end (DC_Table::create, cut, copy, toggle, delete): defaults, sorting,
 * callbacks, versions, the undo list and Contao's voters work as in the
 * article module (or the module of a post). The switches of the extension
 * narrow what may be done; Contao decides each record. Only articles, their
 * content elements and the content elements of posts (Plus) are touched.
 */
final class StructureEditor
{
    private const PASTE_AFTER = 1;

    private const PASTE_INTO = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
        private readonly ElementAccess $access,
        private readonly DcaRunner $runner,
        private readonly PostTypes $postTypes,
        private readonly Editions $editions,
    ) {
    }

    /**
     * New element in an article, after an element of the same article or at its
     * top. The record is a draft (tstamp 0) until Contao's form saves it.
     */
    public function createElement(int $articleId, int|null $afterId): StructureResult
    {
        return $this->createElementIn('tl_article', $articleId, $afterId);
    }

    /** The same for any parent of content elements the editor knows (an article, a news, an event …). */
    public function createElementIn(string $ptable, int $pid, int|null $afterId): StructureResult
    {
        $module = $this->module($ptable, $pid);

        if (!$this->access->has(Permission::EditStructure) || null === $module) {
            return StructureResult::forbidden();
        }

        if (!$this->parentExists($ptable, $pid)) {
            return StructureResult::notFound();
        }

        if (null !== $afterId) {
            $after = $this->element($afterId);

            if (null === $after || (int) $after['pid'] !== $pid || $after['ptable'] !== $ptable) {
                return StructureResult::notFound();
            }
        }

        $query = null === $afterId
            ? ['act' => 'create', 'mode' => self::PASTE_INTO, 'pid' => $pid, 'id' => $pid]
            : ['act' => 'create', 'mode' => self::PASTE_AFTER, 'pid' => $afterId, 'id' => $pid];

        return $this->run($module, $ptable, $query, function (DC_Table $dc): StructureResult {
            $before = $this->newRecords('tl_content');

            try {
                $dc->create();
            } catch (RedirectResponseException) {
                // Contao switches to its form after creating; the editor opens that form itself.
            }

            $created = array_values(array_diff($this->newRecords('tl_content'), $before));

            return [] === $created ? StructureResult::forbidden() : StructureResult::done((int) end($created));
        });
    }

    /**
     * Moves an element after another element or to the top of its parent: within
     * the articles of the same page, or within the same post.
     */
    public function moveElement(int $id, int|null $afterId, int|null $parentId): StructureResult
    {
        if (!$this->access->has(Permission::EditStructure)) {
            return StructureResult::forbidden();
        }

        $element = $this->element($id);

        if (null === $element || (null === $afterId) === (null === $parentId) || $afterId === $id) {
            return null === $element ? StructureResult::notFound() : StructureResult::invalid();
        }

        $ptable = (string) $element['ptable'];
        $after = null !== $afterId ? $this->element($afterId) : null;

        if (null !== $afterId && (null === $after || $after['ptable'] !== $ptable)) {
            return StructureResult::notFound();
        }

        $target = null !== $after ? (int) $after['pid'] : (int) $parentId;
        $sameContainer = 'tl_article' === $ptable ? $this->samePage((int) $element['pid'], $target) : $target === (int) $element['pid'];

        if (!$sameContainer || !$this->parentExists($ptable, $target)) {
            return StructureResult::notFound();
        }

        $query = null !== $afterId
            ? ['act' => 'cut', 'mode' => self::PASTE_AFTER, 'pid' => $afterId, 'id' => $id]
            : ['act' => 'cut', 'mode' => self::PASTE_INTO, 'pid' => $target, 'id' => $id];

        return $this->run((string) $this->module($ptable, (int) $element['pid']), $ptable, $query, static function (DC_Table $dc) use ($id): StructureResult {
            $dc->cut(true);

            return StructureResult::done($id);
        });
    }

    /**
     * Duplicates an element, as Contao's "copy" does (with the elements in
     * it): directly after the original, or after another element / at the top
     * of another container of the same kind (an article, an element that
     * contains elements, a post; e.g. into a language version of the page).
     */
    public function copyElement(int $id, int|null $afterId = null, int|null $parentId = null): StructureResult
    {
        if (!$this->access->has(Permission::EditStructure)) {
            return StructureResult::forbidden();
        }

        $element = $this->element($id);
        $ptable = (string) ($element['ptable'] ?? '');
        $after = null !== $afterId ? $this->element($afterId) : null;

        if (
            null === $element
            || (null !== $parentId && (!$this->parentExists($ptable, $parentId) || null === $this->module($ptable, $parentId)))
            || (null !== $afterId && (null === $after || $after['ptable'] !== $ptable))
        ) {
            return StructureResult::notFound();
        }

        $query = match (true) {
            null !== $afterId => ['act' => 'copy', 'mode' => self::PASTE_AFTER, 'pid' => $afterId, 'id' => $id],
            null !== $parentId => ['act' => 'copy', 'mode' => self::PASTE_INTO, 'pid' => $parentId, 'id' => $id],
            default => ['act' => 'copy', 'mode' => self::PASTE_AFTER, 'pid' => $id, 'id' => $id],
        };

        return $this->run((string) $this->module($ptable, (int) $element['pid']), $ptable, $query, static function (DC_Table $dc): StructureResult {
            $newId = $dc->copy(true);

            return \is_numeric($newId) ? StructureResult::done((int) $newId) : StructureResult::invalid();
        });
    }

    /** Hides a visible element or shows a hidden one (field "invisible", as Contao's toggle). */
    public function toggleElement(int $id): StructureResult
    {
        if (!$this->access->has(Permission::EditStructure)) {
            return StructureResult::forbidden();
        }

        $element = $this->element($id);

        if (null === $element) {
            return StructureResult::notFound();
        }

        $ptable = (string) $element['ptable'];

        return $this->run((string) $this->module($ptable, (int) $element['pid']), $ptable, ['act' => 'toggle', 'id' => $id], function (DC_Table $dc) use ($id): StructureResult {
            $dc->toggle($id, 'invisible', true);

            return StructureResult::done($id, ['invisible' => (bool) $this->db->fetchOne('SELECT invisible FROM tl_content WHERE id = ?', [$id])]);
        });
    }

    /** Deletes an element; Contao keeps it in its undo list ("Restore" in the back end). */
    public function deleteElement(int $id): StructureResult
    {
        if (!$this->access->has(Permission::EditStructure)) {
            return StructureResult::forbidden();
        }

        $element = $this->element($id);

        if (null === $element) {
            return StructureResult::notFound();
        }

        $ptable = (string) $element['ptable'];

        return $this->run((string) $this->module($ptable, (int) $element['pid']), $ptable, ['act' => 'delete', 'id' => $id], static function (DC_Table $dc) use ($id): StructureResult {
            $dc->delete(true);

            return StructureResult::done($id);
        });
    }

    /** New article on the page, after the last article of the column, or at the top of the page. */
    public function createArticle(int $pageId, string $column): StructureResult
    {
        if (!$this->access->has(Permission::CreateArticles)) {
            return StructureResult::forbidden();
        }

        $page = $this->db->fetchAssociative('SELECT id FROM tl_page WHERE id = ?', [$pageId]);

        if (false === $page) {
            return StructureResult::notFound();
        }

        $lastId = $this->db->fetchOne(
            'SELECT id FROM tl_article WHERE pid = ? AND tstamp > 0 ORDER BY inColumn = ? DESC, sorting DESC LIMIT 1',
            [$pageId, $column],
        );

        $query = false === $lastId
            ? ['act' => 'create', 'mode' => self::PASTE_INTO, 'pid' => $pageId]
            : ['act' => 'create', 'mode' => self::PASTE_AFTER, 'pid' => (int) $lastId];

        return $this->runner->run('article', 'tl_article', $query, function (DC_Table $dc) use ($column): StructureResult {
            $before = $this->newRecords('tl_article');

            try {
                // The column is preset like a default value; the form shows it for changing.
                $dc->create('main' === $column || $this->access->canEditField('inColumn', 'tl_article') ? ['inColumn' => $column] : []);
            } catch (RedirectResponseException) {
                // Contao switches to its form after creating; the editor opens that form itself.
            }

            $created = array_values(array_diff($this->newRecords('tl_article'), $before));

            return [] === $created ? StructureResult::forbidden() : StructureResult::done((int) end($created));
        });
    }

    /**
     * Removes a record that was created in this session and never saved
     * (tstamp 0), like Contao does when its form is left without saving.
     */
    public function discard(string $table, int $id): StructureResult
    {
        if (!\in_array($table, ['tl_content', 'tl_article', 'tl_page', 'tl_form_field'], true) && null === $this->postTypes->get($table)) {
            return StructureResult::forbidden();
        }

        $bag = $this->requestStack->getSession()->getBag('contao_backend');
        $newRecords = $bag->get('new_records') ?? [];

        if (!\in_array($id, array_map('intval', $newRecords[$table] ?? []), true)) {
            return StructureResult::notFound();
        }

        $this->db->executeStatement("DELETE FROM $table WHERE id = ? AND tstamp = 0", [$id]);

        $newRecords[$table] = array_values(array_filter($newRecords[$table], static fn ($recordId): bool => (int) $recordId !== $id));
        $bag->set('new_records', $newRecords);

        return StructureResult::done($id);
    }

    /**
     * Runs an operation on content elements in the back-end module of their parent.
     *
     * @param array<string, int|string>        $query
     * @param \Closure(DC_Table): StructureResult $operation
     */
    private function run(string $module, string $ptable, array $query, \Closure $operation): StructureResult
    {
        return $this->runner->run($module, 'tl_content', $query, $operation, $ptable);
    }

    /**
     * Back-end module of a parent of content elements: articles, or a post type
     * with content elements (edition Plus, switch "edit posts"); null for any
     * other parent.
     */
    private function module(string $ptable, int $pid, int $depth = 0): string|null
    {
        if ('tl_article' === $ptable) {
            return 'article';
        }

        // Elements in an element (accordion, slider, element group …): the module of the outermost element.
        if ('tl_content' === $ptable) {
            $parent = $depth < 10 ? $this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ? AND tstamp > 0', [$pid]) : false;

            return false === $parent ? null : $this->module((string) $parent['ptable'], (int) $parent['pid'], $depth + 1);
        }

        $type = $this->access->has(Permission::EditPosts) ? $this->postTypes->get($ptable) : null;

        if (null === $type || !$type['hasContent'] || !$this->editions->hasPlus($this->postTypes->pageOf($ptable, $pid))) {
            return null;
        }

        return $type['module'];
    }

    private function parentExists(string $ptable, int $pid): bool
    {
        if ('tl_article' === $ptable) {
            return null !== $this->article($pid);
        }

        return false !== $this->db->fetchOne('SELECT id FROM '.$this->db->quoteIdentifier($ptable).' WHERE id = ? AND tstamp > 0', [$pid]);
    }

    /** @return array<string, mixed>|null a content element of an article or a post that is not a draft */
    private function element(int $id): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ? AND tstamp > 0', [$id]);

        return false === $row || null === $this->module((string) $row['ptable'], (int) $row['pid']) ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function article(int $id): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_article WHERE id = ? AND tstamp > 0', [$id]);

        return false === $row ? null : $row;
    }

    private function samePage(int $articleId, int $otherArticleId): bool
    {
        $pages = $this->db->fetchFirstColumn('SELECT DISTINCT pid FROM tl_article WHERE id IN (?, ?)', [$articleId, $otherArticleId]);

        return 1 === \count($pages) && null !== $this->article($otherArticleId);
    }

    /** @return list<int> IDs Contao keeps as "new" in the back-end session (see DC_Table::create) */
    private function newRecords(string $table): array
    {
        $records = $this->requestStack->getSession()->getBag('contao_backend')->get('new_records') ?? [];

        return array_map('intval', $records[$table] ?? []);
    }
}
