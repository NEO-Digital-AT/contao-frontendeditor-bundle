<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Language;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Locales;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Terminal42\ChangeLanguage\PageFinder;

/**
 * The language versions of a page as the extension changelanguage links them
 * (tl_page.languageMain, articles by tl_article.languageMain). The main
 * language is the one of the fallback root page. Without changelanguage there
 * are no language versions.
 */
final class LanguageVersions
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Security $security,
        private readonly Connection $db,
        #[Autowire(service: 'contao.intl.locales')]
        private readonly Locales $locales,
    ) {
    }

    /** Name of a language as Contao shows it, in the language of the back-end user ("Englisch"). */
    public function name(string $language): string
    {
        $user = $this->security->getUser();
        $names = $this->locales->getLocales($user instanceof BackendUser ? (string) $user->language : null);
        $id = str_replace('-', '_', $language);

        return $names[$id] ?? $names[strtolower(explode('_', $id)[0])] ?? $language;
    }

    public function isAvailable(): bool
    {
        return class_exists(PageFinder::class);
    }

    /**
     * All versions of the page the user may open, the page itself included,
     * the main language first.
     *
     * @return list<array{id: int, title: string, language: string, name: string, main: bool}>
     */
    public function versions(int $pageId): array
    {
        $this->framework->initialize();
        $page = PageModel::findWithDetails($pageId);

        if (null === $page || !$this->isAvailable()) {
            return [];
        }

        $finder = new PageFinder();
        $masterRoot = $finder->findMasterRootForPage($page);
        $versions = [];

        foreach ($finder->findAssociatedForPage($page, false, null, false) as $version) {
            $version->loadDetails();

            if ((int) $version->id !== $pageId && !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_PAGE, (int) $version->id)) {
                continue;
            }

            $versions[] = [
                'id' => (int) $version->id,
                'title' => (string) $version->title,
                'language' => (string) $version->language,
                'name' => $this->name((string) $version->language),
                'main' => null !== $masterRoot && (int) $version->rootId === (int) $masterRoot->id,
            ];
        }

        usort($versions, static fn (array $a, array $b): int => [$b['main'], $a['language']] <=> [$a['main'], $b['language']]);

        return $versions;
    }

    /** The version in the main language, null when the page has none the user may open. */
    public function mainVersion(int $pageId): array|null
    {
        foreach ($this->versions($pageId) as $version) {
            if ($version['main']) {
                return $version;
            }
        }

        return null;
    }

    /** Whether two pages are versions of each other (or the same page). */
    public function areVersions(int $pageId, int $otherPageId): bool
    {
        return \in_array($otherPageId, array_column($this->versions($pageId), 'id'), true);
    }

    /**
     * Which article of the target page belongs to which article of the main
     * page: by tl_article.languageMain (changelanguage), otherwise by column
     * and position.
     *
     * @return array<int, int> main article ID => target article ID
     */
    public function articleMap(int $mainPageId, int $targetPageId): array
    {
        $hasLanguageMain = \in_array('languagemain', array_map('strtolower', array_keys($this->db->createSchemaManager()->listTableColumns('tl_article'))), true);
        $columns = 'id, inColumn'.($hasLanguageMain ? ', languageMain' : '');
        $main = $this->db->fetchAllAssociative("SELECT $columns FROM tl_article WHERE pid = ? AND tstamp > 0 ORDER BY sorting", [$mainPageId]);
        $target = $this->db->fetchAllAssociative("SELECT $columns FROM tl_article WHERE pid = ? AND tstamp > 0 ORDER BY sorting", [$targetPageId]);
        $map = [];

        foreach ($target as $article) {
            $languageMain = (int) ($article['languageMain'] ?? 0);

            if ($languageMain > 0 && \in_array($languageMain, array_map('intval', array_column($main, 'id')), true)) {
                $map[$languageMain] = (int) $article['id'];
            }
        }

        // The rest by column and position, as long as nothing is linked otherwise.
        $byColumn = static function (array $articles, array $exclude): array {
            $grouped = [];

            foreach ($articles as $article) {
                if (!\in_array((int) $article['id'], $exclude, true)) {
                    $grouped[(string) $article['inColumn']][] = (int) $article['id'];
                }
            }

            return $grouped;
        };

        $mainRest = $byColumn($main, array_keys($map));
        $targetRest = $byColumn($target, array_values($map));

        foreach ($mainRest as $column => $ids) {
            foreach ($ids as $index => $id) {
                if (isset($targetRest[$column][$index])) {
                    $map[$id] = $targetRest[$column][$index];
                }
            }
        }

        return $map;
    }
}
