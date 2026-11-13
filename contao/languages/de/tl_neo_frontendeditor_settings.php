<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Verbindung';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adresse des Backends', 'Nur ausfüllen, wenn das Backend unter einer eigenen Adresse läuft, z. B. https://manage.example.com. Leer: dieselbe Adresse wie die Website.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Übersetzungsvorschläge (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Übersetzungsdienst', 'Welcher Dienst Übersetzungen der Sprachversionen vorschlägt. Wählbar sind nur Dienste, die unter „Zugänge und Lizenzen“ eingerichtet sind. Die Texte des Elements gehen an diesen Dienst.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'KI-Modell (KI-Verbindung)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Modell für Übersetzungen', 'Das Sprachmodell der KI-Verbindung, das übersetzt. Listet die Route keine Modelle, den Namen des Modells eintippen.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Noch kein Übersetzungsdienst eingerichtet (Azure Translator, DeepL oder die KI-Verbindung).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s ist gewählt, aber nicht mehr eingerichtet.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Bitte ein Modell wählen: ohne Modell kann das KI-Modell nicht übersetzen.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: noch nicht eingerichtet.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Nicht verfügbar: Die KI-Verbindung ist nicht eingerichtet.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Unter „Zugänge und Lizenzen“ einrichten';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Schreibhilfe (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Modell', 'Modell der KI-Verbindung für die Schreibhilfe. Listet die Route keine Modelle, den Namen des Modells eintippen.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Anfragen pro Tag', 'Für alle Redakteure zusammen; eine Anfrage bringt drei Vorschläge. 0 schaltet die Schreibhilfe ab.'];
