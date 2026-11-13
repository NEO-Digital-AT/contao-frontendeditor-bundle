<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Contao\System;
use Contao\Widget;
use Neo\FrontendEditorBundle\Content\EditorDataContainer;
use Neo\FrontendEditorBundle\Content\FieldWriter;
use Neo\FrontendEditorBundle\Content\FileMeta;
use Neo\FrontendEditorBundle\Content\Palette;
use Neo\FrontendEditorBundle\Content\RelatedRecords;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Content\WriteStatus;

/**
 * Everything of a content element that a translation covers, whatever made the
 * field (Contao, the website, another extension): every text, rich text and
 * code field of its palette, every row of a list and cell of a table, the same
 * of its entries (records of child tables of tl_content, e.g. the cards of a
 * card list) and the metadata of its images (title, alternative text, caption
 * per language). Not covered: choices, check boxes, links and other pickers,
 * dates, CSS ID and classes (Palette).
 *
 * Every text has an address that is the same on the element and on its
 * version, so the two sides can be paired: "e|FIELD[.ROW[.COL]]" for the
 * element, "c.TABLE.GROUP.N|…" for its N-th entry of a kind (GROUP: the value of
 * the entry's type field, e.g. the slot), "…|@FIELD.N.KEY" for a text of the
 * metadata of the N-th image of a file field.
 */
final class TranslationUnits
{
    /** Code a translation service gets: HTML as HTML (its tags stay), Markdown as text. */
    private const MACHINE_CODE = ['html' => true, 'markdown' => false];

