<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Backend\BackendStyles;
use Neo\FrontendEditorBundle\Content\FieldWriter;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Content\SourceMap\TextSources;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\SourceHash;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Security\RecordAccess;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * JSON endpoints of the editor, in the back-end firewall: only logged-in
 * back-end users reach them, and every write needs the request token of the
 * session. Contao's voters decide each record and field (FieldWriter,
 * PageStructure); errors have one shape (ErrorResponse).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class ApiController
{
    public const TOKEN_HEADER = 'X-Request-Token';

    public function __construct(
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly PageStructure $structure,
        private readonly FieldWriter $writer,
        private readonly BackendStyles $styles,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly Connection $db,
        private readonly ElementLinks $links,
        private readonly SourceHash $hashes,
        private readonly Editions $editions,
        private readonly LoggerInterface $logger,
        private readonly TextSources $sources,
        private readonly RecordAccess $records,
        private readonly InsertTagParser $insertTags,
        private readonly ElementPlaces $places,
        private readonly PostTypes $postTypes,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** Who edits, with which language and permissions, and the request token for writes. */
    // A GET is never checked for a token, but only with _token_check does Contao write the CSRF cookie with the
    // answer: without that cookie a token fetched here is not valid (a new login, a deleted or expired cookie).
    #[Route('/session', name: 'neo_frontend_editor_session', methods: ['GET'], defaults: ['_token_check' => true])]
    public function session(): Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser || !$this->access->has(Permission::EditMode)) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->json([
            'success' => true,
            'token' => $this->tokenManager->getDefaultTokenValue(),
            'user' => ['name' => (string) $user->name, 'language' => (string) $user->language],
        ]);
    }

    #[Route('/page/{id<\d+>}', name: 'neo_frontend_editor_page', methods: ['GET'])]
    public function page(int $id): Response
    {
        if (!$this->access->has(Permission::EditMode)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $structure = $this->structure->forPage($id);

        return null === $structure ? ErrorResponse::notFound($this->logger) : $this->json(['success' => true] + $structure);
    }

    /**
     * Saves inline fields of one element. Body (JSON): {"fields": {"headline": {"value": "…", "unit": "h2"}, "text": "…"},
     * "rows": {"listitems": {"2": "…"}, "tableitems": {"1": {"0": "…"}}}} (changed rows of lists and cells of tables).
     * With "synced": true (Plus, element of a language version) the element then
     * counts as corresponding to its element in the main language as it is now.
     */
    #[Route('/element/{id<\d+>}', name: 'neo_frontend_editor_element_save', methods: ['POST'])]
    public function saveElement(int $id, Request $request): Response
    {
        if (!$this->isTokenValid($request)) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $body = json_decode($request->getContent(), true);
        $fields = \is_array($body) && \is_array($body['fields'] ?? []) ? $body['fields'] ?? [] : null;
        $rows = \is_array($body) && \is_array($body['rows'] ?? []) ? $body['rows'] ?? [] : null;

        if (null === $fields || null === $rows || [] === $fields + $rows) {
            return ErrorResponse::badRequest($this->logger);
        }

        $result = $this->writer->update($id, $fields, $rows);

        if (WriteStatus::Saved === $result->status) {
            $this->afterSave($id, $result->record, true === ($body['synced'] ?? false));
        }

        return match ($result->status) {
            WriteStatus::NotFound => ErrorResponse::notFound($this->logger),
            WriteStatus::Forbidden => ErrorResponse::forbidden($this->logger),
            WriteStatus::Invalid => ErrorResponse::invalid($this->logger, $result->errors),
            WriteStatus::Saved => $this->json(['success' => true, 'element' => $this->elementData($result->record)]),
        };
    }

    /**
     * The texts of front-end modules on the page with their sources (keys
     * fm:ID, comma-separated, as the page marks them): whether the user may
     * change them, decided by Contao's voters and field permissions, and if not,
     * which of Contao's permissions is missing. Also the texts of posts in lists
     * and readers (keys lp:TABLE:ID; Plus, query "page"): their headline, teaser
     * and other text fields, saved into the post (saveRecord).
     */
    #[Route('/texts', name: 'neo_frontend_editor_texts', methods: ['GET'])]
    public function texts(Request $request): Response
    {
        if (!$this->access->has(Permission::EditMode)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $ids = [];
        $posts = [];

        foreach (\array_slice(explode(',', (string) $request->query->get('keys', '')), 0, 50) as $key) {
            if (preg_match('/^fm:(\d+)$/', $key, $match)) {
                $ids[] = (int) $match[1];
            } elseif (preg_match('/^lp:([a-z0-9_]+):(\d+)$/', $key, $match) && null !== $this->postTypes->get($match[1])) {
                $posts[$match[1]][] = (int) $match[2];
            }
        }

        $modules = [];
        System::loadLanguageFile('modules');

        foreach ([] === $ids ? [] : $this->db->fetchAllAssociative('SELECT * FROM tl_module WHERE id IN (?)', [array_unique($ids)], [ArrayParameterType::INTEGER]) as $row) {
            $access = $this->records->check('tl_module', $row);
            $canEdit = $access['canEdit'] && $this->access->has(Permission::EditContent);
            $sources = [];

            foreach ($this->sources->of('tl_module', $row) as $source) {
                $reason = match (true) {
                    !$canEdit => 'module',
                    !$this->access->canEditField($source['field'], 'tl_module') => 'field',
                    default => null,
                };

                // The keys of the source are replaced where they are given here (value).
                $sources[] = array_replace($source, [
                    'label' => $this->sources->label('tl_module', $source['field'], $source['row'], $source['col']),
                    'scope' => 'module',
                    'editable' => null === $reason,
                    'reason' => $reason,
                    'value' => 'rich' === $source['kind'] ? $source['value'] : StringUtil::decodeEntities($source['value']),
                    'shown' => $this->insertTags->replaceInline($source['value']),
                ]);
            }

            $modules['fm:'.$row['id']] = [
                'name' => StringUtil::decodeEntities((string) $row['name']),
                'type' => (string) $row['type'],
                'typeLabel' => (string) (\is_array($GLOBALS['TL_LANG']['FMD'][$row['type']] ?? null) ? $GLOBALS['TL_LANG']['FMD'][$row['type']][0] : $row['type']),
                'sources' => $sources,
                'missing' => $access['missing'],
            ];
        }

        return $this->json(['success' => true, 'modules' => $modules + $this->postTexts($posts, $request->query->getInt('page'))]);
    }

    /**
     * The texts of posts shown on the page, like a module's (texts()): editable
     * with Plus and the switch for posts where Contao's voters allow the change
     * of the post and the field.
     *
     * @param array<string, list<int>> $posts IDs by table
     *
     * @return array<string, array<string, mixed>>
     */
    private function postTexts(array $posts, int $page): array
    {
        $texts = [];
        $mayEdit = [] !== $posts && $page > 0 && $this->access->has(Permission::EditPosts) && $this->editions->hasPlus($page);

        foreach ($posts as $table => $ids) {
            foreach ($this->db->fetchAllAssociative("SELECT * FROM $table WHERE id IN (?)", [array_unique($ids)], [ArrayParameterType::INTEGER]) as $row) {
                $canEdit = $mayEdit && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new UpdateAction($table, $row));
                $sources = [];

                foreach ($this->sources->of($table, $row) as $source) {
                    $reason = match (true) {
                        !$canEdit => 'post',
                        !$this->access->canEditField($source['field'], $table) => 'field',
                        default => null,
                    };

                    $sources[] = array_replace($source, [
                        'label' => $this->sources->label($table, $source['field'], $source['row'], $source['col']),
                        'scope' => 'post',
                        'editable' => null === $reason,
                        'reason' => $reason,
                        'value' => 'rich' === $source['kind'] ? $source['value'] : StringUtil::decodeEntities($source['value']),
                        'shown' => $this->insertTags->replaceInline($source['value']),
                    ]);
                }

                $texts["lp:$table:".$row['id']] = [
                    'name' => StringUtil::decodeEntities((string) ($row['headline'] ?? $row['title'] ?? $row['question'] ?? '')),
                    'type' => 'post',
                    'typeLabel' => '',
                    'sources' => $sources,
                    'missing' => [],
                ];
            }
        }

        return $texts;
    }

    /**
     * Saves texts of a record of a child table of a content element, of a
     * front-end module (tl_module) or of a post shown on the page (Plus). Body
     * (JSON): {"element": 12, "fields": {…}, "rows": {…}, "page": 3}; "element"
     * is the content element the child record belongs to (0 for a module or a
     * post). Answers with the element's data again.
     */
    #[Route('/record/{table<[a-z0-9_]+>}/{id<\d+>}', name: 'neo_frontend_editor_record_save', methods: ['POST'])]
    public function saveRecord(string $table, int $id, Request $request): Response
    {
        if (!$this->isTokenValid($request)) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $body = json_decode($request->getContent(), true);
        $fields = \is_array($body) && \is_array($body['fields'] ?? []) ? $body['fields'] ?? [] : null;
        $rows = \is_array($body) && \is_array($body['rows'] ?? []) ? $body['rows'] ?? [] : null;
        $elementId = \is_array($body) ? (int) ($body['element'] ?? 0) : 0;

        if (null === $fields || null === $rows || [] === $fields + $rows) {
            return ErrorResponse::badRequest($this->logger);
        }

        if (0 === $elementId && 'tl_module' !== $table && null !== $this->postTypes->get($table)) {
            if (!$this->access->has(Permission::EditPosts) || !$this->editions->hasPlus(\is_array($body) ? (int) ($body['page'] ?? 0) : 0)) {
                return ErrorResponse::forbidden($this->logger);
            }

            $result = $this->writer->updateOwn($table, $id, $fields, $rows);
        } else {
            $result = $this->writer->updateRecord($table, $id, $elementId, $fields, $rows);
        }
        $element = WriteStatus::Saved === $result->status && $elementId > 0 ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$elementId]) : false;

        // The texts of an entry belong to its element: its translations may be outdated now.
        if (false !== $element) {
            $this->links->refreshOutdated($elementId, $this->hashes->of($element));
        }

        return match ($result->status) {
            WriteStatus::NotFound => ErrorResponse::notFound($this->logger),
            WriteStatus::Forbidden => ErrorResponse::forbidden($this->logger),
            WriteStatus::Invalid => ErrorResponse::invalid($this->logger, $result->errors),
            WriteStatus::Saved => $this->json(['success' => true] + (false !== $element ? ['element' => $this->elementData($element)] : [])),
        };
    }

    /**
     * The stylesheets of the back end, prepared for the editor's shadow DOM. The
     * address has no .css ending: servers that hand files with such an ending to
     * their static file handling (PHP's built-in server, many nginx setups) would
     * answer 404 instead of passing the request to Contao.
     */
    #[Route('/backend-styles', name: 'neo_frontend_editor_styles', methods: ['GET'])]
    public function styles(Request $request): Response
    {
        $css = $this->styles->scoped();
        $response = new Response($css, Response::HTTP_OK, ['Content-Type' => 'text/css; charset=UTF-8']);
        $response->setPrivate();
        $response->setEtag(md5($css));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Translations of an element of the main language are outdated once its
     * texts change; a translated element can be confirmed as current.
     *
     * @param array<string, mixed> $record
     */
    private function afterSave(int $id, array $record, bool $synced): void
    {
        $this->links->refreshOutdated($id, $this->hashes->of($record));

        $link = $synced ? $this->links->ofElement($id) : null;
        $main = null !== $link ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$link['main']]) : false;
        $pageId = $this->places->pageOf($record);

        if (false !== $main && $this->access->has(Permission::EditLanguages) && $this->editions->hasPlus($pageId)) {
            $this->links->markSynced($id, $this->hashes->of($main));
        }
    }

    /**
     * A saved element as the structure has it, with the state of its
     * translation (versions), so the editor keeps its entries after a save.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function elementData(array $record): array
    {
        return $this->structure->element($record, (string) PageModel::findWithDetails($this->places->pageOf($record))?->language) + ['translation' => $this->structure->translationOf($record)];
    }

    private function isTokenValid(Request $request): bool
    {
        $value = (string) $request->headers->get(self::TOKEN_HEADER, '');

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
