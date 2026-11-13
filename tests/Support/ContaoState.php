<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\Support;

use Contao\DcaLoader;
use Contao\System;

/**
 * The global state Contao's static helpers read, set up without the Contao
 * framework: DCA arrays and language texts that count as loaded, so
 * Controller::loadDataContainer() and System::loadLanguageFile() return
 * without looking for files, and Contao's default settings (allowed HTML tags
 * and attributes). clear() removes all of it again.
 */
final class ContaoState extends DcaLoader
{
    /**
     * The DCA of a table, as if Contao had loaded its files.
     *
     * @param array<string, mixed> $dca
     */
    public static function dca(string $table, array $dca): void
    {
        $GLOBALS['TL_DCA'][$table] = $dca;
        static::$arrLoaded['dcaFiles'][$table] = true;
    }

    /**
     * Language texts of a file, as if Contao had loaded it in English.
     *
     * @param array<string, mixed> $texts merged into $GLOBALS['TL_LANG']
     */
    public static function language(string $name, array $texts = []): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'en';
        $GLOBALS['TL_LANG'] = array_replace_recursive($GLOBALS['TL_LANG'] ?? [], $texts);
        static::$arrLanguageFiles[$name]['en'] = true;
    }

    /**
     * Contao's own English texts of a group of default.xlf (e.g. ERR, the
     * messages of the widgets), as $GLOBALS['TL_LANG'][$group].
     */
    public static function contaoTexts(string $group): void
    {
        $file = \dirname((string) (new \ReflectionClass(System::class))->getFileName(), 3).'/languages/en/default.xlf';
        $xml = simplexml_load_string((string) file_get_contents($file));

        foreach ($xml->xpath('//*[local-name()="trans-unit"]') ?: [] as $unit) {
            $id = (string) $unit['id'];

            if (str_starts_with($id, $group.'.') && 1 === substr_count($id, '.')) {
                $GLOBALS['TL_LANG'][$group][substr($id, \strlen($group) + 1)] = (string) $unit->source;
            }
        }
    }

    /** Contao's own default settings ($GLOBALS['TL_CONFIG']), e.g. the allowed tags of rich text. */
    public static function defaultConfig(): void
    {
        include \dirname((string) (new \ReflectionClass(System::class))->getFileName(), 3).'/config/default.php';
    }

    /** The DCA loading of Contao 5.7 depends on the request: it starts from this one without resetting what is set up. */
    public static function forRequest(): void
    {
        if (method_exists(DcaLoader::class, 'switchToCurrentRequest')) {
            DcaLoader::switchToCurrentRequest();
        }
    }

    public static function clear(): void
    {
        if (method_exists(DcaLoader::class, 'reset')) {
            DcaLoader::reset();
        }

        static::$arrLoaded = [];
        static::$arrLanguageFiles = [];
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_CONFIG'], $GLOBALS['BE_FFL'], $GLOBALS['TL_CTE'], $GLOBALS['BE_MOD']);
    }
}
