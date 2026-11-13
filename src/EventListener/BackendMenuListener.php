<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Neo\FrontendEditorBundle\License\Editions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Back-end menu: the entries of the paid editions only with their licence —
 * "Translations" (language versions, Plus) and "Page styles" (Pro). Without
 * the licence the editor shows these functions locked with their edition, so
 * the lists would stay empty. The settings of the extension stay in the menu.
 * After Contao's main menu (BackendMainListener, priority 10); the records stay.
 */
#[AsEventListener(priority: 0)]
final class BackendMenuListener
{
    /** Module of the back-end menu => edition it needs. */
    public const MODULES = [
        'neo_frontendeditor_translations' => Editions::PLUS,
        'neo_frontendeditor_styles' => Editions::PRO,
    ];

    public function __construct(private readonly Editions $editions)
    {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ('mainMenu' !== $tree->getName() || null === ($group = $tree->getChild('neo'))) {
            return;
        }

        $edition = $this->editions->forBackend();

        foreach (self::MODULES as $module => $required) {
            if (null !== $group->getChild($module) && !Editions::includes($edition, $required)) {
                $group->removeChild($module);
            }
        }
    }
}
