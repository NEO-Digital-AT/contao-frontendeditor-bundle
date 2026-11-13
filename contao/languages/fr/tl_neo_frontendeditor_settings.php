<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Connexion';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Adresse du back-office', 'À remplir seulement si le back-office fonctionne sous sa propre adresse, p. ex. https://manage.example.com. Vide : la même adresse que le site.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Propositions de traduction (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Service de traduction', 'Le service qui propose les traductions des versions linguistiques. Seuls les services configurés sous « Accès et licences » peuvent être choisis. Les textes de l’élément sont envoyés à ce service.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Modèle d’IA (connexion IA)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Modèle pour les traductions', 'Le modèle de langage de la connexion IA qui traduit. Si la route ne liste aucun modèle, saisissez le nom du modèle.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Aucun service de traduction n’est encore configuré (Azure Translator, DeepL ou la connexion IA).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s est choisi mais n’est plus configuré.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Choisissez un modèle : sans modèle, le modèle d’IA ne peut pas traduire.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s : pas encore configuré.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'Indisponible : la connexion IA n’est pas configurée.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Le configurer sous « Accès et licences »';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Aide à la rédaction (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Modèle', 'Modèle de la connexion IA pour l’aide à la rédaction. Si la route ne liste aucun modèle, saisissez le nom du modèle.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Requêtes par jour', 'Pour tous les rédacteurs ensemble ; une requête donne trois propositions. 0 désactive l’aide à la rédaction.'];
