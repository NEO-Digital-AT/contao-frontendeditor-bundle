<?php

declare(strict_types=1);

use Neo\FrontendEditorBundle\Backend\ServiceSelectMenu;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Settings;

// The switches of the extension are inherited from the user groups like Contao's own permission fields.
$GLOBALS['TL_PERMISSIONS'][] = Permission::FIELD;

// Select menu with options that cannot be chosen yet, the reason below (translation service in the settings).
$GLOBALS['BE_FFL']['nfeServiceSelect'] = ServiceSelectMenu::class;

// Settings of the extension in the menu group of the extensions (named by the Basic bundle).
$GLOBALS['BE_MOD']['neo'][Settings::MODULE] = [
    'tables' => [Settings::TABLE],
];

// Language versions (Plus): the links between the elements of the languages, outdated translations first.
$GLOBALS['BE_MOD']['neo']['neo_frontendeditor_translations'] = [
    'tables' => ['tl_neo_fe_link'],
];

// Page styles (Pro): the CSS or SCSS of single pages; created in the editor, listed, edited and deleted here.
$GLOBALS['BE_MOD']['neo']['neo_frontendeditor_styles'] = [
    'tables' => ['tl_neo_fe_style'],
];
