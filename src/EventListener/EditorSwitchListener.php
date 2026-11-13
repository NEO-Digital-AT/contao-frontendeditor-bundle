<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Turns the editor on when the preview is opened with "Frontend Editor" in
 * the head of the back end (EditorHeaderListener): Contao's preview route
 * answers with a redirect to the page; with the parameter, the answer also
 * sets the switch of the preview bar (cookie EditMode::COOKIE, as the switch
 * itself sets it). Where the front end has a domain of its own, Contao signs
 * the user in there and calls the route again with the same query on that
 * domain, so the switch is set where the page is shown.
 */
#[AsEventListener]
final class EditorSwitchListener
{
    public const PARAMETER = 'nfe_editor';

    private const ROUTE = 'contao_backend_preview';

    public function __construct(private readonly AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$event->isMainRequest() || self::ROUTE !== $request->attributes->get('_route') || '1' !== $request->query->get(self::PARAMETER)) {
            return;
        }

        if (!$response->isRedirection() || !$this->authorizationChecker->isGranted(Permission::EditMode->attribute())) {
            return;
        }

        $response->headers->setCookie(Cookie::create(EditMode::COOKIE, '1', 0, '/', null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX));
    }
}
