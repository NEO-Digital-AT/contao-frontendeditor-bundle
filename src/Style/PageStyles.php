<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Style;

use Contao\PageModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * The styles of the pages (Pro), one record per page in tl_neo_fe_style: the
 * entered style (scss), the CSS made from it (css) and whether it applies to
 * the subpages, too (scope). A record with tstamp 0 is a draft the editor has
 * opened and not saved yet; it does not count.
 */
final class PageStyles
{
    public const TABLE = 'tl_neo_fe_style';

    public const SCOPE_PAGE = 'page';

    public const SCOPE_SUBPAGES = 'subpages';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null the record of a page, also a draft */
    public function record(int $pageId): array|null
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE pid = ?', [$pageId]);
        } catch (TableNotFoundException) {
            return null;
        }

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * The record of a page, created as a draft (tstamp 0) when the page has
     * none yet.
     *
     * @return array{0: array<string, mixed>, 1: bool} the record and whether it was created now
     */
    public function open(int $pageId): array
    {
        $record = $this->record($pageId);

        if (null !== $record) {
            return [$record, false];
        }

        $this->db->insert(self::TABLE, ['pid' => $pageId, 'tstamp' => 0, 'scope' => self::SCOPE_PAGE, 'scss' => '', 'css' => '']);

        return [$this->record($pageId) ?? throw new \RuntimeException('The style could not be created.'), true];
    }

    /** Removes a draft that was never saved; saved styles stay. */
    public function discard(int $id): bool
    {
        return $this->db->delete(self::TABLE, ['id' => $id, 'tstamp' => 0]) > 0;
    }

    /**
     * The styles that apply to a page, in their order: those of the pages
     * above it that apply to their subpages (from the top), then its own.
     *
     * @return list<array{id: int, pid: int, css: string, title: string, own: bool}>
     */
    public function forPage(PageModel $page): array
    {
        $trail = array_map('intval', (array) $page->trail);

        if ([] === $trail) {
            $trail = [(int) $page->id];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT s.id, s.pid, s.scope, s.css, p.title FROM '.self::TABLE.' s JOIN tl_page p ON p.id = s.pid WHERE s.pid IN (?) AND s.tstamp > 0 AND s.css != ?',
                [$trail, ''],
                [ArrayParameterType::INTEGER, ParameterType::STRING],
            );
        } catch (TableNotFoundException) {
            return [];
        }

        $byPage = array_column($rows, null, 'pid');
        $styles = [];

        foreach ($trail as $id) {
            $row = $byPage[$id] ?? null;
            $own = $id === (int) $page->id;

            if (null === $row || (!$own && self::SCOPE_SUBPAGES !== $row['scope'])) {
                continue;
            }

            $styles[] = ['id' => (int) $row['id'], 'pid' => $id, 'css' => (string) $row['css'], 'title' => (string) $row['title'], 'own' => $own];
        }

        return $styles;
    }
}
