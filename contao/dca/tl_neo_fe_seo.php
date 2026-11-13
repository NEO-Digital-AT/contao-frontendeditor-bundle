<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * tl_neo_fe_seo — the focus keyword of a page for the SEO check (Pro): what
 * the page should be found for. One record per page; tl_page gets no new
 * column. Edited in the editor only, hence no back-end module.
 */
$GLOBALS['TL_DCA']['tl_neo_fe_seo'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'unique',
                'keyword' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        // The page (tl_page.id).
        'pid' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'keyword' => ['sql' => "varchar(128) NOT NULL default ''"],
    ],
];
