<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Style;

use Contao\BackendUser;
use Contao\Config;
use Contao\DataContainer;
use Contao\Date;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Neo\BasicBundle\Cache\PageCacheTags;
use Neo\FrontendEditorBundle\Security\StyleVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Contao's form of a page style: the entered style is translated when it is
 * saved (an error keeps the old state and shows the plain text under the
 * field); the CSS made from it is stored next to it, before Contao creates the
 * version, so a restored version brings back both. The list "Page styles"
 * shows the page, where the style applies and when it was changed. Saving or
 * deleting a style empties the page cache of the pages it concerns.
 */
final class StyleCallbacks
{
    /** @var array<int, string> CSS made in this request, by record */
    private array $compiled = [];

    public function __construct(
        private readonly StyleCompiler $compiler,
        private readonly StyleMessages $messages,
        private readonly Connection $db,
        private readonly PageCacheTags $cacheTags,
        private readonly StyleVoter $voter,
        private readonly Security $security,
    ) {
    }

    /**
     * The list shows only the styles of pages the user may style; Contao
     * would otherwise refuse the whole list because of a single row.
     */
    public function limitList(): void
    {
        $user = $this->security->getUser();
        $token = $this->security->getToken();

        if (!$user instanceof BackendUser || $user->isAdmin || null === $token) {
            return;
        }

        $ids = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, pid FROM '.PageStyles::TABLE) as $row) {
            if ($this->voter->mayStyle($token, (int) $row['pid'])) {
                $ids[] = (int) $row['id'];
            }
        }

        $GLOBALS['TL_DCA'][PageStyles::TABLE]['list']['sorting']['root'] = [] === $ids ? [0] : $ids;
    }

    public function compile(mixed $value, DataContainer $dc): string
    {
        $source = (string) $value;

        try {
            $this->compiled[(int) $dc->id] = $this->compiler->compile($source);
        } catch (StyleException $exception) {
            // Contao shows the message as HTML under the field.
            throw new \RuntimeException(StringUtil::specialchars($this->messages->text($exception)), 0, $exception);
        }

        return $source;
    }

    public function storeCss(DataContainer $dc): void
    {
        $id = (int) $dc->id;

        if (!\array_key_exists($id, $this->compiled)) {
            try {
                $this->compiled[$id] = $this->compiler->compile((string) ($this->db->fetchOne('SELECT scss FROM '.PageStyles::TABLE.' WHERE id = ?', [$id]) ?: ''));
            } catch (StyleException) {
                return;
            }
        }

        $this->db->update(PageStyles::TABLE, ['css' => $this->compiled[$id]], ['id' => $id]);
        $this->invalidate($dc);
    }

    /**
     * Cached pages that show the style (or showed it before a change of
     * "applies to") are built again: the page and all pages below it. Pages
     * without a style before carry no tag of it, hence the page tags.
     */
    public function invalidate(DataContainer $dc): void
    {
        $pid = (int) ($this->db->fetchOne('SELECT pid FROM '.PageStyles::TABLE.' WHERE id = ?', [(int) $dc->id]) ?: 0);

        if ($pid < 1) {
            return;
        }

        $pages = [$pid];
        $parents = [$pid];

        while ([] !== $parents) {
            $parents = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM tl_page WHERE pid IN (?)', [$parents], [\Doctrine\DBAL\ArrayParameterType::INTEGER]));
            $pages = [...$pages, ...$parents];
        }

        $this->cacheTags->invalidate(array_map(static fn (int $id): string => 'contao.db.tl_page.'.$id, $pages));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public function label(array $row): array
    {
        System::loadLanguageFile(PageStyles::TABLE);
        $lang = $GLOBALS['TL_LANG'][PageStyles::TABLE] ?? [];
        $title = (string) ($this->db->fetchOne('SELECT title FROM tl_page WHERE id = ?', [(int) $row['pid']]) ?: '');

        return [
            StringUtil::specialchars($title).' <span class="tl_gray">(ID '.(int) $row['pid'].')</span>',
            StringUtil::specialchars((string) ($lang['scope_options'][$row['scope']] ?? $row['scope'])),
            $row['tstamp'] ? Date::parse(Config::get('datimFormat'), (int) $row['tstamp']) : StringUtil::specialchars((string) ($lang['draft'] ?? '')),
        ];
    }
}
