<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Seo\FocusKeyword;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * SEO check (Pro): the focus keyword of a page and the other pages with the
 * same term. The checks themselves run in the browser on the page as the
 * preview shows it. Changing the term needs the permission to edit the page.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/seo', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class SeoController
{
    public function __construct(
        private readonly FocusKeyword $keywords,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly Security $security,
        private readonly Connection $db,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    #[Route('/{id<\d+>}', name: 'neo_frontend_editor_seo', methods: ['GET'])]
    public function show(int $id): Response
    {
        $page = $this->page($id);

        if (null === $page) {
            return ErrorResponse::forbidden($this->logger);
        }

        $keyword = $this->keywords->get($id);

        return $this->json([
            'success' => true,
            'keyword' => $keyword,
            'usedOn' => $this->keywords->usedOn($id, $keyword),
            'robots' => (string) $page['robots'],
            'alias' => (string) $page['alias'],
            'canEdit' => $this->canEdit($page),
        ]);
    }

    /** Body: {"keyword": "…"} ("" removes it). */
    #[Route('/{id<\d+>}', name: 'neo_frontend_editor_seo_save', methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $page = $this->page($id);
        $body = json_decode($request->getContent(), true);

        if (null === $page || !$this->canEdit($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        if (!\is_array($body) || !\is_string($body['keyword'] ?? null)) {
            return ErrorResponse::badRequest($this->logger);
        }

        $this->keywords->set($id, $body['keyword']);
        $keyword = $this->keywords->get($id);

        return $this->json(['success' => true, 'keyword' => $keyword, 'usedOn' => $this->keywords->usedOn($id, $keyword)]);
    }

    /** @return array<string, mixed>|null a page the user may open in the editor, with Pro */
    private function page(int $id): array|null
    {
        if (!$this->access->has(Permission::EditMode) || !$this->editions->hasPro($id) || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, $id)) {
            return null;
        }

        $row = $this->db->fetchAssociative('SELECT * FROM tl_page WHERE id = ? AND tstamp > 0', [$id]);

        return false === $row ? null : $row;
    }

    /** @param array<string, mixed> $page */
    private function canEdit(array $page): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_page', new UpdateAction('tl_page', $page));
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
