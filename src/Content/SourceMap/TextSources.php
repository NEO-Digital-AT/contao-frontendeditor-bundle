<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content\SourceMap;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\FieldWriter;
use Neo\FrontendEditorBundle\Content\Palette;
use Neo\FrontendEditorBundle\Content\RelatedRecords;

/**
 * The texts a record gives the page: one source per text field of its palette,
 * per row of a list and per cell of a table, each with its key TABLE.ID.FIELD
 * (and .ROW, .ROW.COL), its kind and its stored value. Also the child records
 * of a content element that live in a table of their own (a child table of
 * tl_content, e.g. the cards of a card list) and the records it shows from
 * other tables (RelatedRecords, e.g. the questions of a FAQ category), and the
 * marked copy of a record for the source map.
 */
final class TextSources
{
    /** Values longer than this are not marked (whole documents in a field). */
    private const MAX_LENGTH = 65535;

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $db,
        private readonly RelatedRecords $related,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{key: string, table: string, id: int, field: string, row: int|null, col: int|null, kind: string, value: string, unit: string|null}>
     */
    public function of(string $table, array $row): array
    {
        $this->load($table);
        // An included element (Contao's "Include element") is rendered with the ID of the including one; its texts are the original's.
        $id = (int) ($row['origId'] ?? 0) ?: (int) ($row['id'] ?? 0);
        $sources = [];

        foreach (Palette::sources($table, $row) as ['field' => $field, 'kind' => $kind]) {
            $value = $row[$field] ?? null;

            if (null === $value || '' === $value || 'tl_content' === $table && 'singleSRC' === $field) {
                continue;
            }

            $base = ['table' => $table, 'id' => $id, 'field' => $field];

            // A value with a unit stored as plain text (a template that reads the field directly): a line of text.
            if ('unit' === $kind && FieldWriter::isPlainUnit($table, $field, $row)) {
                if (\strlen((string) $value) <= self::MAX_LENGTH) {
                    $sources[] = $base + ['key' => "$table.$id.$field", 'row' => null, 'col' => null, 'kind' => 'line', 'value' => (string) $value, 'unit' => null];
                }
            } elseif ('unit' === $kind) {
                $unit = StringUtil::deserialize($value, true);
                $text = (string) ($unit['value'] ?? '');

                if ('' !== trim($text)) {
                    $sources[] = $base + ['key' => "$table.$id.$field", 'row' => null, 'col' => null, 'kind' => 'tl_content' === $table && 'headline' === $field ? 'headline' : 'unit', 'value' => $text, 'unit' => (string) ($unit['unit'] ?? '')];
                }
            } elseif ('list' === $kind) {
                foreach (array_values(StringUtil::deserialize($value, true)) as $index => $item) {
                    if (\is_string($item) && '' !== trim($item)) {
                        $sources[] = $base + ['key' => "$table.$id.$field.$index", 'row' => $index, 'col' => null, 'kind' => 'line', 'value' => $item, 'unit' => null];
                    }
                }
            } elseif ('table' === $kind) {
                // The cells of a row by position, also where they have names (key and value of a keyValueWizard).
                foreach (array_values(StringUtil::deserialize($value, true)) as $r => $cells) {
                    foreach (array_values((array) $cells) as $c => $cell) {
                        if (\is_string($cell) && '' !== trim($cell)) {
                            $sources[] = $base + ['key' => "$table.$id.$field.$r.$c", 'row' => $r, 'col' => $c, 'kind' => 'line', 'value' => $cell, 'unit' => null];
                        }
                    }
                }
            } elseif (\is_string($value) && '' !== trim($value) && \strlen($value) <= self::MAX_LENGTH) {
                $sources[] = $base + ['key' => "$table.$id.$field", 'row' => null, 'col' => null, 'kind' => $kind, 'value' => $value, 'unit' => null];
            }
        }

        return $sources;
    }

