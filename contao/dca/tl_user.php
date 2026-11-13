<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\PermissionOptions;

$GLOBALS['TL_DCA']['tl_user']['fields'][Permission::FIELD] = [
    'inputType' => 'checkbox',
    // Only the switches of the editions the licence includes; hidden ones keep their state.
    'options_callback' => [PermissionOptions::class, '__invoke'],
    'save_callback' => [[PermissionOptions::class, 'keepHidden']],
    'reference' => &$GLOBALS['TL_LANG']['tl_user']['nfe_permissions_options'],
    'eval' => ['multiple' => true, 'tl_class' => 'clr'],
    'sql' => 'blob NULL',
];

// Only the palettes in which a user gets own permissions besides those of the groups.
PaletteManipulator::create()
    ->addLegend('nfe_legend', 'amg_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField(Permission::FIELD, 'nfe_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('extend', 'tl_user')
    ->applyToPalette('custom', 'tl_user')
;
