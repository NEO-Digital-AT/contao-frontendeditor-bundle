<?php

declare(strict_types=1);

use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\License\Editions;

/*
 * The extension's part of Basic's page "Access keys and licences": the licence
 * (editions Plus and Pro), the translation services Azure Translator and DeepL
 * (translation proposals of Plus) and the AI connection (writing aid of Pro,
 * translation by a language model), all shared with other extensions. Without
 * Plus the extension takes its name off the translation services and the AI
 * connection again (EventListener\AccessSectionsListener): the page shows
 * them only while another extension uses them.
 */
$extension = Editions::EXTENSION;

AccessPage::useAi($extension);
AccessPage::useAzureTranslator($extension);
AccessPage::useDeepL($extension);
AccessPage::addLicense(Editions::MODULE, Editions::PACKAGE, $extension);
