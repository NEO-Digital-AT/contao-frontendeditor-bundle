<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Neo\FrontendEditorBundle\Content\PageEditor;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\EventListener\FormBridgeListener;
use Neo\FrontendEditorBundle\EventListener\PageFormListener;
use Neo\FrontendEditorBundle\Language\PageLanguageVersions;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Pages (Plus): publish or withdraw, duplicate, delete, Contao's page tree
 * for a new page and the state of a new page after Contao's form. Changes
 * need the request token, the edition Plus and the switch "create and
 * publish pages"; Contao's voters decide each record (PageEditor).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/page', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class PageController
{
    public function __construct(
        private readonly PageEditor $pages,
        private readonly PageLanguageVersions $languageVersions,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** What can be created for a new page in the other languages of its website (after Contao's form of the new page). */
    #[Route('/{id<\d+>}/versions', name: 'neo_frontend_editor_page_versions', methods: ['GET'])]
    public function versions(int $id): Response
    {
        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditPages) || !$this->editions->hasPlus($id)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $plan = $this->languageVersions->plan($id);

        return null === $plan ? ErrorResponse::notFound($this->logger) : $this->json(['success' => true] + $plan);
    }

    /** Body: {"versions": [{"root": <ID>, "title": "…", "alias": "…", "parent": <ID, only without a place>}]}. */
    #[Route('/{id<\d+>}/versions', name: 'neo_frontend_editor_page_versions_create', methods: ['POST'])]
    public function createVersions(int $id, Request $request): Response
    {
        return $this->handle($request, $id, function () use ($id, $request): StructureResult {
            if (!$this->access->has(Permission::EditPages)) {
                return StructureResult::forbidden();
            }

            $body = json_decode($request->getContent(), true);

            return \is_array($body) && \is_array($body['versions'] ?? null) && array_is_list($body['versions'])
                ? $this->languageVersions->create($id, $body['versions'])
                : StructureResult::invalid();
        });
    }

    #[Route('/{id<\d+>}/toggle', name: 'neo_frontend_editor_page_toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        return $this->handle($request, $id, fn (): StructureResult => $this->pages->togglePublished($id));
    }

    #[Route('/{id<\d+>}/copy', name: 'neo_frontend_editor_page_copy', methods: ['POST'])]
    public function copy(int $id, Request $request): Response
    {
        return $this->handle($request, $id, fn (): StructureResult => $this->pages->copyPage($id));
    }

    #[Route('/{id<\d+>}/delete', name: 'neo_frontend_editor_page_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        return $this->handle($request, $id, fn (): StructureResult => $this->pages->deletePage($id));
    }

    /** A new page whose form was left without saving (Contao's draft with tstamp 0). */
    #[Route('/{id<\d+>}/discard', name: 'neo_frontend_editor_page_discard', methods: ['POST'])]
    public function discard(int $id, Request $request): Response
    {
        return $this->handle($request, null, function () use ($id): StructureResult {
            $this->pages->leaveTree();

            return $this->pages->discardDraft($id);
        });
    }

    /** The page tree was closed without choosing a place. */
    #[Route('/tree/leave', name: 'neo_frontend_editor_page_tree_leave', methods: ['POST'])]
    public function leaveTree(Request $request): Response
    {
        return $this->handle($request, null, function (): StructureResult {
            $this->pages->leaveTree();

            return StructureResult::done(0);
        });
    }

    #[Route('/{id<\d+>}/state', name: 'neo_frontend_editor_page_state', methods: ['GET'])]
    public function state(int $id): Response
    {
        if (!$this->access->has(Permission::EditMode)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $state = $this->pages->state($id);

        return null === $state ? ErrorResponse::notFound($this->logger) : $this->json(['success' => true] + $state);
    }

    /**
     * Contao's page tree in create mode, opened down to the current page, for
     * the sidebar (query: origin = address of the website in the preview,
     * popup = 1, so the open nodes are stored where Contao's popup reads them).
     */
    #[Route('/{id<\d+>}/tree', name: 'neo_frontend_editor_page_tree', methods: ['GET'])]
    public function tree(int $id, Request $request): Response
    {
        if (!$this->access->has(Permission::EditMode) || !$this->editions->hasPlus($id) || !$this->pages->prepareTree($id)) {
            return ErrorResponse::forbidden($this->logger);
        }

        return new RedirectResponse($this->router->generate('contao_backend', [
            'do' => 'page',
            'popup' => '1',
            FormBridgeListener::ORIGIN_PARAMETER => (string) $request->query->get('origin', ''),
            PageFormListener::VIEW_PARAMETER => PageFormListener::TREE,
            'nfe_current' => $id,
        ]));
    }

    /** @param \Closure(): StructureResult $operation */
    private function handle(Request $request, int|null $pageId, \Closure $operation): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->access->has(Permission::EditMode) || (null !== $pageId && !$this->editions->hasPlus($pageId))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $result = $operation();

        return match ($result->status) {
            WriteStatus::NotFound => ErrorResponse::notFound($this->logger),
            WriteStatus::Forbidden => ErrorResponse::forbidden($this->logger),
            WriteStatus::Invalid => ErrorResponse::badRequest($this->logger),
            WriteStatus::Saved => $this->json(['success' => true, 'id' => $result->id] + $result->data),
        };
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
