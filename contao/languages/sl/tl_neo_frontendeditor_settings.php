<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Povezava';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Naslov zaledja', 'Izpolnite le, če zaledje deluje na lastnem naslovu, npr. https://manage.example.com. Prazno: isti naslov kot spletna stran.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Predlogi prevodov (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Prevajalska storitev', 'Storitev, ki predlaga prevode jezikovnih različic. Izbrati je mogoče le storitve, nastavljene v »Dostopi in licence«. Besedila elementa gredo tej storitvi.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Model UI (povezava UI)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Model za prevode', 'Jezikovni model povezave UI, ki prevaja. Če pot ne navaja modelov, vpišite ime modela.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Prevajalska storitev še ni nastavljena (Azure Translator, DeepL ali povezava UI).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s je izbran, a ni več nastavljen.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Izberite model: brez modela model UI ne more prevajati.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: še ni nastavljeno.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Ni na voljo: povezava UI ni nastavljena.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Nastavite v »Dostopi in licence«';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Pomočnik pri pisanju (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Model', 'Model povezave UI za pomočnika pri pisanju. Če pot ne navaja modelov, vpišite ime modela.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Zahteve na dan', 'Za vse urednike skupaj; ena zahteva prinese tri predloge. 0 izklopi pomočnika.'];
