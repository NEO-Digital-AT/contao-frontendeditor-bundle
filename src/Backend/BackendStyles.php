<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Backend;

use Contao\Backend;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The stylesheets of the back end as Contao loads them (theme, core, the
 * back-end skin from contao.backend.custom_css and what extensions add to the
 * back end), prepared for the editor's closed area on the page (shadow DOM):
 * there, ":root" and the dark colour scheme of <html> apply to the host
 * element instead, and relative addresses must stay valid.
 */
final class BackendStyles
{
    /**
     * @param list<string> $customCss
     */
    public function __construct(
        private readonly Packages $packages,
        #[Autowire('%contao.web_dir%')]
        private readonly string $webDir,
        #[Autowire('%contao.backend.custom_css%')]
        private readonly array $customCss,
    ) {
    }

    /** @return list<string> public paths of the stylesheets, in the order of the back end */
    public function paths(): array
    {
        $urls = [];

        // Contao 5: the stylesheet of the back-end theme first; Contao 6 has no themes.
        if (method_exists(Backend::class, 'getTheme')) {
            $urls[] = $this->packages->getUrl('backend.css', 'system/themes/'.Backend::getTheme());
        }

        $urls[] = $this->packages->getUrl('backend.css', 'contao_core');

        foreach ($GLOBALS['TL_CSS'] ?? [] as $entry) {
            $urls[] = explode('|', (string) $entry)[0];
        }

        foreach ($this->customCss as $path) {
            $urls[] = $this->packages->getUrl($path);
        }

        $paths = [];

        foreach ($urls as $url) {
            // Only files of this installation; external stylesheets stay outside.
            if ('' === $url || preg_match('#^(?:[a-z]+:)?//#i', $url)) {
                continue;
            }

            $paths[] = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        }

        return array_values(array_unique($paths));
    }

    /** All stylesheets in one, for the shadow DOM. */
    public function scoped(): string
    {
        $css = '';

        foreach ($this->paths() as $path) {
            $file = $this->webDir.$path;

            if (!str_ends_with($path, '.css') || !is_file($file)) {
                continue;
            }

            $css .= self::scope((string) file_get_contents($file), \dirname($path))."\n";
        }

        return $css;
    }

    public static function scope(string $css, string $directory): string
    {
        $directory = rtrim($directory, '/');

        // Relative addresses (icons, fonts) relative to the original file.
        $css = (string) preg_replace_callback(
            '/url\(\s*([\'"]?)(?![a-z]+:|\/|#)([^\'")]+)\1\s*\)/i',
            static fn (array $m): string => 'url('.$m[1].$directory.'/'.$m[2].$m[1].')',
            $css,
        );

        // The colour scheme and the variables of <html> belong to the host element.
        $css = (string) preg_replace('/html\[data-color-scheme=([\'"]?)(dark|light)\1\]/', ':host([data-color-scheme=$2])', $css);

        return str_replace(':root', ':host', $css);
    }
}
