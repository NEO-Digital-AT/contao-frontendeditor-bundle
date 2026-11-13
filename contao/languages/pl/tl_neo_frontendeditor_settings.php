<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Połączenie';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adres backendu', 'Wypełnij tylko wtedy, gdy backend działa pod własnym adresem, np. https://manage.example.com. Puste: ten sam adres co strona.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Propozycje tłumaczeń (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Usługa tłumaczeniowa', 'Usługa, która proponuje tłumaczenia wersji językowych. Można wybrać tylko usługi skonfigurowane w „Dostępy i licencje”. Teksty elementu trafiają do tej usługi.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Model AI (połączenie AI)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Model do tłumaczeń', 'Model językowy połączenia AI, który tłumaczy. Jeśli trasa nie podaje modeli, wpisz nazwę modelu.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Nie skonfigurowano jeszcze żadnej usługi tłumaczeniowej (Azure Translator, DeepL lub połączenie AI).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = 'Wybrano %s, ale usługa nie jest już skonfigurowana.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Wybierz model: bez modelu model AI nie może tłumaczyć.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: jeszcze nie skonfigurowano.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Niedostępne: połączenie AI nie jest skonfigurowane.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Skonfiguruj w „Dostępy i licencje”';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Asystent pisania (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Model', 'Model połączenia AI dla pomocy w pisaniu. Jeśli trasa nie podaje modeli, wpisz nazwę modelu.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Zapytania dziennie', 'Dla wszystkich redaktorów razem; jedno zapytanie daje trzy propozycje. 0 wyłącza asystenta pisania.'];
