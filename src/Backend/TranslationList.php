<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Backend;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Neo\FrontendEditorBundle\Language\ElementPlaces;
use Neo\FrontendEditorBundle\Posts\PostTypes;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The back-end side of the language versions (Plus): the list "Translations"
 * (labels and the two edit links of each row) and the warning symbol at an
 * element with an outdated translation in Contao's list of content elements.
 */
final class TranslationList
{
    /** @var array<int, true>|null elements with an outdated translation */
    private array|null $outdated = null;

    public function __construct(
        private readonly Connection $db,
        private readonly UrlGeneratorInterface $router,
        private readonly ElementPlaces $places,
        private readonly PostTypes $postTypes,
    ) {
    }

    /** @param array<string, mixed> $row */
    public function label(array $row): array
    {
        System::loadLanguageFile('tl_neo_fe_link');
        System::loadLanguageFile('default');
        System::loadLanguageFile('modules');
        $lang = $GLOBALS['TL_LANG']['tl_neo_fe_link'];

        return [
            $this->describe((int) $row['main']),
            $this->describe((int) $row['element']),
            StringUtil::specialchars((string) $row['language']),
            $row['outdated'] ? '<strong class="tl_red">'.StringUtil::specialchars($lang['outdated_options']['1'] ?? '').'</strong>' : StringUtil::specialchars($lang['current'] ?? ''),
        ];
    }

    public function editElement(DataContainerOperation $operation): void
    {
        $this->editLink($operation, (int) ($operation->getRecord()['element'] ?? 0), 'edit_element');
    }

    public function editMain(DataContainerOperation $operation): void
    {
        $this->editLink($operation, (int) ($operation->getRecord()['main'] ?? 0), 'edit_main');
    }

    /** At an element in Contao's list: the symbol only when its translation is outdated. */
    public function outdatedMarker(DataContainerOperation $operation): void
    {
        $id = (int) ($operation->getRecord()['id'] ?? 0);

        if (!isset($this->outdatedElements()[$id])) {
            $operation->setHtml(''); // What hide() does from Contao 5.6 on.

            return;
        }

        System::loadLanguageFile('tl_neo_fe_link');
        $operation['label'] = $GLOBALS['TL_LANG']['tl_neo_fe_link']['outdated_options']['1'] ?? '';
        $operation['title'] = $GLOBALS['TL_LANG']['tl_neo_fe_link']['outdated_hint'] ?? '';
        $operation->setUrl($this->router->generate('contao_backend', ['do' => 'neo_frontendeditor_translations']));
    }

    private function editLink(DataContainerOperation $operation, int $elementId, string $key): void
    {
        System::loadLanguageFile('tl_neo_fe_link');

        $row = $elementId > 0 ? $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$elementId]) : false;

        if (false === $row) {
            $operation->setHtml(''); // What hide() does from Contao 5.6 on.

            return;
        }

        // Elements of posts open in the back-end module of the post type.
        $top = $this->places->top($row);
        $module = 'tl_article' === $top['ptable'] ? 'article' : ($this->postTypes->get($top['ptable'])['module'] ?? 'article');

        $operation['label'] = $GLOBALS['TL_LANG']['tl_neo_fe_link'][$key][0] ?? $key;
        $operation['title'] = $GLOBALS['TL_LANG']['tl_neo_fe_link'][$key][1] ?? $key;
        $operation->setUrl($this->router->generate('contao_backend', ['do' => $module, 'table' => 'tl_content', 'id' => $elementId, 'act' => 'edit']));
    }

    /** "Text: Ankommen und durchatmen (Startseite)" */
    private function describe(int $elementId): string
    {
        $row = $this->db->fetchAssociative('SELECT * FROM tl_content WHERE id = ?', [$elementId]);

        if (false === $row) {
            return '–';
        }

        // The page of its article, or of its post, also for an element inside an element.
        $row['page'] = (string) $this->db->fetchOne('SELECT title FROM tl_page WHERE id = ?', [$this->places->pageOf($row)]);

        $headline = StringUtil::deserialize($row['headline'] ?? '', true);
        $summary = trim((string) ($headline['value'] ?? '')) ?: (trim(strip_tags((string) $row['text'])) ?: trim((string) $row['alt']));
        $summary = mb_strlen($summary) > 60 ? mb_substr($summary, 0, 59).'…' : $summary;
        $type = $GLOBALS['TL_LANG']['CTE'][$row['type']][0] ?? $row['type'];

        return StringUtil::specialchars($type.': '.html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8')).' <span class="tl_gray">('.StringUtil::specialchars((string) $row['page']).')</span>';
    }

    /** @return array<int, true> */
    private function outdatedElements(): array
    {
        if (null === $this->outdated) {
            try {
                $this->outdated = array_fill_keys(array_map('intval', $this->db->fetchFirstColumn("SELECT element FROM tl_neo_fe_link WHERE outdated = '1'")), true);
            } catch (TableNotFoundException) {
                $this->outdated = [];
            }
        }

        return $this->outdated;
    }
}
