<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\SourceHash;

/**
 * The state of a translation (SourceHash) covers the records an element shows
 * from other tables (RelatedRecords, e.g. the questions of a FAQ category)
 * since they are translated with it. A link that was current before keeps
 * being current: it gets the hash with those texts. Links that were outdated
 * already stay outdated.
 */
final class RelatedRecordsHashMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $db,
        private readonly SourceHash $hashes,
        private readonly RelatedRecords $related,
    ) {
    }

    public function shouldRun(): bool
    {
        return [] !== $this->current();
    }

    public function run(): MigrationResult
    {
        $links = $this->current();

        foreach ($links as $id => $hash) {
            $this->db->update(ElementLinks::TABLE, ['source_hash' => $hash], ['id' => $id]);
        }

        return $this->createResult(true, \sprintf('Frontend Editor: %d current translations take the texts of related records into their state.', \count($links)));
    }

    /** @return array<int, string> link ID => new hash, for current links of elements with related records */
    private function current(): array
    {
        if (!$this->db->createSchemaManager()->tablesExist([ElementLinks::TABLE, 'tl_content'])) {
            return [];
        }

        $current = [];

        foreach ($this->db->fetchAllAssociative('SELECT l.id AS link_id, l.source_hash, c.* FROM '.ElementLinks::TABLE." l JOIN tl_content c ON c.id = l.main WHERE l.source_hash != ''") as $row) {
            $main = array_diff_key($row, ['link_id' => true, 'source_hash' => true]);

            if ([] === $this->related->of($main) || !hash_equals((string) $row['source_hash'], $this->hashes->of($main, false))) {
                continue;
            }

            $hash = $this->hashes->of($main);

            if (!hash_equals((string) $row['source_hash'], $hash)) {
                $current[(int) $row['link_id']] = $hash;
            }
        }

        return $current;
    }
}
