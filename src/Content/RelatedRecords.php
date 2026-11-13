<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;

/**
 * Records a content element shows from other tables: those a field of its
 * palette points to through a relation of Contao's data container
 * ("foreignKey" or "relation", e.g. a FAQ category or an entry of a
 * catalogue), and their entries (the records of the child tables of that
 * table, e.g. the questions of the category). Their texts are edited and
 * translated with the element, as its entries are.
 *
 * Not included are records of the structure of the website (pages, articles,
 * elements, modules, layouts, forms, files) and of people (users, members,
 * newsletter recipients): other parts of the editor or Contao's own forms
 * handle them.
 */
final class RelatedRecords
{
    private const STRUCTURE = [
        'tl_page', 'tl_article', 'tl_content', 'tl_module', 'tl_layout', 'tl_theme', 'tl_form', 'tl_form_field',
        'tl_files', 'tl_image_size', 'tl_image_size_item', 'tl_user', 'tl_user_group', 'tl_member', 'tl_member_group',
        'tl_newsletter_recipients', 'tl_comments', 'tl_log', 'tl_undo', 'tl_version', 'tl_opt_in',
    ];

    /** Records per field (a field may point to several, e.g. a multiple choice). */
    private const MAX_RECORDS = 20;

    /** Entries per record. */
    private const MAX_ENTRIES = 100;

    /** @var array<string, list<string>|null> columns by table, null for a table that does not exist */
    private array $columns = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $db,
    ) {
    }

    /**
     * The related records of an element (or of a record of another table),
     * each followed by its entries.
     *
     * @param array<string, mixed> $element
     *
     * @return list<array{table: string, row: array<string, mixed>}>
     */
    public function of(array $element, string $ownTable = 'tl_content'): array
    {
        $this->load($ownTable);
        $records = [];

        foreach (Palette::relationFields($ownTable, $element) as $field => $table) {
            if (!$this->usable($table) || $table === $ownTable) {
                continue;
            }

            foreach (\array_slice(self::ids($element[$field] ?? null), 0, self::MAX_RECORDS) as $id) {
                $row = $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]);

                if (false !== $row) {
                    $records[] = ['table' => $table, 'row' => $row];
                    array_push($records, ...$this->entries($table, $id));
                }
            }
        }

        return $records;
    }

    /**
     * Whether the element shows this record as a related record or an entry of one.
     *
     * @param array<string, mixed> $element
     */
    public function contains(array $element, string $table, int $id, string $ownTable = 'tl_content'): bool
    {
        foreach ($this->of($element, $ownTable) as $record) {
            if ($record['table'] === $table && (int) $record['row']['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries of a record: the records of the child tables of its table in
     * their order (not content elements, which are translated on their own).
     * Without $posts not the posts of an archive either (a post has an address
     * of its own, alias): an archive translated on its own leaves its posts to
     * their own translation.
     *
     * @return list<array{table: string, row: array<string, mixed>}>
     */
    public function entries(string $table, int $id, bool $posts = true): array
    {
        $this->load($table);
        $entries = [];

        foreach ((array) ($GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? []) as $child) {
            if (!\is_string($child) || !$this->usable($child)) {
                continue;
            }

            $this->load($child);
            $config = $GLOBALS['TL_DCA'][$child]['config'] ?? [];

            if (!$posts && isset($GLOBALS['TL_DCA'][$child]['fields']['published'], $GLOBALS['TL_DCA'][$child]['fields']['alias'])) {
                continue;
            }

            $dynamic = (bool) ($config['dynamicPtable'] ?? false);
            $columns = $this->columns[$child] ?? [];

            if (($table !== ($config['ptable'] ?? null) && !$dynamic) || !\in_array('pid', $columns, true)) {
                continue;
            }

            $where = 'pid = ?'.($dynamic && \in_array('ptable', $columns, true) ? ' AND ptable = ?' : '');
            $order = \in_array('sorting', $columns, true) ? ' ORDER BY sorting, id' : ' ORDER BY id';
            $params = $dynamic && \in_array('ptable', $columns, true) ? [$id, $table] : [$id];

            foreach ($this->db->fetchAllAssociative("SELECT * FROM $child WHERE $where$order LIMIT ".self::MAX_ENTRIES, $params) as $row) {
                $entries[] = ['table' => $child, 'row' => $row];
            }
        }

        return $entries;
    }

    /** A table of content (not of the structure or of people) that exists and has an id. */
    private function usable(string $table): bool
    {
        if (\in_array($table, self::STRUCTURE, true) || 1 !== preg_match('/^[a-z0-9_]+$/', $table)) {
            return false;
        }

        if (!\array_key_exists($table, $this->columns)) {
            try {
                $this->columns[$table] = array_map('strtolower', array_keys($this->db->createSchemaManager()->listTableColumns($table)));
            } catch (\Throwable) {
                $this->columns[$table] = null;
            }
        }

        return \in_array('id', $this->columns[$table] ?? [], true);
    }

    /** @return list<int> the ids a field holds: one number or a serialized list */
    private static function ids(mixed $value): array
    {
        $values = is_numeric($value) ? [$value] : StringUtil::deserialize($value, true);
        $ids = array_map('intval', array_filter($values, 'is_numeric'));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function load(string $table): void
    {
        $this->framework->initialize();
        Controller::loadDataContainer($table);
        System::loadLanguageFile($table);
    }
}
