<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\Event\MenuEvent;
use Knp\Menu\Util\MenuManipulator;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Frontend Editor" in the head of the back end, right after Contao's
 * "Preview": the same preview of the same page, with the editor switched on
 * (EditorSwitchListener). Only for users who may use the editor. Runs after
 * Contao's entry (BackendPreviewListener, priority -48) and takes its address,
 * so both open the page the back end shows.
 */
#[AsEventListener(priority: -49)]
final class EditorHeaderListener
{
    public const ITEM = 'neo_frontend_editor';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ('headerMenu' !== $tree->getName() || null === ($preview = $tree->getChild('preview'))) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(Permission::EditMode->attribute())) {
            return;
        }

        $uri = (string) $preview->getUri();

        $item = $event->getFactory()
            ->createItem(self::ITEM)
            ->setLabel($this->translator->trans('backend.header', [], 'neo_frontend_editor'))
            ->setUri($uri.(str_contains($uri, '?') ? '&' : '?').EditorSwitchListener::PARAMETER.'=1')
            ->setLinkAttribute('class', 'icon-frontend-editor')
            ->setLinkAttribute('title', $this->translator->trans('backend.header_title', [], 'neo_frontend_editor'))
            ->setLinkAttribute('target', '_blank')
            ->setExtra('translation_domain', false)
        ;

        $tree->addChild($item);

        $position = array_search('preview', array_keys($tree->getChildren()), true);
        (new MenuManipulator())->moveToPosition($item, (int) $position + 1);
    }
}
