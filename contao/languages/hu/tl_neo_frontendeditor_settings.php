<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Kapcsolat';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['A backend címe', 'Csak akkor töltse ki, ha a backend saját címen fut, pl. https://manage.example.com. Üresen: ugyanaz a cím, mint a weboldalé.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Fordítási javaslatok (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Fordítószolgáltatás', 'Melyik szolgáltatás javasol fordítást a nyelvi változatokhoz. Csak a „Hozzáférések és licencek” oldalon beállított szolgáltatások választhatók. Az elem szövegei ehhez a szolgáltatáshoz kerülnek.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'MI-modell (MI-kapcsolat)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Modell a fordításokhoz', 'Az MI-kapcsolat nyelvi modellje, amely fordít. Ha az útvonal nem sorol fel modelleket, írja be a modell nevét.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Még nincs beállított fordítószolgáltatás (Azure Translator, DeepL vagy az MI-kapcsolat).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = 'A(z) %s ki van választva, de már nincs beállítva.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Válasszon modellt: modell nélkül az MI-modell nem tud fordítani.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: még nincs beállítva.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Nem érhető el: az MI-kapcsolat nincs beállítva.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Beállítás a „Hozzáférések és licencek” oldalon';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Írássegéd (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Modell', 'Az MI-kapcsolat modellje az írássegédhez. Ha az útvonal nem sorol fel modelleket, írja be a modell nevét.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Kérések naponta', 'Az összes szerkesztőnek együtt; egy kérés három javaslatot hoz. A 0 kikapcsolja az írássegédet.'];
