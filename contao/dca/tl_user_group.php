<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\PermissionOptions;

$GLOBALS['TL_DCA']['tl_user_group']['fields'][Permission::FIELD] = [
    'inputType' => 'checkbox',
    // Only the switches of the editions the licence includes; hidden ones keep their state.
    'options_callback' => [PermissionOptions::class, '__invoke'],
    'save_callback' => [[PermissionOptions::class, 'keepHidden']],
    'reference' => &$GLOBALS['TL_LANG']['tl_user_group']['nfe_permissions_options'],
    'eval' => ['multiple' => true, 'tl_class' => 'clr'],
    'sql' => 'blob NULL',
];

PaletteManipulator::create()
    ->addLegend('nfe_legend', 'amg_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField(Permission::FIELD, 'nfe_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_user_group')
;
