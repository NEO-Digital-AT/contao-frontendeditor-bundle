<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The stylesheet of the extension's entry in the head of the back end (the
 * symbol of "Frontend Editor", EditorHeaderListener), on every back-end page.
 */
#[AsEventListener]
final class BackendAssetsListener
{
    public const STYLESHEET = 'bundles/neofrontendeditor/backend.css';

    public function __construct(private readonly ScopeMatcher $scopeMatcher)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($this->scopeMatcher->isBackendMainRequest($event)) {
            $GLOBALS['TL_CSS']['neo_frontend_editor'] = self::STYLESHEET;
        }
    }
}
