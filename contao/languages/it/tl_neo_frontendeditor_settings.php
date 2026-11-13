<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Connessione';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Indirizzo del backend', 'Compilare solo se il backend ha un proprio indirizzo, ad es. https://manage.example.com. Vuoto: lo stesso indirizzo del sito.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Proposte di traduzione (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Servizio di traduzione', 'Il servizio che propone le traduzioni delle versioni linguistiche. Si possono scegliere solo i servizi configurati in «Accessi e licenze». I testi dell’elemento vengono inviati a questo servizio.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Modello di IA (connessione IA)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Modello per le traduzioni', 'Il modello linguistico della connessione IA che traduce. Se la route non elenca modelli, digitare il nome del modello.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Nessun servizio di traduzione è ancora configurato (Azure Translator, DeepL o la connessione IA).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s è selezionato ma non è più configurato.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Scegliere un modello: senza modello il modello di IA non può tradurre.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: non ancora configurato.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Non disponibile: la connessione IA non è configurata.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Configurarlo in «Accessi e licenze»';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Assistente di scrittura (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Modello', 'Modello della connessione IA per l’aiuto alla scrittura. Se la route non elenca modelli, digitare il nome del modello.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Richieste al giorno', 'Per tutti i redattori insieme; una richiesta fornisce tre proposte. 0 disattiva l’assistente di scrittura.'];
