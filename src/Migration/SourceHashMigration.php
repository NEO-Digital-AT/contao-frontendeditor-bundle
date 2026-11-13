<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Language\ContentHash;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\SourceHash;

/**
 * Links stored before SourceHash hold the hash of four fields only
 * (ContentHash::legacy). A link whose element in the main language has not
 * changed since gets the hash of every text it has, so it stays current; the
 * others stay outdated.
 */
final class SourceHashMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $db,
        private readonly SourceHash $hashes,
    ) {
    }

    public function shouldRun(): bool
    {
        return [] !== $this->current();
    }

    public function run(): MigrationResult
    {
        $links = $this->current();

        foreach ($links as $id => $main) {
            $this->db->update(ElementLinks::TABLE, ['source_hash' => $this->hashes->of($main)], ['id' => $id]);
        }

        return $this->createResult(true, \sprintf('Frontend Editor: %d current translations take the state of all their texts.', \count($links)));
    }

    /** @return array<int, array<string, mixed>> link ID => element of the main language, for current links with the old hash */
    private function current(): array
    {
        $schema = $this->db->createSchemaManager();

        if (!$schema->tablesExist([ElementLinks::TABLE, 'tl_content'])) {
            return [];
        }

        $current = [];

        foreach ($this->db->fetchAllAssociative('SELECT l.id AS link_id, l.source_hash, c.* FROM '.ElementLinks::TABLE." l JOIN tl_content c ON c.id = l.main WHERE l.source_hash != ''") as $row) {
            if (hash_equals((string) $row['source_hash'], ContentHash::legacy($row))) {
                $current[(int) $row['link_id']] = array_diff_key($row, ['link_id' => true, 'source_hash' => true]);
            }
        }

        return $current;
    }
}
