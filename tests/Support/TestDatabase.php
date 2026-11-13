<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Neo\BasicBundle\NeoBasicBundle;

/**
 * An SQLite database in memory. The tables of this bundle and of the Basic
 * bundle (licences) are built from the "sql" definitions of their DCA files,
 * so the tests run the real queries against the real column names and
 * defaults; Contao's tables get the columns the bundle reads. MySQL's IF() is
 * added as a function, as the bundle uses it.
 */
final class TestDatabase
{
    /** Columns of Contao's own tables that the bundle reads or writes. */
    private const CONTAO = [
        'tl_page' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', alias varchar(255) NOT NULL DEFAULT '', type varchar(64) NOT NULL DEFAULT 'regular', language varchar(64) NOT NULL DEFAULT '', dns varchar(255) NOT NULL DEFAULT '', useSSL char(1) NOT NULL DEFAULT '', published char(1) NOT NULL DEFAULT '1'",
        'tl_article' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT '', inColumn varchar(32) NOT NULL DEFAULT 'main'",
        'tl_content' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, ptable varchar(64) NOT NULL DEFAULT 'tl_article', sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, type varchar(64) NOT NULL DEFAULT 'text', headline varchar(255) NOT NULL DEFAULT '', text mediumtext NULL, singleSRC binary(16) NULL, alt varchar(255) NOT NULL DEFAULT '', caption varchar(255) NOT NULL DEFAULT '', overwriteMeta tinyint(1) NOT NULL DEFAULT 0, url varchar(255) NOT NULL DEFAULT '', linkTitle varchar(255) NOT NULL DEFAULT '', listitems blob NULL, tableitems mediumblob NULL, code text NULL, html text NULL, invisible tinyint(1) NOT NULL DEFAULT 0, origId int NOT NULL DEFAULT 0, faq_category int NOT NULL DEFAULT 0, jumpTo int NOT NULL DEFAULT 0",
        'tl_faq_category' => "id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT ''",
        'tl_faq' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, question varchar(255) NOT NULL DEFAULT '', alias varchar(255) NOT NULL DEFAULT '', answer text NULL",
        'tl_files' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid binary(16) NULL, tstamp int NOT NULL DEFAULT 0, uuid binary(16) NULL, type varchar(16) NOT NULL DEFAULT 'file', path varchar(1022) NOT NULL DEFAULT '', extension varchar(16) NOT NULL DEFAULT '', name varchar(255) NOT NULL DEFAULT '', meta blob NULL",
        'tl_module' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, name varchar(255) NOT NULL DEFAULT '', type varchar(64) NOT NULL DEFAULT '', headline varchar(255) NOT NULL DEFAULT '', html text NULL",
        'tl_card' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pid int NOT NULL DEFAULT 0, ptable varchar(64) NOT NULL DEFAULT '', sorting int NOT NULL DEFAULT 0, tstamp int NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT ''",
    ];

    public static function create(string ...$tables): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $native = $db->getNativeConnection();
        $if = static fn (mixed $condition, mixed $then, mixed $else): mixed => $condition ? $then : $else;

        if ($native instanceof \Pdo\Sqlite) {
            $native->createFunction('IF', $if, 3);
        } elseif ($native instanceof \PDO) {
            $native->sqliteCreateFunction('IF', $if, 3);
        }

        foreach ($tables as $table) {
            $db->executeStatement('CREATE TABLE '.$table.' ('.self::sqlite(self::CONTAO[$table] ?? self::fromDca($table)).')');
        }

        return $db;
    }

    /** MySQL's column types under the names SQLite and Doctrine's schema reader know. */
    private static function sqlite(string $columns): string
    {
        return (string) preg_replace(['/\b(?:tiny|medium|long)blob\b|\bbinary\(\d+\)/i', '/\b(?:tiny|medium|long)text\b/i', '/ unsigned\b/i'], ['blob', 'text', ''], $columns);
    }

    /**
     * The columns of a table of this bundle or of the Basic bundle (this
     * bundle's DCA of a table of Basic only adds sections to its page) from the
     * "sql" of its DCA file.
     */
    private static function fromDca(string $table): string
    {
        $basic = \dirname((string) (new \ReflectionClass(NeoBasicBundle::class))->getFileName(), 2).'/contao/dca/'.$table.'.php';
        $file = is_file($basic) ? $basic : \dirname(__DIR__, 2).'/contao/dca/'.$table.'.php';

        $saved = $GLOBALS['TL_DCA'] ?? null;
        include $file;
        $fields = $GLOBALS['TL_DCA'][$table]['fields'] ?? [];
        $GLOBALS['TL_DCA'] = $saved;

        if (null === $saved) {
            unset($GLOBALS['TL_DCA']);
        }

        $columns = [];

        foreach ($fields as $name => $field) {
            $sql = $field['sql'] ?? null;

            if (!\is_string($sql)) {
                continue;
            }

            $columns[] = str_contains($sql, 'auto_increment')
                ? $name.' INTEGER PRIMARY KEY AUTOINCREMENT'
                : $name.' '.$sql;
        }

        return implode(', ', $columns);
    }
}
