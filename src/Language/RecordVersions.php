<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\Controller;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\DcaRunner;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The language versions of a record that is translated on its own: a page
 * (title and meta data), a post and its archive (news, events, FAQ and posts
 * of extensions), a front-end module, a record of another back-end module.
 *
 * Contao's own links come first: pages by changelanguage (LanguageVersions),
 * posts by changelanguage's field languageMain, their archives by its field
 * master. Records Contao does not link (modules, records of extensions) are
 * linked in RecordLinks. The language of a record: of its page (a page, a
 * post by its archive's page), of the pages that show it (an archive an
 * element shows), of its own field "language", or of its link.
 */
final class RecordVersions
{
    /** Tables of Contao itself: translated with Plus; the records of extensions need Pro. */
    public const CONTAO_TABLES = ['tl_page', 'tl_news', 'tl_news_archive', 'tl_calendar_events', 'tl_calendar', 'tl_faq', 'tl_faq_category'];

    /** Tables that are not translated as records: the structure, people, logs, forms and the settings of extensions. */
    private const NOT_TRANSLATED = [
        'tl_content', 'tl_article', 'tl_layout', 'tl_theme', 'tl_files', 'tl_image_size', 'tl_image_size_item',
        'tl_user', 'tl_user_group', 'tl_member', 'tl_member_group', 'tl_newsletter_recipients', 'tl_comments',
        'tl_log', 'tl_undo', 'tl_version', 'tl_opt_in', 'tl_cron_job', 'tl_trusted_device', 'tl_remember_me',
        'tl_search', 'tl_search_index', 'tl_search_term', 'tl_crawl_queue', 'tl_form', 'tl_form_field',
    ];

    /** Records offered to link at most. */
    private const MAX_CANDIDATES = 200;

    /** @var array<int, string> page => language */
    private array $pageLanguages = [];

