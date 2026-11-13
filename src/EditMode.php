<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Whether a front end request is edited: only in Contao's preview (preview.php),
 * only for a logged-in back-end user who may edit in the front end, and only
 * after the switch in the preview toolbar has been turned on (cookie).
 * Visitors never reach the editor: without the preview there is nothing to check.
 *
 * An edited page is shown in two documents: the browser's own navigation gets
 * the editor view (sidebar and Contao's preview bar, EditorViewListener), which
 * loads the page itself into a frame (FRAME_PARAMETER, or any further page the
 * browser loads into that frame).
 */
final class EditMode
{
    public const COOKIE = 'contao_frontend_editor';

    /** Query parameter of the page in the frame of the editor view. */
    public const FRAME_PARAMETER = 'nfe_frame';

    /** @var \WeakMap<Request, BackendUser|false> */
    private \WeakMap $editors;

    /**
     * @param UserProviderInterface<BackendUser> $backendUserProvider
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TokenChecker $tokenChecker,
        #[Autowire(service: 'contao.security.backend_user_provider')]
        private readonly UserProviderInterface $backendUserProvider,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
        $this->editors = new \WeakMap();
    }

    /** The back-end user editing the current page, or null when the page is not edited. */
    public function editor(Request|null $request = null): BackendUser|null
    {
        $request ??= $this->requestStack->getMainRequest();

        if (null === $request) {
            return null;
        }

        $this->editors[$request] ??= $this->resolve($request);

        return $this->editors[$request] ?: null;
    }

    /** The preview of a back-end user who may use the editor (the switch is shown), regardless of the switch. */
    public function mayEdit(Request $request): bool
    {
        return null !== $this->previewUser($request);
    }

    /**
     * The page in the frame of the editor view: loaded with the parameter, or a
     * further page the browser loads into that frame (a link followed there).
     */
    public function isFrame(Request $request): bool
    {
        return $request->query->has(self::FRAME_PARAMETER) || 'iframe' === $request->headers->get('Sec-Fetch-Dest');
    }

    /**
     * A page the browser opens as a document of its own: not in a frame, not by
     * a script (fetch, XMLHttpRequest), not a form sent with POST. Browsers
     * without the Fetch Metadata headers count by the other conditions.
     */
    public function isDocumentRequest(Request $request): bool
    {
        $destination = $request->headers->get('Sec-Fetch-Dest');
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $request->isMethod('GET')
            && !$request->isXmlHttpRequest()
            && !$this->isFrame($request)
            && (null === $destination || 'document' === $destination)
            && (null === $mode || 'navigate' === $mode)
            && str_contains((string) $request->headers->get('Accept'), 'text/html');
    }

    private function resolve(Request $request): BackendUser|false
    {
        if ('1' !== $request->cookies->get(self::COOKIE)) {
            return false;
        }

        return $this->previewUser($request) ?? false;
    }

    /** The back-end user of the preview when they may use the editor, regardless of the switch. */
    public function previewUser(Request $request): BackendUser|null
    {
        if (true !== $request->attributes->get('_preview')) {
            return null;
        }

        $username = $this->tokenChecker->getBackendUsername();

        if (null === $username) {
            return null;
        }

        try {
            $user = $this->backendUserProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            return null;
        }

        if (!$user instanceof BackendUser) {
            return null;
        }

        $token = new UsernamePasswordToken($user, 'contao_backend', $user->getRoles());

        return $this->accessDecisionManager->decide($token, [Permission::EditMode->attribute()]) ? $user : null;
    }
}
