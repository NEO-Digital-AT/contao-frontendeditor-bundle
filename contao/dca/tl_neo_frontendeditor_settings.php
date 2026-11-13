<?php

declare(strict_types=1);

use Contao\DC_Table;
use Neo\FrontendEditorBundle\Backend\SettingsCallbacks;

$GLOBALS['TL_DCA']['tl_neo_frontendeditor_settings'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'enableVersioning' => true,
        'notCreatable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        // One record, the list opens its form: the breadcrumb shows the module name only (BreadcrumbListener of the Basic bundle).
        'neoSingleRecord' => true,
        'onload_callback' => [
            [SettingsCallbacks::class, 'openRecord'],
            // The writing aid (Pro) only with a licence for Pro, the translation service only with Plus; the values stay stored.
            [SettingsCallbacks::class, 'hideUnlicensed'],
            [SettingsCallbacks::class, 'adjustModelField'],
            [SettingsCallbacks::class, 'adjustTranslationFields'],
        ],
        'sql' => ['keys' => ['id' => 'primary']],
    ],
    'edit' => [
        'buttons_callback' => [[SettingsCallbacks::class, 'editButtons']],
    ],
    'list' => [
        'sorting' => ['mode' => 0],
        'label' => ['fields' => ['id'], 'format' => '%s'],
    ],
    'palettes' => [
        'default' => '{connection_legend},backend_url;{translation_legend},translation_service,translation_model;{ai_legend},ai_model,ai_daily_limit',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
        'backend_url' => [
            'inputType' => 'text',
            'eval' => ['rgxp' => 'url', 'decodeEntities' => true, 'maxlength' => 255, 'placeholder' => 'https://manage.example.com', 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // Translation proposals (Plus): the service, offered when its access is set up on Basic's page
        // "Access keys and licences"; for the AI connection also its model.
        'translation_service' => [
            // Contao's select menu; services without access disabled, with the reason below (SettingsCallbacks).
            'inputType' => 'nfeServiceSelect',
            'options_callback' => [SettingsCallbacks::class, 'translationServiceOptions'],
            'reference' => &$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'],
            'eval' => ['submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        'translation_model' => [
            'inputType' => 'select',
            'options_callback' => [SettingsCallbacks::class, 'translationModelOptions'],
            'eval' => ['chosen' => true, 'includeBlankOption' => true, 'decodeEntities' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        // Writing aid (Pro): model of the AI connection from Basic's page "Access keys and licences".
        'ai_model' => [
            'inputType' => 'select',
            'options_callback' => [SettingsCallbacks::class, 'modelOptions'],
            'eval' => ['chosen' => true, 'includeBlankOption' => true, 'decodeEntities' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        // Requests of all editors together per day; one request brings three proposals.
        'ai_daily_limit' => [
            'inputType' => 'text',
            'default' => 100,
            'eval' => ['rgxp' => 'natural', 'minval' => 0, 'maxlength' => 6, 'tl_class' => 'w50'],
            'sql' => 'int(10) unsigned NOT NULL default 100',
        ],
        'ai_usage_day' => ['sql' => "varchar(10) NOT NULL default ''"],
        'ai_usage_count' => ['sql' => 'int(10) unsigned NOT NULL default 0'],
    ],
];
