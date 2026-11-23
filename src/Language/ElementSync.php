<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Translation\TranslationException;
use Neo\FrontendEditorBundle\Content\FieldWriter;
use Neo\FrontendEditorBundle\Content\FileMeta;
use Neo\FrontendEditorBundle\Content\PageStructure;
use Neo\FrontendEditorBundle\Content\Palette;
use Neo\FrontendEditorBundle\Content\StructureEditor;
use Neo\FrontendEditorBundle\Content\WriteStatus;
use Neo\FrontendEditorBundle\License\Editions;
use Neo\FrontendEditorBundle\Security\ElementAccess;
use Neo\FrontendEditorBundle\Security\Permission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the elements of the language versions of a page in step (Plus): what
 * is done to an element that belongs together with elements of the other
 * languages can be done to them as well, once the user agrees in the editor.
 *
 * - A new element (inserted or duplicated): in each other language a copy at
 *   the matching place, translated by the translation service of the settings
 *   and linked; or a link to an element that is already there; or nothing, and
 *   the element stays in its own language only.
 * - Its image changed: the same file in its counterparts.
 * - Moved, hidden or shown: its counterparts go to the matching place or get
 *   the same visibility.
 * - Deleted: its counterparts are deleted as well; those that stay lose their
 *   link with the element (LinkCallbacks).
 *
 * Elements of the languages belong together through the element of the main
 * language (ElementLinks): an element of a version reaches the other versions
 * through it. "Counterpart" is the element of the same group on another
 * language version of the page. Each write goes through Contao's operations and
 * voters (StructureEditor, FieldWriter) and needs Plus for the target page.
 */
