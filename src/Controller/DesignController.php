<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\Comments;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\Date;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Neo\FrontendEditorBundle\Content\StructureEditor;
use Neo\FrontendEditorBundle\Content\StructureResult;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\Design\FormFieldEditor;
use Neo\FrontendEditorBundle\Design\TemplateInfo;
use Neo\FrontendEditorBundle\Posts\PostEditor;
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
 * The parts of a page besides its content elements (Stage 7, Basic): which
 * templates output an element, a module or the page; front-end modules, the
 * layout, form fields and comments with their Contao forms; posts in lists
 * (Plus). Templates and design need the switch "Templates and modules"; what a
 * user may change is decided by Contao's voters and module permissions, as in
 * the back end. Changes go through Contao's forms; form fields are also
 * inserted and deleted with Contao's own operations (FormFieldEditor).
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/design', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class DesignController
{
    private const MAX_KEYS = 200;

    public function __construct(
        private readonly TemplateInfo $templates,
        private readonly PostEditor $posts,
        private readonly ElementAccess $access,
        private readonly RecordAccess $records,
        private readonly Security $security,
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly LoggerInterface $logger,
        private readonly FormFieldEditor $fields,
        private readonly StructureEditor $structure,
        private readonly ContaoCsrfTokenManager $tokenManager,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** A new field after a field of a form on the page; its form opens in the sidebar. */
    #[Route('/field/{id<\d+>}/after', name: 'neo_frontend_editor_design_field_after', methods: ['POST'])]
    public function fieldAfter(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->fields->createAfter($id));
    }

    #[Route('/field/{id<\d+>}/delete', name: 'neo_frontend_editor_design_field_delete', methods: ['POST'])]
    public function fieldDelete(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->fields->delete($id));
    }

    /** A new field whose form was left without saving. */
    #[Route('/field/{id<\d+>}/discard', name: 'neo_frontend_editor_design_field_discard', methods: ['POST'])]
    public function fieldDiscard(int $id, Request $request): Response
    {
        return $this->handle($request, fn (): StructureResult => $this->structure->discard('tl_form_field', $id));
    }

    /**
     * Facts about the marked parts of a page: keys fm:ID (module), ff:ID (form
     * field), cm:ID (comment), lp:TABLE:ID (post in a list), comma-separated.
     */
    #[Route('/items', name: 'neo_frontend_editor_design_items', methods: ['GET'])]
    public function items(Request $request): Response
    {
        $page = $this->page((int) $request->query->get('page'));

        if (null === $page) {
            return ErrorResponse::forbidden($this->logger);
        }

        $keys = array_slice(array_unique(array_filter(explode(',', (string) $request->query->get('keys', '')))), 0, self::MAX_KEYS);
        $design = $this->access->has(Permission::EditDesign);
        $items = [];

        $this->framework->initialize();
        System::loadLanguageFile('default');
        System::loadLanguageFile('modules');

        foreach ($keys as $key) {
            $item = match (true) {
                (bool) preg_match('/^fm:(\d+)$/', $key, $m) => $design ? $this->module((int) $m[1], $page) : null,
                (bool) preg_match('/^ff:(\d+)$/', $key, $m) => $design ? $this->field((int) $m[1], $page) : null,
                (bool) preg_match('/^cm:(\d+)$/', $key, $m) => $design ? $this->comment((int) $m[1], $page) : null,
                (bool) preg_match('/^lp:([a-z0-9_]+):(\d+)$/', $key, $m) => $this->post($m[1], (int) $m[2]),
                default => null,
            };

            if (null !== $item) {
                $items[$key] = $item;
            }
        }

        return $this->json(['success' => true, 'items' => $items, 'studio' => $design && $this->studio()]);
    }

    /** The templates of a content element of the page. */
    #[Route('/content/{id<\d+>}', name: 'neo_frontend_editor_design_content', methods: ['GET'])]
    public function content(int $id, Request $request): Response
    {
        $page = $this->page((int) $request->query->get('page'));
        $row = $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$id]);

        if (null === $page || false === $row || !$this->access->has(Permission::EditDesign) || !$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_content', new ReadAction('tl_content', $row))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $this->framework->initialize();

        return $this->json([
            'success' => true,
            'templates' => $this->templates->forContent($row, $page),
            'cssClass' => $this->cssClasses($row),
            'studio' => $this->studio(),
        ]);
    }

    /** Overview of the page: its template and layout, the modules of the layout and the templates of its elements. */
    #[Route('/page/{id<\d+>}', name: 'neo_frontend_editor_design_page', methods: ['GET'])]
    public function overview(int $id): Response
    {
        $page = $this->page($id);

        if (null === $page || !$this->access->has(Permission::EditDesign)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $this->framework->initialize();
        System::loadLanguageFile('default');
        System::loadLanguageFile('modules');
        $layout = $this->db->fetchAssociative('SELECT * FROM tl_layout WHERE id = ?', [(int) $page->layout]) ?: null;
        $modules = [];

        foreach (StringUtil::deserialize($layout['modules'] ?? null, true) as $entry) {
            if (empty($entry['mod']) || empty($entry['enable'])) {
                continue;
            }

            $row = $this->db->fetchAssociative('SELECT * FROM tl_module WHERE id = ?', [(int) $entry['mod']]);

            if (false !== $row) {
                $modules[] = $this->moduleData($row, $page) + ['column' => (string) ($GLOBALS['TL_LANG']['COLS'][$entry['col']] ?? $entry['col'])];
            }
        }

        $elements = $this->db->fetchAllAssociative(
            "SELECT c.* FROM tl_content c JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article' WHERE a.pid = ? AND a.tstamp > 0 AND c.tstamp > 0 ORDER BY a.sorting, c.sorting",
            [$id],
        );
        $groups = [];

        Controller::loadDataContainer('tl_content');

        foreach ($elements as $row) {
            $templates = $this->templates->forContent($row, $page);
            $name = (string) ($templates[0]['name'] ?? $row['type']);

            $groups[$name] ??= ['typeLabel' => $this->label('CTE', (string) $row['type']), 'count' => 0, 'templates' => $templates];
            ++$groups[$name]['count'];
        }

        return $this->json([
            'success' => true,
            'page' => ['title' => (string) $page->title, 'layout' => (string) ($layout['name'] ?? ''), 'templates' => $this->templates->forPage($layout, $page)],
            'modules' => $modules,
            'elements' => array_values($groups),
            'studio' => $this->studio(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function module(int $id, PageModel $page): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_module WHERE id = ?', [$id]);

        return false === $row ? null : $this->moduleData($row, $page);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function moduleData(array $row, PageModel $page): array
    {
        return [
            'kind' => 'module',
            'id' => (int) $row['id'],
            'label' => StringUtil::decodeEntities((string) $row['name']),
            'typeLabel' => $this->label('FMD', (string) $row['type']),
            ...$this->records->check('tl_module', $row),
            'form' => ['module' => 'themes', 'table' => 'tl_module', 'id' => (int) $row['id']],
            'templates' => $this->templates->forModule($row, $page),
        ];
    }

    /** @return array<string, mixed>|null */
    private function field(int $id, PageModel $page): array|null
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_form_field WHERE id = ?', [$id]);

        if (false === $row) {
            return null;
        }

        $class = $GLOBALS['TL_FFL'][$row['type']] ?? null;
        $default = \is_string($class) && class_exists($class) ? ((new \ReflectionClass($class))->getDefaultProperties()['strTemplate'] ?? null) : null;
        $template = (string) ($row['customTpl'] ?? '') ?: (string) $default;

        return [
            'kind' => 'field',
            'id' => (int) $row['id'],
            'label' => StringUtil::decodeEntities((string) ($row['label'] ?: $row['name'])),
            'typeLabel' => $this->label('FFL', (string) $row['type']),
            ...$this->records->check('tl_form_field', $row),
            'canDelete' => $this->moduleAccess('form') && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_form_field', new DeleteAction('tl_form_field', $row)),
            'canCreate' => $this->moduleAccess('form') && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_form_field', new CreateAction('tl_form_field', ['pid' => (int) $row['pid']])),
            'form' => ['module' => 'form', 'table' => 'tl_form_field', 'id' => (int) $row['id']],
            'templates' => '' === $template ? [] : $this->templates->forName($template, $page),
        ];
    }

    /** @return array<string, mixed>|null */
    private function comment(int $id, PageModel $page): array|null
    {
        if (!class_exists(Comments::class)) {
            return null;
        }

        try {
            $row = $this->db->fetchAssociative('SELECT * FROM tl_comments WHERE id = ?', [$id]);
        } catch (\Throwable) {
            return null;
        }

        if (false === $row) {
            return null;
        }

        System::loadLanguageFile('tl_comments');

        return [
            'kind' => 'comment',
            'id' => (int) $row['id'],
            'label' => StringUtil::decodeEntities((string) $row['name']).' · '.Date::parse((string) ($GLOBALS['objPage']->datimFormat ?? 'd.m.Y H:i'), (int) $row['date']),
            'typeLabel' => (string) ($GLOBALS['TL_LANG']['MOD']['comments'][0] ?? 'Comments'),
            ...$this->records->check('tl_comments', $row),
            'form' => ['module' => 'comments', 'table' => 'tl_comments', 'id' => (int) $row['id']],
            'templates' => $this->templates->forName($this->commentTemplate($row), $page),
        ];
    }

    /**
     * The template of a comment: the one chosen in the comments element, else Contao's.
     *
     * @param array<string, mixed> $row
     */
    private function commentTemplate(array $row): string
    {
        if ('tl_content' === ($row['source'] ?? null)) {
            try {
                $template = $this->db->fetchOne('SELECT com_template FROM tl_content WHERE id = ?', [(int) $row['parent']]);
            } catch (\Throwable) {
                $template = null;
            }

            if (\is_string($template) && '' !== $template) {
                return $template;
            }
        }

        return 'com_default';
    }

    /** @return array<string, mixed>|null */
    private function post(string $table, int $id): array|null
    {
        $item = $this->posts->listItem($table, $id);

        return null === $item ? null : ['kind' => 'post', 'label' => $item['title'], 'typeLabel' => $item['typeLabel'], 'canEdit' => $item['canEdit'], 'post' => $item, 'templates' => []];
    }

    /** The page, when the user may use the editor on it. */
    private function page(int $id): PageModel|null
    {
        if ($id < 1 || !$this->access->has(Permission::EditMode) || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, $id)) {
            return null;
        }

        $this->framework->initialize();

        return PageModel::findWithDetails($id);
    }

    /** @param array<string, mixed> $row */
    private function cssClasses(array $row): array
    {
        $cssId = StringUtil::deserialize($row['cssID'] ?? null, true);

        return ['type' => 'content-'.str_replace('_', '-', (string) $row['type']), 'own' => trim((string) ($cssId[1] ?? '')), 'id' => trim((string) ($cssId[0] ?? ''))];
    }

    private function label(string $group, string $type): string
    {
        foreach ($GLOBALS['TL_LANG'][$group] ?? [] as $key => $value) {
            if ($key === $type && \is_array($value)) {
                return (string) $value[0];
            }
        }

        return $type;
    }

    private function studio(): bool
    {
        return $this->moduleAccess('tpl_editor');
    }

    private function moduleAccess(string $module): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module);
    }

    /**
     * Runs a change after checking the request token and that the user may use
     * the editor on the page given in the body ({"page": ID}).
     *
     * @param \Closure(): StructureResult $operation
     */
    private function handle(Request $request, \Closure $operation): Response
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        $body = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);

        if (!\is_array($body) || null === $this->page(\is_int($body['page'] ?? null) ? $body['page'] : 0)) {
            return \is_array($body) ? ErrorResponse::forbidden($this->logger) : ErrorResponse::badRequest($this->logger);
        }

        $result = $operation();

        return match ($result->status) {
            WriteStatus::NotFound => ErrorResponse::notFound($this->logger),
            WriteStatus::Forbidden => ErrorResponse::forbidden($this->logger),
            WriteStatus::Invalid => ErrorResponse::badRequest($this->logger),
            WriteStatus::Saved => $this->json(['success' => true, 'id' => $result->id]),
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
