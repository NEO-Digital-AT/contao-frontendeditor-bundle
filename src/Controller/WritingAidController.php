<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Ai\WritingAid;
use Neo\FrontendEditorBundle\Ai\WritingAidException;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The writing aid (edition Pro): three proposals for the headline or the text
 * of an element. Needs Pro for the page's website, "change texts and images"
 * and Contao's rights on the element; the request token is required.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class WritingAidController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly WritingAid $writingAid,
        private readonly Settings $settings,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** Body: {"field": "headline"|"text", "term": "…"}. */
    #[Route('/element/{id<\d+>}/suggest', name: 'neo_frontend_editor_suggest', methods: ['POST'])]
    public function suggest(int $id, Request $request): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditContent)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $pageId = (int) $this->db->fetchOne("SELECT a.pid FROM tl_content c JOIN tl_article a ON a.id = c.pid WHERE c.id = ? AND c.ptable = 'tl_article'", [$id]);

        if ($pageId < 1) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPro($pageId)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $body = json_decode($request->getContent() ?: '{}', true);
        $body = \is_array($body) ? $body : [];

        try {
            $proposals = $this->writingAid->propose($id, (string) ($body['field'] ?? ''), \is_string($body['term'] ?? null) ? $body['term'] : '');
        } catch (WritingAidException $e) {
            return WritingAidException::FORBIDDEN === $e->reason
                ? ErrorResponse::forbidden($this->logger)
                : ErrorResponse::unavailable($this->logger, 'NFE503-ai-'.$e->reason, $this->text('error.ai_'.$e->reason));
        }

        return $this->json(['success' => true, 'proposals' => $proposals, 'left' => $this->settings->aiRequestsLeft()]);
    }

    private function text(string $key): string
    {
        $user = $this->security->getUser();

        return $this->translator->trans('editor.'.$key, [], 'neo_frontend_editor', $user instanceof BackendUser ? (string) $user->language : null);
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
