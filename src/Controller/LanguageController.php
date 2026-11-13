<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Translation\TranslationException;
use Neo\FrontendEditorBundle\Content\FileMeta;
use Neo\FrontendEditorBundle\Content\StructureEditor;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\Language\Comparison;
use Neo\FrontendEditorBundle\Language\ElementLinks;
use Neo\FrontendEditorBundle\Language\ElementPairing;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Language\ElementTranslator;
use Neo\FrontendEditorBundle\Language\LanguageVersions;
use Neo\FrontendEditorBundle\Language\SourceHash;
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
 * Endpoints of the language versions (edition Plus): versions side by side,
 * links between the elements of the languages, translating an element (every
 * text it has, TranslationUnits) with proposals of the translation service of
 * the settings, "mark as current", taking an element over into a language
 * version and, the other way round, an element written in a version first
 * over into the main language. Elements of articles, of posts and inside
 * other elements alike (ElementPlaces). Each one needs Plus for the page's
 * website, the switch of the function and Contao's rights on the records;
 * writes need the request token.
 */
#[Route('%contao.backend.route_prefix%/frontend-editor', defaults: ['_scope' => 'backend', '_token_check' => false])]
final class LanguageController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
        private readonly ElementAccess $access,
        private readonly Editions $editions,
        private readonly LanguageVersions $versions,
        private readonly Comparison $comparison,
        private readonly ElementLinks $links,
        private readonly ElementPairing $pairing,
        private readonly ElementPlaces $places,
        private readonly SourceHash $hashes,
        private readonly ElementTranslator $elementTranslator,
        private readonly TranslationUnits $units,
        private readonly FileMeta $files,
        private readonly TranslationService $translation,
        private readonly StructureEditor $structure,
        private readonly ContaoCsrfTokenManager $tokenManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%contao.csrf_token_name%')]
        private readonly string $tokenName,
    ) {
    }

    /** The language versions of a page. */
    #[Route('/languages/{id<\d+>}', name: 'neo_frontend_editor_languages', methods: ['GET'])]
    public function languages(int $id): Response
    {
        if (null !== ($denied = $this->deny($id, Permission::EditLanguages))) {
            return $denied;
        }

        return $this->json(['success' => true, 'available' => $this->versions->isAvailable(), 'versions' => $this->versions->versions($id)]);
    }

    /**
     * The page in the main language next to the version {target}, with the
     * posts shown on the page (query "posts": table:ID, comma-separated; the
     * post of any language, its posts of the main language and of the
     * version are compared).
     */
    #[Route('/languages/{id<\d+>}/{target<\d+>}', name: 'neo_frontend_editor_compare', methods: ['GET'])]
    public function compare(int $id, int $target, Request $request): Response
    {
        if (null !== ($denied = $this->deny($id, Permission::EditLanguages))) {
            return $denied;
        }

        $main = $this->versions->mainVersion($id);

        if (null === $main || $main['id'] === $target || !$this->versions->areVersions($id, $target)) {
            return ErrorResponse::notFound($this->logger);
        }

        $posts = $this->postsToCompare((string) $request->query->get('posts', ''), $main['id'], $target);

        // Versions made before the editor: pair their elements by structure once, as "Link"
        // would, where the user may link them (ElementPairing), also in elements and posts.
        if ($this->editions->hasPlus($target)) {
            $language = (string) PageModel::findWithDetails($target)?->language;
            $mayLink = fn (array $element): bool => $this->access->canUpdate($element);
            $this->pairing->pair($main['id'], $target, $language, $mayLink);

            foreach ($posts as $post) {
                if (null !== $post['main'] && null !== $post['target']) {
                    $this->pairing->pairContainer($post['table'], $post['main'], $post['target'], $language, $mayLink);
                }
            }
        }

        return $this->json(['success' => true] + $this->comparison->build($main['id'], $target, $posts));
    }

    /**
     * The posts of a comparison: for each post named (any language) its post
     * of the main language and of the version, where the user may read them.
     *
     * @return list<array{table: string, main: int|null, target: int|null}>
     */
    private function postsToCompare(string $query, int $mainPage, int $target): array
    {
        if ('' === $query || !$this->access->has(Permission::EditPosts)) {
            return [];
        }

        $posts = [];

        foreach (\array_slice(explode(',', $query), 0, 10) as $entry) {
            [$table, $id] = explode(':', $entry.':');
            $id = (int) $id;
            $page = $this->places->isPostTable($table) ? $this->places->containerPage($table, $id) : 0;

            if (0 === $page || !$this->versions->areVersions($mainPage, $page) || !$this->mayReadPost($table, $id)) {
                continue;
            }

            $main = $page === $mainPage ? $id : $this->places->mainPostOf($table, $id);
            $version = $page === $target ? $id : (null !== $main ? $this->places->postOn($table, $main, $target) : null);
            $key = $table.':'.($main ?? 'v'.$version);

            if (isset($posts[$key]) || (null !== $main && !$this->mayReadPost($table, $main)) || (null !== $version && !$this->mayReadPost($table, $version))) {
                continue;
            }

            $posts[$key] = ['table' => $table, 'main' => $main, 'target' => $version];
        }

        return array_values($posts);
    }

    private function mayReadPost(string $table, int $id): bool
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.$this->db->quoteIdentifier($table).' WHERE id = ?', [$id]);

        return false !== $row && $this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new ReadAction($table, $row));
    }

    /** Links an element of a version to an element of the main language. Body: {"main": ID, "element": ID}. */
    #[Route('/link', name: 'neo_frontend_editor_link', methods: ['POST'])]
    public function link(Request $request): Response
    {
        $body = $this->body($request);

        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $main = $this->element((int) ($body['main'] ?? 0));
        $element = $this->element((int) ($body['element'] ?? 0));

        if (null === $main || null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        // Both elements are of the same kind of container (elements of articles, of posts or in an element).
        if (($this->places->top($main)['ptable'] ?? '') !== ($this->places->top($element)['ptable'] ?? '')) {
            return ErrorResponse::forbidden($this->logger);
        }

        $mainPage = $this->pageOf($main);
        $elementPage = $this->pageOf($element);
        $mainVersion = $this->versions->mainVersion($elementPage);

        if (null === $mainVersion || $mainVersion['id'] !== $mainPage || $mainPage === $elementPage || !$this->editions->hasPlus($elementPage) || !$this->access->canUpdate($element)) {
            return ErrorResponse::forbidden($this->logger);
        }

        // Linking says: the element corresponds to the main element as it is now.
        $linkId = $this->links->link((int) $main['id'], (int) $element['id'], (string) PageModel::findWithDetails($elementPage)?->language, $this->hashes->of($main));

        return $this->json(['success' => true, 'id' => $linkId]);
    }

    #[Route('/link/{id<\d+>}/delete', name: 'neo_frontend_editor_unlink', methods: ['POST'])]
    public function unlink(int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $link = $this->links->find($id);
        $element = null !== $link ? $this->element((int) $link['element']) : null;

        if (null === $link || null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPlus($this->pageOf($element)) || !$this->access->canUpdate($element)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $this->links->unlink($id);

        return $this->json(['success' => true, 'id' => $id]);
    }

    /** Proposals for every text of an element of a version, from its element in the main language; nothing is saved. */
    #[Route('/element/{id<\d+>}/translate', name: 'neo_frontend_editor_translate', methods: ['POST'])]
    public function translate(int $id, Request $request): Response
    {
        $element = $this->element($id);

        return $this->propose($element, 0, null !== $element ? $this->pageOf($element) : 0, null, false, $request);
    }

    /**
     * Everything of an element that a translation covers, for the window
     * "Translate", in one direction between the language versions of its page:
     * the texts of the element in the language of {source}, each with its
     * counterpart in the language of {page}. The element {id} may be of any
     * language; without {source} the source is the main language (the other
     * way round, for an element of a version with the main page as {page}: the
     * element's own language); without {page} the target is the element's own
     * version (for an element of the main language: the first version).
     * Versions made before the editor are paired first. "languages" lists the
     * versions of the page with whether the element exists there.
     */
    #[Route('/translation/{id<\d+>}', name: 'neo_frontend_editor_translation', methods: ['GET'])]
    public function translation(int $id, Request $request): Response
    {
        $element = $this->element($id);

        if (null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        if (null !== ($denied = $this->deny($this->pageOf($element), Permission::EditLanguages))) {
            return $denied;
        }

        $pair = $this->pairOf($element, $request->query->getInt('source'), $request->query->getInt('page'), true);

        if (null === $pair) {
            return ErrorResponse::notFound($this->logger);
        }

        $units = $this->unitsOf($pair);
        $canUpdate = null !== $pair['target'] && $this->access->canUpdate($pair['target']);

        return $this->json([
            'success' => true,
            'source' => ['id' => (int) $pair['source']['id'], 'page' => $pair['sourcePage'], 'language' => $pair['from'], 'name' => $this->versions->name($pair['from']), 'type' => (string) $pair['source']['type'], 'typeLabel' => $this->units->typeLabel((string) $pair['source']['type'])],
            'target' => [
                'id' => null !== $pair['target'] ? (int) $pair['target']['id'] : null,
                'page' => $pair['targetPage'],
                'language' => $pair['to'],
                'name' => $this->versions->name($pair['to']),
                'canUpdate' => $canUpdate,
                // Without the element there: whether its container exists there to take it over into, and which kind.
                'parent' => null !== $pair['target'] || null !== $this->places->containerOn((string) $pair['main']['ptable'], (int) $pair['main']['pid'], $pair['mainPage'], $pair['mainPage'], $pair['targetPage']),
                'container' => $this->containerKind($pair['main']),
            ],
            'main' => ['id' => (int) $pair['main']['id'], 'page' => $pair['mainPage']],
            // The languages to translate from (where the element exists) and into.
            'languages' => array_map(fn (array $version): array => [
                'page' => $version['id'],
                'language' => $version['language'],
                'name' => $version['name'],
                'main' => $version['id'] === $pair['mainPage'],
                'element' => $version['id'] === $pair['mainPage'] || null !== $this->places->counterpartOn((int) $pair['main']['id'], $version['id']),
            ], $this->versions->versions($pair['mainPage'])),
            'units' => $units,
        ]);
    }

    /**
     * Proposals for texts of the window "Translate" and of "Translate the whole
     * page". Body: {"source": page (optional), "page": target page (pairOf),
     * "addresses": [...] (optional: only these), "open": true (optional: only
     * texts the target has not translated yet)}.
     */
    #[Route('/translation/{id<\d+>}/propose', name: 'neo_frontend_editor_translation_propose', methods: ['POST'])]
    public function translationPropose(int $id, Request $request): Response
    {
        $body = $this->body($request);
        $addresses = \is_array($body['addresses'] ?? null) ? array_values(array_filter($body['addresses'], 'is_string')) : null;

        return $this->propose($this->element($id), (int) ($body['source'] ?? 0), (int) ($body['page'] ?? 0), $addresses, true === ($body['open'] ?? false), $request);
    }

    /**
     * Saves the texts of the window "Translate" into the target and updates the
     * states of the links (ElementLinks::afterTranslation): into a version it
     * is current once everything was saved (from another version: as current
     * as that one); into the main language its other versions are outdated and
     * the version it came from is current. Body: {"source": page (optional),
     * "page": target page, "values": {address: text}}.
     */
    #[Route('/translation/{id<\d+>}/save', name: 'neo_frontend_editor_translation_save', methods: ['POST'])]
    public function translationSave(int $id, Request $request): Response
    {
        $body = $this->body($request);

        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $element = $this->element($id);
        $pair = null !== $element ? $this->pairOf($element, (int) ($body['source'] ?? 0), (int) ($body['page'] ?? 0), false) : null;

        if (null === $pair || null === $pair['target']) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPlus($pair['targetPage']) || !$this->access->canUpdate($pair['target'])) {
            return ErrorResponse::forbidden($this->logger);
        }

        $values = array_filter(\is_array($body['values'] ?? null) ? $body['values'] : [], 'is_string');
        $result = $this->units->save($pair['source'], $pair['target'], $pair['from'], $pair['to'], $values);
        $complete = [] === $result['failed'] && [] === $result['invalid'];
        $main = $this->element((int) $pair['main']['id']);

        if (null !== $main) {
            $this->links->afterTranslation((int) $main['id'], $this->hashes->of($main), (int) $pair['source']['id'], (int) $pair['target']['id'], $result['saved'] > 0, $complete);
        }

        return $this->json(['success' => $complete, 'id' => (int) $pair['target']['id']] + $result);
    }

    /** The element corresponds to its element in the main language as it is now ("mark as current"). */
    #[Route('/element/{id<\d+>}/synced', name: 'neo_frontend_editor_synced', methods: ['POST'])]
    public function synced(int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $element = $this->element($id);
        $link = $this->links->ofElement($id);
        $main = null !== $link ? $this->element((int) $link['main']) : null;

        if (null === $element || null === $main) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPlus($this->pageOf($element)) || !$this->access->canUpdate($element)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $this->links->markSynced($id, $this->hashes->of($main));

        return $this->json(['success' => true, 'id' => $id]);
    }

    /**
     * Takes an element of the main language over into a version of the page: a
     * copy at the matching place of the matching container (article, element
     * or post), linked and marked as still to be translated, with the elements
     * in it. Body: {"page": ID of the version}.
     */
    #[Route('/element/{id<\d+>}/adopt', name: 'neo_frontend_editor_adopt', methods: ['POST'])]
    public function adopt(int $id, Request $request): Response
    {
        $body = $this->body($request);

        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $main = $this->element($id);
        $targetPage = (int) ($body['page'] ?? 0);

        if (null === $main || $targetPage < 1) {
            return ErrorResponse::notFound($this->logger);
        }

        $mainPage = $this->pageOf($main);
        $mainVersion = $this->versions->mainVersion($targetPage);

        if (null === $mainVersion || $mainVersion['id'] !== $mainPage || $mainPage === $targetPage || !$this->editions->hasPlus($targetPage)) {
            return ErrorResponse::forbidden($this->logger);
        }

        $targetParent = $this->places->containerOn((string) $main['ptable'], (int) $main['pid'], $mainPage, $mainPage, $targetPage);

        if (null === $targetParent) {
            return ErrorResponse::notFound($this->logger);
        }

        $after = $this->links->counterpartBefore($main, $targetParent);
        $result = $this->structure->copyElement($id, $after, null === $after ? $targetParent : null);

        if (WriteStatus::Saved !== $result->status) {
            return WriteStatus::NotFound === $result->status ? ErrorResponse::notFound($this->logger) : ErrorResponse::forbidden($this->logger);
        }

        // The copy keeps the main element's headline without Contao's "(copy)" mark: it is still to be translated.
        $this->db->update('tl_content', ['headline' => $main['headline']], ['id' => $result->id]);
        $language = (string) PageModel::findWithDetails($targetPage)?->language;
        $this->links->link($id, $result->id, $language, '');
        $this->linkCopies($id, $result->id, true, $language);

        return $this->json(['success' => true, 'id' => $result->id]);
    }

    /**
     * The other way round: takes an element that exists only in a version
     * (written there first) over into the main language: a copy at the
     * matching place of the matching container in the main language (article,
     * element or post), linked to the element, which counts as current for it,
     * with the elements in it. Its texts are then translated into the main
     * language in the window "Translate" (the main page as {page}).
     */
    #[Route('/element/{id<\d+>}/adopt-main', name: 'neo_frontend_editor_adopt_main', methods: ['POST'])]
    public function adoptIntoMain(int $id, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::EditLanguages))) {
            return $denied;
        }

        $element = $this->element($id);

        if (null === $element) {
            return ErrorResponse::notFound($this->logger);
        }

        $elementPage = $this->pageOf($element);
        $mainVersion = $this->versions->mainVersion($elementPage);

        if (
            null === $mainVersion
            || $mainVersion['id'] === $elementPage
            || null !== $this->links->ofElement($id)
            || !$this->editions->hasPlus($elementPage)
            || !$this->editions->hasPlus($mainVersion['id'])
            || !$this->access->canUpdate($element)
        ) {
            return ErrorResponse::forbidden($this->logger);
        }

        $mainParent = $this->places->containerOn((string) $element['ptable'], (int) $element['pid'], $elementPage, $mainVersion['id'], $mainVersion['id']);

        if (null === $mainParent) {
            return ErrorResponse::notFound($this->logger);
        }

        $after = $this->links->counterpartBefore($element, $mainParent);
        $result = $this->structure->copyElement($id, $after, null === $after ? $mainParent : null);

        if (WriteStatus::Saved !== $result->status) {
            return WriteStatus::NotFound === $result->status ? ErrorResponse::notFound($this->logger) : ErrorResponse::forbidden($this->logger);
        }

        // The copy keeps the headline without Contao's "(copy)" mark: the same texts as the element, to be translated.
        $this->db->update('tl_content', ['headline' => $element['headline']], ['id' => $result->id]);
        $copy = $this->element($result->id);
        $language = (string) PageModel::findWithDetails($elementPage)?->language;
        $this->links->link($result->id, $id, $language, null !== $copy ? $this->hashes->of($copy) : '');
        $this->linkCopies($id, $result->id, false, $language);

        return $this->json(['success' => true, 'id' => $result->id]);
    }

    /**
     * After an element was taken over into another language with the elements
     * in it (Contao copies them along, in their order): each element in it is
     * linked to its copy as well. Into a version ($intoVersion) the copies are
     * still to be translated; into the main language the copies are the main
     * elements and the elements of the version count as current for them.
     */
    private function linkCopies(int $original, int $copy, bool $intoVersion, string $language, int $depth = 0): void
    {
        $copies = $this->places->elements('tl_content', $copy);

        foreach ($this->places->elements('tl_content', $original) as $index => $child) {
            $copied = $copies[$index] ?? null;

            if (null === $copied || $copied['type'] !== $child['type'] || $depth > 10) {
                return;
            }

            $this->db->update('tl_content', ['headline' => $child['headline']], ['id' => $copied['id']]);
            $copied['headline'] = $child['headline'];

            if ($intoVersion) {
                $this->links->link((int) $child['id'], (int) $copied['id'], $language, '');
            } else {
                $this->links->link((int) $copied['id'], (int) $child['id'], $language, $this->hashes->of($copied));
            }

            $this->linkCopies((int) $child['id'], (int) $copied['id'], $intoVersion, $language, $depth + 1);
        }
    }

    /** Read access: edit mode, Plus for the page's website and the switch of the function. */
    private function deny(int $pageId, Permission $permission): Response|null
    {
        if (!$this->access->has(Permission::EditMode) || !$this->access->has($permission)) {
            return ErrorResponse::forbidden($this->logger);
        }

        if (null === PageModel::findById($pageId)) {
            return ErrorResponse::notFound($this->logger);
        }

        return $this->editions->hasPlus($pageId) ? null : ErrorResponse::forbidden($this->logger);
    }

    /**
     * @param array<string, mixed>|null $element
     * @param list<string>|null         $addresses
     */
    private function propose(array|null $element, int $source, int $page, array|null $addresses, bool $open, Request $request): Response
    {
        if (null !== ($denied = $this->denyWrite($request, Permission::Translate))) {
            return $denied;
        }

        $pair = null !== $element ? $this->pairOf($element, $source, $page, false) : null;

        if (null === $pair) {
            return ErrorResponse::notFound($this->logger);
        }

        if (!$this->editions->hasPlus($pair['targetPage']) || (null !== $pair['target'] && !$this->access->canUpdate($pair['target']))) {
            return ErrorResponse::forbidden($this->logger);
        }

        // Only texts the user may change get proposals: the rest could not be saved.
        $allowed = array_column(array_filter(
            $this->unitsOf($pair),
            static fn (array $unit): bool => (!$unit['locked'] || null === $pair['target']) && (!$open || '' === trim($unit['target'])),
        ), 'address');
        $addresses = null === $addresses ? $allowed : array_values(array_intersect($addresses, $allowed));

        try {
            $values = [] === $addresses ? [] : $this->elementTranslator->proposal($pair['source'], $pair['target'], $pair['from'], $pair['to'], $addresses);
        } catch (TranslationException $e) {
            return ErrorResponse::unavailable($this->logger, 'NFE503-'.$e->reason, $this->text('error.translate_'.$e->reason, ['{service}' => $this->text('service_'.$this->translation->service())]));
        }

        return $this->json(['success' => true, 'values' => $values]);
    }

    /**
     * The texts of a pair (TranslationUnits::pair), each marked "locked" when the
     * user may not change it: no element in the version yet, no place for it, the
     * field not allowed (Contao's field permissions) or, for image metadata, the
     * file not editable as in the file manager.
     *
     * @param array{source: array<string, mixed>, target: array<string, mixed>|null, main: array<string, mixed>, sourcePage: int, targetPage: int, mainPage: int, from: string, to: string} $pair
     *
     * @return list<array<string, mixed>>
     */
    private function unitsOf(array $pair): array
    {
        $units = $this->units->pair($pair['source'], $pair['target'], $pair['from'], $pair['to']);
        $canUpdate = null !== $pair['target'] && $this->access->canUpdate($pair['target']);
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

    /**
     * One direction of a translation within the elements that belong together
     * across the language versions of a page (the element of the main language
     * and its linked elements of the versions), for any element of them:
     * "source" in the language of $sourcePage, "target" (null: none yet) in the
     * language of $targetPage, "main" the element of the main language. Both
     * pages are versions of the element's page (the main page among them).
     * Defaults: the target is the element's own version (for an element of the
     * main language: the first version); the source is the main language, or,
     * for an element of a version translated into the main language, the
     * element's own language. Null when the element belongs to no element of
     * the main language, a page is no version, both are the same or the
     * source has no element.
     *
     * @param array<string, mixed> $element
     *
     * @return array{source: array<string, mixed>, target: array<string, mixed>|null, main: array<string, mixed>, sourcePage: int, targetPage: int, mainPage: int, from: string, to: string}|null
     */
    private function pairOf(array $element, int $sourcePage, int $targetPage, bool $pairFirst): array|null
    {
        $elementPage = $this->pageOf($element);
        $mainVersion = $this->versions->mainVersion($elementPage);

        if (null === $mainVersion) {
            return null;
        }

        $mainPage = $mainVersion['id'];

        if ($elementPage === $mainPage) {
            $main = $element;
            $targetPage = $targetPage ?: (int) (array_values(array_filter($this->versions->versions($mainPage), static fn (array $v): bool => !$v['main']))[0]['id'] ?? 0);
        } else {
            // A version made before the editor: its elements get their counterparts by structure first.
            if ($pairFirst) {
                $this->pairFor($element, $mainPage, $elementPage);
            }

            $link = $this->links->ofElement((int) $element['id']);
            $main = null !== $link ? $this->element((int) $link['main']) : null;
            $targetPage = $targetPage ?: $elementPage;
        }

        $sourcePage = $sourcePage ?: ($elementPage !== $mainPage && $targetPage === $mainPage ? $elementPage : $mainPage);

        if (
            null === $main
            || $this->pageOf($main) !== $mainPage
            || $sourcePage === $targetPage
            || $targetPage < 1
            || !$this->versions->areVersions($mainPage, $sourcePage)
            || !$this->versions->areVersions($mainPage, $targetPage)
        ) {
            return null;
        }

        // Versions made before the editor: their elements are paired by structure first.
        foreach ([$sourcePage, $targetPage] as $page) {
            if ($pairFirst && $page !== $elementPage) {
                $this->pairFor($main, $mainPage, $page);
            }
        }

        $on = function (int $page) use ($main, $mainPage): array|null {
            $id = $page === $mainPage ? (int) $main['id'] : $this->places->counterpartOn((int) $main['id'], $page);

            return null !== $id ? $this->element($id) : null;
        };
        $source = $on($sourcePage);

        if (null === $source) {
            return null;
        }

        return [
            'source' => $source,
            'target' => $on($targetPage),
            'main' => $main,
            'sourcePage' => $sourcePage,
            'targetPage' => $targetPage,
            'mainPage' => $mainPage,
            'from' => (string) PageModel::findWithDetails($sourcePage)?->language,
            'to' => (string) PageModel::findWithDetails($targetPage)?->language,
        ];
    }

    /**
     * Pairs the elements of a version made before the editor with those of the
     * main language, where the element stands: the articles of the page (and
     * the elements in their elements), or its post (ElementPairing).
     *
     * @param array<string, mixed> $element an element of the page in any language
     */
    private function pairFor(array $element, int $mainPage, int $page): void
    {
        if ($page === $mainPage || !$this->editions->hasPlus($page)) {
            return;
        }

        $language = (string) PageModel::findWithDetails($page)?->language;
        $mayLink = fn (array $row): bool => $this->access->canUpdate($row);
        $top = $this->places->top($element);

        if ('tl_article' === $top['ptable']) {
            $this->pairing->pair($mainPage, $page, $language, $mayLink);

            return;
        }

        $mainPost = $this->places->containerOn($top['ptable'], $top['pid'], $this->places->containerPage($top['ptable'], $top['pid']), $mainPage, $mainPage);
        $post = null !== $mainPost ? $this->places->postOn($top['ptable'], $mainPost, $page) : null;

        if (null !== $post) {
            $this->pairing->pairContainer($top['ptable'], $mainPost, $post, $language, $mayLink);
        }
    }

    /**
     * The kind of container of an element, for the note when it is missing in
     * another language: "article", "element" (inside another element) or "post".
     *
     * @param array<string, mixed> $element
     */
    private function containerKind(array $element): string
    {
        return match ((string) $element['ptable']) {
            'tl_article' => 'article',
            'tl_content' => 'element',
            default => 'post',
        };
    }

    /** Write access: request token, edit mode and the switch of the function (records are checked by the action). */
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

    /** @return array<string, mixed>|null a content element of an article or a post (also inside an element) that is not a draft */
    private function element(int $id): array|null
    {
        return $this->places->element($id);
    }

    /** @param array<string, mixed> $element the page whose language the element has (ElementPlaces) */
    private function pageOf(array $element): int
    {
        return $this->places->pageOf($element);
    }

    /** @param array<string, string> $parameters */
    private function text(string $key, array $parameters = []): string
    {
        $user = $this->security->getUser();
        $locale = $user instanceof BackendUser ? (string) $user->language : null;

        return $this->translator->trans('editor.'.$key, $parameters, 'neo_frontend_editor', $locale);
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
