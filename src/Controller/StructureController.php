<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Neo\FrontendEditorBundle\Content\StructureEditor;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Endpoints that change the structure of a page: create, move, duplicate,
 * hide and delete elements, create articles, and discard a new record whose
 * form was left without saving. Every call needs the request token; the
 * switches of the extension and Contao's voters decide (StructureEditor).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false], methods: ['POST'])]
final class StructureController
{
    public function __construct(
        private readonly StructureEditor $editor,
        private readonly ElementAccess $access,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** New element in the article. Body: {"after": <element ID>} or {} for the top of the article. */
    #[Route('/article/{id<\d+>}/element', name: 'neo_frontend_editor_element_create')]
    public function createElement(int $id, Request $request): Response
    {
        return $this->handle($request, fn (array $body): StructureResult => $this->editor->createElement($id, $this->optionalId($body, 'after')));
    }

    /** New element in an element that contains elements (accordion, slider, element group …). Body: {"after": <element ID>} or {}. */
    #[Route('/element/{id<\d+>}/nested', name: 'neo_frontend_editor_element_create_nested')]
    public function createNested(int $id, Request $request): Response
    {
        return $this->handle($request, fn (array $body): StructureResult => $this->editor->createElementIn('tl_content', $id, $this->optionalId($body, 'after')));
    }

    /** Body: {"after": <element ID>} or {"article": <article ID>} / {"parent": <post or element ID>} for the top of that article, post or element. */
    #[Route('/element/{id<\d+>}/move', name: 'neo_frontend_editor_element_move')]
    public function move(int $id, Request $request): Response
    {
        return $this->handle($request, fn (array $body): StructureResult => $this->editor->moveElement($id, $this->optionalId($body, 'after'), $this->optionalId($body, 'article') ?? $this->optionalId($body, 'parent')));
    }

    #[Route('/element/{id<\d+>}/copy', name: 'neo_frontend_editor_element_copy')]
    public function copy(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->editor->copyElement($id));
    }

    #[Route('/element/{id<\d+>}/toggle', name: 'neo_frontend_editor_element_toggle')]
    public function toggle(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->editor->toggleElement($id));
    }

    #[Route('/element/{id<\d+>}/delete', name: 'neo_frontend_editor_element_delete')]
    public function delete(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->editor->deleteElement($id));
    }

    #[Route('/element/{id<\d+>}/discard', name: 'neo_frontend_editor_element_discard')]
    public function discardElement(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->editor->discard('tl_content', $id));
    }

    /** New article on the page. Body: {"column": "main"}. */
    #[Route('/page/{id<\d+>}/article', name: 'neo_frontend_editor_article_create')]
    public function createArticle(int $id, Request $request): Response
    {
        return $this->handle($request, function (array $body) use ($id): StructureResult {
            $column = $body['column'] ?? 'main';

            return \is_string($column) && preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $column)
                ? $this->editor->createArticle($id, $column)
                : StructureResult::invalid();
        });
    }

    #[Route('/article/{id<\d+>}/discard', name: 'neo_frontend_editor_article_discard')]
    public function discardArticle(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->editor->discard('tl_article', $id));
    }

    /** @param \Closure(array<string, mixed>): StructureResult $operation */
    private function handle(Request $request, \Closure $operation): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->access->has(Permission::EditMode)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $body = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);

        if (!\is_array($body)) {
            return ErrorResponse::badRequest($this->logger);
        }

        try {
            $result = $operation($body);
        } catch (\InvalidArgumentException) {
            return ErrorResponse::badRequest($this->logger);
        }

        return match ($result->status) {
            WriteStatus::NotFound => ErrorResponse::notFound($this->logger),
            WriteStatus::Forbidden => ErrorResponse::forbidden($this->logger),
            WriteStatus::Invalid => ErrorResponse::badRequest($this->logger),
            WriteStatus::Saved => $this->json(['success' => true, 'id' => $result->id] + $result->data),
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws \InvalidArgumentException when the value is there but no ID
     */
    private function optionalId(array $body, string $key): int|null
    {
        if (!\array_key_exists($key, $body) || null === $body[$key]) {
            return null;
        }

        if (!\is_int($body[$key]) || $body[$key] < 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" must be an ID.', $key));
        }

        return $body[$key];
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
