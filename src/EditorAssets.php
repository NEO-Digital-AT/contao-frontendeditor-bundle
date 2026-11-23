<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle;

use Symfony\Component\Asset\Packages;

/**
 * Addresses of the editor's own files (public/: script, stylesheet, icons,
 * axe-core) with a version, so that after an update the browser, or a cache
 * in between, never keeps using the file of the old version. Contao 5.7 adds
 * one itself (the file's modification time as "?v=…"); before 5.7 the files
 * of a bundle have none, and the address gets a short hash of the file's
 * content instead.
 */
final class EditorAssets
{
    private const BASE = 'bundles/neofrontendeditor/';

    /** @var array<string, string|null> version per file, for the request */
    private array $versions = [];

    public function __construct(
        private readonly Packages $packages,
        private readonly string $publicDir = __DIR__.'/../public',
    ) {
    }

    /** The address of $file (path below public/, e.g. "editor.js"), with its version when the file exists. */
    public function url(string $file): string
    {
        $file = ltrim($file, '/');
        $url = $this->packages->getUrl(self::BASE.$file);

        // Contao's version strategy has given it one already.
        if (str_contains($url, '?')) {
            return $url;
        }

        $version = $this->version($file);

        return null === $version ? $url : $url.'?v='.$version;
    }

    /** Whether public/ holds $file. */
    public function exists(string $file): bool
    {
        return is_file($this->publicDir.'/'.ltrim($file, '/'));
    }

    private function version(string $file): string|null
    {
        if (\array_key_exists($file, $this->versions)) {
            return $this->versions[$file];
        }

        $path = $this->publicDir.'/'.$file;
        $hash = is_file($path) ? hash_file('xxh3', $path) : false;

        return $this->versions[$file] = false === $hash ? null : substr($hash, 0, 12);
    }
}
