<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Posts;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Model;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The kinds of posts the editor offers: Contao's news, events and FAQ, and
 * every module of an extension built the same way. A table counts as a post
 * type when a back-end module lists it after its archive table, its DCA shows
 * it below that archive (parent mode), it has a field "published" and a Contao
 * model. Pages of posts are found with Contao's content URL resolvers.
 *
 * @phpstan-type PostType array{table: string, module: string, label: string, archiveTable: string, archiveTitle: string, titleField: string, dateField: string|null, sortable: bool, hasContent: bool, kind: string}
 */
final class PostTypes
{
    /** Modules that are not posts (pages, articles, forms, users, newsletters, comments …). */
    private const NOT_POSTS = ['page', 'article', 'form', 'files', 'themes', 'tpl_editor', 'member', 'mgroup', 'user', 'group', 'login', 'security', 'favorites', 'comments', 'newsletter', 'undo', 'log', 'maintenance', 'preview_link', 'opt_in'];

    /** Kind of the known types, for the names of their archives ("archive", "calendar", "category"). */
    private const KINDS = ['tl_news' => 'news', 'tl_calendar_events' => 'events', 'tl_faq' => 'faq'];

    /** @var list<PostType>|null */
    private array|null $types = null;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        private readonly Connection $db,
    ) {
    }

    /**
     * The page that shows the posts of the archive of a post (field jumpTo of
     * the archive), for the licence check by the website's domain; 0 when the
     * archive names none.
     */
    public function pageOf(string $table, int $id): int
    {
        $type = $this->get($table);

        if (null === $type || !isset($GLOBALS['TL_DCA'][$type['archiveTable']]['fields']['jumpTo'])) {
            return 0;
        }

        return (int) $this->db->fetchOne(
            'SELECT a.jumpTo FROM '.$this->db->quoteIdentifier($type['archiveTable']).' a JOIN '.$this->db->quoteIdentifier($table).' p ON p.pid = a.id WHERE p.id = ?',
            [$id],
        );
    }

    /** @return list<PostType> the post types of the installation the user may open */
    public function all(): array
    {
        if (null !== $this->types) {
            return $this->types;
        }

        $this->framework->initialize();
        System::loadLanguageFile('modules');
        $types = [];

        foreach ($GLOBALS['BE_MOD'] ?? [] as $modules) {
            foreach ($modules as $module => $config) {
                if (\in_array($module, self::NOT_POSTS, true) || !\is_array($config['tables'] ?? null)) {
                    continue;
                }

                foreach ($this->typesOfModule((string) $module, array_values($config['tables'])) as $type) {
                    $types[$type['table']] ??= $type;
                }
            }
        }

        return $this->types = array_values(array_filter(
            $types,
            fn (array $type): bool => $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $type['module']),
        ));
    }

    /** @return PostType|null */
    public function get(string $table): array|null
    {
        foreach ($this->all() as $type) {
            if ($type['table'] === $table) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<PostType>
     */
    private function typesOfModule(string $module, array $tables): array
    {
        $types = [];

        foreach ($tables as $table) {
            if ('tl_content' === $table || !\is_string($table)) {
                continue;
            }

            Controller::loadDataContainer($table);
            $dca = $GLOBALS['TL_DCA'][$table] ?? null;
            $archive = $dca['config']['ptable'] ?? null;

            if (
                !\is_array($dca) || !\is_string($archive) || !\in_array($archive, $tables, true)
                || DataContainer::MODE_PARENT !== (int) ($dca['list']['sorting']['mode'] ?? 0)
                || !isset($dca['fields']['published'])
                || !self::hasModel($table)
            ) {
                continue;
            }

            Controller::loadDataContainer($archive);
            $sorting = array_map(static fn (string $field): string => trim(explode(' ', trim($field))[0]), (array) ($dca['list']['sorting']['fields'] ?? []));
            $dateField = array_values(array_filter($sorting, static fn (string $field): bool => 'sorting' !== $field && isset($dca['fields'][$field])))[0] ?? null;

            $types[] = [
                'table' => $table,
                'module' => $module,
                'label' => (string) ($GLOBALS['TL_LANG']['MOD'][$module][0] ?? $module),
                'archiveTable' => $archive,
                'archiveTitle' => $this->firstField($GLOBALS['TL_DCA'][$archive] ?? [], ['title', 'name']),
                'titleField' => $this->firstField($dca, ['headline', 'title', 'question', 'name']),
                'dateField' => $dateField,
                'sortable' => \in_array('sorting', $sorting, true),
                'hasContent' => \in_array('tl_content', (array) ($dca['config']['ctable'] ?? []), true),
                'kind' => self::KINDS[$table] ?? 'other',
            ];
        }

        return $types;
    }

    /**
     * Whether a Contao model is registered for the table. From Contao 5.7 on,
     * Model::getClassFromTable() throws for a table without one (e.g. a table
     * of an extension that is not a post at all).
     */
    private static function hasModel(string $table): bool
    {
        try {
            return class_exists(Model::getClassFromTable($table));
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * The first label field of the list, else the first of the given fields that exists.
     *
     * @param array<string, mixed> $dca
     * @param list<string>         $candidates
     */
    private function firstField(array $dca, array $candidates): string
    {
        $label = $dca['list']['label']['fields'][0] ?? null;

        if (\is_string($label) && isset($dca['fields'][$label])) {
            return $label;
        }

        foreach ($candidates as $field) {
            if (isset($dca['fields'][$field])) {
                return $field;
            }
        }

        return 'id';
    }
}
