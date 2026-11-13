<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;
use Neo\FrontendEditorBundle\Backend\TranslationList;

/*
 * tl_neo_fe_link — which content element of a language version corresponds to
 * which element of the main language (Plus). Pages and articles are linked by
 * the extension changelanguage (field languageMain); content elements have no
 * such field, and tl_content gets no new column, so the link lives here.
 *
 * source_hash is the state of the main element's texts when the element was
 * last translated or confirmed as current; outdated is 1 when the main
 * element's texts have changed since (kept for the filter in the back end).
 *
 * The back-end module "Translations" lists the links, outdated ones first,
 * with a filter; deleting a row removes the link, not the elements.
 */
$GLOBALS['TL_DCA']['tl_neo_fe_link'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notSortable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'element' => 'unique',
                'main' => 'index',
                'outdated' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['outdated DESC', 'language', 'tstamp DESC'],
            'flag' => DataContainer::SORT_ASC,
            'panelLayout' => 'filter;limit',
        ],
        'label' => [
            'fields' => ['main', 'element', 'language', 'outdated'],
            'showColumns' => true,
            'label_callback' => [TranslationList::class, 'label'],
        ],
        'operations' => [
            'nfe_edit_element' => [
                'icon' => 'edit.svg',
                'button_callback' => [TranslationList::class, 'editElement'],
                'primary' => true,
            ],
            'nfe_edit_main' => [
                'icon' => 'editor.svg',
                'button_callback' => [TranslationList::class, 'editMain'],
                'primary' => true,
            ],
            'delete',
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'main' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'element' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'language' => [
            'filter' => true,
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'source_hash' => ['sql' => "varchar(64) NOT NULL default ''"],
        'synced_at' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'outdated' => [
            'filter' => true,
            'inputType' => 'checkbox',
            'reference' => &$GLOBALS['TL_LANG']['tl_neo_fe_link']['outdated_options'],
            'options' => ['1'],
            'sql' => "char(1) NOT NULL default ''",
        ],
    ],
];
