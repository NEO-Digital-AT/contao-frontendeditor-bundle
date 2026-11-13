<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Pairs the elements of a language version with those of the main language by
 * their structure, for websites whose versions were made before the editor
 * (Plus): within each pair of containers, elements that belong to no element
 * of the other language are paired in order where their type is the same
 * (longest common sequence of types), and the pair is stored as a link, as
 * "Link" would do it: the version counts as current for the main element as
 * it is now; a later change of the main element makes it outdated.
 *
 * Containers are the article pairs of a page (LanguageVersions::articleMap),
 * the posts of the languages (ElementPlaces) and, one level deeper each time,
 * every pair of linked elements that contain elements (accordion, element
 * group …): the elements in them are paired the same way.
 *
 * Once per container pair (tl_neo_fe_pairing): a link someone removed later is
 * not made again, and elements added later are not paired by position.
 */
final class ElementPairing
{
    public const TABLE = 'tl_neo_fe_pairing';

    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly LanguageVersions $versions,
        private readonly ElementLinks $links,
        private readonly SourceHash $hashes,
    ) {
    }

    /**
     * The elements of the articles of a page and, inside them, of the elements
     * that contain elements.
     *
     * @param callable(array<string, mixed>): bool $mayLink whether the user may link this element of the version
     *
     * @return int the number of links made
     */
    public function pair(int $mainPageId, int $targetPageId, string $language, callable $mayLink): int
    {
        $made = 0;

        foreach ($this->versions->articleMap($mainPageId, $targetPageId) as $mainArticle => $targetArticle) {
            $made += $this->pairContainer('tl_article', $mainArticle, $targetArticle, $language, $mayLink);
        }

        return $made;
    }

    /**
     * The elements of one pair of containers ($ptable: tl_article, tl_content
     * or the table of a post) and, recursively, of the linked elements in
     * them that contain elements.
     *
     * @param callable(array<string, mixed>): bool $mayLink
     *
     * @return int the number of links made
     */
    public function pairContainer(string $ptable, int $mainPid, int $targetPid, string $language, callable $mayLink, int $depth = 0): int
    {
        $made = 0;
        $main = $this->elements($ptable, $mainPid);
        $target = $this->elements($ptable, $targetPid);
        $targetIds = array_map('intval', array_column($target, 'id'));

        if (false === $this->db->fetchOne('SELECT id FROM '.self::TABLE.' WHERE ptable = ? AND main_article = ? AND target_article = ?', [$ptable, $mainPid, $targetPid])) {
            $made = $this->pairElements($ptable, $mainPid, $targetPid, $main, $target, $language, $mayLink);
        }

        if ($depth >= self::MAX_DEPTH) {
            return $made;
        }

        // One level deeper: the elements in each pair of linked elements (also of pairs linked before).
        $mainIds = array_map('intval', array_column($main, 'id'));
        $containers = $this->withChildren([...$mainIds, ...$targetIds]);

        foreach ($this->links->ofMains($mainIds) as $mainId => $links) {
            foreach ($links as $link) {
                $element = (int) $link['element'];

                if (\in_array($element, $targetIds, true) && (isset($containers[$mainId]) || isset($containers[$element]))) {
                    $made += $this->pairContainer('tl_content', $mainId, $element, $language, $mayLink, $depth + 1);
                }
            }
        }

        return $made;
    }

    /**
     * @param list<array<string, mixed>>           $main
     * @param list<array<string, mixed>>           $target
     * @param callable(array<string, mixed>): bool $mayLink
     */
    private function pairElements(string $ptable, int $mainPid, int $targetPid, array $main, array $target, string $language, callable $mayLink): int
    {
        $targetIds = array_map('intval', array_column($target, 'id'));

        // Main elements with a counterpart in this container, and elements of the version linked to anything.
        $linkedMain = [];

        foreach ($this->links->ofMains(array_map('intval', array_column($main, 'id'))) as $mainId => $links) {
            foreach ($links as $link) {
                if (\in_array((int) $link['element'], $targetIds, true)) {
                    $linkedMain[(int) $mainId] = true;
                }
            }
        }

        $linkedTarget = $this->links->ofElements($targetIds);
        $main = array_values(array_filter($main, static fn (array $row): bool => !isset($linkedMain[(int) $row['id']])));
        $target = array_values(array_filter($target, static fn (array $row): bool => !isset($linkedTarget[(int) $row['id']])));

        // Nothing of the version to pair yet (e.g. an empty article): ask again next time.
        if ([] === $target) {
            return 0;
        }

        $pairs = self::align($main, $target);

        foreach ($pairs as [, $targetRow]) {
            if (!$mayLink($targetRow)) {
                // Not this user's to decide: leave the container for one who may.
                return 0;
            }
        }

        foreach ($pairs as [$mainRow, $targetRow]) {
            $this->links->link((int) $mainRow['id'], (int) $targetRow['id'], $language, $this->hashes->of($mainRow));
        }

        $this->db->insert(self::TABLE, ['tstamp' => time(), 'ptable' => $ptable, 'main_article' => $mainPid, 'target_article' => $targetPid]);

        return \count($pairs);
    }

    /**
     * Pairs of the two lists in order where the type is the same: the longest
     * common sequence of types, so an element only one side has does not shift
     * the rest.
     *
     * @param list<array<string, mixed>> $main
     * @param list<array<string, mixed>> $target
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function align(array $main, array $target): array
    {
        $n = \count($main);
        $m = \count($target);
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $length[$i][$j] = $main[$i]['type'] === $target[$j]['type']
                    ? $length[$i + 1][$j + 1] + 1
                    : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $pairs = [];

        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if ($main[$i]['type'] === $target[$j]['type'] && $length[$i][$j] === $length[$i + 1][$j + 1] + 1) {
                $pairs[] = [$main[$i], $target[$j]];
                ++$i;
                ++$j;
            } elseif ($length[$i + 1][$j] >= $length[$i][$j + 1]) {
                ++$i;
            } else {
                ++$j;
            }
        }

        return $pairs;
    }

    /** @return list<array<string, mixed>> elements of the container in order */
    private function elements(string $ptable, int $pid): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM tl_content WHERE ptable = ? AND pid = ? AND tstamp > 0 ORDER BY sorting', [$ptable, $pid]);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, true> the elements among them that contain elements
     */
    private function withChildren(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $pids = $this->db->fetchFirstColumn("SELECT DISTINCT pid FROM tl_content WHERE ptable = 'tl_content' AND pid IN (?) AND tstamp > 0", [$ids], [ArrayParameterType::INTEGER]);

        return array_fill_keys(array_map('intval', $pids), true);
    }
}
