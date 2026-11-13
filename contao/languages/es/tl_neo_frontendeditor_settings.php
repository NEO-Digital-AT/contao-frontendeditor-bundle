<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['connection_legend'] = 'Conexión';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['backend_url'] = ['Dirección del backend', 'Rellenar solo si el backend funciona en su propia dirección, p. ej. https://manage.example.com. Vacío: la misma dirección que el sitio.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['edit'] = 'Frontend Editor';

// Translation proposals (Plus)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_legend'] = 'Propuestas de traducción (Plus)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service'] = ['Servicio de traducción', 'Qué servicio propone las traducciones de las versiones de idioma. Solo se pueden elegir los servicios configurados en «Accesos y licencias». Los textos del elemento se envían a este servicio.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_service_options'] = ['azure' => 'Azure Translator', 'deepl' => 'DeepL', 'ai' => 'Modelo de IA (conexión de IA)'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model'] = ['Modelo para traducciones', 'El modelo de lenguaje de la conexión de IA que traduce. Si la ruta no muestra modelos, escriba el nombre del modelo.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_none'] = 'Todavía no hay ningún servicio de traducción configurado (Azure Translator, DeepL o la conexión de IA).';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_lost'] = '%s está elegido, pero ya no está configurado.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_model_missing'] = 'Elija un modelo: sin modelo, el modelo de IA no puede traducir.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_not_set_up'] = '%s: todavía no configurado.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_connection_missing'] = 'No disponible: la conexión de IA no está configurada.';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['translation_set_up'] = 'Configurarlo en «Accesos y licencias»';

// Writing aid (Pro)
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_legend'] = 'Asistente de redacción (Pro)';
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_model'] = ['Modelo', 'Modelo de la conexión de IA para la ayuda de redacción. Si la ruta no muestra modelos, escriba el nombre del modelo.'];
$GLOBALS['TL_LANG']['tl_neo_frontendeditor_settings']['ai_daily_limit'] = ['Solicitudes por día', 'Para todos los redactores juntos; una solicitud da tres propuestas. 0 desactiva el asistente.'];
