<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * News as posts with language versions: the tables of Contao's news with the
 * columns the editor reads (the reader page of an archive, the field
 * languageMain of changelanguage) and their DCA.
 */
final class News
{
    public static function create(Connection $db): void
    {
        $db->executeStatement("CREATE TABLE tl_news_archive (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', jumpTo int NOT NULL DEFAULT 0, master int NOT NULL DEFAULT 0)");
        $db->executeStatement("CREATE TABLE tl_news (id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, headline varchar(255) NOT NULL DEFAULT '', languageMain int NOT NULL DEFAULT 0)");
        ContaoState::dca('tl_news', ['config' => ['ptable' => 'tl_news_archive'], 'fields' => ['headline' => [], 'languageMain' => []]]);
        ContaoState::dca('tl_news_archive', ['config' => [], 'fields' => ['jumpTo' => []]]);
    }
}
