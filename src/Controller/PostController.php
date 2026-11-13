<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\PageModel;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Content\StructureEditor;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Posts\PostEditor;
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
 * Posts (Plus): types and archives, lists, publish or withdraw, duplicate,
 * delete, reorder, create, and the posts whose elements are on the current
 * page. Every call names the current page (parameter "page") for the
 * licence; changes need the request token. The switch "edit posts" and
 * Contao's voters decide (PostEditor).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class PostController
{
    private const TABLE = '{table<tl_[a-z0-9_]{1,60}>}';

    public function __construct(
        private readonly PostEditor $posts,
        private readonly StructureEditor $structure,
        private readonly PageStructure $pageStructure,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** Post types and their archives for the page (query: page). */
    #[Route('/posts', name: 'neo_frontend_editor_posts', methods: ['GET'])]
    public function types(Request $request): Response
    {
        $page = $request->query->getInt('page');

        if (!$this->mayRead($page)) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->json(['success' => true, 'types' => $this->posts->typesForPage($page)]);
    }

    /** Posts of an archive (query: page, q = search term). */
    #[Route('/posts/'.self::TABLE.'/{archive<\d+>}', name: 'neo_frontend_editor_posts_list', methods: ['GET'])]
    public function items(string $table, int $archive, Request $request): Response
    {
        if (!$this->mayRead($request->query->getInt('page'))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $items = $this->posts->items($table, $archive, mb_substr((string) $request->query->get('q', ''), 0, 100));

        return null === $items ? ErrorResponse::notFound($this->logger) : $this->json(['success' => true, 'items' => $items]);
    }

    /**
     * The posts whose content elements are on the page, with all their elements
     * (query: page, ids = IDs of the elements found on the page, comma-separated,
     * path = address of the page, for the post of a reader page).
     */
    #[Route('/posts/on-page', name: 'neo_frontend_editor_posts_on_page', methods: ['GET'], priority: 10)]
    public function onPage(Request $request): Response
    {
        $page = $request->query->getInt('page');

        if (!$this->mayRead($page)) {
            return $this->json(['success' => true, 'posts' => []]);
        }

        $ids = \array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $request->query->get('ids', ''))))), 0, 500);
        $this->pageStructure->prepare();
        $model = PageModel::findWithDetails($page);
        $language = (string) $model?->language;

        return $this->json(['success' => true, 'posts' => $this->posts->postsOfElements(
            $ids,
            // With language versions (Plus): the state of the translation, as for the elements of the page.
            fn (array $row): array => $this->pageStructure->element($row, $language) + ['translation' => $this->pageStructure->translationOf($row)],
            $page,
            $this->itemOfPath((string) $request->query->get('path', ''), (string) $model?->alias),
        )]);
    }

    /** The part of the address after the page alias (Contao's auto_item), without a suffix. */
    private function itemOfPath(string $path, string $alias): string
    {
        $position = '' !== $alias ? strpos($path, '/'.$alias.'/') : false;

        if (false === $position) {
            return '';
        }

        $rest = explode('/', substr($path, $position + \strlen($alias) + 2))[0];

        return mb_substr(rawurldecode((string) preg_replace('/\.[a-z0-9]{1,5}$/i', '', $rest)), 0, 255);
    }

    #[Route('/post/'.self::TABLE.'/{id<\d+>}/toggle', name: 'neo_frontend_editor_post_toggle', methods: ['POST'])]
    public function toggle(string $table, int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->posts->toggle($table, $id));
    }

    #[Route('/post/'.self::TABLE.'/{id<\d+>}/copy', name: 'neo_frontend_editor_post_copy', methods: ['POST'])]
    public function copy(string $table, int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->posts->copy($table, $id));
    }

    #[Route('/post/'.self::TABLE.'/{id<\d+>}/delete', name: 'neo_frontend_editor_post_delete', methods: ['POST'])]
    public function delete(string $table, int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->posts->delete($table, $id));
    }

    /** Body: {"after": <post ID>} or {} for the top of its archive. */
    #[Route('/post/'.self::TABLE.'/{id<\d+>}/move', name: 'neo_frontend_editor_post_move', methods: ['POST'])]
    public function move(string $table, int $id, Request $request): Response
    {
        return $this->handle($request, function (array $body) use ($table, $id): StructureResult {
            $after = $body['after'] ?? null;

            return null === $after || (\is_int($after) && $after > 0) ? $this->posts->move($table, $id, $after) : StructureResult::invalid();
        });
    }

    #[Route('/posts/'.self::TABLE.'/{archive<\d+>}/create', name: 'neo_frontend_editor_post_create', methods: ['POST'])]
    public function create(string $table, int $archive, Request $request): Response
    {
        return $this->handle($request, function () use ($table, $archive): StructureResult {
            $result = $this->posts->create($table, $archive);

            return WriteStatus::Saved === $result->status ? StructureResult::done((int) $result->id, ['module' => $this->posts->type($table)['module'] ?? '']) : $result;
        });
    }

    /** A new post whose form was left without saving. */
    #[Route('/post/'.self::TABLE.'/{id<\d+>}/discard', name: 'neo_frontend_editor_post_discard', methods: ['POST'])]
    public function discard(string $table, int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->structure->discard($table, $id));
    }

    /** Where Contao's preview shows the post (after its form was saved). */
    #[Route('/post/'.self::TABLE.'/{id<\d+>}/preview', name: 'neo_frontend_editor_post_preview', methods: ['GET'])]
    public function preview(string $table, int $id, Request $request): Response
    {
        if (!$this->mayRead($request->query->getInt('page')) || null === $this->posts->type($table)) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->json(['success' => true, 'saved' => $this->posts->isSaved($table, $id), 'preview' => $this->posts->previewQuery($table, $id)]);
    }

    /** New element in a post (Plus). Body: {"after": <element ID>} or {}. */
    #[Route('/content/'.self::TABLE.'/{pid<\d+>}/element', name: 'neo_frontend_editor_post_element_create', methods: ['POST'])]
    public function createElement(string $table, int $pid, Request $request): Response
    {
        return $this->handle($request, function (array $body) use ($table, $pid): StructureResult {
            $after = $body['after'] ?? null;

            return null === $after || (\is_int($after) && $after > 0) ? $this->structure->createElementIn($table, $pid, $after) : StructureResult::invalid();
        });
    }

    private function mayRead(int $page): bool
    {
        return $page > 0 && $this->access->has(Permission::EditMode) && $this->access->has(Permission::EditPosts) && $this->editions->hasPlus($page);
    }

    /** @param \Closure(array<string, mixed>): StructureResult $operation */
    private function handle(Request $request, \Closure $operation): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $body = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);

        if (!\is_array($body)) {
            return ErrorResponse::badRequest($this->logger);
        }

        if (!$this->mayRead(\is_int($body['page'] ?? null) ? $body['page'] : 0)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $result = $operation($body);

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
