<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Symfony's translator with the editor's own translation files
 * (translations/neo_frontend_editor.*.yaml), English unless asked otherwise.
 */
final class EditorTranslations
{
    public static function translator(string ...$locales): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());

        foreach (['en', ...$locales] as $locale) {
            $translator->addResource('yaml', \dirname(__DIR__, 2).'/translations/neo_frontend_editor.'.$locale.'.yaml', $locale, 'neo_frontend_editor');
        }

        return $translator;
    }
}
