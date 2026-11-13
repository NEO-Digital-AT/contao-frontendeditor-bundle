<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\System;
use Neo\BasicBundle\Translation\TranslationException;
use Neo\FrontendEditorBundle\Content\FileMeta;
use Neo\FrontendEditorBundle\Language\ElementTranslator;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\RecordLinks;
use Neo\FrontendEditorBundle\Language\RecordVersions;
use Neo\FrontendEditorBundle\Language\TranslationService;
use Neo\FrontendEditorBundle\Language\TranslationUnits;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
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
 * The window "Translate" for a record that is translated on its own: a page
 * (title and meta data), a post or its archive, a front-end module, a record
 * of another back-end module (RecordVersions). The same texts, proposals and
 * states as for an element (TranslationUnits), and: link an existing record
 * as the version, or make the version as a copy.
 *
 * Plus for the records of Contao itself (pages, news, events, FAQ, Contao's
 * front-end modules), Pro for those of extensions and of the website.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor/record-translation/{table<[a-z0-9_]+>}/{id<\d+>}', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class RecordTranslationController
{
    public function __construct(
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly LanguageVersions $languages,
        private readonly RecordVersions $versions,
        private readonly RecordLinks $links,
        private readonly TranslationUnits $units,
        private readonly ElementTranslator $translator,
        private readonly TranslationService $translation,
        private readonly FileMeta $files,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly TranslatorInterface $texts,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /**
     * The texts of the record in the language translated from (query
     * "source": a page in that language, else the record's own) next to its
     * version in the language of the page "page", with the records that could
     * be linked as the version when it has none.
     */
    #[Route('', name: 'neo_frontend_editor_record_translation', methods: ['GET'])]
    public function translation(string $table, int $id, Request $request): Response
    {
        $pair = $this->pairOf($table, $id, $request->query->getInt('source'), $request->query->getInt('page'), $request->query->getInt('at'));

        if ($pair instanceof Response) {
            return $pair;
        }

        $target = $pair['target'];
        $canUpdate = null !== $target && $this->granted($table, new UpdateAction($table, $target));
        $hash = $this->hashOf($table, $pair['main'], $pair['mainLanguage']);
        $link = null !== $target && (int) $target['id'] !== (int) $pair['main']['id'] ? $this->links->ofTarget($table, (int) $target['id']) : null;

        return $this->json([
            'success' => true,
            'record' => [
                'table' => $table,
                'id' => (int) $pair['main']['id'],
                'kind' => $this->versions->kind($table),
                'title' => $this->titleOf($table, $pair['source'] ?? $pair['main']),
                'typeLabel' => $this->typeLabel($table),
            ],
            'source' => ['id' => null !== $pair['source'] ? (int) $pair['source']['id'] : null, 'page' => $pair['sourcePage'], 'language' => $pair['from'], 'name' => $this->languages->name($pair['from'])],
            'target' => [
                'id' => null !== $target ? (int) $target['id'] : null,
                'page' => $pair['targetPage'],
                'language' => $pair['to'],
                'name' => $this->languages->name($pair['to']),
                'canUpdate' => $canUpdate,
                // A version made before: as current as when it was last translated.
                'status' => null === $link || '' === $link['source_hash'] ? null : (hash_equals($link['source_hash'], $hash) ? 'current' : 'outdated'),
                // Without a version: what could be linked, and whether a copy can be made.
                'candidates' => null === $target ? $this->versions->candidates($table, $pair['main'], $pair['to']) : [],
                'canCreate' => null === $target && 'page' !== $this->versions->kind($table) && 'archive' !== $this->versions->kind($table) && null === $this->archiveMissing($table, $pair),
                // A post whose archive has no version in that language yet: the archive is linked first.
                'archive' => null === $target ? $this->archiveMissing($table, $pair) : null,
            ],
            'main' => ['id' => (int) $pair['main']['id'], 'page' => $pair['mainPage']],
            'languages' => array_map(fn (array $version): array => [
                'page' => $version['id'],
                'language' => $version['language'],
                'name' => $version['name'],
                'main' => $version['main'],
                'element' => $pair['orphan'] ? $version['language'] === $pair['from'] : null !== $this->versions->versionIn($table, $pair['main'], $version['language']) || $version['language'] === $pair['mainLanguage'],
            ], $pair['pages']),
            'units' => null !== $pair['source'] ? $this->unitsOf($table, $pair) : [],
        ]);
    }

    /** Proposals of the translation service. Body: {"source", "page", "addresses" (optional), "open" (optional)}. */
    #[Route('/propose', name: 'neo_frontend_editor_record_translation_propose', methods: ['POST'])]
    public function propose(string $table, int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::Translate))) {
            return $denied;
        }

        $body = $this->body($request);
        $pair = $this->pairOf($table, $id, (int) ($body['source'] ?? 0), (int) ($body['page'] ?? 0));

        if ($pair instanceof Response) {
            return $pair;
        }

        if (null === $pair['source']) {
            return ErrorResponse::notFound($this->logger);
        }

        $open = true === ($body['open'] ?? false);
        $allowed = array_column(array_filter(
            $this->unitsOf($table, $pair),
            static fn (array $unit): bool => (!$unit['locked'] || null === $pair['target']) && (!$open || '' === trim($unit['target'])),
        ), 'address');
        $addresses = \is_array($body['addresses'] ?? null) ? array_values(array_intersect(array_filter($body['addresses'], 'is_string'), $allowed)) : $allowed;

        try {
            $values = [] === $addresses ? [] : $this->translator->proposal($pair['source'], $pair['target'], $pair['from'], $pair['to'], $addresses, $table);
        } catch (TranslationException $e) {
            return ErrorResponse::unavailable($this->logger, 'NFE503-'.$e->reason, $this->text('error.translate_'.$e->reason, ['{service}' => $this->text('service_'.$this->translation->service())]));
        }

        return $this->json(['success' => true, 'values' => $values]);
    }

    /** Saves the texts into the version. Body: {"source", "page", "values": {address: text}}. */
    #[Route('/save', name: 'neo_frontend_editor_record_translation_save', methods: ['POST'])]
    public function save(string $table, int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $body = $this->body($request);
        $pair = $this->pairOf($table, $id, (int) ($body['source'] ?? 0), (int) ($body['page'] ?? 0));

        if ($pair instanceof Response) {
            return $pair;
        }

        if (null === $pair['source'] || null === $pair['target']) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->granted($table, new UpdateAction($table, $pair['target']))) {
            return ErrorResponse::forbidden($this->logger);
        }

        $values = array_filter(\is_array($body['values'] ?? null) ? $body['values'] : [], 'is_string');
        $result = $this->units->save($pair['source'], $pair['target'], $pair['from'], $pair['to'], $values, $table);
        $complete = [] === $result['failed'] && [] === $result['invalid'];

        // Into a version: current with the main record as it is now, once everything was saved.
        if ($complete && $result['saved'] > 0 && $pair['to'] !== $pair['mainLanguage']) {
            $main = $this->versions->row($table, (int) $pair['main']['id']) ?? $pair['main'];
            $this->links->translated($table, (int) $main['id'], (int) $pair['target']['id'], $pair['to'], $this->hashOf($table, $main, $pair['mainLanguage']));
        }

        // A copy made here gets its address from the translated title (pages keep theirs).
        if ($result['saved'] > 0 && 'tl_page' !== $table) {
            $this->versions->fillAlias($table, (int) $pair['target']['id']);
        }

        return $this->json(['success' => $complete, 'id' => (int) $pair['target']['id']] + $result);
    }

    /** Links an existing record as the version in the language of "page". Body: {"page", "target": ID}. */
    #[Route('/link', name: 'neo_frontend_editor_record_translation_link', methods: ['POST'])]
    public function link(string $table, int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $body = $this->body($request);
        $pair = $this->pairOf($table, $id, 0, (int) ($body['page'] ?? 0));

        if ($pair instanceof Response) {
            return $pair;
        }

        $targetId = (int) ($body['target'] ?? 0);
        $offered = array_column($this->versions->candidates($table, $pair['main'], $pair['to']), 'id');
        $target = \in_array($targetId, $offered, true) ? $this->versions->row($table, $targetId) : null;

        if (null !== $pair['target'] || null === $target) {
            return ErrorResponse::notFound($this->logger);
        }

        // The record without a record of the main language: the chosen one becomes its main record.
        $linked = $pair['orphan']
            ? $this->versions->link($table, $target, $pair['main'], $pair['from'])
            : $this->versions->link($table, $pair['main'], $target, $pair['to']);

        if (!$linked) {
            return ErrorResponse::forbidden($this->logger);
        }

        return $this->json(['success' => true, 'id' => $targetId]);
    }

    /** Makes the version in the language of "page" as a copy of the main record and links it. Body: {"page"}. */
    #[Route('/create', name: 'neo_frontend_editor_record_translation_create', methods: ['POST'])]
    public function create(string $table, int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $body = $this->body($request);
        $pair = $this->pairOf($table, $id, 0, (int) ($body['page'] ?? 0));

        if ($pair instanceof Response) {
            return $pair;
        }

        if (null !== $pair['target']) {
            return $this->json(['success' => true, 'id' => (int) $pair['target']['id']]);
        }

        $newId = $pair['orphan']
            ? $this->versions->createMain($table, $pair['main'], $pair['to'])
            : $this->versions->create($table, $pair['main'], $pair['to']);

        return null === $newId ? ErrorResponse::forbidden($this->logger) : $this->json(['success' => true, 'id' => $newId]);
    }

    /**
     * Makes the versions of a new post in every language whose archive is
     * linked with the post's archive, where it has none yet; a post written
     * first in another language than the main one gets its record of the main
     * language first. Body: {"at": the page the editor shows}. Answers with the
     * versions made.
     */
    #[Route('/versions', name: 'neo_frontend_editor_record_translation_versions', methods: ['POST'])]
    public function versions(string $table, int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $created = [];
        $at = (int) ($this->body($request)['at'] ?? 0);
        $pages = $this->languages->versions($at);
        $record = $this->versions->row($table, $id);

        // Written first in another language than the main one: the record of the main language comes first.
        foreach ($pages as $page) {
            if (!$page['main'] || null === $record || 'post' !== $this->versions->kind($table) || $this->versions->languageOf($table, $record) === $page['language']) {
                continue;
            }

            $pair = $this->pairOf($table, $id, 0, $page['id']);

            if (!$pair instanceof Response && null === $pair['target'] && null !== ($newId = $this->versions->createMain($table, $record))) {
                $created[] = ['id' => $newId, 'page' => $page['id'], 'language' => $page['language'], 'name' => $page['name']];
            }
        }

        foreach ($pages as $page) {
            if ($page['main'] || 'post' !== $this->versions->kind($table)) {
                continue;
            }

            $pair = $this->pairOf($table, $id, 0, $page['id']);

            if ($pair instanceof Response || null !== $pair['target'] || null !== $this->archiveMissing($table, $pair)) {
                continue;
            }

            $newId = $pair['orphan']
            ? $this->versions->createMain($table, $pair['main'], $pair['to'])
            : $this->versions->create($table, $pair['main'], $pair['to']);

            if (null !== $newId) {
                $created[] = ['id' => $newId, 'page' => $page['id'], 'language' => $page['language'], 'name' => $page['name']];
            }
        }

        return $this->json(['success' => true, 'created' => $created]);
    }

    /**
     * The archive of a post when it has no version in the target language yet
     * (so the post's version has nowhere to go), else null.
     *
     * @param array<string, mixed> $pair
     *
     * @return array{table: string, id: int}|null
     */
    private function archiveMissing(string $table, array $pair): array|null
    {
        if ('post' !== $this->versions->kind($table)) {
            return null;
        }

        $archiveTable = (string) $this->versions->archiveTable($table);
        $archive = $this->versions->row($archiveTable, (int) $pair['main']['pid']);

        if (null === $archive || null !== $this->versions->versionIn($archiveTable, $this->versions->mainOf($archiveTable, $archive), $pair['to'])) {
            return null;
        }

        return ['table' => $archiveTable, 'id' => (int) $archive['id']];
    }

    /**
     * The record, its main record and the versions in the two languages, or
     * the response that refuses it.
     *
     * @return array{main: array<string, mixed>, source: array<string, mixed>|null, target: array<string, mixed>|null, from: string, to: string, mainLanguage: string, sourcePage: int, targetPage: int, mainPage: int, pages: list<array{id: int, title: string, language: string, name: string, main: bool}>, orphan: bool}|Response
     */
    private function pairOf(string $table, int $id, int $sourcePage, int $targetPage, int $at = 0): array|Response
    {
        if (!$this->access->has(Permission::EditMode) || !$this->access->has(Permission::EditLanguages)) {
            return ErrorResponse::forbidden($this->logger);
        }

        if (!$this->versions->translatable($table)) {
            return ErrorResponse::notFound($this->logger);
        }

        $record = $this->versions->row($table, $id);
        $pages = $this->languages->versions($targetPage > 0 ? $targetPage : $at);

        // Without a language to translate into: the page the editor shows, or from its main language into the first version.
        if ($targetPage < 1 && [] !== $pages) {
            $versions = array_values(array_filter($pages, static fn (array $page): bool => !$page['main']));
            $current = array_values(array_filter($pages, static fn (array $page): bool => $page['id'] === $at && !$page['main']));
            $targetPage = (int) ($current[0]['id'] ?? $versions[0]['id'] ?? 0);
        }

        if (null === $record || [] === $pages || !$this->granted($table, new ReadAction($table, $record))) {
            return ErrorResponse::notFound($this->logger);
        }

        $main = $this->versions->mainOf($table, $record);
        $mainPage = (int) (array_values(array_filter($pages, static fn (array $page): bool => $page['main']))[0]['id'] ?? $pages[0]['id']);
        $byPage = array_column($pages, 'language', 'id');
        $siteLanguage = (string) $byPage[$mainPage];
        $own = $this->versions->languageOf($table, $main);
        // A record of another language than the main one with no record of the main language yet: that record is
        // the one missing. It is linked or made as the main record, never the other way round.
        $orphan = 'page' !== $this->versions->kind($table) && (int) $main['id'] === (int) $record['id'] && '' !== $own && $own !== $siteLanguage;
        $mainLanguage = $orphan ? $siteLanguage : ($own ?: $siteLanguage);
        $to = (string) ($byPage[$targetPage] ?? '');
        $from = $sourcePage > 0 && isset($byPage[$sourcePage]) ? (string) $byPage[$sourcePage] : ($this->versions->languageOf($table, $record) ?: $mainLanguage);

        if ($orphan) {
            [$from, $to, $targetPage] = [$own, $siteLanguage, $mainPage];
        }

        // The same language on both sides: from the main language into a version, from a version into the main language.
        if ($from === $to) {
            $others = array_values(array_diff($byPage, [$mainLanguage]));
            $from = $to !== $mainLanguage ? $mainLanguage : (string) ($others[0] ?? '');
        }

        $sourcePage = (int) (array_search($from, $byPage, true) ?: $mainPage);

        if ('' === $to || $from === $to) {
            return ErrorResponse::notFound($this->logger);
        }

        // Records of Contao itself with Plus, those of extensions and of the website with Pro.
        if (!($this->versions->isContao($table, $main) ? $this->editions->hasPlus($targetPage) : $this->editions->hasPro($targetPage))) {
            $response = $this->json(['success' => false, 'error' => 'NFE403-pro', 'message' => $this->text('rec_pro')]);

            return $response->setStatusCode(Response::HTTP_FORBIDDEN);
        }

        $versionIn = fn (string $language): array|null => $orphan
            ? ($language === $own ? $main : null)
            : ($language === $mainLanguage ? $main : $this->versions->versionIn($table, $main, $language));
        $source = $versionIn($from);
        $target = $versionIn($to);

        foreach ([$source, $target] as $version) {
            if (null !== $version && !$this->granted($table, new ReadAction($table, $version))) {
                return ErrorResponse::forbidden($this->logger);
            }
        }

        return ['main' => $main, 'source' => $source, 'target' => $target, 'from' => $from, 'to' => $to, 'mainLanguage' => $mainLanguage, 'sourcePage' => $sourcePage, 'targetPage' => $targetPage, 'mainPage' => $mainPage, 'pages' => $pages, 'orphan' => $orphan];
    }

    /**
     * The texts of the pair, each marked "locked" where the user may not
     * change it (no version yet, no place, field or file not allowed).
     *
     * @param array<string, mixed> $pair
     *
     * @return list<array<string, mixed>>
     */
    private function unitsOf(string $table, array $pair): array
    {
        $units = $this->units->pair($pair['source'], $pair['target'], $pair['from'], $pair['to'], $table);
        $canUpdate = null !== $pair['target'] && $this->granted($table, new UpdateAction($table, $pair['target']));
        $files = [];

        foreach ($units as &$unit) {
            $unit['locked'] = !$canUpdate || !$unit['place'] || (null !== $unit['file']
                ? !($files[$unit['file']] ??= $this->files->canEdit($unit['file']))
                : !$this->access->canEditField($unit['field'], $unit['table']));
            unset($unit['table'], $unit['file']);
        }

        unset($unit);

        return $units;
    }

    /** @param array<string, mixed> $record */
    private function hashOf(string $table, array $record, string $language): string
    {
        return hash('sha256', json_encode($this->units->texts($record, $language, true, $table), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $record */
    private function titleOf(string $table, array $record): string
    {
        $field = $this->versions->titleField($table);
        $value = (string) ($record[$field] ?? '');

        if ('headline' === $field && str_starts_with($value, 'a:')) {
            $value = (string) (unserialize($value, ['allowed_classes' => false])['value'] ?? '');
        }

        return trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '#'.$record['id'];
    }

    /** The name of the kind of record: the back-end module it belongs to ("News", "FAQ", "Pages"). */
    private function typeLabel(string $table): string
    {
        System::loadLanguageFile('modules');
        $module = 'tl_page' === $table ? 'page' : (string) $this->versions->moduleOf($table);
        $label = $GLOBALS['TL_LANG']['MOD'][$module] ?? $module;

        return (string) (\is_array($label) ? ($label[0] ?? $module) : $label);
    }

    private function granted(string $table, ReadAction|UpdateAction $action): bool
    {
        return $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, $action);
    }

    private function denyWrite(Request $request, Permission $permission): Response|null
    {
        $value = (string) $request->headers->get(ApiController::TOKEN_HEADER, '');

        if ('' === $value || !$this->tokenManager->isTokenValid(new CsrfToken($this->tokenName, $value))) {
            return ErrorResponse::invalidToken($this->logger);
        }

        if (!$this->access->has(Permission::EditMode) || !$this->access->has($permission)) {
            return ErrorResponse::forbidden($this->logger);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = '' === $request->getContent() ? [] : json_decode($request->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /** @param array<string, string> $parameters */
    private function text(string $key, array $parameters = []): string
    {
        $user = $this->security->getUser();

        return $this->texts->trans('editor.'.$key, $parameters, 'neo_frontend_editor', $user instanceof BackendUser ? (string) $user->language : null);
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
