<?php

declare(strict_types=1);

use Neo\FrontendEditorBundle\Backend\TranslationList;

// A warning symbol in Contao's list of content elements when the translation of an element is outdated (Plus).
$GLOBALS['TL_DCA']['tl_content']['list']['operations']['nfe_translation'] = [
    'icon' => 'important.svg',
    'button_callback' => [TranslationList::class, 'outdatedMarker'],
    'primary' => true,
];
