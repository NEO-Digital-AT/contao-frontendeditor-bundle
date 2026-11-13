<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * tl_neo_fe_pairing — container pairs (main language, language version) whose
 * elements the editor has paired by their structure once (ElementPairing):
 * articles, elements that contain elements and posts. "ptable" names the
 * kind of container (tl_article, tl_content or the table of the post),
 * main_article and target_article its two records. It does so only once per
 * pair, so it never undoes a link someone removed. No back-end view; deleting
 * a row lets the editor pair those containers again.
 */
$GLOBALS['TL_DCA']['tl_neo_fe_pairing'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'ptable,main_article,target_article' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'ptable' => ['sql' => "varchar(64) NOT NULL default 'tl_article'"],
        'main_article' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'target_article' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];