final class ElementSync
{
    /** Depth of elements in elements followed when a copy is translated. */
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly Editions $editions,
        private readonly ElementAccess $access,
        private readonly LanguageVersions $versions,
        private readonly ElementPlaces $places,
        private readonly ElementLinks $links,
        private readonly ElementPairing $pairing,
        private readonly SourceHash $hashes,
        private readonly TranslationUnits $units,
        private readonly ElementTranslator $translator,
        private readonly TranslationService $translation,
        private readonly StructureEditor $structure,
        private readonly FieldWriter $writer,
        private readonly FileMeta $files,
        private readonly PageStructure $pageStructure,
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $imageExtensions = [],
    ) {
    }

    /**
     * What the element has in the other languages of its page, for the
     * questions of the editor: per language its counterpart (null: none),
     * whether the matching container exists there, whether a copy may be put
     * there, and the elements there that belong to nothing yet (candidates for
     * a link, those of the same type first). Null when the page has no other
     * language versions the user may open. A new element ($new) is left out
     * when versions made before the editor are paired by structure: what
     * becomes of it is asked.
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>|null
     */
    public function overview(array $element, bool $new = false): array|null
    {
        $page = $this->places->pageOf($element);
        $mainVersion = $this->versions->mainVersion($page);
        $others = array_values(array_filter($this->versions->versions($page), static fn (array $version): bool => $version['id'] !== $page));

        if (null === $mainVersion || [] === $others) {
            return null;
        }

        $mainPage = $mainVersion['id'];

        // Versions made before the editor: their elements are paired by structure first, as "Link" would do it.
        foreach ([$page, ...array_column($others, 'id')] as $version) {
            $this->pairFor($element, $mainPage, $version, $new ? [(int) $element['id']] : []);
        }

        $main = $this->mainOf($element, $mainPage);
        $languages = [];

        foreach ($others as $version) {
            $target = $version['id'];
            $counterpart = null !== $main ? $this->counterpart($main, $mainPage, $target) : null;
            $container = $this->places->containerOn((string) $element['ptable'], (int) $element['pid'], $page, $mainPage, $target);
            $language = (string) $version['language'];

            $languages[] = [
                'page' => $target,
                'language' => $language,
                'name' => $version['name'],
                'main' => $target === $mainPage,
                'plus' => $this->editions->hasPlus($target),
                'element' => null !== $counterpart ? $this->describe($counterpart, $language) : null,
                'container' => null !== $container,
                'canCreate' => null !== $container && $this->access->canCopyInto($main ?? $element, $container),
                'candidates' => null === $counterpart && null !== $container ? $this->candidates($element, $target, $mainPage, $container, $language) : [],
            ];
        }

        return [
            'page' => $page,
            'mainPage' => $mainPage,
            'main' => null !== $main ? (int) $main['id'] : null,
            'translate' => $this->access->has(Permission::Translate) && $this->translation->isReady(),
            'languages' => $languages,
        ];
    }

    /**
     * A new element into the other languages of its page. $choices by page:
     * "create" (a copy at the matching place, translated and linked), "link"
     * (with the element $links[page] there) or anything else (nothing). An
     * element of a version reaches the other versions through the main
     * language: without an element there, the versions are left as they are.
     *
     * Only with translations at hand: the switch "Request translations" and a
     * translation service set up (overview()["translate"]). Without them
     * nothing is taken over, and a copy whose translation the service refuses
     * or cannot answer is removed again, so no language gets an untranslated
     * copy.
     *
     * @param array<string, mixed>  $element
     * @param array<int, string>    $choices
     * @param array<int, int>       $linkTo
     *
     * @return list<array{page: int, language: string, done: string, id: int|null, translated: bool, reason: string|null}>
     */
    public function spread(array $element, array $choices, array $linkTo): array
    {
        $overview = $this->overview($element, true);

        if (null === $overview) {
            return [];
        }

        if (!$overview['translate']) {
            $reason = $this->access->has(Permission::Translate) ? 'translate_unavailable' : 'translate_forbidden';

            return array_map(static fn (array $language): array => [
                'page' => $language['page'],
                'language' => $language['language'],
                'done' => 'none',
                'id' => null,
                'translated' => false,
                'reason' => \in_array($choices[$language['page']] ?? '', ['create', 'link'], true) ? $reason : null,
            ], $overview['languages']);
        }

        $page = $overview['page'];
        $mainPage = $overview['mainPage'];
        $from = $this->languageOf($page);
        $byPage = array_column($overview['languages'], null, 'page');
        $results = [];
        $main = null !== $overview['main'] ? $this->places->element($overview['main']) : null;

        // An element of a version: the main language first, the other versions follow from there.
        if ($page !== $mainPage && null === $main && isset($byPage[$mainPage])) {
            $result = $this->intoMain($element, $byPage[$mainPage], $choices[$mainPage] ?? '', (int) ($linkTo[$mainPage] ?? 0), $from);
            $results[] = $result;
            $main = null !== $result['id'] ? $this->places->element($result['id']) : null;
        }

        foreach ($overview['languages'] as $language) {
            if ($language['main']) {
                continue;
            }

            $results[] = $this->intoVersion($element, $page, $from, $main, $mainPage, $language, $choices[$language['page']] ?? '', (int) ($linkTo[$language['page']] ?? 0));
        }

        return $results;
    }

    /**
     * The element's image field into its counterparts on the pages $pages: the
     * same file, where the counterpart has the field and the user may change it.
     *
     * @param array<string, mixed> $element
     * @param list<int>            $pages
     *
     * @return list<array{page: int, id: int|null, done: string, reason: string|null}>
     */
    public function image(array $element, string $field, array $pages): array
    {
        // The palettes come from the data container of tl_content.
        $this->framework->initialize();
        $this->framework->getAdapter(Controller::class)->loadDataContainer('tl_content');

        if (!\in_array($field, Palette::imageFields('tl_content', $element, $this->imageExtensions), true)) {
            return [];
        }

        $value = $this->posted($element[$field] ?? null);

        return $this->eachCounterpart($element, $pages, function (array $counterpart) use ($field, $value): string|null {
            if (!\in_array($field, Palette::imageFields('tl_content', $counterpart, $this->imageExtensions), true)) {
                return 'no_field';
            }

            if ($this->posted($counterpart[$field] ?? null) === $value) {
                return null;
            }

            $result = $this->writer->update((int) $counterpart['id'], [$field => $value]);

            if (WriteStatus::Saved !== $result->status) {
                return WriteStatus::Forbidden === $result->status ? 'forbidden' : 'failed';
            }

            // A change of the file can change the texts of its metadata: the states of the translations follow.
            $this->links->refreshOutdated((int) $counterpart['id'], $this->hashes->of($result->record));

            return null;
        });
    }

    /**
     * After the element was moved: its counterparts on the pages $pages go to
     * the matching place (after the counterpart of the nearest element before
     * it, or to the top of the matching container).
     *
     * @param array<string, mixed> $element as it is now
     * @param list<int>            $pages
     *
     * @return list<array{page: int, id: int|null, done: string, reason: string|null}>
     */
    public function move(array $element, array $pages): array
    {
        $page = $this->places->pageOf($element);
        $mainPage = (int) ($this->versions->mainVersion($page)['id'] ?? 0);

        return $this->eachCounterpart($element, $pages, function (array $counterpart, int $target) use ($element, $page, $mainPage): string|null {
            $parent = $this->places->containerOn((string) $element['ptable'], (int) $element['pid'], $page, $mainPage, $target);

            if (null === $parent || (string) $counterpart['ptable'] !== (string) $element['ptable']) {
                return 'no_container';
            }

            $after = $this->links->counterpartBefore($element, $parent);
            $before = $this->db->fetchOne(
                'SELECT id FROM tl_content WHERE ptable = ? AND pid = ? AND sorting < ? AND tstamp > 0 ORDER BY sorting DESC LIMIT 1',
                [$counterpart['ptable'], $counterpart['pid'], $counterpart['sorting']],
            );

            // Already at that place.
            if ((int) $counterpart['pid'] === $parent && (false === $before ? null : (int) $before) === $after) {
                return null;
            }

            $result = $this->structure->moveElement((int) $counterpart['id'], $after, null === $after ? $parent : null);

            return WriteStatus::Saved === $result->status ? null : $this->reason($result->status);
        });
    }

    /**
     * After the element was hidden or shown: its counterparts on the pages
     * $pages get the same visibility.
     *
     * @param array<string, mixed> $element as it is now
     * @param list<int>            $pages
     *
     * @return list<array{page: int, id: int|null, done: string, reason: string|null}>
     */
    public function visibility(array $element, array $pages): array
    {
        $hidden = (bool) $element['invisible'];

        return $this->eachCounterpart($element, $pages, function (array $counterpart) use ($hidden): string|null {
            if ((bool) $counterpart['invisible'] === $hidden) {
                return null;
            }

            $result = $this->structure->toggleElement((int) $counterpart['id']);

            return WriteStatus::Saved === $result->status ? null : $this->reason($result->status);
        });
    }

    /**
     * Before the element is deleted: its counterparts on the pages $pages are
     * deleted (with the elements in them, as Contao deletes them).
     *
     * @param array<string, mixed> $element
     * @param list<int>            $pages
     *
     * @return list<array{page: int, id: int|null, done: string, reason: string|null}>
     */
    public function delete(array $element, array $pages): array
    {
        return $this->eachCounterpart($element, $pages, function (array $counterpart): string|null {
            $result = $this->structure->deleteElement((int) $counterpart['id']);

            return WriteStatus::Saved === $result->status ? null : $this->reason($result->status);
        });
    }

    /**
     * Pairs the elements of a version made before the editor with those of the
     * main language, where the element stands: the articles of the page (and
     * the elements in their elements), or its post (ElementPairing).
     *
     * @param array<string, mixed> $element an element of the page in any language
     * @param list<int>            $exclude elements not to pair
     */
    public function pairFor(array $element, int $mainPage, int $page, array $exclude = []): void
    {
        if ($page === $mainPage || !$this->editions->hasPlus($page)) {
            return;
        }

        $language = $this->languageOf($page);
        $mayLink = fn (array $row): bool => $this->access->canUpdate($row);
        $top = $this->places->top($element);

        if ('tl_article' === $top['ptable']) {
            $this->pairing->pair($mainPage, $page, $language, $mayLink, $exclude);

            return;
        }

        $mainPost = $this->places->containerOn($top['ptable'], $top['pid'], $this->places->containerPage($top['ptable'], $top['pid']), $mainPage, $mainPage);
        $post = null !== $mainPost ? $this->places->postOn($top['ptable'], $mainPost, $page) : null;

        if (null !== $post) {
            $this->pairing->pairContainer($top['ptable'], $mainPost, $post, $language, $mayLink, 0, $exclude);
        }
    }

    /**
     * The new element of a version into the main language: a copy at the
     * matching place, translated into the main language (the element counts as
     * current for it), or a link with an element of the main language.
     *
     * @param array<string, mixed> $element
     * @param array<string, mixed> $language the entry of the main language in overview()
     *
     * @return array{page: int, language: string, done: string, id: int|null, translated: bool, reason: string|null}
     */
    private function intoMain(array $element, array $language, string $choice, int $linkTo, string $from): array
    {
        $page = $language['page'];
        $to = $language['language'];
        $result = ['page' => $page, 'language' => $to, 'done' => 'none', 'id' => null, 'translated' => false, 'reason' => null];
        $own = $this->places->pageOf($element);

        if ('link' === $choice) {
            $other = $this->places->element($linkTo);

            if (null === $other || !\in_array($linkTo, array_column($language['candidates'], 'id'), true) || !$this->access->canUpdate($element)) {
                return ['reason' => 'link_invalid'] + $result;
            }

            $this->links->link($linkTo, (int) $element['id'], $this->languageOf($own), $this->hashes->of($other));

            return ['done' => 'linked', 'id' => $linkTo] + $result;
        }

        if ('create' !== $choice) {
            return $result;
        }

        if (!$language['plus'] || !$language['canCreate'] || !$this->access->canUpdate($element)) {
            return ['reason' => $language['container'] ? 'forbidden' : 'no_container'] + $result;
        }

        $parent = $this->places->containerOn((string) $element['ptable'], (int) $element['pid'], $own, $page, $page);
        $copy = null !== $parent ? $this->copyTo($element, $parent) : null;

        if (null === $copy) {
            return ['reason' => 'failed'] + $result;
        }

        // The element of the version counts as current for its copy, which is translated now.
        $this->links->link($copy, (int) $element['id'], $this->languageOf($own), $this->hashes->of((array) $this->places->element($copy)));
        $this->linkChildren((int) $element['id'], $copy, false, $this->languageOf($own));
        [$translated, $reason] = $this->translateGroup((int) $element['id'], $copy, $from, $to, $copy);

        if ($this->refused($reason)) {
            $this->structure->deleteElement($copy);

            return ['reason' => $reason] + $result;
        }

        return ['done' => 'created', 'id' => $copy, 'translated' => $translated, 'reason' => $reason] + $result;
    }

    /**
     * The new element into a version: a copy of its element of the main
     * language at the matching place, translated from the language the element
     * was written in, or a link with an element of the version.
     *
     * @param array<string, mixed>      $element
     * @param array<string, mixed>|null $main     the element of the main language (the element itself on the main page)
     * @param array<string, mixed>      $language the entry of the version in overview()
     *
     * @return array{page: int, language: string, done: string, id: int|null, translated: bool, reason: string|null}
     */
    private function intoVersion(array $element, int $page, string $from, array|null $main, int $mainPage, array $language, string $choice, int $linkTo): array
    {
        $target = $language['page'];
        $to = $language['language'];
        $result = ['page' => $target, 'language' => $to, 'done' => 'none', 'id' => null, 'translated' => false, 'reason' => null];

        if (!\in_array($choice, ['create', 'link'], true)) {
            return $result;
        }

        if (null === $main) {
            return ['reason' => 'no_main'] + $result;
        }

        if (null !== $this->places->counterpartOn((int) $main['id'], $target)) {
            return ['reason' => 'exists'] + $result;
        }

        if (!$language['plus']) {
            return ['reason' => 'forbidden'] + $result;
        }

        if ('link' === $choice) {
            $other = $this->places->element($linkTo);

            if (null === $other || !\in_array($linkTo, array_column($language['candidates'], 'id'), true) || !$this->access->canUpdate($other)) {
                return ['reason' => 'link_invalid'] + $result;
            }

            $this->links->link((int) $main['id'], $linkTo, $to, $this->hashes->of($main));

            return ['done' => 'linked', 'id' => $linkTo] + $result;
        }

        $parent = $this->places->containerOn((string) $main['ptable'], (int) $main['pid'], $mainPage, $mainPage, $target);

        if (null === $parent || !$this->access->canCopyInto($main, $parent)) {
            return ['reason' => null === $parent ? 'no_container' : 'forbidden'] + $result;
        }

        $copy = $this->copyTo($main, $parent);

        if (null === $copy) {
            return ['reason' => 'failed'] + $result;
        }

        // Linked as still to be translated, then translated from the language the element was written in.
        $this->links->link((int) $main['id'], $copy, $to, '');
        $this->linkChildren((int) $main['id'], $copy, true, $to);
        [$translated, $reason] = $this->translateGroup((int) $element['id'], $copy, $from, $to, (int) $main['id']);

        if ($this->refused($reason)) {
            $this->structure->deleteElement($copy);

            return ['reason' => $reason] + $result;
        }

        return ['done' => 'created', 'id' => $copy, 'translated' => $translated, 'reason' => $reason] + $result;
    }

    /**
     * A copy of the element at the matching place of $parent (after the
     * counterpart of the nearest element before it, or at the top), with the
     * elements in it and without Contao's "(copy)" mark in the headline.
     *
     * @param array<string, mixed> $element
     */
    private function copyTo(array $element, int $parent): int|null
    {
        $after = $this->links->counterpartBefore($element, $parent);
        $result = $this->structure->copyElement((int) $element['id'], $after, null === $after ? $parent : null);

        if (WriteStatus::Saved !== $result->status) {
            return null;
        }

        $this->db->update('tl_content', ['headline' => $element['headline']], ['id' => $result->id]);

        return $result->id;
    }

    /**
     * After a copy with the elements in it (Contao copies them along, in their
     * order): each element in it is linked with its copy as well. Into a
     * version ($intoVersion) the copies are still to be translated; into the
     * main language the copies are the main elements and the originals count as
     * current for them.
     */
    private function linkChildren(int $original, int $copy, bool $intoVersion, string $language, int $depth = 0): void
    {
        $copies = $this->places->elements('tl_content', $copy);

        foreach ($this->places->elements('tl_content', $original) as $index => $child) {
            $copied = $copies[$index] ?? null;

            if (null === $copied || $copied['type'] !== $child['type'] || $depth >= self::MAX_DEPTH) {
                return;
            }

            $this->db->update('tl_content', ['headline' => $child['headline']], ['id' => $copied['id']]);
            $copied['headline'] = $child['headline'];

            if ($intoVersion) {
                $this->links->link((int) $child['id'], (int) $copied['id'], $language, '');
            } else {
                $this->links->link((int) $copied['id'], (int) $child['id'], $language, $this->hashes->of($copied));
            }

            $this->linkChildren((int) $child['id'], (int) $copied['id'], $intoVersion, $language, $depth + 1);
        }
    }

    /**
     * Translates the element $source and the elements in it into $target and
     * its elements (the same structure), and sets the states of the links
     * (ElementLinks::afterTranslation, $mainId the element of the main
     * language). Without the switch "Translate" or a translation service the
     * copy keeps the texts of the source and stays marked as still to be
     * translated.
     *
     * @return array{0: bool, 1: string|null} translated completely, reason when not
     */
    private function translateGroup(int $source, int $target, string $from, string $to, int $mainId, int $depth = 0): array
    {
        if (!$this->access->has(Permission::Translate)) {
            return [false, 'translate_forbidden'];
        }

        if (!$this->translation->isReady()) {
            return [false, 'translate_unavailable'];
        }

        $sourceRow = $this->places->element($source);
        $targetRow = $this->places->element($target);

        if (null === $sourceRow || null === $targetRow) {
            return [false, 'failed'];
        }

        $allowed = [];
        $files = [];

        foreach ($this->units->pair($sourceRow, $targetRow, $from, $to) as $unit) {
            $editable = null !== $unit['file']
                ? ($files[$unit['file']] ??= $this->files->canEdit($unit['file']))
                : $this->access->canEditField($unit['field'], $unit['table']);

            if ($unit['place'] && $editable) {
                $allowed[] = $unit['address'];
            }
        }

        $complete = true;
        $reason = null;

        if ([] !== $allowed) {
            try {
                $values = $this->translator->proposal($sourceRow, $targetRow, $from, $to, $allowed);
            } catch (TranslationException $e) {
                return [false, 'translate_'.$e->reason];
            }

            $saved = $this->units->save($sourceRow, $targetRow, $from, $to, $values);
            $complete = [] === $saved['failed'] && [] === $saved['invalid'];
            $main = $this->places->element($mainId);

            if (null !== $main) {
                $this->links->afterTranslation((int) $main['id'], $this->hashes->of($main), $source, $target, $saved['saved'] > 0, $complete);
            }

            $reason = $complete ? null : 'translate_incomplete';
        } else {
            $main = $this->places->element($mainId);

            if (null !== $main && $target !== $mainId) {
                $this->links->markSynced($target, $this->hashes->of($main));
            }
        }

        // The elements in it, pair by pair in their order.
        if ($depth < self::MAX_DEPTH) {
            $targets = $this->places->elements('tl_content', $target);

            foreach ($this->places->elements('tl_content', $source) as $index => $child) {
                $copied = $targets[$index] ?? null;

                if (null === $copied || $copied['type'] !== $child['type']) {
                    break;
                }

                $childMain = $mainId === $target ? (int) $copied['id'] : (int) ($this->links->ofElement((int) $copied['id'])['main'] ?? 0);
                [$done, $why] = $this->translateGroup((int) $child['id'], (int) $copied['id'], $from, $to, $childMain, $depth + 1);
                $complete = $complete && $done;
                $reason ??= $why;
            }
        }

        return [$complete, $reason];
    }

    /**
     * Calls $apply for the counterpart of the element on each of the pages
     * (versions of its page with Plus); a result per page.
     *
     * @param array<string, mixed>                                       $element
     * @param list<int>                                                  $pages
     * @param callable(array<string, mixed>, int): (string|null)         $apply the reason when it was not done
     *
     * @return list<array{page: int, id: int|null, done: string, reason: string|null}>
     */
    private function eachCounterpart(array $element, array $pages, callable $apply): array
    {
        $page = $this->places->pageOf($element);
        $mainVersion = $this->versions->mainVersion($page);

        if (null === $mainVersion) {
            return [];
        }

        $mainPage = $mainVersion['id'];
        $main = $this->mainOf($element, $mainPage);
        $counterparts = [];

        // All of them first: deleting the element of the main language takes the links of the others along.
        foreach (array_unique(array_map('intval', $pages)) as $target) {
            $counterparts[$target] = null !== $main && $target !== $page && $this->versions->areVersions($page, $target) ? $this->counterpart($main, $mainPage, $target) : null;
        }

        $results = [];

        foreach ($counterparts as $target => $counterpart) {
            if (null === $counterpart) {
                $results[] = ['page' => $target, 'id' => null, 'done' => 'none', 'reason' => 'no_element'];
                continue;
            }

            // Fresh from the database: an earlier step (a deleted element of the main language) may have changed it.
            $counterpart = $this->places->element((int) $counterpart['id']);
            $reason = null === $counterpart ? 'no_element' : ($this->editions->hasPlus($target) ? $apply($counterpart, $target) : 'forbidden');
            $results[] = ['page' => $target, 'id' => null !== $counterpart ? (int) $counterpart['id'] : null, 'done' => null === $reason ? 'done' : 'none', 'reason' => $reason];
        }

        return $results;
    }

    /**
     * The element of the main language the element belongs to (the element
     * itself on the page of the main language), null without one.
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>|null
     */
    private function mainOf(array $element, int $mainPage): array|null
    {
        if ($this->places->pageOf($element) === $mainPage) {
            return $element;
        }

        $link = $this->links->ofElement((int) $element['id']);
        $main = null !== $link ? $this->places->element((int) $link['main']) : null;

        return null !== $main && $this->places->pageOf($main) === $mainPage ? $main : null;
    }

    /**
     * @param array<string, mixed> $main
     *
     * @return array<string, mixed>|null the element of the group on page $page
     */
    private function counterpart(array $main, int $mainPage, int $page): array|null
    {
        if ($page === $mainPage) {
            return $main;
        }

        $id = $this->places->counterpartOn((int) $main['id'], $page);

        return null !== $id ? $this->places->element($id) : null;
    }

    /**
     * The elements of the container $container on page $target that belong to
     * no element of the element's language yet: of the main language those
     * without a counterpart on the element's page, of a version those linked
     * to nothing; the same type first.
     *
     * @param array<string, mixed> $element
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(array $element, int $target, int $mainPage, int $container, string $language): array
    {
        $page = $this->places->pageOf($element);
        $rows = $this->places->elements((string) $element['ptable'], $container);
        $ids = array_map('intval', array_column($rows, 'id'));
        $free = [];

        if ($target === $mainPage) {
            $taken = [];

            foreach ($this->links->ofMains($ids) as $mainId => $links) {
                foreach ($links as $link) {
                    $linked = $this->places->element((int) $link['element']);

                    if (null !== $linked && $this->places->pageOf($linked) === $page) {
                        $taken[$mainId] = true;
                    }
                }
            }

            $free = array_filter($rows, static fn (array $row): bool => !isset($taken[(int) $row['id']]));
        } else {
            $linked = $this->links->ofElements($ids);
            $free = array_filter($rows, static fn (array $row): bool => !isset($linked[(int) $row['id']]));
        }

        $free = array_filter($free, fn (array $row): bool => $this->access->canUpdate($row));
        usort($free, static fn (array $a, array $b): int => [(string) $a['type'] !== (string) $element['type'], (int) $a['sorting']] <=> [(string) $b['type'] !== (string) $element['type'], (int) $b['sorting']]);

        return array_values(array_map(fn (array $row): array => $this->describe($row, $language), $free));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, type: string, typeLabel: string, summary: string, invisible: bool, canUpdate: bool, canMove: bool, canToggle: bool, canDelete: bool}
     */
    private function describe(array $row, string $language): array
    {
        $data = $this->pageStructure->element($row, $language);

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'typeLabel' => (string) $data['typeLabel'],
            'summary' => (string) $data['summary'],
            'invisible' => (bool) $row['invisible'],
            'canUpdate' => (bool) $data['canUpdate'],
            'canMove' => (bool) $data['canMove'],
            'canToggle' => (bool) $data['canToggle'],
            'canDelete' => (bool) $data['canDelete'],
        ];
    }

    /** The value of a file field as the back-end form posts it: the UUID as text, '' for none. */
    private function posted(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            return '';
        }

        return 16 === \strlen($value) ? StringUtil::binToUuid($value) : $value;
    }

    private function languageOf(int $page): string
    {
        return (string) PageModel::findWithDetails($page)?->language;
    }

    /**
     * Whether a translation did not happen at all (no switch, no service, the
     * service refused or did not answer): the copy goes again. A translation
     * that left out texts without a place or the user may not change
     * ("translate_incomplete") keeps its copy.
     */
    private function refused(string|null $reason): bool
    {
        return null !== $reason && str_starts_with($reason, 'translate_') && 'translate_incomplete' !== $reason;
    }

    private function reason(WriteStatus $status): string
    {
        return match ($status) {
            WriteStatus::Forbidden => 'forbidden',
            WriteStatus::NotFound => 'no_element',
            default => 'failed',
        };
    }
}
