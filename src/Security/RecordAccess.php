<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether the user may change a front-end module, layout, form field or
 * comment, decided by Contao's voters as in the back end. When Contao says no,
 * which of Contao's permissions of the user group is missing, named with
 * Contao's own labels in the language of the back end, so the administrator
 * knows what to set (back-end modules, theme permissions, allowed module or
 * field types, allowed forms, allowed fields, table permissions, the access
 * rights of the page).
 */
final class RecordAccess
{
    /** The back-end module each table belongs to. */
    public const MODULES = ['tl_module' => 'themes', 'tl_layout' => 'themes', 'tl_form_field' => 'form', 'tl_comments' => 'comments'];

    public function __construct(
        private readonly Security $security,
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{canEdit: bool, missing: list<array{field: string, value: string}>}
     */
    public function check(string $table, array $row): array
    {
        $module = self::MODULES[$table] ?? null;

        if (null !== $module && $this->granted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)
            && $this->granted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $row))) {
            return ['canEdit' => true, 'missing' => []];
        }

        return ['canEdit' => false, 'missing' => null === $module ? [] : $this->missing($table, $module, $row)];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{field: string, value: string}>
     */
    private function missing(string $table, string $module, array $row): array
    {
        $this->framework->initialize();

        foreach (['default', 'modules', 'tl_user', 'tl_user_group', 'tl_page'] as $file) {
            System::loadLanguageFile($file);
        }

        Controller::loadDataContainer($table);
        $missing = [];

        if (!$this->granted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)) {
            $missing[] = $this->entry('tl_user', 'modules', $this->label('MOD', $module));
        }

        if ('tl_module' === $table || 'tl_layout' === $table) {
            $part = 'tl_module' === $table ? 'modules' : 'layout';

            if (!$this->granted('contao_user.themes', $part)) {
                $missing[] = $this->entry('tl_user', 'themes', $this->label('MOD', $part));
            }
        }

        if ('tl_module' === $table && !$this->granted('contao_user.frontendModules', (string) $row['type'])) {
            $missing[] = $this->entry('tl_user', 'frontendModules', $this->label('FMD', (string) $row['type']));
        }

        if ('tl_form_field' === $table) {
            if (!$this->granted('contao_user.forms', (int) $row['pid'])) {
                $title = $this->db->fetchOne('SELECT title FROM tl_form WHERE id = ?', [(int) $row['pid']]);
                $missing[] = $this->entry('tl_user', 'forms', StringUtil::decodeEntities((string) ($title ?: $row['pid'])));
            }

            if (!$this->granted('contao_user.fields', (string) $row['type'])) {
                $missing[] = $this->entry('tl_user', 'fields', $this->label('FFL', (string) $row['type']));
            }
        }

        // Comments of a content element: the access rights "edit articles" of its page.
        if ('tl_comments' === $table && 'tl_content' === ($row['source'] ?? null) && !$this->granted('contao_comment.access', ['source' => 'tl_content', 'parent' => (int) $row['parent']])) {
            $page = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = (SELECT pid FROM tl_article WHERE id = (SELECT pid FROM tl_content WHERE id = ?))', [(int) $row['parent']]);

            if (false !== $page) {
                $missing[] = [
                    'field' => $this->text($GLOBALS['TL_LANG']['tl_page']['chmod'] ?? 'chmod').' · '.StringUtil::decodeEntities((string) $page['title']),
                    'value' => $this->text($GLOBALS['TL_LANG']['CHMOD']['editarticles'] ?? 'editarticles'),
                ];
            }
        }

        // Every field of the table is excluded: the user group needs at least one allowed field of it.
        if (!$this->granted('contao_user.can_edit_fields', $table)) {
            $missing[] = $this->entry('tl_user_group', 'alexf', $table);
        }

        // Since Contao 5.7 tables carry permissions for create, update and delete (table permissions).
        if (\in_array('update', (array) ($GLOBALS['TL_DCA'][$table]['config']['permissions'] ?? []), true) && !$this->granted('contao_user.cud', $table.'::update')) {
            $missing[] = $this->entry('tl_user', 'cud', $table.' → '.$this->text($GLOBALS['TL_LANG']['tl_user']['cud']['update'] ?? 'update'));
        }

        return $missing;
    }

    private function granted(string $attribute, mixed $subject): bool
    {
        try {
            return $this->security->isGranted($attribute, $subject);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{field: string, value: string} */
    private function entry(string $file, string $field, string $value): array
    {
        return ['field' => $this->text($GLOBALS['TL_LANG'][$file][$field] ?? $field), 'value' => $value];
    }

    private function label(string $group, string $key): string
    {
        foreach ($GLOBALS['TL_LANG'][$group] ?? [] as $name => $value) {
            if ($name === $key) {
                return $this->text($value);
            }
        }

        return $key;
    }

    private function text(mixed $value): string
    {
        return (string) (\is_array($value) ? ($value[0] ?? '') : $value);
    }
}
