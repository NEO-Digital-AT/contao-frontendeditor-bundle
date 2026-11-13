<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\System;

/**
 * Which of the inline fields a content element type has in its back-end form,
 * and which text fields a record of any table shows in its form (the sources
 * of texts on the page). Reads the palettes as Contao has built them
 * (including the changes of other extensions and their onpalette callbacks);
 * the DCA must be loaded.
 */
final class Palette
{
    /** @return list<string> inline fields of the type, in the order of ElementAccess::INLINE_FIELDS */
    public static function inlineFields(string $type): array
    {
        $dca = $GLOBALS['TL_DCA']['tl_content'] ?? [];
        $palette = (string) ($dca['palettes'][$type] ?? '');
        $fields = [];

        if (self::contains($palette, 'headline')) {
            $fields[] = 'headline';
        }

        // Rich text only (TinyMCE), not code or plain text areas.
        if (self::contains($palette, 'text') && 'tinyMCE' === ($dca['fields']['text']['eval']['rte'] ?? null)) {
            $fields[] = 'text';
        }

        // Image elements: the file and, through "overwrite metadata", alt text and caption.
        if (self::contains($palette, 'singleSRC') && self::contains($palette, 'overwriteMeta')) {
            $sub = (string) ($dca['subpalettes']['overwriteMeta'] ?? '');
            $fields[] = 'singleSRC';

            foreach (['alt', 'caption'] as $field) {
                if (self::contains($sub, $field)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * The other text fields of the type that the editor changes on the page when
     * it finds their value there (the editor decides that in the browser): text
     * inputs and text areas, plain or with TinyMCE, in the palette and in the
     * subpalettes of switches that are on for the record. Not code (ACE), not
     * hidden input, no picker, no input with several values, and none of the
     * fields edited on the page in their own way (inlineFields()).
     *
     * @param array<string, mixed> $record
     *
     * @return list<array{field: string, kind: 'text'|'textarea'|'rich'}>
     */
    public static function textFields(string $type, array $record): array
    {
        $dca = $GLOBALS['TL_DCA']['tl_content'] ?? [];
        $own = self::inlineFields($type);
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes('tl_content', (string) ($dca['palettes'][$type] ?? ''), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;
            $kind = \is_array($config) && !\in_array($field, $own, true) ? self::textKind($config) : null;

            if (null !== $kind) {
                $fields[] = ['field' => $field, 'kind' => $kind];
            }
        }

        return $fields;
    }

    /**
     * The fields of a record's palette (with the subpalettes that are on) whose
     * values are texts a template may show: a line (text input), lines (plain
     * text area), rich text (TinyMCE), a value with a unit (a headline and its
     * level), the rows of a list (listWizard) or the cells of a table
     * (tableWizard, keyValueWizard: key and value of each row; rowWizard with
     * text columns only, e.g. term and description of a description list). For tl_content
     * without the image (singleSRC).
     *
     * @param array<string, mixed> $record
     *
     * @return list<array{field: string, kind: 'line'|'lines'|'rich'|'unit'|'list'|'table'}>
     */
    public static function sources(string $table, array $record): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;

            if (!\is_array($config)) {
                continue;
            }

            $kind = match ($config['inputType'] ?? null) {
                'inputUnit' => 'unit',
                'listWizard' => 'list',
                'tableWizard', 'keyValueWizard' => 'table',
                'rowWizard' => self::textRows($config) ? 'table' : null,
                default => ['text' => 'line', 'textarea' => 'lines', 'rich' => 'rich'][self::textKind($config) ?? ''] ?? null,
            };

            if (null !== $kind && !($config['eval']['hideInput'] ?? false) && !($config['eval']['readonly'] ?? false)) {
                $fields[] = ['field' => $field, 'kind' => $kind];
            }
        }

        return $fields;
    }

    /**
     * The code fields of a record's palette (a text area with Contao's code
     * editor, e.g. the HTML of an HTML element), with the language the editor
     * is set to ("html", "css", "js" …; "text" without one). They are not
     * edited on the page; translation shows them.
     *
     * @param array<string, mixed> $record
     *
     * @return list<array{field: string, mode: string}>
     */
    public static function codeFields(string $table, array $record): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;
            $eval = \is_array($config) ? (array) ($config['eval'] ?? []) : [];
            $rte = (string) ($eval['rte'] ?? '');

            if (!\is_array($config) || 'textarea' !== ($config['inputType'] ?? null) || !str_starts_with($rte, 'ace') || ($eval['hideInput'] ?? false) || ($eval['readonly'] ?? false) || ($eval['disabled'] ?? false)) {
                continue;
            }

            $fields[] = ['field' => $field, 'mode' => strtolower(explode('|', $rte)[1] ?? '') ?: 'text'];
        }

        return $fields;
    }

