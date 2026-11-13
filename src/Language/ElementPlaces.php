<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Where a content element stands, for its language versions: its container
 * (an article, an element that contains elements, or a post such as a news or
 * an event), the page whose language it has, and the matching container in
 * another language.
 *
 * - Elements of an article: the article's page; articles of the languages
 *   belong together as LanguageVersions::articleMap() pairs them.
 * - Elements in an element (accordion, element group …): as the outermost
 *   element; the containers of the languages are the linked elements
 *   (ElementLinks).
 * - Elements of a post: the page that shows the posts of its archive (field
 *   jumpTo); the posts of the languages are linked by the field languageMain
 *   of changelanguage (news, events, FAQ and extensions with the same
 *   field), the post of a version pointing to the post of the main language.
 */
final class ElementPlaces
{
    private const MAX_DEPTH = 10;

    /** @var array<string, int> "ptable:pid" => page ID */
    private array $pages = [];

    /** @var array<string, array{archive: string, languageMain: bool}|null> */
    private array $postTables = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly LanguageVersions $versions,
        private readonly ElementLinks $links,
    ) {
    }

    /** @return array<string, mixed>|null a content element that is not a draft, of an article or a post (also inside elements) */
    public function element(int $id): array|null
    {
        $row = $id > 0 ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ? AND tstamp > 0', [$id]) : false;

        return false === $row || 0 === $this->pageOf($row) ? null : $row;
    }

    /** @param array<string, mixed> $element the page whose language the element has; 0 when it has none */
    public function pageOf(array $element): int
    {
        return $this->containerPage((string) ($element['ptable'] ?? ''), (int) ($element['pid'] ?? 0));
    }

    /** The page of a container of content elements; 0 when it has none. */
    public function containerPage(string $ptable, int $pid, int $depth = 0): int
    {
        $key = $ptable.':'.$pid;

        if (isset($this->pages[$key])) {
            return $this->pages[$key];
        }

        $page = 0;

        if ('tl_article' === $ptable) {
            $page = (int) $this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [$pid]);
        } elseif ('tl_content' === $ptable) {
            $parent = $depth < self::MAX_DEPTH ? $this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ? AND tstamp > 0', [$pid]) : false;
            $page = false === $parent ? 0 : $this->containerPage((string) $parent['ptable'], (int) $parent['pid'], $depth + 1);
        } elseif (null !== ($post = $this->postTable($ptable))) {
            $page = (int) $this->db->fetchOne(
                'SELECT a.jumpTo FROM '.$this->db->quoteIdentifier($post['archive']).' a JOIN '.$this->db->quoteIdentifier($ptable).' p ON p.pid = a.id WHERE p.id = ? AND p.tstamp > 0',
                [$pid],
            );
        }

        return $this->pages[$key] = $page;
    }

    /**
     * The outermost container of an element: its article, or its post.
     *
     * @param array<string, mixed> $element
     *
     * @return array{ptable: string, pid: int}
     */
    public function top(array $element): array
    {
        $ptable = (string) ($element['ptable'] ?? '');
        $pid = (int) ($element['pid'] ?? 0);

        for ($depth = 0; 'tl_content' === $ptable && $depth < self::MAX_DEPTH; ++$depth) {
            $parent = $this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [$pid]);

            if (false === $parent) {
                break;
            }

            $ptable = (string) $parent['ptable'];
            $pid = (int) $parent['pid'];
        }

        return ['ptable' => $ptable, 'pid' => $pid];
    }

    /** Whether elements of this table are elements of a post (a table whose archive names a page). */
    public function isPostTable(string $table): bool
    {
        return null !== $this->postTable($table);
    }

    /**
     * The container on page $page (a language version, $mainPage the version
     * in the main language) that matches the container $ptable/$pid of page
     * $fromPage: the article, the element or the post there; null when it
     * has none.
     */
    public function containerOn(string $ptable, int $pid, int $fromPage, int $mainPage, int $page): int|null
    {
        if ($fromPage === $page) {
            return $pid;
        }

        if ('tl_article' === $ptable) {
            $main = $fromPage === $mainPage ? $pid : array_search($pid, $this->versions->articleMap($mainPage, $fromPage), true);

            if (false === $main) {
                return null;
            }

            return $page === $mainPage ? (int) $main : ($this->versions->articleMap($mainPage, $page)[(int) $main] ?? null);
        }

        if ('tl_content' === $ptable) {
            $main = $fromPage === $mainPage ? $pid : (int) ($this->links->ofElement($pid)['main'] ?? 0);
            $row = $main > 0 ? $this->element($main) : null;

            if (null === $row || $this->pageOf($row) !== $mainPage) {
                return null;
            }

            return $page === $mainPage ? $main : $this->counterpartOn($main, $page);
        }

        if (!$this->isPostTable($ptable)) {
            return null;
        }

        $main = $fromPage === $mainPage ? $pid : $this->mainPostOf($ptable, $pid);

        if (null === $main || $this->containerPage($ptable, $main) !== $mainPage) {
            return null;
        }

        return $page === $mainPage ? $main : $this->postOn($ptable, $main, $page);
    }

    /**
     * The element of the main element $mainId on the page $page of a version,
     * null when it has none there.
     */
    public function counterpartOn(int $mainId, int $page): int|null
    {
        foreach ($this->links->ofMains([$mainId])[$mainId] ?? [] as $link) {
            $row = $this->element((int) $link['element']);

            if (null !== $row && $this->pageOf($row) === $page) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /** The post of the main language for a post of a version (field languageMain); null without one. */
    public function mainPostOf(string $table, int $post): int|null
    {
        if (!($this->postTable($table)['languageMain'] ?? false)) {
            return null;
        }

        $main = (int) $this->db->fetchOne('SELECT languageMain FROM '.$this->db->quoteIdentifier($table).' WHERE id = ?', [$post]);

        return $main > 0 ? $main : null;
    }

    /** The post of the main-language post $mainPost shown on page $page (a version); null without one. */
    public function postOn(string $table, int $mainPost, int $page): int|null
    {
        if (!($this->postTable($table)['languageMain'] ?? false)) {
            return null;
        }

        foreach ($this->db->fetchFirstColumn('SELECT id FROM '.$this->db->quoteIdentifier($table).' WHERE languageMain = ? AND tstamp > 0 ORDER BY id', [$mainPost]) as $id) {
            if ($this->containerPage($table, (int) $id) === $page) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * The elements of a container in their order.
     *
     * @return list<array<string, mixed>>
     */
    public function elements(string $ptable, int $pid): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM tl_content WHERE ptable = ? AND pid = ? AND tstamp > 0 ORDER BY sorting', [$ptable, $pid]);
    }

    /**
     * Which of these elements contain elements.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    public function withChildren(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $pids = $this->db->fetchFirstColumn("SELECT DISTINCT pid FROM tl_content WHERE ptable = 'tl_content' AND pid IN (?) AND tstamp > 0", [$ids], [ArrayParameterType::INTEGER]);

        return array_fill_keys(array_map('intval', $pids), true);
    }

    /**
     * A table of posts: its archive table names a page in the field jumpTo;
     * languageMain says whether its posts are linked across languages.
     *
     * @return array{archive: string, languageMain: bool}|null
     */
    private function postTable(string $table): array|null
    {
        if (\array_key_exists($table, $this->postTables)) {
            return $this->postTables[$table];
        }

        if (\in_array($table, ['', 'tl_article', 'tl_content'], true) || 1 !== preg_match('/^[a-z0-9_]+$/i', $table)) {
            return $this->postTables[$table] = null;
        }

        $this->framework->initialize();
        $controller = $this->framework->getAdapter(Controller::class);
        $controller->loadDataContainer($table);
        $archive = $GLOBALS['TL_DCA'][$table]['config']['ptable'] ?? null;

        if (!\is_string($archive) || 1 !== preg_match('/^[a-z0-9_]+$/i', $archive)) {
            return $this->postTables[$table] = null;
        }

        $controller->loadDataContainer($archive);

        if (!isset($GLOBALS['TL_DCA'][$archive]['fields']['jumpTo'])) {
            return $this->postTables[$table] = null;
        }

        return $this->postTables[$table] = ['archive' => $archive, 'languageMain' => isset($GLOBALS['TL_DCA'][$table]['fields']['languageMain'])];
    }
}
