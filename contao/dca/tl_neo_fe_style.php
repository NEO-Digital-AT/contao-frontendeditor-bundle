<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;
use Neo\FrontendEditorBundle\Style\StyleCallbacks;

/*
 * tl_neo_fe_style — the style of a page (Pro): CSS or SCSS entered in the
 * editor (scss), the CSS made from it (css, what visitors get) and whether it
 * applies to the subpages, too (scope). One record per page; tl_page gets no
 * new column. Saving goes through Contao's form with versions; the back-end
 * module "Page styles" lists, edits and deletes them. New records are only
 * created by the editor for a page (closed).
 */
$GLOBALS['TL_DCA']['tl_neo_fe_style'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'enableVersioning' => true,
        'onload_callback' => [[StyleCallbacks::class, 'limitList']],
        'onsubmit_callback' => [[StyleCallbacks::class, 'storeCss']],
        'ondelete_callback' => [[StyleCallbacks::class, 'invalidate']],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['tstamp DESC'],
            'flag' => DataContainer::SORT_DESC,
            'panelLayout' => 'filter;limit',
        ],
        'label' => [
            'fields' => ['pid', 'scope', 'tstamp'],
            'showColumns' => true,
            'label_callback' => [StyleCallbacks::class, 'label'],
        ],
        'operations' => [
            'edit',
            'delete',
            'show',
        ],
    ],
    'palettes' => [
        'default' => '{style_legend},scope,scss',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        // The page (tl_page.id).
        'pid' => [
            'foreignKey' => 'tl_page.title',
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy'],
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        // Who may change these fields is decided by the switch "Edit page styles" and the permission to edit the page (StyleVoter).
        'scope' => [
            'exclude' => false,
            'filter' => true,
            'inputType' => 'select',
            'options' => ['page', 'subpages'],
            'reference' => &$GLOBALS['TL_LANG']['tl_neo_fe_style']['scope_options'],
            'eval' => ['tl_class' => 'w50'],
            'sql' => "varchar(16) NOT NULL default 'page'",
        ],
        'scss' => [
            'exclude' => false,
            'inputType' => 'textarea',
            'eval' => ['useRawRequestData' => true, 'class' => 'monospace', 'rte' => 'ace|scss', 'tl_class' => 'clr'],
            'save_callback' => [[StyleCallbacks::class, 'compile']],
            'sql' => 'mediumtext NULL',
        ],
        // The CSS made from scss when saving; only this goes to visitors.
        'css' => ['sql' => 'mediumtext NULL'],
    ],
];
