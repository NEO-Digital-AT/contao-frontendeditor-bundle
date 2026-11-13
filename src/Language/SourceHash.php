<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;

/**
 * The state of the texts of an element in the main language, for the state of
 * its translations: a hash over every text a translation covers
 * (TranslationUnits: the fields, the entries and the image metadata in the
 * language of the element's page). A link stores it when the version is
 * translated or confirmed; when it differs later, the version is outdated.
 *
 * Links stored before this hash have ContentHash::legacy(); SourceHashMigration
 * converts those that were current.
 */
final class SourceHash
{
    /** @var array<int, string> page ID => language */
    private array $languages = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ContaoFramework $framework,
        private readonly TranslationUnits $units,
        private readonly ElementPlaces|null $places = null,
    ) {
    }

    /**
     * @param array<string, mixed> $element a content element of an article or a post, also inside an element
     * @param bool                 $related false: without the records it shows from other tables (the hash before they were covered)
     */
    public function of(array $element, bool $related = true): string
    {
        return hash('sha256', json_encode($this->units->texts($element, $this->languageOf($element), $related), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** The language of the page of an article. */
    public function language(int $articleId): string
    {
        return $this->pageLanguage((int) $this->db->fetchOne('SELECT pid FROM tl_article WHERE id = ?', [$articleId]));
    }

    /**
     * The language of the page an element has (ElementPlaces): of its article,
     * or of the page of its post, also for an element inside an element.
     *
     * @param array<string, mixed> $element
     */
    public function languageOf(array $element): string
    {
        if ('tl_article' === ($element['ptable'] ?? 'tl_article') || null === $this->places) {
            return $this->language((int) ($element['pid'] ?? 0));
        }

        return $this->pageLanguage($this->places->pageOf($element));
    }

    private function pageLanguage(int $pageId): string
    {
        if (!isset($this->languages[$pageId])) {
            $this->framework->initialize();
            $this->languages[$pageId] = $pageId > 0 ? (string) $this->framework->getAdapter(PageModel::class)->findWithDetails($pageId)?->language : '';
        }

        return $this->languages[$pageId];
    }
}
