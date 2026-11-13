<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Neo\FrontendEditorBundle\Security\ElementAccess;

/**
 * The page in the main language next to one language version: per article of
 * the main page its elements in order, each with its counterpart in the
 * version and the state of the translation; after an element that contains
 * elements (accordion, element group …) the elements in it, one level deeper
 * ("depth"), paired the same way within the two containers. Elements of the
 * version without a counterpart follow at the end of their container. The
 * posts shown on the page (news, events …) follow as groups of their own: the
 * post of the main language next to its post in the version (ElementPlaces).
 *
 * States: current (translated or confirmed since the last change of the main
 * element), outdated (the main element changed since), missing (no
 * counterpart in the version), unlinked (element of the version that belongs
 * to no element of the main language). Each row names the containers of its
 * place in both languages ("mainParent", "targetParent"; null where it is
 * missing): a missing element is taken over into "targetParent"; an element
 * of the version without a counterpart says whether the user may take it over
 * into the main language (canAdoptMain): it needs a container of the main
 * language to go into and the rights to copy it there and to link it.
 */
final class Comparison
{
    public const CURRENT = 'current';

    public const OUTDATED = 'outdated';

    public const MISSING = 'missing';

    public const UNLINKED = 'unlinked';

    private const MAX_DEPTH = 10;

