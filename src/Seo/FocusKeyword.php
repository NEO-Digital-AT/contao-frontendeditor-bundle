<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Seo;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The focus keyword of a page (Pro): one term per page in tl_neo_fe_seo,
 * and the other pages of the website that use the same term.
 */
final class FocusKeyword
{
    public const MAX_LENGTH = 128;

    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
    ) {
    }

    public function get(int $pageId): string
    {
        try {
            return (string) ($this->db->fetchOne('SELECT keyword FROM tl_neo_fe_seo WHERE pid = ?', [$pageId]) ?: '');
        } catch (TableNotFoundException) {
            return '';
        }
    }

    public function set(int $pageId, string $keyword): void
    {
        $keyword = self::clean($keyword);

        if ('' === $keyword) {
            $this->db->delete('tl_neo_fe_seo', ['pid' => $pageId]);

            return;
        }

        $updated = $this->db->update('tl_neo_fe_seo', ['keyword' => $keyword, 'tstamp' => time()], ['pid' => $pageId]);

        if (0 === $updated && false === $this->db->fetchOne('SELECT id FROM tl_neo_fe_seo WHERE pid = ?', [$pageId])) {
            $this->db->insert('tl_neo_fe_seo', ['pid' => $pageId, 'keyword' => $keyword, 'tstamp' => time()]);
        }
    }

    /**
     * Other pages of the same website and language the user may see that have the
     * same term (case and spaces ignored); pages of other websites do not compete.
     *
     * @return list<array{id: int, title: string}>
     */
    public function usedOn(int $pageId, string $keyword): array
    {
        $keyword = self::clean($keyword);

        if ('' === $keyword) {
            return [];
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT p.id, p.title FROM tl_neo_fe_seo s JOIN tl_page p ON p.id = s.pid WHERE LOWER(s.keyword) = LOWER(?) AND s.pid != ? ORDER BY p.title',
                [$keyword, $pageId],
            );
        } catch (TableNotFoundException) {
            return [];
        }

        $rootId = (int) PageModel::findWithDetails($pageId)?->rootId;

        return array_values(array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            array_filter($rows, fn (array $row): bool => $rootId === (int) PageModel::findWithDetails((int) $row['id'])?->rootId
                && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, (int) $row['id'])),
        ));
    }

    /** One line, single spaces, no markup, limited length. */
    public static function clean(string $keyword): string
    {
        $keyword = trim((string) preg_replace('/\s+/u', ' ', strip_tags($keyword)));

        return mb_substr($keyword, 0, self::MAX_LENGTH);
    }
}
