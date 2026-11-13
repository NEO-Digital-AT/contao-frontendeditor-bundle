<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Design;

use Contao\ContentElement;
use Contao\ContentProxy;
use Contao\Controller;
use Contao\CoreBundle\Twig\Loader\ContaoFilesystemLoader;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Contao\Module;
use Contao\ModuleProxy;
use Contao\PageModel;
use Contao\TemplateLoader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Which templates output an element, a front-end module or a page, and where
 * each one comes from: Contao, an extension, the website's own templates
 * folder or the theme's. Twig templates are looked up with Contao's template
 * loader in the theme of the page, legacy .html5 templates with Contao's
 * template loader; templates used inside a Twig template (extends, use,
 * include, embed, import) are read from its source.
 *
 * Each template: name, kind (twig|html5), source (contao|extension|custom|
 * theme), package (for extensions), path (relative to the project), inner
 * (list of the templates it uses).
 */
final class TemplateInfo
{
    private const MAX_DEPTH = 3;

    public function __construct(
        #[Autowire(service: 'contao.twig.filesystem_loader')]
        private readonly ContaoFilesystemLoader $loader,
        #[Autowire(service: 'contao.twig.loader.theme_namespace')]
        private readonly ThemeNamespace $themeNamespace,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $row a record of tl_content
     *
     * @return list<array<string, mixed>>
     */
    public function forContent(array $row, PageModel $page): array
    {
        $type = (string) $row['type'];
        $name = (string) ($row['customTpl'] ?? '') ?: $this->defaultName('content_element', $type, static function (string $type): string|null {
            $class = ContentElement::findClass($type);

            return $class && ContentProxy::class !== $class ? ((new \ReflectionClass($class))->getDefaultProperties()['strTemplate'] ?? null) : null;
        }, 'ce_');

        return [$this->describe($name, $page)];
    }

    /**
     * @param array<string, mixed> $row a record of tl_module
     *
     * @return list<array<string, mixed>>
     */
    public function forModule(array $row, PageModel $page): array
    {
        $type = (string) $row['type'];
        $name = (string) ($row['customTpl'] ?? '') ?: $this->defaultName('frontend_module', $type, static function (string $type): string|null {
            $class = Module::findClass($type);

            return $class && ModuleProxy::class !== $class ? ((new \ReflectionClass($class))->getDefaultProperties()['strTemplate'] ?? null) : null;
        }, 'mod_');
        $template = $this->describe($name, $page);

        // Navigation modules output their items with a second template (field navigationTpl).
        Controller::loadDataContainer('tl_module');

        if (str_contains((string) ($GLOBALS['TL_DCA']['tl_module']['palettes'][$type] ?? ''), 'navigationTpl')) {
            $template['inner'][] = $this->describe((string) ($row['navigationTpl'] ?? '') ?: 'nav_default', $page);
        }

        return [$template];
    }

    /**
     * The template of the page: the layout's (legacy layouts: fe_page or the
     * one chosen in the layout; modern layouts: their Twig template).
     *
     * @param array<string, mixed>|null $layout a record of tl_layout
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(array|null $layout, PageModel $page): array
    {
        if (null === $layout) {
            return [];
        }

        if ('modern' === ($layout['type'] ?? 'default')) {
            $name = (string) ($layout['template'] ?? '') ?: 'page/layout';

            return [$this->describe($name, $page)];
        }

        return [$this->describe((string) ($layout['template'] ?? '') ?: 'fe_page', $page)];
    }

    /**
     * A template by name (Twig "group/name" or legacy "name").
     *
     * @return list<array<string, mixed>>
     */
    public function forName(string $name, PageModel $page): array
    {
        return [$this->describe($name, $page)];
    }

    /**
     * @param \Closure(string): (string|null) $legacy the template of a legacy element class
     */
    private function defaultName(string $prefix, string $type, \Closure $legacy, string $legacyPrefix): string
    {
        $twig = $prefix.'/'.$type;

        if ($this->loader->exists('@Contao/'.$twig.'.html.twig')) {
            return $twig;
        }

        return $legacy($type) ?: $legacyPrefix.$type;
    }

    /** @return array<string, mixed> */
    private function describe(string $name, PageModel $page, int $depth = 0): array
    {
        $name = preg_replace('/\.html(5|\.twig)$/', '', $name) ?? $name;

        if (str_contains($name, '/') && $this->loader->exists('@Contao/'.$name.'.html.twig')) {
            try {
                $identifier = $this->loader->getFirst($name, $this->themeSlug($page));
                $path = $this->loader->getSourceContext($identifier)->getPath();
                $template = ['name' => $name, 'kind' => 'twig', ...$this->origin($identifier, $path), 'inner' => []];

                if ($depth < self::MAX_DEPTH) {
                    foreach ($this->references((string) @file_get_contents($path)) as $reference) {
                        if ($reference !== $name) {
                            $template['inner'][] = $this->describe($reference, $page, $depth + 1);
                        }
                    }
                }

                return $template;
            } catch (\Throwable) {
                // Not resolvable in this theme: listed by name only.
            }
        }

        // Legacy templates (.html5) exist up to Contao 5; Contao 6 has no TemplateLoader.
        if (!str_contains($name, '/') && class_exists(TemplateLoader::class)) {
            try {
                $path = TemplateLoader::getPath($name, 'html5', (string) ($page->templateGroup ?: 'templates'));

                return ['name' => $name, 'kind' => 'html5', ...$this->origin(null, $path), 'inner' => []];
            } catch (\Throwable) {
                // A legacy template that does not exist.
            }
        }

        return ['name' => $name, 'kind' => str_contains($name, '/') ? 'twig' : 'html5', 'source' => 'unknown', 'package' => null, 'path' => null, 'inner' => []];
    }

    /** @return array{source: string, package: string|null, path: string} */
    private function origin(string|null $identifier, string $path): array
    {
        $relative = Path::makeRelative($path, $this->projectDir);
        $package = null;

        if (null !== $identifier && str_starts_with($identifier, '@Contao_Theme_')) {
            $source = 'theme';
        } elseif (str_starts_with($relative, 'templates/') || str_starts_with($relative, 'contao/templates/')) {
            $source = 'custom';
        } elseif (preg_match('~^vendor/([^/]+/[^/]+)/~', $relative, $match)) {
            $source = str_starts_with($match[1], 'contao/') ? 'contao' : 'extension';
            $package = 'extension' === $source ? $match[1] : null;
        } else {
            $source = 'custom';
        }

        return ['source' => $source, 'package' => $package, 'path' => $relative];
    }

    /**
     * Templates a Twig source uses: "@Contao/name.html.twig" in extends, use,
     * include, embed, import, from and include().
     *
     * @return list<string>
     */
    private function references(string $source): array
    {
        preg_match_all('~(?:extends|use|include|embed|import|from)\s*\(?\s*["\']@Contao/([a-zA-Z0-9_./\-]+?)\.html\.twig["\']~', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function themeSlug(PageModel $page): string|null
    {
        $group = (string) $page->templateGroup;

        if ('' === $group) {
            return null;
        }

        try {
            return $this->themeNamespace->generateSlug(Path::makeRelative($group, 'templates'));
        } catch (\Throwable) {
            return null;
        }
    }
}