    /** Of a page only its title and meta data are texts; its other text fields are settings. */
    private const ONLY = ['tl_page' => ['title', 'pageTitle', 'description']];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TextSources $sources,
        private readonly FileMeta $files,
        private readonly FieldWriter $writer,
        private readonly RelatedRecords $related,
    ) {
    }

    /**
     * The texts of an element in the main language, each with its counterpart
     * in the version ($target null: the version has no element yet).
     *
     * @param array<string, mixed>      $main
     * @param array<string, mixed>|null $target
     *
     * @return list<array{address: string, group: string, label: string, kind: string, mode: string|null, machine: bool, html: bool, source: string, target: string, place: bool, table: string, field: string, file: int|null}>
     */
    public function pair(array $main, array|null $target, string $from, string $to, string $table = 'tl_content'): array
    {
        $source = $this->collect($main, $from, true, $table);
        $version = null !== $target ? $this->collect($target, $to, true, $table) : null;
        $units = [];

        foreach ($source['slots'] as $address => $slot) {
            if ('' === trim($slot['value'])) {
                continue;
            }

            $units[] = [
                'address' => $address,
                'group' => $slot['group'],
                'label' => $slot['label'],
                'kind' => $slot['kind'],
                'mode' => $slot['mode'],
                'machine' => $slot['machine'],
                'html' => $slot['html'],
                'source' => $slot['value'],
                'target' => (string) ($version['slots'][$address]['value'] ?? ''),
                'place' => null !== $version && $this->hasPlace($version, $address, $slot, $source),
                'table' => (string) $source['records'][$slot['scope']]['table'],
                'field' => (string) $slot['field'],
                'file' => 'meta' === $slot['kind'] ? ($version['images'][$slot['scope']][$slot['image']] ?? null) : null,
            ];
        }

        return $units;
    }

    /**
     * The texts of an element in a language by address, for the state of its
     * translations (SourceHash); without the records it shows from other
     * tables when $related is false (the state before they were covered).
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, string>
     */
    public function texts(array $element, string $language, bool $related = true, string $table = 'tl_content'): array
    {
        $texts = [];

        foreach ($this->collect($element, $language, $related, $table)['slots'] as $address => $slot) {
            if ('' !== trim($slot['value'])) {
                $texts[$address] = trim($slot['value']);
            }
        }

        ksort($texts);

        return $texts;
    }

    /**
     * Saves translated texts into the version, by address; texts the version
     * has no place for are reported, the rest is saved.
     *
     * @param array<string, mixed>  $main
     * @param array<string, mixed>  $target
     * @param array<string, string> $values
     *
     * @return array{saved: int, failed: list<string>, invalid: list<string>}
     */
    public function save(array $main, array $target, string $from, string $to, array $values, string $table = 'tl_content'): array
    {
        $source = $this->collect($main, $from, true, $table);
        $version = $this->collect($target, $to, true, $table);
        $inputs = [];
        $metas = [];
        $failed = [];

        foreach ($values as $address => $value) {
            $slot = $source['slots'][$address] ?? null;

            if (null === $slot || !\is_string($value)) {
                continue;
            }

            if (!$this->hasPlace($version, $address, $slot, $source)) {
                $failed[] = '' !== $slot['group'] ? $slot['group'].': '.$slot['label'] : $slot['label'];

                continue;
            }

            $scope = $slot['scope'];

            if ('meta' === $slot['kind']) {
                $metas[$version['images'][$scope][$slot['image']]][$slot['meta']] = $value;

                continue;
            }

            $record = $version['records'][$scope]['row'];
            $field = $slot['field'];

            if (null !== $slot['row']) {
                // The whole list or table: rows the version lacks come from the main language.
                $list = $inputs[$scope][$field] ?? array_values(StringUtil::deserialize($record[$field] ?? '', true));
                $mainList = array_values(StringUtil::deserialize($source['records'][$scope]['row'][$field] ?? '', true));

                for ($i = \count($list); $i <= $slot['row']; ++$i) {
                    $list[$i] = $mainList[$i] ?? (null !== $slot['col'] ? [] : '');
                }

                if (null === $slot['col']) {
                    $list[$slot['row']] = $value;
                } else {
                    $cells = (array) $list[$slot['row']];
                    $names = array_keys([] !== $cells ? $cells : (array) ($mainList[$slot['row']] ?? []));
                    $cells[$names[$slot['col']] ?? $slot['col']] = $value;
                    $list[$slot['row']] = $cells;
                }

                $inputs[$scope][$field] = $list;
            } elseif (\in_array($slot['kind'], ['headline', 'unit'], true)) {
                $unit = StringUtil::deserialize($record[$field] ?? '', true);
                $inputs[$scope][$field] = ['value' => $value, 'unit' => (string) (($unit['unit'] ?? '') ?: $slot['unit'])];
            } else {
                $inputs[$scope][$field] = $value;
            }
        }

        $saved = 0;
        $invalid = [];

        foreach ($inputs as $scope => $input) {
            $record = $version['records'][$scope];
            $result = match (true) {
                'e' === $scope && 'tl_content' === $table => $this->writer->update((int) $record['row']['id'], $input),
                'e' === $scope => $this->writer->updateOwn($table, (int) $record['row']['id'], $input),
                'tl_content' === $table => $this->writer->updateRecord($record['table'], (int) $record['row']['id'], (int) $target['id'], $input),
                default => $this->writer->updateEntryOf($table, $target, $record['table'], (int) $record['row']['id'], $input),
            };

            if (WriteStatus::Saved === $result->status) {
                $saved += \count($input);
            } elseif (WriteStatus::Invalid === $result->status) {
                foreach ($result->errors as $messages) {
                    array_push($invalid, ...array_map('strval', (array) $messages));
                }
            } else {
                $failed[] = $this->groupLabel($scope, $record);
            }
        }

        foreach ($metas as $fileId => $texts) {
            if ($this->files->save((int) $fileId, $to, $texts)) {
                $saved += \count($texts);
            } else {
                $failed[] = basename((string) ($this->imagePath($version, (int) $fileId) ?? ''));
            }
        }

        return ['saved' => $saved, 'failed' => array_values(array_unique(array_filter($failed))), 'invalid' => array_values(array_unique($invalid))];
    }

    /**
     * @param array{slots: array<string, array<string, mixed>>, records: array<string, array<string, mixed>>, fields: array<string, array<string, true>>, images: array<string, array<string, int>>} $version
     * @param array<string, mixed>                                                                                                                                                             $slot
     * @param array{records: array<string, array{table: string, row: array<string, mixed>}>}                                                                                                   $main
     */
    private function hasPlace(array $version, string $address, array $slot, array $main): bool
    {
        $scope = $slot['scope'];
        $own = $version['records'][$scope] ?? null;
        $theirs = $main['records'][$scope] ?? null;

        // The same record on both sides (both versions show one FAQ category): a translation would overwrite the original.
        if (null !== $own && null !== $theirs && $own['table'] === $theirs['table'] && (int) $own['row']['id'] === (int) $theirs['row']['id']) {
            return false;
        }

        if (isset($version['slots'][$address])) {
            return true;
        }

        if ('meta' === $slot['kind']) {
            return isset($version['images'][$scope][$slot['image']]);
        }

        // A row of a list or table the version does not have yet: the field is there.
        return null !== $slot['row'] && isset($version['fields'][$scope][$slot['field']]);
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array{slots: array<string, array<string, mixed>>, records: array<string, array{table: string, row: array<string, mixed>}>, fields: array<string, array<string, true>>, images: array<string, array<string, int>>}
     */
    private function collect(array $element, string $language, bool $related = true, string $table = 'tl_content'): array
    {
        $this->framework->initialize();
        Controller::loadDataContainer($table);
        System::loadLanguageFile($table);
        System::loadLanguageFile('default');

        $result = ['slots' => [], 'records' => [], 'fields' => [], 'images' => []];
        $this->collectRecord($result, 'e', $table, $element, '', $language);
        $counts = [];
        // An element: its entries and the records it shows from other tables. Another record (a post, a
        // page, an entry of a module): the entries of its child tables (not its content elements, which are
        // translated as elements) and the records it shows from other tables.
        $children = 'tl_content' === $table
            ? $this->sources->children((int) $element['id'], $related)
            : [...$this->related->entries($table, (int) $element['id'], false), ...($related ? $this->related->of($element, $table) : [])];

        foreach ($children as ['table' => $table, 'row' => $row]) {
            $group = $this->groupOf($table, $row);
            $n = $counts[$table][$group] = ($counts[$table][$group] ?? -1) + 1;
            $scope = "c.$table.$group.$n";
            $this->collectRecord($result, $scope, $table, $row, $this->entryLabel($table, $row, $group, $n), $language);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $row
     */
    private function collectRecord(array &$result, string $scope, string $table, array $row, string $group, string $language): void
    {
        $result['records'][$scope] = ['table' => $table, 'row' => $row];
        $add = function (string $address, array $slot) use (&$result, $scope, $group): void {
            // A plain text with markup (a line break in a headline): the service gets it as HTML, so the tags stay.
            if ('code' !== $slot['kind'] && !$slot['html'] && 1 === preg_match('~</?[a-z][^>]*>~i', $slot['value'])) {
                $slot['html'] = true;
            }

            $result['slots'][$address] = $slot + ['scope' => $scope, 'group' => $group, 'row' => null, 'col' => null, 'unit' => '', 'mode' => null, 'image' => null, 'meta' => null];
        };

        foreach (Palette::sources($table, $row) as ['field' => $field, 'kind' => $kind]) {
            if (('tl_content' === $table && 'singleSRC' === $field) || (isset(self::ONLY[$table]) && !\in_array($field, self::ONLY[$table], true))) {
                continue;
            }

            $result['fields'][$scope][$field] = true;
            $value = $row[$field] ?? '';
            $base = ['field' => $field, 'label' => $this->sources->label($table, $field), 'machine' => true, 'html' => false];

            if ('unit' === $kind && FieldWriter::isPlainUnit($table, $field, $row)) {
                $add("$scope|$field", $base + ['kind' => 'line', 'value' => self::plain((string) $value)]);
            } elseif ('unit' === $kind) {
                $unit = StringUtil::deserialize($value, true);
                $add("$scope|$field", $base + ['kind' => 'tl_content' === $table && 'headline' === $field ? 'headline' : 'unit', 'value' => self::plain((string) ($unit['value'] ?? '')), 'unit' => (string) ($unit['unit'] ?? '')]);
            } elseif ('list' === $kind) {
                foreach (array_values(StringUtil::deserialize($value, true)) as $r => $item) {
                    if (\is_string($item)) {
                        $add("$scope|$field.$r", ['label' => $this->sources->label($table, $field, $r)] + $base + ['kind' => 'line', 'value' => self::plain($item), 'row' => $r]);
                    }
                }
            } elseif ('table' === $kind) {
                foreach (array_values(StringUtil::deserialize($value, true)) as $r => $cells) {
                    foreach (array_values((array) $cells) as $c => $cell) {
                        if (\is_string($cell)) {
                            $add("$scope|$field.$r.$c", ['label' => $this->sources->label($table, $field, $r, $c)] + $base + ['kind' => 'line', 'value' => self::plain($cell), 'row' => $r, 'col' => $c]);
                        }
                    }
                }
            } else {
                $add("$scope|$field", ['html' => 'rich' === $kind] + $base + ['kind' => $kind, 'value' => 'rich' === $kind ? (string) $value : self::plain((string) $value)]);
            }
        }

        foreach (Palette::codeFields($table, $row) as ['field' => $field, 'mode' => $mode]) {
            $result['fields'][$scope][$field] = true;
            $add("$scope|$field", ['field' => $field, 'label' => $this->sources->label($table, $field), 'kind' => 'code', 'mode' => $mode, 'machine' => isset(self::MACHINE_CODE[$mode]), 'html' => self::MACHINE_CODE[$mode] ?? false, 'value' => (string) ($row[$field] ?? '')]);
        }

        foreach ($this->files->imagesOf($table, $row) as $image) {
            $key = $image['field'].'.'.$image['index'];
            $result['images'][$scope][$key] = $image['id'];
            $name = basename($image['path']);

            foreach ($this->files->texts($image['id'], $language) as $meta => $text) {
                $label = (string) ($GLOBALS['TL_LANG']['MSC']['aw_'.$meta] ?? $meta);
                $add("$scope|@$key.$meta", ['field' => $image['field'], 'label' => "$name: $label", 'kind' => 'meta', 'machine' => true, 'html' => false, 'value' => self::plain($text), 'image' => $key, 'meta' => $meta]);
            }
        }
    }

    /** The name of an element type in the language of the back end. */
    public function typeLabel(string $type): string
    {
        $this->framework->initialize();
        System::loadLanguageFile('default');
        $label = $GLOBALS['TL_LANG']['CTE'][$type] ?? $type;

        return (string) (\is_array($label) ? ($label[0] ?? $type) : $label);
    }

    /** The kind of an entry: the value of the first choice its palettes depend on (e.g. the slot), '' without one. */
    private function groupOf(string $table, array $row): string
    {
        foreach ((array) ($GLOBALS['TL_DCA'][$table]['palettes']['__selector__'] ?? []) as $selector) {
            if (\is_string($selector) && 'select' === ($GLOBALS['TL_DCA'][$table]['fields'][$selector]['inputType'] ?? null)) {
                return preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($row[$selector] ?? '')) ?? '';
            }
        }

        return '';
    }

    /**
     * "<kind> <n>": the name of the entry's kind as its form shows it in the
     * choice (from the field's reference, options or options callback, through
     * Contao's widget), else the value itself or the table.
     *
     * @param array<string, mixed> $row
     */
    private function entryLabel(string $table, array $row, string $group, int $n): string
    {
        $name = '';

        foreach ((array) ($GLOBALS['TL_DCA'][$table]['palettes']['__selector__'] ?? []) as $selector) {
            $config = $GLOBALS['TL_DCA'][$table]['fields'][$selector] ?? null;

            if (!\is_string($selector) || !\is_array($config) || 'select' !== ($config['inputType'] ?? null)) {
                continue;
            }

            try {
                $attributes = Widget::getAttributesFromDca($config, $selector, $row[$selector] ?? null, $selector, $table, new EditorDataContainer($table, $row, $selector));
            } catch (\Throwable) {
                $attributes = [];
            }

            $name = self::optionLabel((array) ($attributes['options'] ?? []), (string) ($row[$selector] ?? ''));
            break;
        }

        if ('' === $name && '' === $group && '' !== ($title = $this->title($table, $row))) {
            return $title;
        }

        return trim(('' !== $name ? $name : ('' !== $group ? $group : $table)).' '.($n + 1));
    }

    /**
     * The title of a record without kinds (a question of a FAQ, an entry of a
     * catalogue): the first field of its label in Contao's list, shortened.
     *
     * @param array<string, mixed> $row
     */
    private function title(string $table, array $row): string
    {
        $field = (string) (((array) ($GLOBALS['TL_DCA'][$table]['list']['label']['fields'] ?? []))[0] ?? '');
        $text = trim(self::plain(strip_tags((string) ($row[$field] ?? ''))));

        return mb_strlen($text) > 48 ? rtrim(mb_substr($text, 0, 47)).'…' : $text;
    }

    /** @param array<int|string, mixed> $options options of a widget, also in groups */
    private static function optionLabel(array $options, string $value): string
    {
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }

            if (isset($option['value']) && (string) $option['value'] === $value) {
                return trim(strip_tags((string) ($option['label'] ?? '')));
            }

            if (!isset($option['value']) && '' !== ($label = self::optionLabel($option, $value))) {
                return $label;
            }
        }

        return '';
    }

    /** @param array{table: string, row: array<string, mixed>} $record */
    private function groupLabel(string $scope, array $record): string
    {
        if ('e' === $scope) {
            return $this->typeLabel((string) ($record['row']['type'] ?? ''));
        }

        [, $table, $group, $n] = explode('.', $scope) + [null, '', '', '0'];

        return $this->entryLabel((string) $table, $record['row'], (string) $group, (int) $n);
    }

    /** @param array{images: array<string, array<string, int>>, records: array<string, mixed>} $version */
    private function imagePath(array $version, int $fileId): string|null
    {
        foreach ($version['records'] as $record) {
            foreach ($this->files->imagesOf($record['table'], $record['row']) as $image) {
                if ($image['id'] === $fileId) {
                    return $image['path'];
                }
            }
        }

        return null;
    }

    /** A plain text as stored (special characters encoded) as the visitor reads it. */
    private static function plain(string $value): string
    {
        return StringUtil::decodeEntities($value);
    }
}
