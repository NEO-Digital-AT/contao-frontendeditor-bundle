<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Neo\FrontendEditorBundle\Style\PageStyles;
use Neo\FrontendEditorBundle\Style\StyleCompiler;
use Neo\FrontendEditorBundle\Style\StyleException;
use Neo\FrontendEditorBundle\Style\StyleMessages;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * The style of a page (Pro) in the editor: open it (a draft record when the
 * page has none yet, edited in Contao's form), translate it for the preview
 * while typing (nothing is saved) and remove a draft that was left unsaved.
 * Everything needs the switch "Edit page styles", the back-end module "Page
 * styles", Pro and the permission to edit the page (StyleVoter).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/style', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class StyleController
{
    public const MODULE = 'neo_frontendeditor_styles';

    public function __construct(
        private readonly PageStyles $styles,
        private readonly StyleCompiler $compiler,
        private readonly StyleMessages $messages,
        private readonly StyleVoter $voter,
        private readonly ElementAccess $access,
        private readonly Security $security,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** The saved state of a page's style: whether it exists, when it was saved, its CSS. */
    #[Route('/{page<\d+>}', name: 'neo_frontend_editor_style', methods: ['GET'])]
    public function show(int $page): Response
    {
        if (!$this->mayStyle($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $record = $this->styles->record($page);

        return $this->json([
            'success' => true,
            'id' => (int) ($record['id'] ?? 0),
            'saved' => (int) ($record['tstamp'] ?? 0) > 0,
            'tstamp' => (int) ($record['tstamp'] ?? 0),
            'css' => (int) ($record['tstamp'] ?? 0) > 0 ? (string) $record['css'] : '',
        ]);
    }

    #[Route('/{page<\d+>}/open', name: 'neo_frontend_editor_style_open', methods: ['POST'])]
    public function open(int $page, Request $request): Response
    {
        if (!$this->validToken($request)) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->mayStyle($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        [$record, $created] = $this->styles->open($page);

        return $this->json(['success' => true, 'id' => (int) $record['id'], 'created' => $created, 'tstamp' => (int) $record['tstamp']]);
    }

    /** Body: {"scss": "…"}. Translates only; the answer is the CSS or the plain text of the error with its line. */
    #[Route('/{page<\d+>}/preview', name: 'neo_frontend_editor_style_preview', methods: ['POST'])]
    public function preview(int $page, Request $request): Response
    {
        if (!$this->validToken($request)) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->mayStyle($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $body = json_decode($request->getContent(), true);

        if (!\is_array($body) || !\is_string($body['scss'] ?? null)) {
            return ErrorResponse::badRequest($this->logger);
        }

        try {
            return $this->json(['success' => true, 'css' => $this->compiler->compile($body['scss'])]);
        } catch (StyleException $exception) {
            $text = $this->messages->text($exception);
            $response = ErrorResponse::invalid($this->logger, ['scss' => [$text]]);
            $response->setData(json_decode((string) $response->getContent(), true) + ['line' => 'brace_end' === $exception->key ? null : $exception->sourceLine]);

            return $response;
        }
    }

    /** Removes the record of a page's style only while it is a draft (never saved). */
    #[Route('/record/{id<\d+>}/discard', name: 'neo_frontend_editor_style_discard', methods: ['POST'])]
    public function discard(int $id, Request $request): Response
    {
        if (!$this->validToken($request)) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $record = $this->styles->byId($id);

        if (null === $record || !$this->mayStyle((int) $record['pid'])) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->json(['success' => true, 'removed' => $this->styles->discard($id)]);
    }

    private function mayStyle(int $page): bool
    {
        $token = $this->security->getToken();

        return null !== $token
            && $this->access->has(Permission::EditMode)
            && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, self::MODULE)
            && $this->voter->mayStyle($token, $page);
    }

    private function validToken(Request $request): bool
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        return '' !== $value && $this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