    /**
     * The records of the child tables of a content element (tables with ptable
     * tl_content other than tl_content itself), in their order, then the
     * records it shows from other tables (RelatedRecords), unless $related is false.
     *
     * @return list<array{table: string, row: array<string, mixed>}>
     */
    public function children(int $elementId, bool $related = true): array
    {
        $this->load('tl_content');
        $children = [];

        foreach ((array) ($GLOBALS['TL_DCA']['tl_content']['config']['ctable'] ?? []) as $table) {
            if (!\is_string($table) || 'tl_content' === $table || !preg_match('/^[a-z0-9_]+$/', $table)) {
                continue;
            }

            $this->load($table);
            $config = $GLOBALS['TL_DCA'][$table]['config'] ?? [];

            if ('tl_content' !== ($config['ptable'] ?? null) && !($config['dynamicPtable'] ?? false)) {
                continue;
            }

            $columns = $this->columns[$table] ??= array_map('strtolower', array_keys($this->db->createSchemaManager()->listTableColumns($table)));
            $where = 'pid = ?'.(($config['dynamicPtable'] ?? false) && \in_array('ptable', $columns, true) ? " AND ptable = 'tl_content'" : '');
            $order = \in_array('sorting', $columns, true) ? ' ORDER BY sorting, id' : ' ORDER BY id';

            foreach ($this->db->fetchAllAssociative("SELECT * FROM $table WHERE $where$order", [$elementId]) as $row) {
                $children[] = ['table' => $table, 'row' => $row];
            }
        }

        $element = $related ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$elementId]) : false;

        return false !== $element ? [...$children, ...$this->related->of($element)] : $children;
    }

    /**
     * The record with the values of the given sources wrapped in markers, and
     * the keys by number of the markers.
     *
     * @param array<string, mixed>                                                        $row
     * @param list<array{key: string, field: string, row: int|null, col: int|null, kind: string}> $sources
     *
     * @return array{changes: array<string, string>, keys: array<int, string>}
     */
    public function mark(array $row, array $sources): array
    {
        $changes = [];
        $keys = [];
        $lists = [];

        foreach ($sources as $number => $source) {
            $field = $source['field'];
            $keys[$number] = $source['key'];

            if (\in_array($source['kind'], ['headline', 'unit'], true)) {
                $unit = StringUtil::deserialize($row[$field] ?? '', true);
                $unit['value'] = Markers::wrap((string) ($unit['value'] ?? ''), $number);
                $changes[$field] = serialize($unit);
            } elseif (null !== $source['row']) {
                $lists[$field] ??= array_values(StringUtil::deserialize($row[$field] ?? '', true));

                if (null === $source['col']) {
                    $lists[$field][$source['row']] = Markers::wrap((string) $lists[$field][$source['row']], $number);
                } else {
                    $cells = (array) $lists[$field][$source['row']];
                    $name = array_keys($cells)[$source['col']];
                    $cells[$name] = Markers::wrap((string) $cells[$name], $number);
                    $lists[$field][$source['row']] = $cells;
                }
            } else {
                $changes[$field] = Markers::wrap((string) ($row[$field] ?? ''), $number);
            }
        }

        foreach ($lists as $field => $list) {
            $changes[$field] = serialize($list);
        }

        return ['changes' => $changes, 'keys' => $keys];
    }

    /** Label of a field in the language of the back end, with the row or cell of a list or table. */
    public function label(string $table, string $field, int|null $row = null, int|null $col = null): string
    {
        $this->load($table);
        $label = $GLOBALS['TL_DCA'][$table]['fields'][$field]['label'] ?? $GLOBALS['TL_LANG'][$table][$field] ?? $field;
        $label = (string) (\is_array($label) ? ($label[0] ?? $field) : $label);

        return match (true) {
            null !== $row && null !== $col => \sprintf('%s (%d/%d)', $label, $row + 1, $col + 1),
            null !== $row => \sprintf('%s (%d)', $label, $row + 1),
            default => $label,
        };
    }

    private function load(string $table): void
    {
        $this->framework->initialize();
        Controller::loadDataContainer($table);
        System::loadLanguageFile($table);
    }
}
