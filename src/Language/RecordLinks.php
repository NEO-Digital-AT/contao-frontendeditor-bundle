<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Doctrine\DBAL\Connection;

/**
 * Links between a record of the main language and its version in another
 * language, for records Contao does not link itself (front-end modules,
 * records of other back-end modules), and the state of the translation of
 * every record translated on its own (RecordVersions). Table
 * tl_neo_fe_record_link.
 */
final class RecordLinks
{
    public const TABLE = 'tl_neo_fe_record_link';

    public function __construct(private readonly Connection $db)
    {
    }

    /** The version of a main record in a language, null without a link. */
    public function targetOf(string $table, int $main, string $language): int|null
    {
        $target = $this->db->fetchOne('SELECT target FROM '.self::TABLE.' WHERE record_table = ? AND main = ? AND language = ? ORDER BY id DESC', [$table, $main, $language]);

        return false === $target ? null : (int) $target;
    }

    /** @return array{main: int, language: string, source_hash: string}|null the link of a version */
    public function ofTarget(string $table, int $target): array|null
    {
        $row = $this->db->fetchAssociative('SELECT main, language, source_hash FROM '.self::TABLE.' WHERE record_table = ? AND target = ?', [$table, $target]);

        return false === $row ? null : ['main' => (int) $row['main'], 'language' => (string) $row['language'], 'source_hash' => (string) $row['source_hash']];
    }

    /** @return list<int> records of the table that are versions of another */
    public function targets(string $table): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT target FROM '.self::TABLE.' WHERE record_table = ?', [$table]));
    }

    /**
     * Links $target as the version of $main in $language; an earlier link of
     * the target, or of the main record in that language, is replaced.
     */
    public function link(string $table, int $main, int $target, string $language, string $sourceHash = ''): void
    {
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE record_table = ? AND (target = ? OR (main = ? AND language = ?))', [$table, $target, $main, $language]);
        $this->db->insert(self::TABLE, ['tstamp' => time(), 'record_table' => $table, 'main' => $main, 'target' => $target, 'language' => $language, 'source_hash' => $sourceHash]);
    }

    /** The state of the translation of a version: the hash of the main record's texts when it was translated. */
    public function translated(string $table, int $main, int $target, string $language, string $sourceHash): void
    {
        $updated = $this->db->executeStatement('UPDATE '.self::TABLE.' SET source_hash = ?, tstamp = ? WHERE record_table = ? AND main = ? AND target = ?', [$sourceHash, time(), $table, $main, $target]);

        if (0 === $updated) {
            $this->link($table, $main, $target, $language, $sourceHash);
        }
    }

    public function unlink(string $table, int $target): void
    {
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE record_table = ? AND target = ?', [$table, $target]);
    }
}
