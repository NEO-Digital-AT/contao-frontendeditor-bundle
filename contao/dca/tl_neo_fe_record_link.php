<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * tl_neo_fe_record_link — which record of a language version corresponds to
 * which record of the main language, for records Contao does not link itself
 * (Plus/Pro, RecordVersions): front-end modules and the records of other
 * back-end modules (e.g. a catalogue of an extension). Pages and posts are
 * linked by the extension changelanguage (languageMain, master of the
 * archive); for those this table only keeps the state of the translation.
 *
 * record_table names the table, main and target the two records, language the
 * language of the target. source_hash is the state of the main record's texts
 * when the target was last translated. No back-end view; deleting a row
 * removes the link, not the records.
 */
$GLOBALS['TL_DCA']['tl_neo_fe_record_link'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'record_table,target' => 'unique',
                'record_table,main' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'record_table' => ['sql' => "varchar(64) NOT NULL default ''"],
        'main' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'target' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'language' => ['sql' => "varchar(64) NOT NULL default ''"],
        'source_hash' => ['sql' => "varchar(64) NOT NULL default ''"],
    ],
];