    /**
     * The file pickers of a record's palette (with the subpalettes that are
     * on), single and multiple.
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    public static function fileFields(string $table, array $record): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];

        return array_values(array_filter(
            self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)),
            static fn (string $field): bool => 'fileTree' === ($dca['fields'][$field]['inputType'] ?? null),
        ));
    }

    /**
     * The single-file pickers of a record's palette (with the subpalettes that
     * are on) that take images: the image of an element, also one its template
     * shows as a background.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $imageExtensions
     *
     * @return list<string>
     */
    public static function imageFields(string $table, array $record, array $imageExtensions): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;
            $eval = \is_array($config) ? (array) ($config['eval'] ?? []) : [];

            if (!\is_array($config) || 'fileTree' !== ($config['inputType'] ?? null) || ($eval['multiple'] ?? false) || 'checkbox' === ($eval['fieldType'] ?? null)) {
                continue;
            }

            $extensions = array_filter(array_map('trim', explode(',', strtolower((string) ($eval['extensions'] ?? '')))));

            if ([] === $extensions || array_intersect($extensions, $imageExtensions)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The link fields of a record's palette (with the subpalettes that are on):
     * text inputs with Contao's link picker or for a URL, as the target of a
     * link the template shows.
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    public static function linkFields(string $table, array $record): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;
            $eval = \is_array($config) ? (array) ($config['eval'] ?? []) : [];

            if (!\is_array($config) || 'text' !== ($config['inputType'] ?? null) || ($eval['multiple'] ?? false) || ($eval['hideInput'] ?? false) || ($eval['readonly'] ?? false) || ($eval['disabled'] ?? false)) {
                continue;
            }

            if (($eval['dcaPicker'] ?? false) || 'url' === ($eval['rgxp'] ?? null)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The fields of a record's palette (with the subpalettes that are on) that
     * point to records of another table through a relation of the data
     * container ("foreignKey" or "relation"), with that table.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, string> table by field
     */
    public static function relationFields(string $table, array $record): array
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $fields = [];

        foreach (self::fieldsOf(self::withSubpalettes($table, self::paletteOf($table, $record), $record)) as $field) {
            $config = $dca['fields'][$field] ?? null;

            if (!\is_array($config)) {
                continue;
            }

            $target = (string) ($config['relation']['table'] ?? explode('.', (string) ($config['foreignKey'] ?? ''), 2)[0]);

            if ('' !== $target) {
                $fields[$field] = $target;
            }
        }

        return $fields;
    }

    /**
     * The palette of a record as Contao chooses it: the one named after the value
     * of a selector field (the type of an element or module), otherwise "default".
     *
     * @param array<string, mixed> $record
     */
    public static function paletteOf(string $table, array $record): string
    {
        $palettes = (array) ($GLOBALS['TL_DCA'][$table]['palettes'] ?? []);
        $selectors = (array) ($palettes['__selector__'] ?? []);
        $palette = (string) ($palettes['default'] ?? '');

        foreach (['type', ...$selectors] as $selector) {
            $value = \is_string($selector) ? (string) ($record[$selector] ?? '') : '';

            if ('' !== $value && '__selector__' !== $value && \is_string($palettes[$value] ?? null)) {
                $palette = $palettes[$value];
                break;
            }
        }

        return self::withCallbacks($table, $palette, $record);
    }

    /**
     * The palette as Contao's onpalette callbacks change it for the record, as
     * in Contao's form: e.g. the section headline of an element in an
     * accordion. A callback that only works in Contao's form (or a parent the
     * user may not read) leaves the palette as it is.
     *
     * @param array<string, mixed> $record
     */
    private static function withCallbacks(string $table, string $palette, array $record): string
    {
        $callbacks = $GLOBALS['TL_DCA'][$table]['config']['onpalette_callback'] ?? null;

        if (!\is_array($callbacks) || [] === $callbacks || !isset($record['id'])) {
            return $palette;
        }

        $dc = new EditorDataContainer($table, $record);

        foreach ($callbacks as $callback) {
            try {
                if (\is_array($callback)) {
                    $palette = (string) System::importStatic($callback[0])->{$callback[1]}($palette, $dc);
                } elseif (\is_callable($callback)) {
                    $palette = (string) $callback($palette, $dc);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $palette;
    }

    /**
     * @param array<string, mixed> $config field of the DCA
     *
     * @return 'text'|'textarea'|'rich'|null
     */
    /**
     * A rowWizard whose columns are all text inputs (Contao's description list: term and description); with a
     * select, check box or picker among them the rows stay in Contao's form.
     *
     * @param array<string, mixed> $config
     */
    private static function textRows(array $config): bool
    {
        $columns = (array) ($config['fields'] ?? []);

        return [] !== $columns && [] === array_filter($columns, static fn (mixed $column): bool => !\is_array($column) || !\in_array($column['inputType'] ?? null, ['text', 'textarea'], true));
    }

    private static function textKind(array $config): string|null
    {
        $eval = (array) ($config['eval'] ?? []);
        $picker = ($eval['dcaPicker'] ?? false) || ($eval['datepicker'] ?? false) || ($eval['colorpicker'] ?? false) || !empty($config['wizard']);

        if ($picker || ($eval['multiple'] ?? false) || ($eval['hideInput'] ?? false) || ($eval['readonly'] ?? false) || ($eval['disabled'] ?? false)) {
            return null;
        }

        $inputType = $config['inputType'] ?? null;
        $rte = (string) ($eval['rte'] ?? '');

        if ('text' === $inputType) {
            // Dates are stored as time stamps and shown formatted; an alias is part of an address, not a text.
            return \in_array($eval['rgxp'] ?? null, ['date', 'time', 'datim', 'alias', 'folderalias'], true) ? null : 'text';
        }

        if ('textarea' !== $inputType || str_starts_with($rte, 'ace')) {
            return null;
        }

        return str_starts_with($rte, 'tiny') ? 'rich' : 'textarea';
    }

    /**
     * The palette with the subpalettes of its switches that are on for the
     * record, as Contao's form shows it (a checkbox: subpalette of the field,
     * a selection: subpalette of the field and the chosen value).
     *
     * @param array<string, mixed> $record
     */
    private static function withSubpalettes(string $table, string $palette, array $record): string
    {
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];

        foreach ((array) ($dca['palettes']['__selector__'] ?? []) as $selector) {
            $value = $record[$selector] ?? null;

            if (!\is_string($selector) || !self::contains($palette, $selector) || null === $value || '' === $value || '0' === (string) $value) {
                continue;
            }

            $checkbox = 'checkbox' === ($dca['fields'][$selector]['inputType'] ?? null) && !($dca['fields'][$selector]['eval']['multiple'] ?? false);
            $palette .= ','.(string) ($dca['subpalettes'][$checkbox ? $selector : $selector.'_'.$value] ?? '');
        }

        return $palette;
    }

    /** @return list<string> the field names of a palette, without legends */
    private static function fieldsOf(string $palette): array
    {
        $names = preg_split('/[,;]/', (string) preg_replace('/\{[^}]*\}/', '', $palette)) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $names), static fn (string $name): bool => '' !== $name && !str_starts_with($name, '['))));
    }

    private static function contains(string $palette, string $field): bool
    {
        return 1 === preg_match('/(?:^|[,;}])'.preg_quote($field, '/').'(?=[,;{]|$)/', $palette);
    }
}
