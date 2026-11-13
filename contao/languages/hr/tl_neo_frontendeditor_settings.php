<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Veza';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adresa backenda', 'Ispunite samo ako backend radi na vlastitoj adresi, npr. https://manage.example.com. Prazno: ista adresa kao web-stranica.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Prijedlozi prijevoda (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Usluga prevođenja', 'Koja usluga predlaže prijevode jezičnih verzija. Mogu se odabrati samo usluge postavljene pod „Pristupi i licence”. Tekstovi elementa šalju se toj usluzi.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'AI model (AI veza)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Model za prijevode', 'Jezični model AI veze koji prevodi. Ako ruta ne navodi modele, upišite naziv modela.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Još nije postavljena nijedna usluga prevođenja (Azure Translator, DeepL ili AI veza).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s je odabran, ali više nije postavljen.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Odaberite model: bez modela AI model ne može prevoditi.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: još nije postavljeno.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Nije dostupno: AI veza nije postavljena.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Postavite pod „Pristupi i licence”';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Pomoć pri pisanju (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Model', 'Model AI veze za pomoć pri pisanju. Ako ruta ne navodi modele, upišite naziv modela.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Zahtjeva dnevno', 'Za sve urednike zajedno; jedan zahtjev donosi tri prijedloga. 0 isključuje pomoć pri pisanju.'];
