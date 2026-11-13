<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Contao\Versions;
use Contao\Widget;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Cache\PageCacheTags;
use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Saves the fields the editor changes on the page, the way Contao's back-end
 * form does: the field's own widget reads and filters the value (allowed HTML
 * tags from the Contao settings, mandatory, length, rgxp, file mounts), the
 * field's save callbacks run, Contao's voters decide, a version is created and
 * the cache tags of the record are invalidated.
 *
 * Records: a content element (its inline fields and the text and code fields
 * of its palette), a record of a child table of a content element (the cards of a
 * card list) and a front-end module. Rows of a list (listWizard) and cells of a
 * table (tableWizard) are merged into the stored list before the widget
 * checks the whole of it. Markers of the source map are removed from every
 * value before anything else.
 */
final class FieldWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly ElementAccess $access,
        private readonly PageCacheTags $cacheTags,
        private readonly RecordAccess $records,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $router,
        private readonly RelatedRecords $related,
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $imageExtensions = [],
    ) {
    }

    /**
     * A content element.
     *
     * @param array<string, mixed>                                         $input posted values by field, as the back-end form posts them
     * @param array<string, array<int|string, string|array<int|string, string>>> $rows  changed rows of lists and cells of tables, by field
     */
    public function update(int $id, array $input, array $rows = []): WriteResult
    {
        $record = $this->load('tl_content', $id);

        if (null === $record) {
            return WriteResult::notFound();
        }

        $allowed = [...Palette::inlineFields((string) $record['type']), ...array_column(Palette::sources('tl_content', $record), 'field'), ...array_column(Palette::codeFields('tl_content', $record), 'field'), ...Palette::imageFields('tl_content', $record, $this->imageExtensions), ...Palette::linkFields('tl_content', $record)];

        if (!$this->access->canUpdate($record)) {
            return WriteResult::forbidden();
        }

        return $this->write('tl_content', $record, $input, $rows, $allowed, fn (array $new): bool => $this->access->canUpdate($record, $new), ['contao.db.tl_content.'.$id]);
    }

    /**
     * A record of a child table of the content element $elementId, a record the
     * element shows from another table (RelatedRecords), or a front-end module (tl_module).
     *
     * @param array<string, mixed>                                         $input
     * @param array<string, array<int|string, string|array<int|string, string>>> $rows
     */
    public function updateRecord(string $table, int $id, int $elementId, array $input, array $rows = []): WriteResult
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || 'tl_content' === $table) {
            return WriteResult::forbidden();
        }

        $record = $this->load($table, $id);

        if (null === $record) {
            return WriteResult::notFound();
        }

        $allowed = [...array_column(Palette::sources($table, $record), 'field'), ...array_column(Palette::codeFields($table, $record), 'field')];

        if ('tl_module' === $table) {
            if (!$this->access->has(Permission::EditContent) || !$this->records->check('tl_module', $record)['canEdit']) {
                return WriteResult::forbidden();
            }

            $granted = fn (array $new): bool => $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_module', new UpdateAction('tl_module', $record, $new));

            return $this->write('tl_module', $record, $input, $rows, $allowed, $granted, ['contao.db.tl_module.'.$id]);
        }

        // A child table of tl_content: the record belongs to the element, which the user may change.
        $element = $this->load('tl_content', $elementId);
        $config = $GLOBALS['TL_DCA'][$table]['config'] ?? [];
        $ownTable = \in_array($table, (array) ($GLOBALS['TL_DCA']['tl_content']['config']['ctable'] ?? []), true)
            && ('tl_content' === ($config['ptable'] ?? null) || (($config['dynamicPtable'] ?? false) && 'tl_content' === ($record['ptable'] ?? null)));

        // A record the element shows from another table (a FAQ category, its questions): the voters of that table decide below.
        $related = null !== $element && !$ownTable && $this->related->contains($element, $table, $id);

        if (null === $element || (!$related && (!$ownTable || (int) $record['pid'] !== $elementId)) || !$this->access->canUpdate($element)) {
            return WriteResult::forbidden();
        }

        $granted = fn (array $new): bool => $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $record, $new));

        return $this->write($table, $record, $input, $rows, $allowed, $granted, ["contao.db.$table.$id", 'contao.db.tl_content.'.$elementId]);
    }

    /**
     * A record of any table other than tl_content that is translated on its own:
     * a page (its title and meta data), a post, an archive, a front-end module,
     * an entry of an extension's back-end module. The voters of its table decide.
     *
     * @param array<string, mixed>                                         $input
     * @param array<string, array<int|string, string|array<int|string, string>>> $rows
     */
    public function updateOwn(string $table, int $id, array $input, array $rows = []): WriteResult
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || 'tl_content' === $table) {
            return WriteResult::forbidden();
        }

        if ('tl_module' === $table) {
            return $this->updateRecord('tl_module', $id, 0, $input, $rows);
        }

        $record = $this->load($table, $id);

        if (null === $record) {
            return WriteResult::notFound();
        }

        $allowed = [...array_column(Palette::sources($table, $record), 'field'), ...array_column(Palette::codeFields($table, $record), 'field')];
        $granted = fn (array $new): bool => $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $record, $new));

        if (!$granted([])) {
            return WriteResult::forbidden();
        }

        return $this->write($table, $record, $input, $rows, $allowed, $granted, ["contao.db.$table.$id"]);
    }

    /**
     * An entry of such a record (a record of a child table of its table) or a
     * record it shows from another table (RelatedRecords).
     *
     * @param array<string, mixed>                                         $parent the record of $parentTable
     * @param array<string, mixed>                                         $input
     * @param array<string, array<int|string, string|array<int|string, string>>> $rows
     */
    public function updateEntryOf(string $parentTable, array $parent, string $table, int $id, array $input, array $rows = []): WriteResult
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || \in_array($table, ['tl_content', 'tl_module'], true)) {
            return WriteResult::forbidden();
        }

        $record = $this->load($table, $id);

        if (null === $record) {
            return WriteResult::notFound();
        }

        Controller::loadDataContainer($parentTable);
        $config = $GLOBALS['TL_DCA'][$table]['config'] ?? [];
        $entry = \in_array($table, (array) ($GLOBALS['TL_DCA'][$parentTable]['config']['ctable'] ?? []), true)
            && (int) ($record['pid'] ?? 0) === (int) $parent['id']
            && ($parentTable === ($config['ptable'] ?? null) || (($config['dynamicPtable'] ?? false) && $parentTable === ($record['ptable'] ?? null)));

        if (!$entry && !$this->related->contains($parent, $table, $id, $parentTable)) {
            return WriteResult::forbidden();
        }

        $allowed = [...array_column(Palette::sources($table, $record), 'field'), ...array_column(Palette::codeFields($table, $record), 'field')];
        $granted = fn (array $new): bool => $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $record, $new));

        return $this->write($table, $record, $input, $rows, $allowed, $granted, ["contao.db.$table.$id", "contao.db.$parentTable.".(int) $parent['id']]);
    }

    /** @return array<string, mixed>|null */
    private function load(string $table, int $id): array|null
    {
        $this->framework->initialize();
        Controller::loadDataContainer($table);
        System::loadLanguageFile($table);
        System::loadLanguageFile('default');

        $record = $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]);

        return false === $record ? null : $record;
    }

    /**
     * @param array<string, mixed>                                          $record
     * @param array<string, mixed>                                          $input
     * @param array<string, array<int|string, string|array<int|string, string>>> $rows
     * @param list<string>                                                  $allowed
     * @param \Closure(array<string, mixed>): bool                          $granted the voters' decision on the new values
     * @param list<string>                                                  $tags
     */
    private function write(string $table, array $record, array $input, array $rows, array $allowed, \Closure $granted, array $tags): WriteResult
    {
        $input = self::clean($input);
        $rows = self::clean($rows);

        // Changed rows and cells go into the stored list or table, which the widget then checks as a whole.
        foreach ($rows as $field => $changes) {
            $list = array_values(StringUtil::deserialize($record[$field] ?? '', true));

            foreach ((array) $changes as $row => $change) {
                if (!isset($list[(int) $row])) {
                    return WriteResult::forbidden();
                }

                if (\is_array($change)) {
                    // Cells by position, also where they have names (key and value of a keyValueWizard).
                    $cells = (array) $list[(int) $row];
                    $names = array_keys($cells);

                    foreach ($change as $col => $cell) {
                        if (!isset($names[(int) $col])) {
                            return WriteResult::forbidden();
                        }

                        $cells[$names[(int) $col]] = (string) $cell;
                    }

                    $list[(int) $row] = $cells;
                } else {
                    $list[(int) $row] = (string) $change;
                }
            }

            $input[$field] = $list;
        }

        $fields = array_keys($input);

        if ([] === $fields || array_diff($fields, $allowed)) {
            return WriteResult::forbidden();
        }

        foreach ($fields as $field) {
            if (!$this->access->canEditField($field, $table)) {
                return WriteResult::forbidden();
            }
        }

        // Contao's file picker hands over the path; a file field stores the UUID.
        foreach ($input as $field => $value) {
            if ('fileTree' === ($GLOBALS['TL_DCA'][$table]['fields'][$field]['inputType'] ?? null) && \is_string($value) && '' !== $value && !Validator::isUuid($value)) {
                $file = FilesModel::findByPath($value);
                $input[$field] = null !== $file ? StringUtil::binToUuid($file->uuid) : '';
            }
        }

        $dc = new EditorDataContainer($table, $record);
        $new = [];
        $errors = [];

        foreach ($fields as $field) {
            // A value with a unit stored as plain text (a template that reads the field directly): checked by its
            // widget like any value of it and stored as plain text again.
            $plain = \is_string($input[$field]) && self::isPlainUnit($table, $field, $record);
            $value = $this->widgetValue($table, $field, $plain ? ['value' => $input[$field], 'unit' => self::anyUnit($table, $field)] : $input[$field], $record, $dc->forField($field), $errors);

            if (null !== $value && $plain) {
                $value = (string) (StringUtil::deserialize($value, true)['value'] ?? '');
            }

            if (null !== $value) {
                $new[$field] = $value;
            }
        }

        if ([] !== $errors) {
            return WriteResult::invalid($errors);
        }

        // Alt text and caption of an image element only apply with "overwrite metadata",
        // which the user must be allowed to switch on, as in the back-end form.
        if ('tl_content' === $table && (isset($new['alt']) || isset($new['caption'])) && !$record['overwriteMeta']) {
            if (!$this->access->canEditField('overwriteMeta')) {
                return WriteResult::forbidden();
            }

            $new['overwriteMeta'] = 1;
        }

        // Unchanged values are not saved, so no empty version is created.
        $new = array_filter($new, static fn (mixed $value, string $field): bool => (string) $value !== (string) $record[$field], ARRAY_FILTER_USE_BOTH);

        if ([] === $new) {
            return WriteResult::saved($record);
        }

        if (!$granted(array_replace($record, $new))) {
            return WriteResult::forbidden();
        }

        $id = (int) $record['id'];
        $versions = new Versions($table, $id);
        // The version links to the record's back-end form (not to this endpoint, whose request has no query).
        $versions->setEditUrl($this->editUrl($table, $record));
        $versions->initialize();

        $this->db->update($table, (isset($record['tstamp']) ? ['tstamp' => time()] : []) + $new, ['id' => $id]);

        $versions->create();

        // Pages that show the record are rendered again (Contao tags them with it).
        $this->cacheTags->invalidate($tags);

        return WriteResult::saved((array) $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]));
    }

    /**
     * The address of the record's form in the back end, as Contao keeps it with
     * a version: the back-end module of the table (for a content element the one
     * of its article or post), the table, the record.
     *
     * @param array<string, mixed> $record
     */
    private function editUrl(string $table, array $record): string
    {
        $parent = $table;
        $row = $record;

        // A content element belongs to the module of the record at the top of its parents (article, news, event …).
        for ($depth = 0; 'tl_content' === $parent && $depth < 10; ++$depth) {
            $parent = (string) ($row['ptable'] ?? 'tl_article') ?: 'tl_article';
            $row = 'tl_content' === $parent ? ($this->db->fetchAssociative('SELECT pid, ptable FROM tl_content WHERE id = ?', [(int) $row['pid']]) ?: []) : $row;
        }

        $module = RecordAccess::MODULES[$table] ?? null;

        foreach ($GLOBALS['BE_MOD'] ?? [] as $group) {
            foreach ((array) $group as $name => $config) {
                if (null === $module && \in_array($parent, (array) ($config['tables'] ?? []), true)) {
                    $module = (string) $name;
                }
            }
        }

        return ltrim($this->router->generate('contao_backend', ['do' => $module ?? 'article', 'table' => $table, 'id' => (int) $record['id'], 'act' => 'edit']), '/');
    }

    /**
     * Runs the posted value through the field's widget and save callbacks, as
     * DC_Table does. Returns the value for the database, null on an error.
     *
     * @param array<string, mixed>        $record
     * @param array<string, list<string>> $errors
     */
    private function widgetValue(string $table, string $field, mixed $posted, array $record, EditorDataContainer $dc, array &$errors): mixed
    {
        $config = $GLOBALS['TL_DCA'][$table]['fields'][$field];
        $class = $GLOBALS['BE_FFL'][$config['inputType']] ?? null;

        if (null === $class || !class_exists($class)) {
            $errors[$field][] = (string) $GLOBALS['TL_LANG']['ERR']['general'];

            return null;
        }

        // A rowWizard counts its rows by the hidden inputs [_rows] of the back-end form; without them it keeps none.
        if ('rowWizard' === ($config['inputType'] ?? null) && \is_array($posted)) {
            $posted = array_values($posted);
            $posted['_rows'] = array_fill(0, \count($posted), '');
        }

        // The widget reads the value from the request, like in the back-end form.
        $request = $this->requestStack->getCurrentRequest();
        $request?->request->set($field, $posted);

        /** @var Widget $widget */
        $widget = new $class($class::getAttributesFromDca($config, $field, $record[$field] ?? null, $field, $table, $dc));
        $widget->validate();

        if ($widget->hasErrors()) {
            $errors[$field][] = $widget->getErrorAsString();

            return null;
        }

        $value = $widget->value;

        foreach ($config['save_callback'] ?? [] as $callback) {
            try {
                $value = \is_array($callback)
                    ? System::importStatic($callback[0])->{$callback[1]}($value, $dc)
                    : $callback($value, $dc);
            } catch (\Exception $e) {
                $errors[$field][] = $e->getMessage();

                return null;
            }
        }

        return \is_array($value) ? serialize($value) : $value;
    }

    /**
     * Whether a field with a unit (a headline) holds plain text instead of Contao's value and unit.
     *
     * @param array<string, mixed> $record
     */
    public static function isPlainUnit(string $table, string $field, array $record): bool
    {
        $stored = $record[$field] ?? null;

        return 'inputUnit' === ($GLOBALS['TL_DCA'][$table]['fields'][$field]['inputType'] ?? null)
            && \is_string($stored) && '' !== trim($stored) && !\is_array(StringUtil::deserialize($stored));
    }

    /** A unit the widget accepts (it is not stored with a plain value). */
    private static function anyUnit(string $table, string $field): string
    {
        $options = (array) ($GLOBALS['TL_DCA'][$table]['fields'][$field]['options'] ?? []);

        return \in_array('h2', $options, true) ? 'h2' : (string) (array_is_list($options) ? ($options[0] ?? '') : (array_key_first($options) ?? ''));
    }

    /** Posted values without any marker of the source map (they must never be saved). */
    private static function clean(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) => Markers::strip($value),
            \is_array($value) => array_map(self::clean(...), $value),
            default => $value,
        };
    }
}