    /** @var array<string, mixed> what build() works with */
    private array $run = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly LanguageVersions $versions,
        private readonly ElementLinks $links,
        private readonly PageStructure $structure,
        private readonly SourceHash $hashes,
        private readonly TranslationUnits $units,
        private readonly ElementAccess $access,
        private readonly PostTypes $postTypes,
    ) {
    }

    /**
     * @param list<array{table: string, main: int|null, target: int|null}> $posts posts shown on the page: the post of
     *                                                                             the main language and of the version
     *
     * @return array<string, mixed>
     */
    public function build(int $mainPageId, int $targetPageId, array $posts = []): array
    {
        $this->framework->initialize();
        Controller::loadDataContainer('tl_content');
        System::loadLanguageFile('default');

        $mainPage = PageModel::findWithDetails($mainPageId);
        $targetPage = PageModel::findWithDetails($targetPageId);
        $articleMap = $this->versions->articleMap($mainPageId, $targetPageId);
        $mainArticles = $this->articles($mainPageId);
        $targetArticles = $this->articles($targetPageId);
        $mainTree = $this->tree('tl_article', array_map('intval', array_column($mainArticles, 'id')));
        $targetTree = $this->tree('tl_article', array_map('intval', array_column($targetArticles, 'id')));

        foreach ($posts as $post) {
            $mainTree += null !== $post['main'] ? $this->tree($post['table'], [$post['main']]) : [];
            $targetTree += null !== $post['target'] ? $this->tree($post['table'], [$post['target']]) : [];
        }

        $targetById = [];

        foreach ($targetTree as $rows) {
            foreach ($rows as $row) {
                $targetById[(int) $row['id']] = $row;
            }
        }

        $this->run = [
            'main' => $mainTree,
            'target' => $targetTree,
            'targetById' => $targetById,
            'links' => $this->links->ofMains(array_map('intval', array_merge([], ...array_map(static fn (array $rows): array => array_column($rows, 'id'), array_values($mainTree))))),
            'used' => [],
            'from' => (string) $mainPage?->language,
            'to' => (string) $targetPage?->language,
        ];

        $groups = [];

        foreach ($mainArticles as $article) {
            $targetArticleId = $articleMap[(int) $article['id']] ?? null;
            $groups[] = [
                'kind' => 'article',
                'main' => $this->articleData($article),
                'target' => null !== $targetArticleId ? $this->articleData($this->findArticle($targetArticles, $targetArticleId)) : null,
                'rows' => $this->rows('tl_article', (int) $article['id'], $targetArticleId, 0),
            ];
        }

        // Articles of the version that belong to no article of the main page: no article to take them over into.
        foreach ($targetArticles as $article) {
            if (!\in_array((int) $article['id'], $articleMap, true)) {
                $groups[] = ['kind' => 'article', 'main' => null, 'target' => $this->articleData($article), 'rows' => $this->rows('tl_article', null, (int) $article['id'], 0)];
            }
        }

        foreach ($posts as $post) {
            $groups[] = [
                'kind' => 'post',
                'main' => null !== $post['main'] ? $this->postData($post['table'], $post['main']) : null,
                'target' => null !== $post['target'] ? $this->postData($post['table'], $post['target']) : null,
                'rows' => $this->rows($post['table'], $post['main'], $post['target'], 0),
            ];
        }

        $this->run = [];

        return [
            'main' => ['id' => $mainPageId, 'title' => (string) $mainPage?->title, 'language' => (string) $mainPage?->language, 'name' => $this->versions->name((string) $mainPage?->language)],
            'target' => ['id' => $targetPageId, 'title' => (string) $targetPage?->title, 'language' => (string) $targetPage?->language, 'name' => $this->versions->name((string) $targetPage?->language)],
            'articles' => $groups,
        ];
    }

    /**
     * The rows of one pair of containers ($ptable/$mainPid of the main
     * language, $ptable/$targetPid of the version; null: none), each followed
     * by the rows of the elements in it.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $ptable, int|null $mainPid, int|null $targetPid, int $depth): array
    {
        $rows = [];

        foreach (null !== $mainPid ? $this->run['main'][$ptable.':'.$mainPid] ?? [] : [] as $main) {
            $link = null;

            foreach ($this->run['links'][(int) $main['id']] ?? [] as $candidate) {
                if (isset($this->run['targetById'][(int) $candidate['element']])) {
                    $link = $candidate;
                    break;
                }
            }

            $target = null !== $link ? $this->run['targetById'][(int) $link['element']] : null;

            if (null !== $target) {
                $this->run['used'][(int) $target['id']] = true;
            }

            $rows[] = [
                'main' => $this->structure->element($main, $this->run['from']),
                'target' => null !== $target ? $this->structure->element($target, $this->run['to']) : null,
                'link' => null !== $link ? (int) $link['id'] : null,
                // Texts of the main element whose counterpart in the version is still empty.
                'open' => null !== $target ? \count(array_filter($this->units->pair($main, $target, $this->run['from'], $this->run['to']), static fn (array $unit): bool => $unit['place'] && '' === trim($unit['target']))) : 0,
                'status' => null === $target ? self::MISSING : ($this->hashes->of($main) === (string) $link['source_hash'] ? self::CURRENT : self::OUTDATED),
                'depth' => $depth,
                'mainParent' => $mainPid,
                'targetParent' => $targetPid,
            ];

            if ($depth < self::MAX_DEPTH) {
                array_push($rows, ...$this->rows('tl_content', (int) $main['id'], null !== $target ? (int) $target['id'] : null, $depth + 1));
            }
        }

        foreach (null !== $targetPid ? $this->run['target'][$ptable.':'.$targetPid] ?? [] : [] as $target) {
            if (isset($this->run['used'][(int) $target['id']])) {
                continue;
            }

            $this->run['used'][(int) $target['id']] = true;
            $rows[] = [
                'main' => null,
                'target' => $this->structure->element($target, $this->run['to']),
                'link' => null,
                'status' => self::UNLINKED,
                'depth' => $depth,
                'mainParent' => $mainPid,
                'targetParent' => $targetPid,
                'canAdoptMain' => null !== $mainPid && $this->access->canUpdate($target) && $this->access->canCopyInto($target, $mainPid),
            ];

            // The elements in it have no container of the main language yet either.
            if ($depth < self::MAX_DEPTH) {
                array_push($rows, ...$this->rows('tl_content', null, (int) $target['id'], $depth + 1));
            }
        }

        return $rows;
    }

    /**
     * The elements of containers and, recursively, of the elements in them.
     *
     * @param list<int> $pids
     *
     * @return array<string, list<array<string, mixed>>> "ptable:pid" => elements in order
     */
    private function tree(string $ptable, array $pids, int $depth = 0): array
    {
        if ([] === $pids || $depth > self::MAX_DEPTH) {
            return [];
        }

        $tree = [];
        $ids = [];

        foreach ($this->db->fetchAllAssociative('SELECT * FROM tl_content WHERE ptable = ? AND pid IN (?) AND tstamp > 0 ORDER BY pid, sorting', [$ptable, $pids], [ParameterType::STRING, ArrayParameterType::INTEGER]) as $row) {
            $tree[$ptable.':'.(int) $row['pid']][] = $row;
            $ids[] = (int) $row['id'];
        }

        return $tree + $this->tree('tl_content', $ids, $depth + 1);
    }

    /** @return list<array<string, mixed>> */
    private function articles(int $pageId): array
    {
        return $this->db->fetchAllAssociative('SELECT id, title, inColumn FROM tl_article WHERE pid = ? AND tstamp > 0 ORDER BY sorting', [$pageId]);
    }

    /** @param list<array<string, mixed>> $articles */
    private function findArticle(array $articles, int $id): array
    {
        foreach ($articles as $article) {
            if ((int) $article['id'] === $id) {
                return $article;
            }
        }

        return ['id' => $id, 'title' => '', 'inColumn' => ''];
    }

    /** @param array<string, mixed> $article */
    private function articleData(array $article): array
    {
        return ['id' => (int) $article['id'], 'title' => (string) $article['title'], 'column' => (string) $article['inColumn']];
    }

    /** @return array{id: int, table: string, title: string, typeLabel: string} */
    private function postData(string $table, int $id): array
    {
        $type = $this->postTypes->get($table);
        $field = $type['titleField'] ?? 'id';
        $title = $this->db->fetchOne('SELECT '.$this->db->quoteIdentifier($field).' FROM '.$this->db->quoteIdentifier($table).' WHERE id = ?', [$id]);

        return ['id' => $id, 'table' => $table, 'title' => StringUtil::decodeEntities((string) $title), 'typeLabel' => (string) ($type['label'] ?? $table)];
    }
}
