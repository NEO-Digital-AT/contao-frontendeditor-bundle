<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\ElementSync;
use Neo\FrontendEditorBundle\License\Editions;
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
 * Endpoints that keep an element in step with its language versions (edition
 * Plus, ElementSync): what it has in the other languages, and, once the user
 * agreed in the editor, a new element taken into them (created, translated and
 * linked, or linked with an element there), its image, its place and its
 * visibility passed on, its counterparts deleted with it. Each needs edit
 * mode, the switch "Language versions", Plus for the page and Contao's rights
 * on every record it changes; writes need the request token and, except the
 * image, the switch "Create, move and delete elements".
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/element/{id<\d+>}/versions', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class LanguageSyncController
{
    /** At most this many language versions per request. */
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly ElementSync $sync,
        private readonly ElementPlaces $places,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /**
     * The element's counterparts in the other languages of its page
     * (ElementSync::overview); "languages" is empty without other versions.
     * Query "new=1": the element was just created.
     */
    #[Route('', name: 'neo_frontend_editor_element_versions', methods: ['GET'])]
    public function overview(int $id, Request $request): Response
    {
        $element = $this->places->element($id);

        if (null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditLanguages) || !$this->editions->hasPlus($this->places->pageOf($element))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $overview = $this->sync->overview($element, $request->query->getBoolean('new'));

        return $this->json(['success' => true, 'id' => $id] + ($overview ?? ['languages' => []]));
    }

    /**
     * A new element into the other languages. Body: {"choices": {page: "create"
     * | "link" | "none"}, "link": {page: element ID}}.
     */
    #[Route('/create', name: 'neo_frontend_editor_element_versions_create', methods: ['POST'])]
    public function create(int $id, Request $request): Response
    {
        return $this->write($id, $request, true, function (array $element, array $body): array {
            $choices = [];
            $linkTo = [];

            foreach (\array_slice(\is_array($body['choices'] ?? null) ? $body['choices'] : [], 0, self::MAX_PAGES, true) as $page => $choice) {
                $choices[(int) $page] = \is_string($choice) ? $choice : '';
            }

            foreach (\is_array($body['link'] ?? null) ? $body['link'] : [] as $page => $other) {
                $linkTo[(int) $page] = (int) $other;
            }

            return $this->sync->spread($element, $choices, $linkTo);
        });
    }

    /** The element's image into its counterparts. Body: {"field": "singleSRC", "pages": [ID, …]}. */
    #[Route('/image', name: 'neo_frontend_editor_element_versions_image', methods: ['POST'])]
    public function image(int $id, Request $request): Response
    {
        return $this->write($id, $request, false, fn (array $element, array $body): array => $this->sync->image($element, \is_string($body['field'] ?? null) ? $body['field'] : '', $this->pages($body)));
    }

    /** After a move: the counterparts to the matching place. Body: {"pages": [ID, …]}. */
    #[Route('/move', name: 'neo_frontend_editor_element_versions_move', methods: ['POST'])]
    public function move(int $id, Request $request): Response
    {
        return $this->write($id, $request, true, fn (array $element, array $body): array => $this->sync->move($element, $this->pages($body)));
    }

    /** After hiding or showing: the counterparts the same. Body: {"pages": [ID, …]}. */
    #[Route('/visibility', name: 'neo_frontend_editor_element_versions_visibility', methods: ['POST'])]
    public function visibility(int $id, Request $request): Response
    {
        return $this->write($id, $request, true, fn (array $element, array $body): array => $this->sync->visibility($element, $this->pages($body)));
    }

    /** Before the element is deleted: its counterparts. Body: {"pages": [ID, …]}. */
    #[Route('/delete', name: 'neo_frontend_editor_element_versions_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        return $this->write($id, $request, true, fn (array $element, array $body): array => $this->sync->delete($element, $this->pages($body)));
    }

    /**
     * A write: request token, edit mode, the switches, Plus for the element's
     * page; $operation gets the element and the body and returns a result per
     * language version (done, or the reason why not: no_element, exists,
     * forbidden, no_container, no_main, link_invalid, no_field, failed,
     * translate_*).
     *
     * @param \Closure(array<string, mixed>, array<string, mixed>): list<array<string, mixed>> $operation
     */
    private function write(int $id, Request $request, bool $structure, \Closure $operation): Response
    {
        $token = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $token || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $token))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditLanguages) || ($structure && !$this->access->has(Permission::EditStructure))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $element = $this->places->element($id);

        if (null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPlus($this->places->pageOf($element))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $body = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);
        $results = $operation($element, \is_array($body) ? $body : []);

        // Processed: what came of each language version (done, or why not) is in its result.
        return $this->json(['success' => true, 'id' => $id, 'results' => $results]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<int>
     */
    private function pages(array $body): array
    {
        $pages = \is_array($body['pages'] ?? null) ? $body['pages'] : [];

        return \array_slice(array_values(array_filter(array_map('intval', $pages), static fn (int $page): bool => $page > 0)), 0, self::MAX_PAGES);
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
