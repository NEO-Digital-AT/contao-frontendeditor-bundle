<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Links between a content element of the main language and its counterparts in
 * the other language versions (tl_neo_fe_link). One element of a language
 * version belongs to at most one element of the main language.
 */
final class ElementLinks
{
    public const TABLE = 'tl_neo_fe_link';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null the link of an element of a language version */
    public function ofElement(int $elementId): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE element = ?', [$elementId]);

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $linkId): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$linkId]);

        return false === $row ? null : $row;
    }

    /**
     * @param list<int> $elementIds elements of language versions
     *
     * @return array<int, array<string, mixed>> element ID => link
     */
    public function ofElements(array $elementIds): array
    {
        if ([] === $elementIds) {
            return [];
        }

        $links = [];

        foreach ($this->db->fetchAllAssociative('SELECT * FROM '.self::TABLE.' WHERE element IN (?)', [$elementIds], [ArrayParameterType::INTEGER]) as $row) {
            $links[(int) $row['element']] = $row;
        }

        return $links;
    }

    /**
     * @param list<int> $mainIds elements of the main language
     *
     * @return array<int, list<array<string, mixed>>> main element ID => links
     */
    public function ofMains(array $mainIds): array
    {
        if ([] === $mainIds) {
            return [];
        }

        $links = [];

        foreach ($this->db->fetchAllAssociative('SELECT * FROM '.self::TABLE.' WHERE main IN (?)', [$mainIds], [ArrayParameterType::INTEGER]) as $row) {
            $links[(int) $row['main']][] = $row;
        }

        return $links;
    }

    /**
     * Links an element to an element of the main language, replacing an earlier
     * link of the element. $sourceHash is the state of the main element's texts
     * the element corresponds to; '' when it still has to be translated.
     */
    public function link(int $mainId, int $elementId, string $language, string $sourceHash): int
    {
        $this->db->delete(self::TABLE, ['element' => $elementId]);
        $this->db->insert(self::TABLE, [
            'tstamp' => time(),
            'main' => $mainId,
            'element' => $elementId,
            'language' => $language,
            'source_hash' => $sourceHash,
            'synced_at' => '' !== $sourceHash ? time() : 0,
            'outdated' => '' !== $sourceHash ? '' : '1',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function unlink(int $linkId): void
    {
        $this->db->delete(self::TABLE, ['id' => $linkId]);
    }

    /** The element now corresponds to this state of its main element. */
    public function markSynced(int $elementId, string $sourceHash): void
    {
        $this->db->update(self::TABLE, ['tstamp' => time(), 'source_hash' => $sourceHash, 'synced_at' => time(), 'outdated' => ''], ['element' => $elementId]);
    }

    /** After the main element was saved: its translations with another state are outdated. */
    public function refreshOutdated(int $mainId, string $sourceHash): void
    {
        $this->db->executeStatement(
            "UPDATE ".self::TABLE." SET outdated = IF(source_hash = ?, '', '1') WHERE main = ?",
            [$sourceHash, $mainId],
        );
    }

    /**
     * Where an element taken over into another language goes: after the
     * counterpart in $targetParent of the nearest element before it (in its
     * own container) that has one there; null for the top. $targetParent is a
     * container of the same kind as the element's (an article, an element
     * that contains elements, a post). Both ways: from the main language into
     * a version and from a version into the main language.
     *
     * @param array<string, mixed> $element
     */
    public function counterpartBefore(array $element, int $targetParent): int|null
    {
        $ptable = (string) ($element['ptable'] ?? 'tl_article');
        $before = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM tl_content WHERE ptable = ? AND pid = ? AND sorting < ? AND tstamp > 0 ORDER BY sorting DESC',
            [$ptable, $element['pid'], $element['sorting']],
        ));

        if ([] === $before) {
            return null;
        }

        $inTarget = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_content WHERE ptable = ? AND pid = ? AND tstamp > 0', [$ptable, $targetParent]));
        $counterparts = [];

        foreach ($this->ofMains($before) as $mainId => $links) {
            foreach ($links as $link) {
                $counterparts[$mainId][] = (int) $link['element'];
            }
        }

        foreach ($this->ofElements($before) as $elementId => $link) {
            $counterparts[$elementId][] = (int) $link['main'];
        }

        foreach ($before as $id) {
            foreach ($counterparts[$id] ?? [] as $counterpart) {
                if (\in_array($counterpart, $inTarget, true)) {
                    return $counterpart;
                }
            }
        }

        return null;
    }

    /**
     * The states after a translation from $sourceId into $targetId, elements of
     * the same main element $mainId ($mainHash: the state of its texts now):
     *
     * - into the main language: its translations are outdated once something
     *   was saved, except the element it was translated from, once everything
     *   was saved;
     * - from the main language into a version: the version is current, once
     *   everything was saved;
     * - from one version into another: the target corresponds to the main
     *   element as the source does (current when the source is, outdated when
     *   it is), once everything was saved.
     */
    public function afterTranslation(int $mainId, string $mainHash, int $sourceId, int $targetId, bool $saved, bool $complete): void
    {
        if ($targetId === $mainId) {
            if ($saved) {
                $this->refreshOutdated($mainId, $mainHash);
            }

            if ($complete) {
                $this->markSynced($sourceId, $mainHash);
            }

            return;
        }

        if (!$complete) {
            return;
        }

        if ($sourceId === $mainId) {
            $this->markSynced($targetId, $mainHash);

            return;
        }

        $this->markSynced($targetId, (string) ($this->ofElement($sourceId)['source_hash'] ?? ''));
        $this->refreshOutdated($mainId, $mainHash);
    }

    /** A deleted element takes its links along (as main element and as translation). */
    public function removeElement(int $elementId): void
    {
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE element = ? OR main = ?', [$elementId, $elementId]);
    }
}
