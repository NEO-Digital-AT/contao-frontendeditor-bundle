<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Neo\BasicBundle\Access\AccessPage;
use Neo\FrontendEditorBundle\License\Editions;

/**
 * Basic's page "Access keys and licences" shows the translation services
 * (Azure Translator, DeepL) and the AI connection for every extension that
 * registered for them (contao/dca/tl_neo_access.php). The editor needs them only
 * from Plus on (translation proposals, Pro adds the writing aid); without Plus it
 * takes its name off them again, before Basic puts the palette together
 * (AccessPaletteListener, same hook, lower priority). Another extension that
 * uses them keeps them on the page; the stored keys stay either way.
 */
#[AsHook('loadDataContainer', priority: 16)]
final class AccessSectionsListener
{
    /** Sections of the page the editor uses only with Plus or Pro. */
    private const LICENSED = ['ai', 'azure_translator', 'deepl'];

    public function __construct(private readonly Editions $editions)
    {
    }

    public function __invoke(string $table): void
    {
        if (AccessPage::TABLE !== $table || Editions::includes($this->editions->forBackend(), Editions::PLUS)) {
            return;
        }

        $config = &$GLOBALS['TL_DCA'][AccessPage::TABLE]['config']['neo_access'];

        foreach (self::LICENSED as $kind) {
            if (!isset($config[$kind])) {
                continue;
            }

            $config[$kind] = array_values(array_diff((array) $config[$kind], [Editions::EXTENSION]));

            if ([] === $config[$kind]) {
                unset($config[$kind]);
            }
        }
    }
}