    /** @var array<string, list<string>> columns by table */
    private array $columns = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        private readonly LanguageVersions $versions,
        private readonly RecordLinks $links,
        private readonly DcaRunner $runner,
        private readonly Slug $slug,
    ) {
    }

    /**
     * How the table's records are linked: "page" (changelanguage), "post"
     * (languageMain), "archive" (master), "record" (RecordLinks).
     */
    public function kind(string $table): string
    {
        $this->load($table);

        return match (true) {
            'tl_page' === $table => 'page',
            null !== $this->archiveTable($table) && isset($GLOBALS['TL_DCA'][$table]['fields']['languageMain']) => 'post',
            isset($GLOBALS['TL_DCA'][$table]['fields']['master']) && $this->hasColumn($table, 'master') => 'archive',
            default => 'record',
        };
    }

    /** The language of a record, '' when it cannot be told. */
    public function languageOf(string $table, array $row): string
    {
        return match ($this->kind($table)) {
            'page' => $this->pageLanguage((int) $row['id']),
            'post' => $this->archiveLanguage((string) $this->archiveTable($table), (int) ($row['pid'] ?? 0)),
            'archive' => $this->archiveLanguage($table, (int) $row['id']),
            default => $this->hasColumn($table, 'language') ? (string) ($row['language'] ?? '') : (string) ($this->links->ofTarget($table, (int) $row['id'])['language'] ?? ''),
        };
    }

    /** The record of the main language a record belongs to (the record itself when it is one). */
    public function mainOf(string $table, array $row): array
    {
        $id = match ($this->kind($table)) {
            'page' => (int) ($this->versions->mainVersion((int) $row['id'])['id'] ?? $row['id']),
            'post' => (int) ($row['languageMain'] ?? 0),
            'archive' => (int) ($row['master'] ?? 0),
            default => (int) ($this->links->ofTarget($table, (int) $row['id'])['main'] ?? 0),
        };

        return $id > 0 && $id !== (int) $row['id'] ? ($this->row($table, $id) ?? $row) : $row;
    }

    /** The version of a main record in a language; the main record for its own language; null without one. */
    public function versionIn(string $table, array $main, string $language): array|null
    {
        if ('' === $language || $language === $this->languageOf($table, $main)) {
            return $main;
        }

        $kind = $this->kind($table);

        if ('page' === $kind) {
            foreach ($this->versions->versions((int) $main['id']) as $version) {
                if ($version['language'] === $language) {
                    return $this->row('tl_page', $version['id']);
                }
            }

            return null;
        }

        $linked = $this->links->targetOf($table, (int) $main['id'], $language);
        $field = ['post' => 'languageMain', 'archive' => 'master'][$kind] ?? null;

        if (null !== $linked && null !== ($row = $this->row($table, $linked)) && (null === $field || (int) $row[$field] === (int) $main['id'])) {
            return $row;
        }

        if (null === $field) {
            return null;
        }

        foreach ($this->db->fetchAllAssociative("SELECT * FROM $table WHERE $field = ? ORDER BY id", [(int) $main['id']]) as $row) {
            if ($language === $this->languageOf($table, $row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Records that could be linked as the version of $main in $language, the
     * proposed one (same place in its list as $main in its own) marked.
     *
     * @return list<array{id: int, title: string, proposed: bool}>
     */
    public function candidates(string $table, array $main, string $language): array
    {
        $kind = $this->kind($table);
        $own = $this->languageOf($table, $main);

        if ('page' === $kind) {
            return [];
        }

        if ('post' === $kind) {
            $archiveTable = (string) $this->archiveTable($table);
            $archive = $this->row($archiveTable, (int) $main['pid']);
            $target = null !== $archive ? $this->versionIn($archiveTable, $this->mainOf($archiveTable, $archive), $language) : null;

            if (null === $target) {
                return [];
            }

            $order = $this->hasColumn($table, 'sorting') ? 'sorting, id' : 'id';
            $mine = $this->db->fetchFirstColumn("SELECT id FROM $table WHERE pid = ? AND tstamp > 0 ORDER BY $order", [(int) $main['pid']]);
            $rows = $this->db->fetchAllAssociative("SELECT * FROM $table WHERE pid = ? AND languageMain = 0 AND tstamp > 0 ORDER BY $order LIMIT ".self::MAX_CANDIDATES, [(int) $target['id']]);
            $all = $this->db->fetchFirstColumn("SELECT id FROM $table WHERE pid = ? AND tstamp > 0 ORDER BY $order", [(int) $target['id']]);

            return $this->listed($table, $this->withoutVersionIn($table, $rows, $own), $all, array_search($main['id'], $mine, false));
        }

        $order = $this->hasColumn($table, 'sorting') ? 'sorting, id' : 'id';
        $versions = $this->links->targets($table);
        $mine = [];
        $theirs = [];
        // Every record of the target language in its order, linked or not: the proposal is the one at the same place.
        $places = [];

        foreach ($this->db->fetchAllAssociative("SELECT * FROM $table ORDER BY $order LIMIT 1000") as $row) {
            // Front-end modules: only modules of the same type.
            if ('tl_module' === $table && $row['type'] !== $main['type']) {
                continue;
            }

            $rowLanguage = $this->languageOf($table, $row);
            $isVersion = \in_array((int) $row['id'], $versions, true) || ('archive' === $kind && (int) $row['master'] > 0);

            // The records of the main record's language give its place in the list.
            if ((int) $row['id'] === (int) $main['id'] || ('' !== $own && $rowLanguage === $own)) {
                $mine[] = (int) $row['id'];

                continue;
            }

            if ('' !== $rowLanguage && $rowLanguage === $language) {
                $places[] = (int) $row['id'];
            }

            if (!$isVersion && ('' === $rowLanguage || $rowLanguage === $language)) {
                $theirs[] = $row;
            }
        }

        $theirs = \array_slice($this->withoutVersionIn($table, $theirs, $own), 0, self::MAX_CANDIDATES);

        return $this->listed($table, $theirs, $places, '' !== $own ? array_search((int) $main['id'], $mine, true) : false);
    }

    /**
     * The records without a version in $language yet: a record that has one
     * cannot take another (offered when a record of a version is linked to a
     * record of the main language).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withoutVersionIn(string $table, array $rows, string $language): array
    {
        if ('' === $language) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($table, $language): bool {
            $version = $this->versionIn($table, $row, $language);

            return null === $version || (int) $version['id'] === (int) $row['id'];
        }));
    }

    /**
     * Links $target as the version of $main in $language: changelanguage's
     * field for pages' posts and archives, RecordLinks for the rest. Two linked
     * archives whose posts are not linked yet get their posts linked by their
     * order, when both have as many.
     */
    public function link(string $table, array $main, array $target, string $language): bool
    {
        $kind = $this->kind($table);
        $field = ['post' => 'languageMain', 'archive' => 'master'][$kind] ?? null;

        if ('page' === $kind || (int) $main['id'] === (int) $target['id']) {
            return false;
        }

        if (null !== $field) {
            if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $target, [$field => (int) $main['id']]))) {
                return false;
            }

            $this->db->update($table, [$field => (int) $main['id'], 'tstamp' => time()], ['id' => (int) $target['id']]);
        } elseif (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $target))) {
            return false;
        }

        $this->links->link($table, (int) $main['id'], (int) $target['id'], $language);

        if ('archive' === $kind) {
            $this->linkPosts($table, (int) $main['id'], (int) $target['id'], $language);
        }

        return true;
    }

    /**
     * The version of $main in $language as a copy of it, made by Contao's
     * own copy (callbacks, permissions), then linked: a post into the linked
     * archive of that language (unpublished), any other record next to it.
     * Null when it cannot be made (no linked archive, no permission).
     */
    public function create(string $table, array $main, string $language): int|null
    {
        $kind = $this->kind($table);
        $module = $this->moduleOf($table);

        if ('page' === $kind || 'archive' === $kind || null === $module) {
            return null;
        }

        $query = ['act' => 'copy', 'id' => (int) $main['id']];

        if ('post' === $kind) {
            $archiveTable = (string) $this->archiveTable($table);
            $archive = $this->row($archiveTable, (int) $main['pid']);
            $target = null !== $archive ? $this->versionIn($archiveTable, $archive, $language) : null;

            if (null === $target || (int) $target['id'] === (int) $archive['id']) {
                return null;
            }

            $query += ['mode' => 2, 'pid' => (int) $target['id']];
        } elseif ($this->hasColumn($table, 'pid') && isset($GLOBALS['TL_DCA'][$table]['config']['ptable'])) {
            $query += ['mode' => 2, 'pid' => (int) $main['pid']];
        }

        $id = $this->copy($table, $main, $query, 'post' !== $kind && $this->hasColumn($table, 'language') ? $language : null);
        $copy = null !== $id ? $this->row($table, $id) : null;

        return null !== $copy && $this->link($table, $main, $copy, $language) ? $id : null;
    }

    /**
     * The record of the main language for a record written first in another
     * language, the record linked to it as its version: a post as a copy in
     * the linked archive of the main language (master), any other record as a
     * copy next to it (its field "language" set to $mainLanguage). Null when
     * the record already has one, a post's archive is not linked, or for pages
     * and archives.
     */
    public function createMain(string $table, array $record, string $mainLanguage = ''): int|null
    {
        $kind = $this->kind($table);
        $language = $this->languageOf($table, $record);

        if ('page' === $kind || 'archive' === $kind || null === $this->moduleOf($table) || (int) $this->mainOf($table, $record)['id'] !== (int) $record['id']) {
            return null;
        }

        $query = ['act' => 'copy', 'id' => (int) $record['id']];

        if ('post' === $kind) {
            $archiveTable = (string) $this->archiveTable($table);
            $master = (int) ($this->row($archiveTable, (int) $record['pid'], false)['master'] ?? 0);

            if ($master < 1 || null === $this->row($archiveTable, $master)) {
                return null;
            }

            $query += ['mode' => 2, 'pid' => $master];
        } elseif ($this->hasColumn($table, 'pid') && isset($GLOBALS['TL_DCA'][$table]['config']['ptable'])) {
            $query += ['mode' => 2, 'pid' => (int) $record['pid']];
        }

        $id = $this->copy($table, $record, $query, 'post' !== $kind && '' !== $mainLanguage && $this->hasColumn($table, 'language') ? $mainLanguage : null);
        $main = null !== $id ? $this->row($table, $id) : null;

        return null !== $main && $this->link($table, $main, $record, $language) ? $id : null;
    }

    /**
     * A copy of a record by Contao's own copy (callbacks, permissions), a
     * record of its own afterwards; the id, null when it was not made.
     *
     * @param array<string, int|string> $query
     */
    private function copy(string $table, array $record, array $query, string|null $language): int|null
    {
        $module = $this->moduleOf($table);

        if (null === $module) {
            return null;
        }

        $before = array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM $table"));
        $result = $this->runner->run($module, $table, $query, static function (DC_Table $dc): StructureResult {
            try {
                $newId = $dc->copy(true);
            } catch (RedirectResponseException) {
                $newId = null;
            }

            return \is_numeric($newId) ? StructureResult::done((int) $newId) : StructureResult::done(0);
        });

        $created = array_values(array_diff(array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM $table")), $before));
        $id = [] !== $created ? (int) end($created) : (int) ($result->id ?? 0);

        if ($id < 1 || null === $this->row($table, $id, false)) {
            return null;
        }

        // A copy Contao keeps as a draft until its form is saved: it is a record of its own now.
        $values = ['tstamp' => time()];

        // What Contao leaves out of a copy for a new record (dates, times, author) belongs to the version
        // as well; not its address (unique, made from the translated title) and not its publication.
        foreach ($GLOBALS['TL_DCA'][$table]['fields'] ?? [] as $field => $config) {
            $eval = (array) ($config['eval'] ?? []);

            if (($eval['doNotCopy'] ?? false) && !($eval['unique'] ?? false) && !\in_array($field, ['published', 'alias', 'languageMain', 'master'], true) && \array_key_exists($field, $record) && $this->hasColumn($table, (string) $field)) {
                $values[$field] = $record[$field];
            }
        }

        if (null !== $language) {
            $values['language'] = $language;
        }

        $this->db->update($table, $values, ['id' => $id]);

        return $id;
    }

    /**
     * A version without an address (a copy) gets one from its title once the
     * title is translated, made like Contao does (slug of the archive's page,
     * unique in the table). An address of its own stays.
     */
    public function fillAlias(string $table, int $id): void
    {
        $this->load($table);
        $row = $this->row($table, $id);
        $field = $this->titleField($table);

        if (null === $row || !isset($GLOBALS['TL_DCA'][$table]['fields']['alias']) || !$this->hasColumn($table, 'alias') || '' !== (string) $row['alias']) {
            return;
        }

        $title = trim(strip_tags(StringUtil::decodeEntities((string) ($row[$field] ?? ''))));

        if ('' === $title || 'id' === $field) {
            return;
        }

        $archiveTable = $this->archiveTable($table);
        $page = null !== $archiveTable ? (int) ($this->row($archiveTable, (int) $row['pid'], false)['jumpTo'] ?? 0) : 0;
        $exists = fn (string $alias): bool => false !== $this->db->fetchOne("SELECT id FROM $table WHERE alias = ? AND id != ?", [$alias, $id]);
        $alias = $this->slug->generate($title, $page > 0 ? $page : [], $exists);

        if ('' !== $alias) {
            $this->db->update($table, ['alias' => $alias], ['id' => $id]);
        }
    }

    /**
     * Whether the record's table is one of Contao itself (Plus) or of an
     * extension (Pro). A front-end module counts as Contao's when its type is.
     */
    public function isContao(string $table, array $row): bool
    {
        if ('tl_module' === $table) {
            $type = (string) ($row['type'] ?? '');

            foreach ($GLOBALS['FE_MOD'] ?? [] as $modules) {
                if (isset($modules[$type])) {
                    return \is_string($modules[$type]) && str_starts_with(ltrim($modules[$type], '\\'), 'Contao\\');
                }
            }

            return false;
        }

        return \in_array($table, self::CONTAO_TABLES, true);
    }

    /**
     * A table whose records are translated on their own: of a back-end module
     * the user may open, with fields, not of the structure, of people or of
     * the settings of extensions (tl_neo_…).
     */
    public function translatable(string $table): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || \in_array($table, self::NOT_TRANSLATED, true) || str_starts_with($table, 'tl_neo_')) {
            return false;
        }

        $this->load($table);
        $module = 'tl_page' === $table ? 'page' : $this->moduleOf($table);

        return isset($GLOBALS['TL_DCA'][$table]['fields']) && null !== $module && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module);
    }

    /** The table of the archives of a post table, null when the table holds no posts. */
    public function archiveTable(string $table): string|null
    {
        $this->load($table);
        $ptable = $GLOBALS['TL_DCA'][$table]['config']['ptable'] ?? null;

        if (!\is_string($ptable) || '' === $ptable || 'tl_page' === $ptable || 'tl_theme' === $ptable || !isset($GLOBALS['TL_DCA'][$table]['fields']['published'])) {
            return null;
        }

        $this->load($ptable);

        return \in_array($table, (array) ($GLOBALS['TL_DCA'][$ptable]['config']['ctable'] ?? []), true) ? $ptable : null;
    }

    /** The back-end module a table belongs to, null when none has it. */
    public function moduleOf(string $table): string|null
    {
        foreach ($GLOBALS['BE_MOD'] ?? [] as $modules) {
            foreach ($modules as $module => $config) {
                if (\in_array($table, (array) ($config['tables'] ?? []), true)) {
                    return (string) $module;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function row(string $table, int $id, bool $saved = true): array|null
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table) || $id < 1) {
            return null;
        }

        $row = $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]);

        return false === $row || ($saved && $this->hasColumn($table, 'tstamp') && 0 === (int) $row['tstamp']) ? null : $row;
    }

    /**
     * Posts of two linked archives linked by their order, when none of the
     * version's posts is linked yet and both archives have as many.
     */
    private function linkPosts(string $archiveTable, int $mainArchive, int $targetArchive, string $language): void
    {
        foreach ((array) ($GLOBALS['TL_DCA'][$archiveTable]['config']['ctable'] ?? []) as $table) {
            if (!\is_string($table) || 'tl_content' === $table || 'post' !== $this->kind($table) || $this->archiveTable($table) !== $archiveTable) {
                continue;
            }

            $order = $this->hasColumn($table, 'sorting') ? 'sorting, id' : 'id';
            $main = $this->db->fetchFirstColumn("SELECT id FROM $table WHERE pid = ? AND tstamp > 0 ORDER BY $order", [$mainArchive]);
            $target = $this->db->fetchAllAssociative("SELECT id, languageMain FROM $table WHERE pid = ? AND tstamp > 0 ORDER BY $order", [$targetArchive]);

            if ([] === $main || \count($main) !== \count($target) || [] !== array_filter(array_column($target, 'languageMain'))) {
                continue;
            }

            foreach ($target as $i => $post) {
                $this->db->update($table, ['languageMain' => (int) $main[$i]], ['id' => (int) $post['id']]);
                $this->links->link($table, (int) $main[$i], (int) $post['id'], $language);
            }
        }
    }

    /**
     * The language of an archive: of the page its posts are shown on (jumpTo),
     * of its own field "language", or of the pages whose elements show it (a
     * field with a relation to its table).
     */
    private function archiveLanguage(string $table, int $id): string
    {
        $row = $this->row($table, $id, false);

        if (null === $row) {
            return '';
        }

        if ((int) ($row['jumpTo'] ?? 0) > 0 && '' !== ($language = $this->pageLanguage((int) $row['jumpTo']))) {
            return $language;
        }

        if ('' !== (string) ($row['language'] ?? '')) {
            return (string) $row['language'];
        }

        $languages = [];
        $this->load('tl_content');

        foreach ($GLOBALS['TL_DCA']['tl_content']['fields'] ?? [] as $field => $config) {
            $target = (string) ($config['relation']['table'] ?? explode('.', (string) ($config['foreignKey'] ?? ''), 2)[0]);

            if ($target !== $table || !$this->hasColumn('tl_content', strtolower((string) $field))) {
                continue;
            }

            foreach ($this->db->fetchAllAssociative("SELECT c.pid, c.ptable FROM tl_content c WHERE c.$field = ? AND c.ptable = 'tl_article' LIMIT 20", [$id]) as $element) {
                $page = (int) $this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [(int) $element['pid']]);
                $languages[$this->pageLanguage($page)] = true;
            }
        }

        unset($languages['']);

        return 1 === \count($languages) ? (string) array_key_first($languages) : '';
    }

    private function pageLanguage(int $pageId): string
    {
        if ($pageId < 1) {
            return '';
        }

        if (!isset($this->pageLanguages[$pageId])) {
            $this->framework->initialize();
            $this->pageLanguages[$pageId] = (string) $this->framework->getAdapter(PageModel::class)->findWithDetails($pageId)?->language;
        }

        return $this->pageLanguages[$pageId];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int|string>           $all  the ids of the target list in their order
     *
     * @return list<array{id: int, title: string, proposed: bool}>
     */
    private function listed(string $table, array $rows, array $all, int|string|false $position): array
    {
        $proposed = false !== $position ? (int) ($all[(int) $position] ?? 0) : 0;
        $field = $this->titleField($table);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => trim(strip_tags(StringUtil::decodeEntities((string) ($row[$field] ?? '')))) ?: '#'.$row['id'],
            'proposed' => (int) $row['id'] === $proposed,
        ], $rows);
    }

    /** The field that names a record: the first of its label in Contao's list, else a common one. */
    public function titleField(string $table): string
    {
        $this->load($table);
        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $label = $dca['list']['label']['fields'][0] ?? null;

        if (\is_string($label) && isset($dca['fields'][$label])) {
            return $label;
        }

        foreach (['title', 'headline', 'question', 'name'] as $field) {
            if (isset($dca['fields'][$field])) {
                return $field;
            }
        }

        return 'id';
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->columns[$table])) {
            try {
                $this->columns[$table] = array_map('strtolower', array_keys($this->db->createSchemaManager()->listTableColumns($table)));
            } catch (\Throwable) {
                $this->columns[$table] = [];
            }
        }

        return \in_array(strtolower($column), $this->columns[$table], true);
    }

    private function load(string $table): void
    {
        $this->framework->initialize();
        Controller::loadDataContainer($table);
    }
}
