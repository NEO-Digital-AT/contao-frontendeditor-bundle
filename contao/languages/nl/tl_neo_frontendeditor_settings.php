<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Verbinding';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adres van de backend', 'Alleen invullen als de backend onder een eigen adres draait, bijv. https://manage.example.com. Leeg: hetzelfde adres als de website.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Vertaalvoorstellen (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Vertaaldienst', 'Welke dienst vertalingen van de taalversies voorstelt. Alleen diensten die onder „Toegang en licenties” zijn ingesteld, kunnen worden gekozen. De teksten van het element gaan naar deze dienst.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'AI-model (AI-verbinding)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Model voor vertalingen', 'Het taalmodel van de AI-verbinding dat vertaalt. Als de route geen modellen toont, typ dan de naam van het model.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Er is nog geen vertaaldienst ingesteld (Azure Translator, DeepL of de AI-verbinding).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s is gekozen maar niet meer ingesteld.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Kies een model: zonder model kan het AI-model niet vertalen.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: nog niet ingesteld.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Niet beschikbaar: de AI-verbinding is niet ingesteld.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Instellen onder „Toegang en licenties”';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Schrijfhulp (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Model', 'Model van de AI-verbinding voor de schrijfhulp. Als de route geen modellen toont, typ dan de naam van het model.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Verzoeken per dag', 'Voor alle redacteuren samen; één verzoek levert drie voorstellen. 0 schakelt de schrijfhulp uit.'];
