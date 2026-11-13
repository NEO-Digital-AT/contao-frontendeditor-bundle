<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Připojení';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adresa backendu', 'Vyplňte jen tehdy, když backend běží na vlastní adrese, např. https://manage.example.com. Prázdné: stejná adresa jako web.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Návrhy překladů (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Překladová služba', 'Služba, která navrhuje překlady jazykových verzí. Vybrat lze jen služby nastavené v části „Přístupy a licence“. Texty prvku se odesílají této službě.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Model AI (připojení AI)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Model pro překlady', 'Jazykový model připojení AI, který překládá. Pokud trasa neuvádí žádné modely, zadejte název modelu.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Zatím není nastavena žádná překladová služba (Azure Translator, DeepL nebo připojení AI).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s je vybrána, ale už není nastavena.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Vyberte model: bez modelu nemůže model AI překládat.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: zatím nenastaveno.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Nedostupné: připojení AI není nastaveno.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Nastavit v části „Přístupy a licence“';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Pomocník při psaní (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Model', 'Model připojení AI pro pomocníka při psaní. Pokud trasa neuvádí žádné modely, zadejte název modelu.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Požadavky za den', 'Pro všechny redaktory dohromady; jeden požadavek přinese tři návrhy. 0 pomocníka vypne.'];
